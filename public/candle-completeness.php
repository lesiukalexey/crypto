<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ticker-settings-lib.php';

function candleCompletenessRespond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function candleCompletenessDate(string $value, DateTimeZone $timezone): ?DateTimeImmutable
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
    $timezone = new DateTimeZone('Europe/Kyiv');
    $startValue = (string) ($_GET['start_day'] ?? '');
    $endValue = (string) ($_GET['end_day'] ?? '');
    $startDay = candleCompletenessDate($startValue, $timezone);
    $endDay = candleCompletenessDate($endValue, $timezone);
    if ($startDay === null || $endDay === null || $endDay < $startDay) {
        candleCompletenessRespond(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }

    $tickerSetId = trim((string) ($_GET['ticker_set'] ?? ''));
    if ($tickerSetId === '') {
        candleCompletenessRespond(['status' => 'error', 'message' => 'Выбранный список тикеров не передан.'], 400);
    }
    $tickerSet = selectedTickerSet($config, $tickerSetId);
    if ($tickerSet['id'] !== $tickerSetId) {
        candleCompletenessRespond(['status' => 'error', 'message' => 'Выбранный список тикеров не найден.'], 400);
    }
    $enabled = $tickerSet['symbols'];
    $selected = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    if ($selected !== '' && !in_array($selected, $enabled, true)) {
        candleCompletenessRespond(['status' => 'error', 'message' => 'Пара не входит в выбранный активный список тикеров.'], 400);
    }
    if ($enabled === []) {
        candleCompletenessRespond(['status' => 'ready', 'symbol_incomplete' => false, 'all_incomplete' => false, 'checked_days' => 0]);
    }

    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') throw new RuntimeException('MySQL password is not configured.');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    if ((string) ($_GET['coverage_only'] ?? '0') === '1') {
        $excludeEndDay = (string) ($_GET['exclude_end_day'] ?? '0') === '1';
        $rangeStartUtc = $startDay->setTimezone(new DateTimeZone('UTC'));
        $rangeEndKyiv = $excludeEndDay ? $endDay : $endDay->modify('+1 day');
        $rangeEndUtc = $rangeEndKyiv->setTimezone(new DateTimeZone('UTC'));
        $symbolsSql = implode(',', array_fill(0, count($enabled), '?'));
        $coverageStatement = $pdo->prepare(
            "SELECT symbol, MIN(received_at_utc) AS first_quote, MAX(received_at_utc) AS last_quote
             FROM quote_snapshots
             WHERE category = ? AND symbol IN ($symbolsSql)
               AND received_at_utc >= ? AND received_at_utc < ?
             GROUP BY symbol"
        );
        $coverageStatement->execute([
            $config['category'], ...$enabled,
            $rangeStartUtc->format('Y-m-d H:i:s'), $rangeEndUtc->format('Y-m-d H:i:s'),
        ]);
        $coverage = [];
        foreach ($coverageStatement->fetchAll() as $row) $coverage[strtoupper((string) $row['symbol'])] = $row;
        $coverageStartMs = $rangeStartUtc->getTimestamp() * 1000;
        $coverageEndMs = $rangeEndUtc->getTimestamp() * 1000;
        $candleCoverageStatement = $pdo->prepare(
            'SELECT symbol, start_timestamp_ms, end_exclusive_timestamp_ms '
            . 'FROM historical_candle_coverage '
            . 'WHERE category = ? AND interval_name = ? AND symbol IN (' . $symbolsSql . ') '
            . 'AND start_timestamp_ms < ? AND end_exclusive_timestamp_ms > ? '
            . 'ORDER BY symbol, start_timestamp_ms'
        );
        $candleCoverageStatement->execute([
            $config['category'], '1m', ...$enabled, $coverageEndMs, $coverageStartMs,
        ]);
        $candleCoverageBySymbol = [];
        foreach ($candleCoverageStatement->fetchAll() as $row) {
            $candleCoverageBySymbol[strtoupper((string) $row['symbol'])][] = [
                (int) $row['start_timestamp_ms'], (int) $row['end_exclusive_timestamp_ms'],
            ];
        }
        $historicalCandleBoundsStatement = $pdo->prepare(
            'SELECT symbol, MIN(candle_timestamp_ms) AS first_candle_ms, MAX(candle_timestamp_ms) + 60000 AS last_candle_end_ms '
            . 'FROM historical_candles '
            . 'WHERE category = ? AND interval_name = ? AND symbol IN (' . $symbolsSql . ') '
            . 'AND candle_timestamp_ms >= ? AND candle_timestamp_ms < ? GROUP BY symbol'
        );
        $historicalCandleBoundsStatement->execute([
            $config['category'], '1m', ...$enabled, $coverageStartMs, $coverageEndMs,
        ]);
        foreach ($historicalCandleBoundsStatement->fetchAll() as $row) {
            $candleCoverageBySymbol[strtoupper((string) $row['symbol'])][] = [
                (int) $row['first_candle_ms'], (int) $row['last_candle_end_ms'],
            ];
        }
        foreach ($candleCoverageBySymbol as &$ranges) {
            usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        }
        unset($ranges);
        $rangeSeconds = max(0, $rangeEndUtc->getTimestamp() - $rangeStartUtc->getTimestamp());
        $toleranceSeconds = min(3 * 86400, intdiv($rangeSeconds, 2));
        $latestAllowedFirst = $rangeStartUtc->getTimestamp() + $toleranceSeconds;
        $earliestAllowedLast = $rangeEndUtc->getTimestamp() - $toleranceSeconds;
        $details = [];
        foreach ($enabled as $symbol) {
            $row = $coverage[$symbol] ?? null;
            $first = is_array($row) ? new DateTimeImmutable((string) $row['first_quote'], new DateTimeZone('UTC')) : null;
            $last = is_array($row) ? new DateTimeImmutable((string) $row['last_quote'], new DateTimeZone('UTC')) : null;
            $coveredUntilMs = $coverageStartMs;
            foreach ($candleCoverageBySymbol[$symbol] ?? [] as [$coveredStartMs, $coveredEndMs]) {
                $coveredStartMs = max($coverageStartMs, $coveredStartMs);
                $coveredEndMs = min($coverageEndMs, $coveredEndMs);
                if ($coveredEndMs <= $coveredUntilMs) continue;
                if ($coveredStartMs > $coveredUntilMs) break;
                $coveredUntilMs = $coveredEndMs;
                if ($coveredUntilMs >= $coverageEndMs) break;
            }
            $historicalCoverageComplete = $coveredUntilMs >= $coverageEndMs;
            if ($historicalCoverageComplete || ($first !== null && $last !== null
                && $first->getTimestamp() <= $latestAllowedFirst
                && $last->getTimestamp() >= $earliestAllowedLast)) continue;
            $details[] = [
                'symbol' => $symbol,
                'first_quote' => $first?->setTimezone($timezone)->format('Y-m-d H:i:s'),
                'last_quote' => $last?->setTimezone($timezone)->format('Y-m-d H:i:s'),
                'historical_coverage_until_ms' => $coveredUntilMs,
            ];
        }
        candleCompletenessRespond([
            'status' => 'ready',
            'symbol_incomplete' => false,
            'all_incomplete' => $details !== [],
            'all_incomplete_details' => $details,
            'coverage_start_day' => $startDay->format('Y-m-d'),
            'coverage_end_day' => $rangeEndKyiv->modify('-1 day')->format('Y-m-d'),
            'coverage_tolerance_days' => 3,
            'checked_days' => 0,
        ]);
    }

    $nowKyiv = new DateTimeImmutable('now', $timezone);
    $today = $nowKyiv->setTime(0, 0);
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $completeUntilUtc = $nowUtc->setTime((int) $nowUtc->format('H'), (int) $nowUtc->format('i'), 0);
    $prepareCounts = static function (array $symbols) use ($pdo): PDOStatement {
        $symbolsSql = implode(',', array_fill(0, count($symbols), '?'));
        return $pdo->prepare(
            "SELECT symbol, COUNT(DISTINCT DATE_FORMAT(received_at_utc, '%Y-%m-%d %H:%i')) AS minute_count
             FROM quote_snapshots
             WHERE category = ? AND symbol IN ($symbolsSql)
               AND received_at_utc >= ? AND received_at_utc < ?
             GROUP BY symbol"
        );
    };
    $allSymbolsStatement = $prepareCounts($enabled);
    $selectedSymbolStatement = $selected !== '' ? $prepareCounts([$selected]) : null;

    $checkedDays = 0;
    $excludeEndDay = (string) ($_GET['exclude_end_day'] ?? '0') === '1';
    $requestedLastDay = $excludeEndDay ? $endDay->modify('-1 day') : $endDay;
    $lastDay = $requestedLastDay < $today ? $requestedLastDay : $today;
    $dayRanges = [];
    for ($day = $lastDay; $day >= $startDay; $day = $day->modify('-1 day')) {
        $dayEnd = $day->modify('+1 day');
        $dayStartUtc = $day->setTimezone(new DateTimeZone('UTC'));
        $dayEndUtc = $dayEnd->setTimezone(new DateTimeZone('UTC'));
        $expectedUntilUtc = $dayEndUtc < $completeUntilUtc ? $dayEndUtc : $completeUntilUtc;
        $expectedMinutes = intdiv(max(0, $expectedUntilUtc->getTimestamp() - $dayStartUtc->getTimestamp()), 60);
        if ($expectedMinutes > 0) $dayRanges[] = [$day, $dayStartUtc, $expectedUntilUtc, $expectedMinutes];
    }

    // A single missing day is enough to show an import button. Check newest days first
    // so interrupted history is detected promptly instead of scanning the full range.
    $symbolIncomplete = false;
    if ($selected !== '' && $selectedSymbolStatement !== null) {
        foreach ($dayRanges as [, $dayStartUtc, $expectedUntilUtc, $expectedMinutes]) {
            $selectedSymbolStatement->execute([
                $config['category'], $selected,
                $dayStartUtc->format('Y-m-d H:i:s'), $expectedUntilUtc->format('Y-m-d H:i:s'),
            ]);
            $row = $selectedSymbolStatement->fetch();
            $checkedDays++;
            if ((int) ($row['minute_count'] ?? 0) < $expectedMinutes) {
                $symbolIncomplete = true;
                break;
            }
        }
    }

    $allIncomplete = false;
    $allIncompleteDay = null;
    $allIncompleteDetails = [];
    foreach ($dayRanges as [, $dayStartUtc, $expectedUntilUtc, $expectedMinutes]) {
        $allSymbolsStatement->execute([
            $config['category'], ...$enabled,
            $dayStartUtc->format('Y-m-d H:i:s'), $expectedUntilUtc->format('Y-m-d H:i:s'),
        ]);
        $counts = [];
        foreach ($allSymbolsStatement->fetchAll() as $row) $counts[strtoupper((string) $row['symbol'])] = (int) $row['minute_count'];
        $checkedDays++;
        foreach ($enabled as $symbol) {
            $actualMinutes = $counts[$symbol] ?? 0;
            if ($actualMinutes >= $expectedMinutes) continue;
            $allIncomplete = true;
            $allIncompleteDay ??= $dayStartUtc->setTimezone($timezone)->format('Y-m-d');
            $allIncompleteDetails[] = [
                'symbol' => $symbol,
                'actual_minutes' => $actualMinutes,
                'expected_minutes' => $expectedMinutes,
                'missing_minutes' => $expectedMinutes - $actualMinutes,
            ];
        }
        if ($allIncomplete) break;
    }

    candleCompletenessRespond([
        'status' => 'ready',
        'symbol_incomplete' => $selected !== '' && $symbolIncomplete,
        'all_incomplete' => $allIncomplete,
        'all_incomplete_day' => $allIncompleteDay,
        'all_incomplete_details' => $allIncompleteDetails,
        'checked_days' => $checkedDays,
    ]);
} catch (Throwable) {
    candleCompletenessRespond(['status' => 'error', 'message' => 'Не удалось проверить полноту минутных котировок.'], 503);
}
