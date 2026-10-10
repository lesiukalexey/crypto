<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ticker-settings-lib.php';

function candleImportRespond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function writeCandleImportJson(string $path, array $payload): void
{
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
        || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось сохранить состояние загрузки.');
    }
    @chmod($path, 0600);
}

function isCandleImportProcess(int $pid): bool
{
    if ($pid < 2 || !is_dir('/proc/' . $pid)) return false;
    $command = @file_get_contents('/proc/' . $pid . '/cmdline');
    return is_string($command) && str_contains($command, 'import_historical_candles.py');
}

function parseCandleImportDate(string $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value) return null;
    return $date;
}

try {
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $localRoot = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-candle-import';
    if (!is_dir($localRoot) && !mkdir($localRoot, 0700, true) && !is_dir($localRoot)) {
        candleImportRespond(['status' => 'error', 'message' => 'Не удалось подготовить очередь загрузки.'], 500);
    }
    @chmod($localRoot, 0700);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $jobId = (string) ($_GET['job_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) candleImportRespond(['status' => 'error', 'message' => 'Неизвестный запрос загрузки.'], 400);
        $statusPath = $localRoot . '/job-' . $jobId . '.json';
        $status = is_file($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
        if (!is_array($status)) candleImportRespond(['status' => 'error', 'message' => 'Состояние загрузки не найдено.'], 404);
        if (($status['status'] ?? '') === 'running') {
            $activePath = $localRoot . '/active-job.json';
            $active = is_file($activePath) ? json_decode((string) file_get_contents($activePath), true) : null;
            $pid = (int) (is_array($active) && ($active['job_id'] ?? '') === $jobId ? ($active['pid'] ?? 0) : 0);
            if (!isCandleImportProcess($pid)) {
                $status = ['status' => 'error', 'progress' => (int) ($status['progress'] ?? 0), 'message' => 'Загрузка неожиданно завершилась. Проверьте журнал задания.'];
                writeCandleImportJson($statusPath, $status);
            }
        }
        candleImportRespond($status);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: GET, POST');
        candleImportRespond(['status' => 'error', 'message' => 'Требуется POST-запрос.'], 405);
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($origin !== '' && $host !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== preg_replace('/:\d+$/', '', $host)) {
        candleImportRespond(['status' => 'error', 'message' => 'Запрос загрузки отклонён.'], 403);
    }

    $timezone = new DateTimeZone('Europe/Kyiv');
    $enabled = enabledTickerSymbols($config);
    $scope = (string) ($_POST['scope'] ?? 'symbol');
    $latestMode = $scope === 'latest';
    $startValue = (string) ($_POST['start_day'] ?? '');
    $endValue = (string) ($_POST['end_day'] ?? '');
    if ($latestMode) {
        $startValue = 'per-symbol latest';
        $endValue = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    } else {
        $startDay = parseCandleImportDate($startValue, $timezone);
        $endDay = parseCandleImportDate($endValue, $timezone);
        if ($startDay === null || $endDay === null || $endDay < $startDay) {
            candleImportRespond(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
        }
    }
    if ($scope === 'all' || $latestMode) {
        $symbols = $enabled;
    } elseif ($scope === 'symbol') {
        $symbol = strtoupper(trim((string) ($_POST['symbol'] ?? '')));
        if (!in_array($symbol, $enabled, true)) candleImportRespond(['status' => 'error', 'message' => 'Выбранная пара выключена или не настроена.'], 400);
        $symbols = [$symbol];
    } else {
        candleImportRespond(['status' => 'error', 'message' => 'Неизвестный режим загрузки.'], 400);
    }
    if ($symbols === []) candleImportRespond(['status' => 'error', 'message' => 'Нет активных пар для загрузки.'], 400);

    $lock = fopen($localRoot . '/queue.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        candleImportRespond(['status' => 'error', 'message' => 'Не удалось проверить очередь загрузки.'], 503);
    }
    $activePath = $localRoot . '/active-job.json';
    $active = is_file($activePath) ? json_decode((string) file_get_contents($activePath), true) : null;
    if (is_array($active) && ($active['status'] ?? '') === 'running' && isCandleImportProcess((int) ($active['pid'] ?? 0))) {
        flock($lock, LOCK_UN);
        fclose($lock);
        candleImportRespond([
            'status' => 'error', 'message' => 'Другая загрузка минутных свечей уже выполняется. Дождитесь её завершения.',
            'active_job_id' => $active['job_id'] ?? null,
        ], 409);
    }

    $jobId = bin2hex(random_bytes(16));
    $statusPath = $localRoot . '/job-' . $jobId . '.json';
    $status = [
        'job_id' => $jobId, 'status' => 'running', 'progress' => 0,
        'start_day' => $startValue, 'end_day' => $endValue,
        'symbol_count' => count($symbols), 'completed_symbols' => 0,
        'current_symbol' => $symbols[0], 'candles_received' => 0,
        'candles_inserted' => 0, 'snapshots_inserted' => 0,
        'message' => 'Задание запускается…',
    ];
    writeCandleImportJson($statusPath, $status);
    writeCandleImportJson($activePath, ['job_id' => $jobId, 'status' => 'running', 'pid' => 0]);

    $root = dirname(__DIR__);
    $python = $root . '/.venv/bin/python';
    if (!is_executable($python)) $python = '/usr/bin/python3';
    $command = 'nohup ' . escapeshellarg($python) . ' ' . escapeshellarg($root . '/launch-ranking-worker.py')
        . ' ' . escapeshellarg($python) . ' ' . escapeshellarg($root . '/import_historical_candles.py')
        . ' --config ' . escapeshellarg($root . '/config.json')
        . ($scope === 'all' || $latestMode ? ' --all-enabled' : ' --symbol ' . escapeshellarg($symbols[0]))
        . ($latestMode ? ' --from-latest' : ' --start-date ' . escapeshellarg($startValue))
        . ' --end-date ' . escapeshellarg($endValue)
        . ' --status-file ' . escapeshellarg($statusPath)
        . ' >> ' . escapeshellarg($localRoot . '/job-' . $jobId . '.log') . ' 2>&1 < /dev/null & echo $!';
    exec($command, $output, $exitCode);
    $pid = (int) trim((string) ($output[0] ?? '0'));
    if ($exitCode !== 0 || $pid < 2) {
        $failed = ['job_id' => $jobId, 'status' => 'error', 'progress' => 100, 'message' => 'Не удалось запустить фоновую загрузку.'];
        writeCandleImportJson($statusPath, $failed);
        writeCandleImportJson($activePath, ['job_id' => $jobId, 'status' => 'error', 'pid' => 0]);
        flock($lock, LOCK_UN);
        fclose($lock);
        candleImportRespond($failed, 500);
    }
    writeCandleImportJson($activePath, ['job_id' => $jobId, 'status' => 'running', 'pid' => $pid]);
    flock($lock, LOCK_UN);
    fclose($lock);
    candleImportRespond($status + ['job_id' => $jobId]);
} catch (Throwable) {
    candleImportRespond(['status' => 'error', 'message' => 'Не удалось запустить загрузку истории из Bitget.'], 503);
}
