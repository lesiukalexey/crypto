<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rankingWorkerPids(string $projectRoot): array
{
    $workerScripts = array_fill_keys([
        $projectRoot . '/strategy_ranking.py',
        $projectRoot . '/symbol_ranking.py',
        $projectRoot . '/portfolio_ranking.py',
    ], true);
    $pids = [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlinePath) {
        $raw = @file_get_contents($cmdlinePath);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $arguments = explode("\0", rtrim($raw, "\0"));
        if (array_intersect_key(array_fill_keys($arguments, true), $workerScripts) === []) {
            continue;
        }
        $pid = (int) basename(dirname($cmdlinePath));
        if ($pid > 1 && $pid !== getmypid()) {
            $pids[$pid] = true;
        }
    }
    return array_keys($pids);
}

function cancelPendingRankingJobs(string $cacheDir): int
{
    $cancelled = 0;
    foreach (glob($cacheDir . '/*.status.json') ?: [] as $statusPath) {
        $raw = @file_get_contents($statusPath);
        $status = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($status) || ($status['status'] ?? '') !== 'pending') {
            continue;
        }
        $status['status'] = 'cancelled';
        $status['message'] = 'Расчёт остановлен кнопкой сброса кэша.';
        $temporaryPath = tempnam($cacheDir, '.ranking-cancel-');
        if ($temporaryPath === false) {
            continue;
        }
        $encoded = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false && file_put_contents($temporaryPath, $encoded, LOCK_EX) !== false && rename($temporaryPath, $statusPath)) {
            $cancelled++;
        } else {
            @unlink($temporaryPath);
        }
    }
    return $cancelled;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(['status' => 'error', 'message' => 'Для сброса кэша требуется POST-запрос.'], 405);
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($origin !== '' && $host !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== preg_replace('/:\d+$/', '', $host)) {
    respond(['status' => 'error', 'message' => 'Запрос на сброс кэша отклонен.'], 403);
}

try {
    $home = getenv('HOME') ?: '/home/alex';
    $cacheDir = $home . '/.ai/home/.local/bitget-backtest-cache';
    $projectRoot = dirname(__DIR__);
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
        respond(['status' => 'error', 'message' => 'Не удалось подготовить кэш рейтинга.'], 500);
    }

    $generationPath = $cacheDir . '/strategy-ranking-generation.txt';
    $temporaryPath = tempnam($cacheDir, '.strategy-ranking-generation-');
    if ($temporaryPath === false) {
        respond(['status' => 'error', 'message' => 'Не удалось сбросить кэш рейтингов.'], 500);
    }
    $generation = bin2hex(random_bytes(16));
    if (file_put_contents($temporaryPath, $generation, LOCK_EX) === false || !rename($temporaryPath, $generationPath)) {
        @unlink($temporaryPath);
        respond(['status' => 'error', 'message' => 'Не удалось сбросить кэш рейтингов.'], 500);
    }

    $workerPids = rankingWorkerPids($projectRoot);
    $targetWorkerPids = $workerPids;
    foreach ($workerPids as $pid) {
        @posix_kill($pid, SIGTERM);
    }
    $deadline = microtime(true) + 2.0;
    do {
        usleep(100000);
        $alive = array_fill_keys(rankingWorkerPids($projectRoot), true);
        $workerPids = array_values(array_filter($workerPids, static fn (int $pid): bool => isset($alive[$pid])));
    } while ($workerPids !== [] && microtime(true) < $deadline);
    foreach ($workerPids as $pid) {
        @posix_kill($pid, SIGKILL);
    }
    if ($workerPids !== []) {
        usleep(100000);
    }
    $cancelledJobs = cancelPendingRankingJobs($cacheDir);

    $aliveWorkerPids = rankingWorkerPids($projectRoot);
    $stoppedWorkerCount = count(array_diff($targetWorkerPids, $aliveWorkerPids));
    respond([
        'status' => 'ready',
        'stopped_processes' => $stoppedWorkerCount,
        'cancelled_jobs' => $cancelledJobs,
    ]);
} catch (Throwable $error) {
    respond(['status' => 'error', 'message' => 'Не удалось сбросить кэш рейтингов.'], 500);
}
