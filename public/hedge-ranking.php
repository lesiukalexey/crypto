<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate');
require_once dirname(__DIR__) . '/hedge_simulation.php';
require_once __DIR__ . '/ticker-settings-lib.php';

function hedge_ranking_respond(array $payload, int $status = 200): never
{
    if ($GLOBALS['hedge_ranking_stream_started'] ?? false) {
        echo json_encode(['type' => 'error', 'message' => $payload['message'] ?? 'Не удалось рассчитать доходность пар.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @ob_flush();
        flush();
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function hedge_ranking_stream_start(): void
{
    $GLOBALS['hedge_ranking_stream_started'] = true;
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) @ob_end_flush();
    echo json_encode(['type' => 'progress', 'message' => 'Котировки для рейтинга: 0%.'], JSON_UNESCAPED_UNICODE) . "\n";
    flush();
}

function hedge_ranking_progress(string $message): void
{
    echo json_encode(['type' => 'progress', 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @ob_flush();
    flush();
}

function hedge_ranking_result(array $payload): never
{
    if ($GLOBALS['hedge_ranking_stream_started'] ?? false) {
        echo json_encode(['type' => 'result', 'data' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        @ob_flush();
        flush();
        exit;
    }
    hedge_ranking_respond($payload);
}

try {
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $tickerSetId = trim((string) ($_GET['ticker_set'] ?? ''));
    $symbols = selectedTickerSet($config, $tickerSetId)['symbols'];
    if (count($symbols) < 2) {
        hedge_ranking_respond(['status' => 'error', 'message' => 'Для рейтинга нужны минимум два тикера.'], 400);
    }

    $timezone = new DateTimeZone('Europe/Kyiv');
    $startValue = (string) ($_GET['start_day'] ?? '2026-10-06');
    $endValue = (string) ($_GET['end_day'] ?? (new DateTimeImmutable('today', $timezone))->format('Y-m-d'));
    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue, $timezone);
    $startErrors = DateTimeImmutable::getLastErrors();
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $timezone);
    $endErrors = DateTimeImmutable::getLastErrors();
    if ($startDay === false || $endDay === false
        || ($startErrors !== false && ($startErrors['warning_count'] > 0 || $startErrors['error_count'] > 0))
        || ($endErrors !== false && ($endErrors['warning_count'] > 0 || $endErrors['error_count'] > 0))
        || $startDay->format('Y-m-d') !== $startValue
        || $endDay->format('Y-m-d') !== $endValue
        || $endDay < $startDay) {
        hedge_ranking_respond(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }

    $balance = (string) ($_GET['balance'] ?? '10');
    $feePercent = (string) ($_GET['fee'] ?? '0.06');
    $decimalPattern = '/^\d{1,12}(?:\.\d{1,12})?$/';
    if (!preg_match($decimalPattern, $balance) || (float) $balance <= 0 || (float) $balance > 1000000000
        || !preg_match($decimalPattern, $feePercent) || (float) $feePercent < 0 || (float) $feePercent > 5) {
        hedge_ranking_respond(['status' => 'error', 'message' => 'Проверьте стартовый баланс и комиссию.'], 400);
    }

    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') {
        hedge_ranking_respond(['status' => 'error', 'message' => 'Не задан пароль подключения к MySQL.'], 503);
    }
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $statement = $pdo->prepare(
        'SELECT received_at_utc, bid_price, ask_price FROM quote_snapshots '
        . 'WHERE category = ? AND symbol = ? AND received_at_utc >= ? AND received_at_utc < ? '
        . 'ORDER BY received_at_utc, id LIMIT 100001'
    );

    hedge_ranking_stream_start();
    $quotesBySymbol = [];
    $symbolCount = count($symbols);
    foreach ($symbols as $symbolIndex => $symbol) {
        $statement->execute([$config['category'], $symbol, $startUtc, $endUtc]);
        $rows = [];
        while (($row = $statement->fetch()) !== false) {
            if (count($rows) === 100000) {
                hedge_ranking_respond([
                    'status' => 'error',
                    'message' => 'Для ранжирования пар выбрано слишком много снимков. Сузьте диапазон дат и повторите расчёт.',
                ], 400);
            }
            $date = new DateTimeImmutable((string) $row[0], new DateTimeZone('UTC'));
            $rows[] = [
                $date->getTimestamp() * 1000 + (int) $date->format('v'),
                (float) $row[1],
                (float) $row[2],
            ];
        }
        $quotesBySymbol[$symbol] = $rows;
        $loaded = $symbolIndex + 1;
        $percent = (int) floor($loaded * 50 / $symbolCount);
        hedge_ranking_progress("Загружены котировки для тикеров: {$loaded} / {$symbolCount} ({$percent}%).");
    }
    $pdo = null;

    $feeRate = (float) $feePercent / 100.0;
    $pairs = [];
    $bestPair = null;
    $bestLongPairs = [];
    $bestShortPairs = [];
    $orderedPairCount = $symbolCount * ($symbolCount - 1);
    $pairIndex = 0;
    $progressInterval = max(1, (int) ceil($orderedPairCount / 100));
    foreach ($symbols as $longSymbol) {
        foreach ($symbols as $shortSymbol) {
            if ($longSymbol === $shortSymbol) continue;
            $netPnl = cross_hedge_net_pnl(
                $quotesBySymbol[$longSymbol], $quotesBySymbol[$shortSymbol],
                (float) $balance, $feeRate,
            );
            $pair = [
                'long_symbol' => $longSymbol,
                'short_symbol' => $shortSymbol,
                'net_pnl' => $netPnl,
                'return_percent' => $netPnl === null ? null : $netPnl / (float) $balance * 100.0,
            ];
            if (!isset($bestLongPairs[$longSymbol]) || hedge_ranking_pair_is_better($pair, $bestLongPairs[$longSymbol])) {
                $bestLongPairs[$longSymbol] = $pair;
            }
            if (!isset($bestShortPairs[$shortSymbol]) || hedge_ranking_pair_is_better($pair, $bestShortPairs[$shortSymbol])) {
                $bestShortPairs[$shortSymbol] = $pair;
            }
            if ($orderedPairCount > 2000) {
                if ($bestPair === null || ($netPnl !== null && ($bestPair['net_pnl'] === null || $netPnl > $bestPair['net_pnl']))) $bestPair = $pair;
            } else {
                $pairs[] = $pair;
            }
            $pairIndex++;
            if ($pairIndex % $progressInterval === 0 || $pairIndex === $orderedPairCount) {
                $percent = 50 + (int) floor($pairIndex * 50 / $orderedPairCount);
                hedge_ranking_progress("Рассчитаны пары: " . number_format($pairIndex, 0, ',', ' ') . ' / ' . number_format($orderedPairCount, 0, ',', ' ') . " ({$percent}%).");
            }
        }
    }
    if ($orderedPairCount > 2000 && $bestPair !== null) $pairs[] = $bestPair;
    $sortPairs = static function (array $left, array $right): int {
        if ($left['net_pnl'] === null || $right['net_pnl'] === null) {
            if ($left['net_pnl'] === null && $right['net_pnl'] !== null) return 1;
            if ($left['net_pnl'] !== null && $right['net_pnl'] === null) return -1;
        } elseif ($left['net_pnl'] !== $right['net_pnl']) {
            return $right['net_pnl'] <=> $left['net_pnl'];
        }
        return [$left['long_symbol'], $left['short_symbol']]
            <=> [$right['long_symbol'], $right['short_symbol']];
    };
    usort($pairs, $sortPairs);
    $bestLongPairs = array_values($bestLongPairs);
    $bestShortPairs = array_values($bestShortPairs);
    usort($bestLongPairs, $sortPairs);
    usort($bestShortPairs, $sortPairs);

    hedge_ranking_result([
        'status' => 'ready',
        'start_day' => $startValue,
        'end_day' => $endValue,
        'starting_balance' => (float) $balance,
        'fee_percent' => (float) $feePercent,
        'ordered_pair_count' => $orderedPairCount,
        'pairs' => $pairs,
        'best_long_pairs' => $bestLongPairs,
        'best_short_pairs' => $bestShortPairs,
    ]);
} catch (Throwable $error) {
    error_log('Hedge pair ranking failed: ' . $error->getMessage());
    hedge_ranking_respond(['status' => 'error', 'message' => 'Не удалось рассчитать доходность пар.'], 503);
}

function hedge_ranking_pair_is_better(array $candidate, array $current): bool
{
    if ($candidate['net_pnl'] === null) return false;
    if ($current['net_pnl'] === null) return true;
    if ($candidate['net_pnl'] !== $current['net_pnl']) return $candidate['net_pnl'] > $current['net_pnl'];
    return [$candidate['long_symbol'], $candidate['short_symbol']]
        < [$current['long_symbol'], $current['short_symbol']];
}
