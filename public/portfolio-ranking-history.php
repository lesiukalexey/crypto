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

function canonicalizeRankingHistoryValue(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    foreach ($value as $key => $item) $value[$key] = canonicalizeRankingHistoryValue($item);
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    return $value;
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
        if (!is_array($result) || ($result['status'] ?? '') !== 'ready'
            || ($result['ranking_data_source'] ?? '') !== 'strategy_return_points') continue;

        $metadataPath = $cacheDir . '/portfolio-' . $key . '.meta.json';
        $metadata = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : null;
        if (!is_array($metadata) || !is_array($metadata['ticker_set']['symbols'] ?? null)) continue;
        $symbols = array_values(array_map('strval', $metadata['ticker_set']['symbols']));
        $symbols = array_values(array_filter($symbols, static fn (string $symbol): bool => $symbol !== ''));
        sort($symbols, SORT_STRING);
        if ($symbols !== $set['symbols']) continue;
        if (($metadata['ticker_set']['symbols'] ?? []) !== $set['symbols']) continue;

        $cachedAt = gmdate(DATE_ATOM, (int) filemtime($resultPath));
        $rankingRow = [
            'cache_key' => $key,
            'start_day' => (string) ($metadata['start_day'] ?? (is_array($status) ? ($status['start_day'] ?? '') : '')),
            'end_day' => (string) ($metadata['end_day'] ?? (is_array($status) ? ($status['end_day'] ?? '') : '')),
            'cached_at' => $cachedAt,
            'universe_size' => (int) ($result['universe_size'] ?? (is_array($status) ? ($status['universe_size'] ?? count($symbols)) : count($symbols))),
            'ticker_set' => $metadata['ticker_set'],
            'parameters' => is_array($metadata['parameters'] ?? null) ? $metadata['parameters'] : null,
            'request_params' => is_array($metadata['request_params'] ?? null) ? $metadata['request_params'] : null,
        ];
        if (is_array($rankingRow['parameters']) && is_array($rankingRow['request_params'])) {
            // The selected entry and exit selectors do not change the saved
            // overall ranking. Hide old cache copies that differ only by the
            // selected entry, but only when the completed results also match.
            unset($rankingRow['parameters']['entry_config'], $rankingRow['request_params']['entry_config']);
            $canonical = canonicalizeRankingHistoryValue([
                'ticker_set' => $rankingRow['ticker_set'],
                'start_day' => $rankingRow['start_day'],
                'end_day' => $rankingRow['end_day'],
                'parameters' => $rankingRow['parameters'],
                'request_params' => $rankingRow['request_params'],
                'result' => canonicalizeRankingHistoryValue($result),
            ]);
            $rankingRow['_dedupe_key'] = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $rankings[] = $rankingRow;
    }
    $deduplicated = [];
    $dedupeIndexes = [];
    foreach ($rankings as $ranking) {
        $dedupeKey = $ranking['_dedupe_key'] ?? null;
        unset($ranking['_dedupe_key']);
        if (!is_string($dedupeKey) || !isset($dedupeIndexes[$dedupeKey])) {
            if (is_string($dedupeKey)) $ranking['duplicate_count'] = 1;
            if (is_string($dedupeKey)) $dedupeIndexes[$dedupeKey] = count($deduplicated);
            $deduplicated[] = $ranking;
            continue;
        }
        $index = $dedupeIndexes[$dedupeKey];
        $deduplicated[$index]['duplicate_count']++;
        if ($ranking['cached_at'] > $deduplicated[$index]['cached_at']) {
            $duplicateCount = $deduplicated[$index]['duplicate_count'];
            $deduplicated[$index] = $ranking;
            $deduplicated[$index]['duplicate_count'] = $duplicateCount;
        }
    }
    $rankings = $deduplicated;
    usort($rankings, static fn (array $left, array $right): int => [
        $right['end_day'], $right['start_day'], $right['cached_at'],
    ] <=> [
        $left['end_day'], $left['start_day'], $left['cached_at'],
    ]);
    respond(['status' => 'ready', 'ticker_set' => ['id' => $set['id'], 'name' => $set['name'], 'symbols' => $set['symbols']], 'rankings' => $rankings]);
} catch (Throwable $error) {
    respond(['status' => 'error', 'message' => 'Не удалось загрузить сохранённые общие рейтинги.'], 503);
}
