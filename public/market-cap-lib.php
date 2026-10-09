<?php
declare(strict_types=1);

require_once __DIR__ . '/ticker-settings-lib.php';

const MARKET_CAP_CACHE_TTL = 86400;

function marketCapCachePath(): string
{
    return (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-market-cap-cache.json';
}

function marketCapCacheRead(): array
{
    $path = marketCapCachePath();
    if (!is_file($path)) return [];
    $cache = json_decode((string) file_get_contents($path), true);
    return is_array($cache) ? $cache : [];
}

function marketCapCacheWrite(array $cache): void
{
    $path = marketCapCachePath();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось подготовить кэш капитализации.');
    }
    $temporaryPath = tempnam($directory, '.market-cap-');
    $payload = json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($temporaryPath === false || file_put_contents($temporaryPath, $payload, LOCK_EX) === false || !rename($temporaryPath, $path)) {
        if (is_string($temporaryPath)) @unlink($temporaryPath);
        throw new RuntimeException('Не удалось сохранить кэш капитализации.');
    }
    @chmod($path, 0600);
}

function marketCapHttpJson(string $url): ?array
{
    $handle = curl_init($url);
    if ($handle === false) return null;
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'BitgetTickerMarketCap/1.0',
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($response) || $status < 200 || $status >= 300) return null;
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function marketCapCryptoIds(): array
{
    return [
        'BTCUSDT' => 'bitcoin',
        'ETHUSDT' => 'ethereum',
        'SOLUSDT' => 'solana',
        'XRPUSDT' => 'ripple',
        'DOGEUSDT' => 'dogecoin',
    ];
}

function marketCapNotApplicableReason(string $symbol): ?string
{
    return match ($symbol) {
        'XAUUSDT', 'XAGUSDT' => 'для металла капитализация компании не применяется',
        default => null,
    };
}

function refreshMarketCapCache(array $config): void
{
    $symbols = configuredTickerSymbols($config);
    $previous = marketCapCacheRead();
    $marketCaps = is_array($previous['market_caps'] ?? null) ? $previous['market_caps'] : [];
    $symbolSet = array_fill_keys($symbols, true);
    $marketCaps = array_intersect_key($marketCaps, $symbolSet);
    $cryptoIds = marketCapCryptoIds();
    $requestedCryptoIds = [];
    foreach ($symbols as $symbol) {
        if (isset($cryptoIds[$symbol])) $requestedCryptoIds[$symbol] = $cryptoIds[$symbol];
    }

    $unavailable = [];
    if ($requestedCryptoIds !== []) {
        $url = 'https://api.coingecko.com/api/v3/coins/markets?' . http_build_query([
            'vs_currency' => 'usd',
            'ids' => implode(',', array_values($requestedCryptoIds)),
            'order' => 'market_cap_desc',
            'per_page' => 100,
            'page' => 1,
            'sparkline' => 'false',
        ]);
        $coins = marketCapHttpJson($url);
        $updatedCryptoSymbols = [];
        if (is_array($coins)) {
            foreach ($coins as $coin) {
                if (!is_array($coin)) continue;
                $id = (string) ($coin['id'] ?? '');
                $symbol = array_search($id, $requestedCryptoIds, true);
                $cap = $coin['market_cap'] ?? null;
                if (!is_string($symbol) || !is_numeric($cap) || (float) $cap <= 0) continue;
                $marketCaps[$symbol] = [
                    'market_cap_usd' => (string) $cap,
                    'name' => (string) ($coin['name'] ?? $symbol),
                    'source' => 'CoinGecko',
                    'updated_at' => (string) ($coin['last_updated'] ?? gmdate(DATE_ATOM)),
                    'unranked_reason' => null,
                ];
                $updatedCryptoSymbols[$symbol] = true;
            }
        }
        foreach ($requestedCryptoIds as $symbol => $_) {
            if (!isset($updatedCryptoSymbols[$symbol])) $unavailable[$symbol] = true;
        }
    }

    $companyCodes = [];
    foreach ($symbols as $symbol) {
        if (isset($requestedCryptoIds[$symbol])) continue;
        $reason = marketCapNotApplicableReason($symbol);
        if ($reason !== null) {
            $marketCaps[$symbol] = ['market_cap_usd' => null, 'name' => null, 'source' => null, 'updated_at' => gmdate(DATE_ATOM), 'unranked_reason' => $reason];
            continue;
        }
        $code = preg_replace('/USDT$/', '', $symbol) ?? $symbol;
        if ($code !== '') $companyCodes[$symbol] = $code;
    }

    $companyRequestCount = 0;
    foreach ($companyCodes as $symbol => $code) {
        if ($companyRequestCount++ > 0) usleep(1_000_000);
        $url = 'https://api.bitget.com/api/v3/reality/market/company-overview?' . http_build_query(['code' => $code]);
        $response = marketCapHttpJson($url);
        $data = is_array($response) ? ($response['data'] ?? null) : null;
        if (!is_array($response) || ($response['code'] ?? null) !== '00000' || !is_array($data)) {
            $unavailable[$symbol] = true;
            continue;
        }
        $cap = $data['marketCap'] ?? null;
        $name = trim((string) ($data['name'] ?? ''));
        if (!is_numeric($cap) || (float) $cap <= 0) {
            $marketCaps[$symbol] = [
                'market_cap_usd' => null,
                'name' => $name !== '' ? $name : $code,
                'source' => 'Bitget',
                'updated_at' => gmdate(DATE_ATOM),
                'unranked_reason' => 'источник не публикует капитализацию для этого инструмента',
            ];
            continue;
        }
        $marketCaps[$symbol] = [
            'market_cap_usd' => (string) $cap,
            'name' => $name !== '' ? $name : $code,
            'source' => 'Bitget',
            'updated_at' => gmdate(DATE_ATOM),
            'unranked_reason' => null,
        ];
    }

    foreach ($unavailable as $symbol => $_) {
        if (isset($marketCaps[$symbol]['market_cap_usd']) && is_numeric($marketCaps[$symbol]['market_cap_usd'])) continue;
        $marketCaps[$symbol] = [
            'market_cap_usd' => null,
            'name' => null,
            'source' => null,
            'updated_at' => gmdate(DATE_ATOM),
            'unranked_reason' => 'нет данных от источника',
        ];
    }

    marketCapCacheWrite([
        'symbols_hash' => hash('sha256', implode("\n", $symbols)),
        'refreshed_at' => gmdate(DATE_ATOM),
        'retry_after' => gmdate(DATE_ATOM, time() + ($unavailable === [] ? MARKET_CAP_CACHE_TTL : 1800)),
        'market_caps' => $marketCaps,
    ]);
}

