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
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $set = selectedTickerSet($config, trim((string) ($_GET['ticker_set'] ?? '')));
    if ($set['symbols'] === []) respond(['status' => 'ready', 'ticker_set' => $set, 'rankings' => []]);

    $cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    $rankings = [];
    foreach (glob($cacheDir . '/portfolio-*.json') ?: [] as $resultPath) {
        $base = basename($resultPath);
        if (preg_match('/^portfolio-([a-f0-9]{64})\.json$/', $base, $matches) !== 1) continue;
        $key = $matches[1];
        $statusPath = $cacheDir . '/portfolio-' . $key . '.status.json';
        $status = is_file($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
        $result = json_decode((string) file_get_contents($resultPath), true);
        if (!is_array($result) || ($result['status'] ?? '') !== 'ready') continue;

        $metadataPath = $cacheDir . '/portfolio-' . $key . '.meta.json';
        $metadata = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : null;
        $symbols = is_array($metadata['ticker_set']['symbols'] ?? null)
            ? array_values(array_map('strval', $metadata['ticker_set']['symbols']))
            : array_values(array_map(static fn (array $row): string => (string) ($row['symbol'] ?? ''), is_array($result['ticker_leaders'] ?? null) ? $result['ticker_leaders'] : []));
        $symbols = array_values(array_filter($symbols, static fn (string $symbol): bool => $symbol !== ''));
        sort($symbols, SORT_STRING);
        if ($symbols !== $set['symbols']) continue;
        if (is_array($metadata)) {
            if (($metadata['ticker_set']['symbols'] ?? []) !== $set['symbols']) continue;
        }

        $rankings[] = [
            'cache_key' => $key,
            'start_day' => (string) ($metadata['start_day'] ?? (is_array($status) ? ($status['start_day'] ?? '') : '')),
            'end_day' => (string) ($metadata['end_day'] ?? (is_array($status) ? ($status['end_day'] ?? '') : '')),
            'cached_at' => gmdate(DATE_ATOM, (int) filemtime($resultPath)),
            'universe_size' => (int) ($result['universe_size'] ?? (is_array($status) ? ($status['universe_size'] ?? count($symbols)) : count($symbols))),
            'ticker_set' => is_array($metadata['ticker_set'] ?? null)
                ? $metadata['ticker_set']
                : ['id' => $set['id'], 'name' => $set['name'], 'symbols' => $symbols],
            'parameters' => is_array($metadata['parameters'] ?? null) ? $metadata['parameters'] : null,
            'request_params' => is_array($metadata['request_params'] ?? null) ? $metadata['request_params'] : null,
            'legacy' => !is_array($metadata),
        ];
    }
    usort($rankings, static fn (array $left, array $right): int => [
        $right['end_day'], $right['start_day'], $right['cached_at'],
    ] <=> [
        $left['end_day'], $left['start_day'], $left['cached_at'],
    ]);
    respond(['status' => 'ready', 'ticker_set' => ['id' => $set['id'], 'name' => $set['name'], 'symbols' => $set['symbols']], 'rankings' => $rankings]);
} catch (Throwable $error) {
    respond(['status' => 'error', 'message' => 'Не удалось загрузить сохранённые общие рейтинги.'], 503);
}
