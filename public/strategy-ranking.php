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
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $entryProfileConfig = json_decode((string) file_get_contents(dirname(__DIR__) . '/entry_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    $exitProfileConfig = json_decode((string) file_get_contents(dirname(__DIR__) . '/exit_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    $rankingParameters = rankingParameters($_GET, $entryProfileConfig, $exitProfileConfig);
    $ranking = $rankingParameters['worker'];
    $timezone = new DateTimeZone('Europe/Kyiv');
    $today = new DateTimeImmutable('today', $timezone);
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    $startValue = (string) ($_GET['start_day'] ?? $today->format('Y-m-d'));
    $endValue = (string) ($_GET['end_day'] ?? $startValue);
    $entryConfig = (string) ($_GET['entry_config'] ?? 'entry:10:1');
    $saveMode = (string) ($_GET['save_mode'] ?? '1') !== '0';
    $minTrades = $saveMode ? 2 : 1;
    $balance = (string) ($_GET['balance'] ?? '10');
    $fee = (string) ($_GET['fee'] ?? '0.06');
    $martingaleMode = (string) ($_GET['martingale_mode'] ?? 'none');
    $martingaleTiming = (string) ($_GET['martingale_timing'] ?? 'immediate');
    $martingaleAttempts = (int) ($_GET['martingale_attempts'] ?? 3);
    if (!in_array($martingaleMode, ['none', 'simple', 'reverse'], true)
        || !in_array($martingaleTiming, ['immediate', 'rules'], true)
        || $martingaleAttempts < 2 || $martingaleAttempts > 10) {
        respond(['status' => 'error', 'message' => 'Некорректные параметры мартингейла.'], 400);
    }
    $entrySelection = rankingEntryConfig($entryConfig, $ranking, $entryProfileConfig['immediate_directions'] ?? []);
    if ($entrySelection === null) {
        respond(['status' => 'error', 'message' => 'Неизвестный порог входа.'], 400);
    }
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
        || $startDay->format('Y-m-d') !== $startValue || $endDay->format('Y-m-d') !== $endValue
        || $endDay < $startDay) {
        respond(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $password = getenv($config['mysql']['password_env']) ?: '';
    if ($password === '') {
        respond(['status' => 'error', 'message' => 'Не задан пароль подключения к MySQL.'], 503);
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['mysql']['host'], $config['mysql']['port'], $config['mysql']['database']),
        $config['mysql']['user'],
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $stmt = $pdo->prepare('SELECT MAX(id) FROM quote_snapshots WHERE category = ? AND symbol = ? AND received_at_utc >= ? AND received_at_utc < ?');
    $stmt->execute([$config['category'], $symbol, $startUtc, $endUtc]);
    $maxId = (int) ($stmt->fetchColumn() ?: 0);
    if (isset($_GET['snapshot_ids'])) {
        $snapshotIds = json_decode((string) $_GET['snapshot_ids'], true);
        $requestedMaxId = is_array($snapshotIds) ? ($snapshotIds[$symbol] ?? null) : null;
        if ((is_int($requestedMaxId) || (is_string($requestedMaxId) && preg_match('/^\d+$/', $requestedMaxId)))
            && (int) $requestedMaxId <= $maxId) {
            $maxId = (int) $requestedMaxId;
        }
    }

    $home = getenv('HOME') ?: '/home/alex';
    $cacheDir = $home . '/.ai/home/.local/bitget-backtest-cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
        respond(['status' => 'error', 'message' => 'Не удалось подготовить кэш рейтинга.'], 500);
    }
    $generationPath = $cacheDir . '/strategy-ranking-generation.txt';
    $cacheGeneration = 'initial';
    if (is_file($generationPath)) {
        $generationValue = file_get_contents($generationPath);
        if ($generationValue === false || trim($generationValue) === '') {
            respond(['status' => 'error', 'message' => 'Не удалось прочитать поколение кэша рейтинга.'], 503);
        }
        $cacheGeneration = trim($generationValue);
    }
    $key = hash('sha256', json_encode(['ranking-v20-martingale-exit-policies-snapshot-id', $cacheGeneration, $config['category'], $symbol, $startValue, $endValue, $maxId, $balance, $fee, $minTrades, $ranking, $martingaleMode, $martingaleTiming, $martingaleAttempts], JSON_THROW_ON_ERROR));
    $resultPath = $cacheDir . '/' . $key . '.json';
    $statusPath = $cacheDir . '/' . $key . '.status.json';
    $profilePath = $cacheDir . '/' . $key . '.' . $entryConfig . '.json';
    $entries = [];
    if (is_file($resultPath)) {
        $result = json_decode((string) file_get_contents($resultPath), true);
        if (is_array($result) && ($result['status'] ?? '') === 'ready') {
            $entries = $result['entries'] ?? [];
            if ($entries === []) {
                respond(['status' => 'insufficient_data', 'message' => 'Для рейтинга нужно минимум ' . $minTrades . ' ' . ($minTrades === 1 ? 'сделка' : 'сделки') . ' за выбранный период.', 'entries' => [], 'groups' => []]);
            }
            $profile = is_file($profilePath) ? json_decode((string) file_get_contents($profilePath), true) : null;
            if (is_array($profile) && ($profile['status'] ?? '') === 'ready') {
                respond(['status' => 'ready', 'entries' => $entries, 'groups' => $profile['groups']]);
            }
        }
    }

    $status = is_file($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
    if (is_array($status) && ($status['status'] ?? '') === 'error' && time() - (int) filemtime($statusPath) < 300) {
        respond($status, 503);
    }
    $statusAge = is_file($statusPath) ? time() - (int) filemtime($statusPath) : PHP_INT_MAX;
    $workerBusy = false;
    $workerLockProbe = fopen($cacheDir . '/ranking-worker.lock', 'c');
    if ($workerLockProbe !== false) {
        if (flock($workerLockProbe, LOCK_EX | LOCK_NB)) {
            flock($workerLockProbe, LOCK_UN);
        } else {
            $workerBusy = true;
        }
        fclose($workerLockProbe);
    }
    $isPending = is_array($status) && ($status['status'] ?? '') === 'pending';
    if (is_array($status) && ($status['status'] ?? '') === 'ready' && $entries !== []) {
        $entryAvailable = false;
        foreach ($entries as $entry) {
            if (($entry['id'] ?? '') === $entryConfig) { $entryAvailable = true; break; }
        }
        if (!$entryAvailable) {
            respond(['status' => 'entry_unavailable', 'message' => 'Для этого порога нет вариантов выхода минимум с ' . $minTrades . ' ' . ($minTrades === 1 ? 'сделкой' : 'сделками') . '.', 'entries' => $entries, 'groups' => []]);
        }
    }
    $stale = !is_array($status)
        || ($isPending ? !$workerBusy : ($statusAge > 1800 || (($status['status'] ?? '') === 'error' && $statusAge > 300)));
    if ($stale) {
        $lock = fopen($cacheDir . '/' . $key . '.lock', 'c');
        if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
            file_put_contents($statusPath, json_encode(['status' => 'pending', 'progress' => 0]));
            $worker = dirname(__DIR__) . '/strategy_ranking.py';
            $python = dirname(__DIR__) . '/.venv/bin/python';
            if (!is_executable($python)) $python = '/usr/bin/python3';
            $logPath = $cacheDir . '/' . $key . '.log';
            $command = 'nohup ' . escapeshellarg($python) . ' ' . escapeshellarg($worker)
                . ' --cache-key ' . escapeshellarg($key)
                . ' --symbol ' . escapeshellarg($symbol)
                . ' --start-day ' . escapeshellarg($startValue)
                . ' --end-day ' . escapeshellarg($endValue)
                . ' --balance ' . escapeshellarg($balance)
                . ' --fee-percent ' . escapeshellarg($fee)
                . ' --min-trades ' . escapeshellarg((string) $minTrades)
                . ' --max-id ' . escapeshellarg((string) $maxId)
                . ' --cache-dir ' . escapeshellarg($cacheDir)
                . ' --ranking-params ' . escapeshellarg(json_encode($ranking, JSON_THROW_ON_ERROR))
                . ' --martingale-mode ' . escapeshellarg($martingaleMode)
                . ' --martingale-timing ' . escapeshellarg($martingaleTiming)
                . ' --martingale-attempts ' . escapeshellarg((string) $martingaleAttempts)
                . ' >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null &';
            exec($command);
            flock($lock, LOCK_UN);
            fclose($lock);
        } elseif ($lock !== false) {
            fclose($lock);
        }
        $status = ['status' => 'pending', 'progress' => 0];
    }
    respond((is_array($status) ? $status : ['status' => 'pending', 'progress' => 0]) + ['entries' => $entries]);
} catch (InvalidArgumentException $error) {
    respond(['status' => 'error', 'message' => $error->getMessage()], 400);
} catch (Throwable $error) {
    respond(['status' => 'error', 'message' => 'Не удалось загрузить рейтинг стратегий.'], 503);
}
