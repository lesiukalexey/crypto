<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ranking-parameters.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $entryProfileConfig = json_decode((string) file_get_contents($root . '/entry_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    $exitProfileConfig = json_decode((string) file_get_contents($root . '/exit_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    $ranking = rankingParameters($_GET, $entryProfileConfig, $exitProfileConfig)['worker'];
    $timezone = new DateTimeZone('Europe/Kyiv');
    $today = new DateTimeImmutable('today', $timezone);
    $startValue = (string) ($_GET['start_day'] ?? $today->format('Y-m-d'));
    $endValue = (string) ($_GET['end_day'] ?? $startValue);
    $entryConfig = (string) ($_GET['entry_config'] ?? 'entry:10:1');
    $saveMode = (string) ($_GET['save_mode'] ?? '1') !== '0';
    $minTrades = $saveMode ? 2 : 1;
    $balance = (string) ($_GET['balance'] ?? '10');
    $fee = (string) ($_GET['fee'] ?? '0.06');
    $immediateDirections = $entryProfileConfig['immediate_directions'] ?? [];
    $validImmediate = preg_match('/^immediate:(short|long)$/', $entryConfig, $immediateMatches) === 1
        && in_array($immediateMatches[1], $immediateDirections, true);
    $validMomentum = preg_match('/^entry:(\d+):(\d+)$/', $entryConfig, $entryMatches) === 1
        && in_array((int) $entryMatches[1], $ranking['entry_windows'], true)
        && in_array((int) $entryMatches[2], $ranking['entry_thresholds_cents'], true);
    if (!$validImmediate && !$validMomentum) respond(['status' => 'error', 'message' => 'Неизвестный порог входа.'], 400);
    if (!preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $balance) || (float) $balance <= 0 || (float) $balance > 1000000000) {
        respond(['status' => 'error', 'message' => 'Некорректный стартовый баланс.'], 400);
    }
    if (!preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $fee) || (float) $fee < 0 || (float) $fee > 5) {
        respond(['status' => 'error', 'message' => 'Некорректная комиссия.'], 400);
    }
    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue, $timezone);
    $startErrors = DateTimeImmutable::getLastErrors();
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $timezone);
    $endErrors = DateTimeImmutable::getLastErrors();
    if ($startDay === false || $endDay === false
        || ($startErrors !== false && ($startErrors['warning_count'] || $startErrors['error_count']))
        || ($endErrors !== false && ($endErrors['warning_count'] || $endErrors['error_count']))
        || $startDay->format('Y-m-d') !== $startValue || $endDay->format('Y-m-d') !== $endValue || $endDay < $startDay) {
        respond(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') respond(['status' => 'error', 'message' => 'Не задан пароль подключения к MySQL.'], 503);
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $stmt = $pdo->prepare('SELECT symbol, MAX(id) AS max_id FROM quote_snapshots WHERE category = ? AND received_at_utc >= ? AND received_at_utc < ? GROUP BY symbol ORDER BY symbol');
    $stmt->execute([$config['category'], $startUtc, $endUtc]);
    $maxIds = [];
    foreach ($stmt->fetchAll() as $row) $maxIds[(string) $row['symbol']] = (int) $row['max_id'];
    if ($maxIds === []) respond(['status' => 'ready', 'progress' => 100, 'symbols' => []]);

    $home = getenv('HOME') ?: '/home/alex';
    $cacheDir = $home . '/.ai/home/.local/bitget-backtest-cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
        respond(['status' => 'error', 'message' => 'Не удалось подготовить кэш рейтинга.'], 500);
    }
    $key = hash('sha256', json_encode(['symbol-ranking-v3-custom-ranges', $config['category'], $startValue, $endValue, $balance, $fee, $entryConfig, $maxIds, $minTrades, $ranking], JSON_THROW_ON_ERROR));
    $resultPath = $cacheDir . '/symbols-' . $key . '.json';
    $statusPath = $cacheDir . '/symbols-' . $key . '.status.json';
    if (is_file($resultPath)) {
        $result = json_decode((string) file_get_contents($resultPath), true);
        if (is_array($result) && ($result['status'] ?? '') === 'ready') respond($result + ['progress' => 100]);
    }

    $status = is_file($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
    $statusAge = is_file($statusPath) ? time() - (int) filemtime($statusPath) : PHP_INT_MAX;
    $workerBusy = false;
    $probe = fopen($cacheDir . '/ranking-worker.lock', 'c');
    if ($probe !== false) {
        if (flock($probe, LOCK_EX | LOCK_NB)) flock($probe, LOCK_UN);
        else $workerBusy = true;
        fclose($probe);
    }
    $pending = is_array($status) && ($status['status'] ?? '') === 'pending';
    $stale = !is_array($status) || ($pending ? !$workerBusy : $statusAge > 1800 || (($status['status'] ?? '') === 'error' && $statusAge > 300));
    if ($stale) {
        $lock = fopen($cacheDir . '/symbols-' . $key . '.lock', 'c');
        if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
            file_put_contents($statusPath, json_encode(['status' => 'pending', 'progress' => 0]));
            $python = $root . '/.venv/bin/python';
            if (!is_executable($python)) $python = '/usr/bin/python3';
            $command = 'nohup ' . escapeshellarg($python) . ' ' . escapeshellarg($root . '/symbol_ranking.py')
                . ' --cache-key ' . escapeshellarg($key)
                . ' --start-day ' . escapeshellarg($startValue)
                . ' --end-day ' . escapeshellarg($endValue)
                . ' --balance ' . escapeshellarg($balance)
                . ' --fee-percent ' . escapeshellarg($fee)
                . ' --min-trades ' . escapeshellarg((string) $minTrades)
                . ' --entry-config ' . escapeshellarg($entryConfig)
                . ' --max-ids ' . escapeshellarg(json_encode($maxIds, JSON_THROW_ON_ERROR))
                . ' --cache-dir ' . escapeshellarg($cacheDir)
                . ' --ranking-params ' . escapeshellarg(json_encode($ranking, JSON_THROW_ON_ERROR))
                . ' >> ' . escapeshellarg($cacheDir . '/symbols-' . $key . '.log') . ' 2>&1 < /dev/null &';
            exec($command);
            flock($lock, LOCK_UN);
            fclose($lock);
        } elseif ($lock !== false) fclose($lock);
        $status = ['status' => 'pending', 'progress' => 0];
    }
    respond(is_array($status) ? $status : ['status' => 'pending', 'progress' => 0]);
} catch (InvalidArgumentException $error) {
    respond(['status' => 'error', 'message' => $error->getMessage()], 400);
} catch (Throwable $error) {
    respond(['status' => 'error', 'message' => 'Не удалось отсортировать торговые пары.'], 503);
}