function marketCapStartRefresh(array $config): bool
{
    $cachePath = marketCapCachePath();
    $directory = dirname($cachePath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) return false;
    $refreshPath = $cachePath . '.refreshing';
    if (is_file($refreshPath)) {
        if (time() - (int) filemtime($refreshPath) < 1800) return true;
        @unlink($refreshPath);
    }
    $handle = @fopen($refreshPath, 'x');
    if ($handle === false) return is_file($refreshPath);
    fwrite($handle, (string) getmypid());
    fclose($handle);
    if (!function_exists('exec')) {
        @unlink($refreshPath);
        return false;
    }
    $worker = __DIR__ . '/market-cap-worker.php';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) @unlink($refreshPath);
    return $exitCode === 0;
}

function marketCapRankings(array $symbols, array $cache): array
{
    $marketCaps = is_array($cache['market_caps'] ?? null) ? $cache['market_caps'] : [];
    $rankedSymbols = [];
    foreach ($symbols as $symbol) {
        $cap = $marketCaps[$symbol]['market_cap_usd'] ?? null;
        if (is_numeric($cap) && (float) $cap > 0) $rankedSymbols[] = $symbol;
    }
    usort($rankedSymbols, static function (string $left, string $right) use ($marketCaps): int {
        $comparison = (float) $marketCaps[$right]['market_cap_usd'] <=> (float) $marketCaps[$left]['market_cap_usd'];
        return $comparison !== 0 ? $comparison : strcmp($left, $right);
    });
    $rankBySymbol = [];
    foreach ($rankedSymbols as $index => $symbol) $rankBySymbol[$symbol] = $index + 1;

    $items = [];
    foreach ($symbols as $symbol) {
        $item = $marketCaps[$symbol] ?? [];
        $items[$symbol] = [
            'rank' => $rankBySymbol[$symbol] ?? null,
            'universe_size' => count($rankedSymbols),
            'market_cap_usd' => $item['market_cap_usd'] ?? null,
            'name' => $item['name'] ?? null,
            'source' => $item['source'] ?? null,
            'updated_at' => $item['updated_at'] ?? null,
            'unranked_reason' => $item['unranked_reason'] ?? 'данные ещё не загружены',
        ];
    }
    return $items;
}
