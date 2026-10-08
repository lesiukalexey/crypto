<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ranking-parameters.php';
require_once __DIR__ . '/ranking-snapshot.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $entryDefaults = json_decode((string) file_get_contents($root . '/entry_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    $exitDefaults = json_decode((string) file_get_contents($root . '/exit_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    $ranking = rankingParameters($_GET, $entryDefaults, $exitDefaults);
    $startValue = (string) ($_GET['start_day'] ?? '2026-06-10');
    $endValue = (string) ($_GET['end_day'] ?? (new DateTimeImmutable('today', new DateTimeZone('Europe/Kyiv')))->format('Y-m-d'));
    $timezone = new DateTimeZone('Europe/Kyiv');
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

    $balance = (string) ($_GET['balance'] ?? '10');
    $fee = (string) ($_GET['fee'] ?? '0.06');
    if (!preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $balance) || (float) $balance <= 0 || (float) $balance > 1000000000
        || !preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $fee) || (float) $fee < 0 || (float) $fee > 5) {
        respond(['status' => 'error', 'message' => 'Проверьте баланс и комиссию.'], 400);
    }
    $martingaleMode = (string) ($_GET['martingale_mode'] ?? 'none');
    $martingaleTiming = (string) ($_GET['martingale_timing'] ?? 'immediate');
    $martingaleAttempts = filter_var($_GET['martingale_attempts'] ?? 3, FILTER_VALIDATE_INT);
    if (!in_array($martingaleMode, ['none', 'simple', 'reverse'], true)
        || !in_array($martingaleTiming, ['immediate', 'rules'], true)
        || $martingaleAttempts === false || $martingaleAttempts < 2 || $martingaleAttempts > 10) {
        respond(['status' => 'error', 'message' => 'Некорректные параметры мартингейла.'], 400);
    }
    $minTrades = (string) ($_GET['save_mode'] ?? '1') !== '0' ? 2 : 1;
    $entryConfig = (string) ($_GET['entry_config'] ?? '');
    if (rankingEntryConfig($entryConfig, $ranking['worker'], $entryDefaults['immediate_directions'] ?? []) === null) {
        respond(['status' => 'error', 'message' => 'Неизвестный порог входа.'], 400);
    }
    $exitConfig = (string) ($_GET['exit_config'] ?? '');
    $targetPolicy = 'scaled';
    if ($martingaleMode !== 'none' && preg_match('/:(scaled|fixed)$/', $exitConfig, $policyMatch) === 1) {
        $targetPolicy = $policyMatch[1];
        $exitConfig = substr($exitConfig, 0, -strlen($policyMatch[0]));
    }
    $exitRanges = $ranking['ranges'];
    $validExit = false;
    if (preg_match('/^fixed:(\d+):(\d+)$/', $exitConfig, $matches) === 1) {
        $validExit = rankingHasValue((int) $matches[1], $exitRanges['fixed_tp'])
            && rankingHasValue((int) $matches[2], $exitRanges['fixed_sl']);
    } elseif (preg_match('/^loss:(\d+)$/', $exitConfig, $matches) === 1) {
        $validExit = rankingHasValue((int) $matches[1], $exitRanges['loss']);
    } elseif (preg_match('/^profit:(\d+)$/', $exitConfig, $matches) === 1) {
        $validExit = rankingHasValue((int) $matches[1], $exitRanges['profit']);
    }
    if (!$validExit || ($martingaleMode === 'none' && preg_match('/:(scaled|fixed)$/', (string) ($_GET['exit_config'] ?? '')) === 1)) {
        respond(['status' => 'error', 'message' => 'Неизвестная комбинация TakeProfit & StopLoss.'], 400);
    }
    $originalExitConfig = (string) ($_GET['exit_config'] ?? '');
    if ($martingaleMode !== 'none' && $targetPolicy === 'fixed' && !str_starts_with($exitConfig, 'fixed:')) {
        respond(['status' => 'error', 'message' => 'Для этой пары политика TP/SL не поддерживается.'], 400);
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
    $home = getenv('HOME') ?: '/home/alex';
    $cacheDir = $home . '/.ai/home/.local/bitget-backtest-cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
        respond(['status' => 'error', 'message' => 'Не удалось подготовить кэш рейтинга.'], 500);
    }
    $generationLock = fopen($cacheDir . '/strategy-ranking-generation.lock', 'c');
    if ($generationLock === false || !flock($generationLock, LOCK_SH)) {
        if (is_resource($generationLock)) fclose($generationLock);
        respond(['status' => 'error', 'message' => 'Не удалось проверить поколение кэша рейтинга.'], 503);
    }
    $generationPath = $cacheDir . '/strategy-ranking-generation.txt';
    $generation = is_file($generationPath) ? trim((string) file_get_contents($generationPath)) : 'initial';
    if ($generation === '') respond(['status' => 'error', 'message' => 'Не удалось прочитать поколение кэша рейтинга.'], 503);
    $requestedGeneration = (string) ($_GET['ranking_generation'] ?? '');
    if ($requestedGeneration === '' || !hash_equals($generation, $requestedGeneration)) {
        respond(['status' => 'cancelled', 'message' => 'Расчёт остановлен: поколение рейтинга уже сброшено.']);
    }
    $maxIds = rankingSnapshotIds($pdo, $cacheDir, $config['category'], $startValue, $endValue, $startUtc, $endUtc, $generation);
    $snapshotJson = json_encode($maxIds, JSON_THROW_ON_ERROR);
    $key = hash('sha256', json_encode([
        'portfolio-combination-details-v5-percent-entry-tpsl', $generation, hash('sha256', $snapshotJson), $config['category'],
        $startValue, $endValue, $balance, $fee, $minTrades, $entryConfig, $originalExitConfig,
        $ranking['worker'], $martingaleMode, $martingaleTiming, $martingaleAttempts,
    ], JSON_THROW_ON_ERROR));
    $resultPath = $cacheDir . '/combination-' . $key . '.json';
    $statusPath = $cacheDir . '/combination-' . $key . '.status.json';
    if (is_file($resultPath)) {
        $result = json_decode((string) file_get_contents($resultPath), true);
        if (is_array($result) && ($result['status'] ?? '') === 'ready') respond($result + ['progress' => 100]);
    }
    $status = is_file($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
    $statusAge = is_file($statusPath) ? time() - (int) filemtime($statusPath) : PHP_INT_MAX;
    if (is_array($status) && ($status['status'] ?? '') === 'error' && $statusAge < 300) respond($status, 503);
    if (is_array($status) && ($status['status'] ?? '') === 'pending' && $statusAge < 1800) {
        respond(['status' => 'pending', 'progress' => (int) ($status['progress'] ?? 0)]);
    }

    $startLock = fopen($cacheDir . '/combination-' . $key . '.start.lock', 'c');
    if ($startLock !== false && flock($startLock, LOCK_EX | LOCK_NB)) {
        $status = is_file($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
        $statusAge = is_file($statusPath) ? time() - (int) filemtime($statusPath) : PHP_INT_MAX;
        if (!is_array($status) || ($status['status'] ?? '') !== 'pending' || $statusAge >= 1800) {
            file_put_contents($statusPath, json_encode(['status' => 'pending', 'progress' => 0]));
            $python = $root . '/.venv/bin/python';
            if (!is_executable($python)) $python = '/usr/bin/python3';
            $command = 'nohup ' . escapeshellarg($python) . ' ' . escapeshellarg($root . '/launch-ranking-worker.py')
                . ' ' . escapeshellarg($python) . ' ' . escapeshellarg($root . '/portfolio_combination_details.py')
                . ' --cache-key ' . escapeshellarg($key)
                . ' --start-day ' . escapeshellarg($startValue)
                . ' --end-day ' . escapeshellarg($endValue)
                . ' --balance ' . escapeshellarg($balance)
                . ' --fee-percent ' . escapeshellarg($fee)
                . ' --min-trades ' . escapeshellarg((string) $minTrades)
                . ' --entry-config ' . escapeshellarg($entryConfig)
                . ' --exit-config ' . escapeshellarg($originalExitConfig)
                . ' --max-ids ' . escapeshellarg($snapshotJson)
                . ' --cache-dir ' . escapeshellarg($cacheDir)
                . ' --ranking-params ' . escapeshellarg(json_encode($ranking['worker'], JSON_THROW_ON_ERROR))
                . ' --martingale-mode ' . escapeshellarg($martingaleMode)
                . ' --martingale-timing ' . escapeshellarg($martingaleTiming)
                . ' --martingale-attempts ' . escapeshellarg((string) $martingaleAttempts)
                . ' >/dev/null 2>&1 &';
            exec($command);
        }
        flock($startLock, LOCK_UN);
        fclose($startLock);
    }
    respond(['status' => 'pending', 'progress' => 0]);
} catch (InvalidArgumentException $exception) {
    respond(['status' => 'error', 'message' => $exception->getMessage()], 400);
} catch (Throwable $exception) {
    respond(['status' => 'error', 'message' => 'Не удалось подготовить расчёт комбинации.'], 500);
}
