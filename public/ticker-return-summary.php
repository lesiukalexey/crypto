<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respondTickerSummary(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function writeTickerSummaryStatus(string $path, array $payload): void
{
    $temporaryPath = tempnam(dirname($path), '.ticker-summary-');
    if ($temporaryPath === false) throw new RuntimeException('Не удалось подготовить статус сводки.');
    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || !rename($temporaryPath, $path)) {
            throw new RuntimeException('Не удалось сохранить статус сводки.');
        }
        @chmod($path, 0600);
    } finally {
        if (is_file($temporaryPath)) @unlink($temporaryPath);
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        respondTickerSummary(['status' => 'error', 'message' => 'Для подготовки сводки требуется POST-запрос.'], 405);
    }
    $runKey = trim((string) ($_POST['run_key'] ?? ''));
    $startValue = trim((string) ($_POST['start_day'] ?? ''));
    $endValue = trim((string) ($_POST['end_day'] ?? ''));
    $symbols = json_decode((string) ($_POST['symbols'] ?? ''), true);
    $confirmationMode = (string) ($_POST['exit_confirmation_mode'] ?? 'immediate');
    $confirmationSnapshots = (int) ($_POST['exit_confirmation_snapshots'] ?? 0);
    $minTrades = (string) ($_POST['save_mode'] ?? '1') === '0' ? 1 : 3;
    if (($runKey !== '' && !preg_match('/^[a-f0-9]{64}$/', $runKey))
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startValue)
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endValue)
        || !is_array($symbols) || $symbols === [] || count($symbols) > 500
        || !in_array($confirmationMode, ['immediate', 'both_delayed', 'tp_delayed', 'sl_delayed', 'none'], true)
        || $confirmationSnapshots < 0 || $confirmationSnapshots > 100000) {
        respondTickerSummary(['status' => 'error', 'message' => 'Некорректные параметры сортировки тикеров.'], 400);
    }
    $symbols = array_values(array_unique(array_filter(array_map(
        static fn($symbol): string => strtoupper(trim((string) $symbol)), $symbols,
    ), static fn(string $symbol): bool => preg_match('/^[A-Z0-9]{2,30}$/', $symbol) === 1)));
    if ($symbols === []) respondTickerSummary(['status' => 'error', 'message' => 'В списке нет торговых пар.'], 400);

    $cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    if ($runKey !== '') {
        $readyRun = false;
        foreach (glob($cacheDir . '/portfolio-*.status.json') ?: [] as $runStatusPath) {
            $status = json_decode((string) @file_get_contents($runStatusPath), true);
            if (is_array($status) && ($status['return_run_key'] ?? '') === $runKey
                && ($status['status'] ?? '') === 'ready'
                && ($status['start_day'] ?? '') <= $startValue && ($status['end_day'] ?? '') >= $endValue) {
                $readyRun = true;
                break;
            }
        }
        if (!$readyRun) respondTickerSummary(['status' => 'missing', 'symbols' => []]);
    }

    $timezone = new DateTimeZone('Europe/Kyiv');
    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue, $timezone);
    $startErrors = DateTimeImmutable::getLastErrors();
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $timezone);
    $endErrors = DateTimeImmutable::getLastErrors();
    if ($startDay === false || $endDay === false || $endDay < $startDay
        || $startDay->format('Y-m-d') !== $startValue || $endDay->format('Y-m-d') !== $endValue
        || ($startErrors !== false && ($startErrors['warning_count'] || $startErrors['error_count']))
        || ($endErrors !== false && ($endErrors['warning_count'] || $endErrors['error_count']))) {
        respondTickerSummary(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') throw new RuntimeException('Database password is unavailable to the summary endpoint.');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $placeholders = implode(',', array_fill(0, count($symbols), '?'));
    $existingPoints = $pdo->prepare(
        'SELECT 1 FROM strategy_return_points p JOIN strategy_return_profiles s ON s.strategy_id=p.strategy_id '
        . 'WHERE p.symbol IN (' . $placeholders
        . ') AND p.point_minute>=? AND p.point_minute<? AND s.exit_confirmation_mode=? '
        . 'AND s.exit_confirmation_snapshots=? LIMIT 1'
    );
    $existingPoints->execute(array_merge($symbols, [
        $startUtc, $endUtc, $confirmationMode,
        $confirmationMode === 'immediate' ? 0 : $confirmationSnapshots,
    ]));
    if ($existingPoints->fetchColumn() === false) respondTickerSummary(['status' => 'missing', 'symbols' => []]);
    $pdo = null;

    $summaryDir = $cacheDir . '/ticker-return-summaries';
    if (!is_dir($summaryDir) && !mkdir($summaryDir, 0700, true) && !is_dir($summaryDir)) {
        respondTickerSummary(['status' => 'error', 'message' => 'Не удалось подготовить кэш доходности тикеров.'], 500);
    }
    sort($symbols, SORT_STRING);
    $job = [
        'run_key' => $runKey,
        'start_day' => $startValue,
        'end_day' => $endValue,
        'symbols' => $symbols,
        'exit_confirmation_mode' => $confirmationMode,
        'exit_confirmation_snapshots' => $confirmationMode === 'immediate' ? 0 : $confirmationSnapshots,
        'min_trades' => $minTrades,
    ];
    $key = hash('sha256', json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $resultPath = $summaryDir . '/' . $key . '.json';
    $statusPath = $summaryDir . '/' . $key . '.status.json';
    if (is_file($resultPath)) {
        $result = json_decode((string) file_get_contents($resultPath), true);
        if (is_array($result) && ($result['status'] ?? '') === 'ready') respondTickerSummary($result);
    }
    if (is_file($statusPath)) {
        $status = json_decode((string) file_get_contents($statusPath), true);
        if (is_array($status) && ($status['status'] ?? '') === 'pending') {
            $workerPid = (int) ($status['pid'] ?? 0);
            $workerAlive = $workerPid > 1 && function_exists('posix_kill') && @posix_kill($workerPid, 0);
            if ($workerAlive || time() - (int) ($status['updated_at'] ?? 0) < 30) {
                respondTickerSummary([
                    'status' => 'running', 'message' => $status['message'] ?? 'Сортирую пары по доходности…',
                    'progress' => $status['progress'] ?? null,
                ]);
            }
        }
    }

    $lock = fopen($summaryDir . '/' . $key . '.lock', 'c');
    if ($lock === false) respondTickerSummary(['status' => 'error', 'message' => 'Не удалось подготовить блокировку сводки.'], 503);
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) respondTickerSummary(['status' => 'running']);
        if (is_file($resultPath)) {
            $result = json_decode((string) file_get_contents($resultPath), true);
            if (is_array($result) && ($result['status'] ?? '') === 'ready') respondTickerSummary($result);
        }
        $jobPath = $summaryDir . '/' . $key . '.job.json';
        $jobJson = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($jobPath, $jobJson, LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить задание сводки.');
        @chmod($jobPath, 0600);
        writeTickerSummaryStatus($statusPath, [
            'status' => 'pending', 'message' => 'Сортирую пары по рассчитанной доходности…',
            'progress' => 0, 'updated_at' => time(),
        ]);
        $worker = __DIR__ . '/ticker-return-summary-worker.php';
        $logPath = $summaryDir . '/' . $key . '.log';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($jobPath)
            . ' > ' . escapeshellarg($logPath) . ' 2>&1 &';
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) throw new RuntimeException('Не удалось запустить подготовку сводки.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    respondTickerSummary(['status' => 'running', 'message' => 'Сортирую пары по рассчитанной доходности…', 'progress' => 0]);
} catch (Throwable $error) {
    error_log('Ticker return summary failed: ' . $error->getMessage());
    respondTickerSummary(['status' => 'error', 'message' => 'Не удалось подготовить доходность по торговым парам.'], 503);
}
