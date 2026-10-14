<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ticker-settings-lib.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $key = (string) ($_GET['cache_key'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) respond(['status' => 'error', 'message' => 'Некорректный идентификатор общего рейтинга.'], 400);
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $set = selectedTickerSet($config, trim((string) ($_GET['ticker_set'] ?? '')));
    if ($set['symbols'] === []) respond(['status' => 'missing']);

    $cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    $resultPath = $cacheDir . '/portfolio-' . $key . '.json';
    $statusPath = $cacheDir . '/portfolio-' . $key . '.status.json';
    $metadataPath = $cacheDir . '/portfolio-' . $key . '.meta.json';
    if (!is_file($resultPath)) respond(['status' => 'missing']);
    $result = json_decode((string) file_get_contents($resultPath), true);
    $status = is_file($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
    $metadata = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : null;
    if (!is_array($result) || ($result['status'] ?? '') !== 'ready'
        || ($result['ranking_data_source'] ?? '') !== 'strategy_return_points'
        || !is_array($metadata)
        || (is_array($status) && ($status['status'] ?? '') !== 'ready')) {
        respond(['status' => 'missing']);
    }
    $symbols = is_array($metadata['ticker_set']['symbols'] ?? null)
        ? array_values(array_map('strval', $metadata['ticker_set']['symbols']))
        : array_values(array_map(static fn (array $row): string => (string) ($row['symbol'] ?? ''), is_array($result['ticker_leaders'] ?? null) ? $result['ticker_leaders'] : []));
    $symbols = array_values(array_filter($symbols, static fn (string $symbol): bool => $symbol !== ''));
    sort($symbols, SORT_STRING);
    if ($symbols !== $set['symbols']) respond(['status' => 'missing']);
    if (is_array($metadata)
        && ($metadata['ticker_set']['symbols'] ?? []) !== $set['symbols']) {
        respond(['status' => 'missing']);
    }

    respond([
        ...$result,
        'status' => 'ready',
        'cache_key' => $key,
        'progress' => 100,
        'start_day' => (string) ($metadata['start_day'] ?? (is_array($status) ? ($status['start_day'] ?? '') : '')),
        'end_day' => (string) ($metadata['end_day'] ?? (is_array($status) ? ($status['end_day'] ?? '') : '')),
        'universe_size' => (int) ($result['universe_size'] ?? count($symbols)),
        'min_trades' => (int) ($result['min_trades'] ?? 3),
        'history_metadata' => $metadata,
        'result_cached_at' => gmdate(DATE_ATOM, (int) filemtime($resultPath)),
    ]);
} catch (Throwable $error) {
    respond(['status' => 'error', 'message' => 'Не удалось загрузить сохранённый общий рейтинг.'], 503);
}
