<?php
declare(strict_types=1);

require_once __DIR__ . '/market-cap-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__);
$config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
$symbols = configuredTickerSymbols($config);
$cache = marketCapCacheRead();
$symbolsHash = hash('sha256', implode("\n", $symbols));
$retryAfter = strtotime((string) ($cache['retry_after'] ?? '')) ?: 0;
$symbolsChanged = ($cache['symbols_hash'] ?? null) !== $symbolsHash;
$needsRefresh = $symbolsChanged || $retryAfter <= time();
if ($needsRefresh) marketCapStartRefresh($config);

$refreshPath = marketCapCachePath() . '.refreshing';
$refreshing = is_file($refreshPath) && time() - (int) filemtime($refreshPath) < 1800;
$items = marketCapRankings($symbols, $cache);
$rankedCount = count(array_filter($items, static fn (array $item): bool => is_int($item['rank'])));

echo json_encode([
    'status' => $rankedCount > 0 ? 'ready' : ($refreshing ? 'pending' : 'unavailable'),
    'refreshing' => $refreshing,
    'updated_at' => $cache['refreshed_at'] ?? null,
    'configured_count' => count($symbols),
    'ranked_count' => $rankedCount,
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
