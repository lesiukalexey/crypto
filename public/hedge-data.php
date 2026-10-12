<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__) . '/hedge_simulation.php';
require_once __DIR__ . '/ticker-settings-lib.php';
require_once __DIR__ . '/ranking-parameters.php';

function hedge_respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $longSymbol = strtoupper(trim((string) ($_GET['long_symbol'] ?? '')));
    $shortSymbol = strtoupper(trim((string) ($_GET['short_symbol'] ?? '')));
    $tickerSetId = trim((string) ($_GET['ticker_set'] ?? ''));
    $enabledSymbols = selectedTickerSet($config, $tickerSetId)['symbols'];
    if (!in_array($longSymbol, $enabledSymbols, true) || !in_array($shortSymbol, $enabledSymbols, true) || $longSymbol === $shortSymbol) {
        hedge_respond(['status' => 'error', 'message' => 'Выберите два разных тикера из списка настроенных инструментов.'], 400);
    }

    $timezone = new DateTimeZone('Europe/Kyiv');
    $startValue = (string) ($_GET['start_day'] ?? '2026-10-06');
    $endValue = (string) ($_GET['end_day'] ?? (new DateTimeImmutable('today', $timezone))->format('Y-m-d'));
    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue, $timezone);
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $timezone);
    $startErrors = DateTimeImmutable::getLastErrors();
    $endErrors = DateTimeImmutable::getLastErrors();
    if ($startDay === false || $endDay === false
        || ($startErrors !== false && ($startErrors['warning_count'] > 0 || $startErrors['error_count'] > 0))
        || ($endErrors !== false && ($endErrors['warning_count'] > 0 || $endErrors['error_count'] > 0))
        || $startDay->format('Y-m-d') !== $startValue || $endDay->format('Y-m-d') !== $endValue || $endDay < $startDay) {
        hedge_respond(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }
    $balance = (string) ($_GET['balance'] ?? '100');
    $balanceAutoManaged = (string) ($_GET['balance_auto'] ?? '0') === '1';
    $feePercent = (string) ($_GET['fee'] ?? '0.06');
    $decimalPattern = '/^\d{1,12}(?:\.\d{1,12})?$/';
    if (!preg_match($decimalPattern, $balance) || (float) $balance <= 0 || (float) $balance > 1000000000
        || !preg_match($decimalPattern, $feePercent) || (float) $feePercent < 0 || (float) $feePercent > 5) {
        hedge_respond(['status' => 'error', 'message' => 'Проверьте стартовый баланс и комиссию.'], 400);
    }

    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') hedge_respond(['status' => 'error', 'message' => 'Не задан пароль подключения к MySQL.'], 503);
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]
    );
    $latestPriceStatement = $pdo->prepare(
        'SELECT bid_price, ask_price FROM quote_snapshots '
        . 'WHERE category = ? AND symbol = ? ORDER BY received_at_utc DESC, id DESC LIMIT 1'
    );
    $currentPrices = [];
    foreach ([$longSymbol, $shortSymbol] as $selectedSymbol) {
        $latestPriceStatement->execute([$config['category'], $selectedSymbol]);
        $currentPrices[$selectedSymbol] = $latestPriceStatement->fetch() ?: null;
        $latestPriceStatement->closeCursor();
        if (!is_array($currentPrices[$selectedSymbol])) throw new RuntimeException('Нет сохранённой текущей котировки для ' . $selectedSymbol . '; невозможно рассчитать минимальный размер позиции.');
    }
    $longRules = rankingCurrentBitgetContract($config['category'], $longSymbol);
    $shortRules = rankingCurrentBitgetContract($config['category'], $shortSymbol);
    $longMinimum = rankingMinimumOrderBudget($longRules, (string) $currentPrices[$longSymbol]['ask_price'], bcdiv($feePercent, '100', 24));
    $shortMinimum = rankingMinimumOrderBudget($shortRules, (string) $currentPrices[$shortSymbol]['bid_price'], bcdiv($feePercent, '100', 24));
    $requestedStartingBalance = $balance;
    $minimumStartingBalance = bcadd($longMinimum['minimum_balance_usdt'], $shortMinimum['minimum_balance_usdt'], 2);
    if ($balanceAutoManaged || bccomp($balance, $minimumStartingBalance, 2) < 0) $balance = $minimumStartingBalance;
    $minimumOrderRequirements = [
        'long' => ['symbol' => $longSymbol] + $longMinimum,
        'short' => ['symbol' => $shortSymbol] + $shortMinimum,
    ];
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $countStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM quote_snapshots '
        . 'WHERE category = ? AND symbol = ? AND received_at_utc >= ? AND received_at_utc < ?'
    );
    $statement = $pdo->prepare(
        'SELECT received_at_utc, bid_price, ask_price, last_price FROM quote_snapshots '
        . 'WHERE category = ? AND symbol = ? AND received_at_utc >= ? AND received_at_utc < ? '
        . 'ORDER BY received_at_utc, id'
    );
    $quotes = [];
    $quoteCounts = [];
    $samplingSteps = [];
    $sampleLimit = 25000;
    foreach ([$longSymbol, $shortSymbol] as $symbol) {
        $params = [$config['category'], $symbol, $startUtc, $endUtc];
        $countStatement->execute($params);
        $quoteCounts[$symbol] = (int) $countStatement->fetchColumn();
        $countStatement->closeCursor();
        $targetCount = min($quoteCounts[$symbol], $sampleLimit);
        $samplingSteps[$symbol] = $targetCount > 0 ? $quoteCounts[$symbol] / $targetCount : 1;

        $statement->execute($params);
        $quotes[$symbol] = [];
        $index = 0;
        $nextSampleIndex = 0;
        while (($quote = $statement->fetch()) !== false) {
            if ($index === $nextSampleIndex) {
                $quotes[$symbol][] = $quote;
                $sampledIndex = count($quotes[$symbol]);
                if ($sampledIndex < $targetCount) {
                    $nextSampleIndex = (int) round(
                        $sampledIndex * ($quoteCounts[$symbol] - 1) / ($targetCount - 1)
                    );
                }
            }
            $index++;
        }
    }
    $pdo = null;

    $simulation = simulate_cross_hedge(
        $quotes[$longSymbol], $quotes[$shortSymbol], (float) $balance, (float) $feePercent / 100.0,
    );
    if ($simulation['summary'] === null) {
        hedge_respond([
            'status' => 'empty',
            'message' => 'Нет достаточно близких по времени снимков для одновременного открытия двух позиций.',
            'long_symbol' => $longSymbol,
            'short_symbol' => $shortSymbol,
            'long_quotes' => $quoteCounts[$longSymbol],
            'short_quotes' => $quoteCounts[$shortSymbol],
            'sampled_long_quotes' => count($quotes[$longSymbol]),
            'sampled_short_quotes' => count($quotes[$shortSymbol]),
            'long_sampling_interval' => $samplingSteps[$longSymbol],
            'short_sampling_interval' => $samplingSteps[$shortSymbol],
            'requested_starting_balance' => (float) $requestedStartingBalance,
            'starting_balance' => (float) $balance,
            'minimum_starting_balance' => (float) $minimumStartingBalance,
            'minimum_order_requirements' => $minimumOrderRequirements,
        ]);
    }
    $summary = $simulation['summary'];
    $summary['entry_fees'] = round($summary['entry_fees'], 10);
    $summary['estimated_exit_fees'] = round($summary['estimated_exit_fees'], 10);
    foreach ([
        'long_notional', 'short_notional', 'long_quantity', 'short_quantity', 'long_pnl', 'short_pnl',
        'net_pnl', 'ending_balance', 'maximum_net_pnl', 'minimum_after_maximum_net_pnl',
        'decline_after_maximum', 'maximum_drawdown',
        'maximum_drawdown_peak_net_pnl', 'maximum_drawdown_trough_net_pnl',
    ] as $field) {
        $summary[$field] = round($summary[$field], 10);
    }
    foreach (['decline_after_maximum_percent', 'maximum_drawdown_percent'] as $field) {
        $summary[$field] = $summary[$field] === null ? null : round($summary[$field], 10);
    }
    $points = $simulation['points'];
    hedge_respond([
        'status' => 'ready',
        'long_symbol' => $longSymbol,
        'short_symbol' => $shortSymbol,
        'start_day' => $startValue,
        'end_day' => $endValue,
        'starting_balance' => (float) $balance,
        'requested_starting_balance' => (float) $requestedStartingBalance,
        'minimum_starting_balance' => (float) $minimumStartingBalance,
        'minimum_order_requirements' => $minimumOrderRequirements,
        'fee_percent' => (float) $feePercent,
        'long_quotes' => $quoteCounts[$longSymbol],
        'short_quotes' => $quoteCounts[$shortSymbol],
        'sampled_long_quotes' => count($quotes[$longSymbol]),
        'sampled_short_quotes' => count($quotes[$shortSymbol]),
        'long_sampling_interval' => $samplingSteps[$longSymbol],
        'short_sampling_interval' => $samplingSteps[$shortSymbol],
        'raw_aligned_points' => $summary['aligned_points'],
        'summary' => $summary,
        'points' => $points,
    ]);
} catch (InvalidArgumentException $error) {
    hedge_respond(['status' => 'error', 'message' => $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log('Hedge view failed: ' . $error->getMessage());
    hedge_respond(['status' => 'error', 'message' => 'Не удалось получить котировки для выбранной пары.'], 503);
}
