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

function hedge_write_json_cache(string $path, array $payload): void
{
    $temporaryPath = tempnam(dirname($path), '.hedge-cache-');
    if ($temporaryPath === false
        || file_put_contents($temporaryPath, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
        || !rename($temporaryPath, $path)) {
        if (is_string($temporaryPath)) @unlink($temporaryPath);
    }
}

function hedge_load_quote_history(PDO $pdo, string $directory, string $category, string $tickerSetId, string $symbol, string $startUtc, string $endUtc, string $startValue, string $endValue): array
{
    $key = hash('sha256', json_encode(['hedge-quote-history-v1', $category, $tickerSetId, $symbol, $startValue, $endValue], JSON_THROW_ON_ERROR));
    $path = $directory . '/quotes-' . $key . '.json.gz';
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Не удалось открыть кэш котировок пары.');
    }
    try {
        if (is_file($path)) {
            $decoded = @gzdecode((string) file_get_contents($path));
            $cached = is_string($decoded) ? json_decode($decoded, true) : null;
            if (is_array($cached)) return $cached;
        }
        $statement = $pdo->prepare(
            'SELECT received_at_utc, bid_price, ask_price, last_price FROM quote_snapshots '
            . 'WHERE category = ? AND symbol = ? AND received_at_utc >= ? AND received_at_utc < ? '
            . 'ORDER BY received_at_utc, id'
        );
        $statement->execute([$category, $symbol, $startUtc, $endUtc]);
        $quotes = [];
        while (($quote = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $at = new DateTimeImmutable((string) $quote['received_at_utc'], new DateTimeZone('UTC'));
            $quote['_timestamp_ms'] = $at->getTimestamp() * 1000 + (int) $at->format('v');
            $quotes[] = $quote;
        }
        $statement->closeCursor();
        $encoded = gzencode(json_encode($quotes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 6);
        if (is_string($encoded)) {
            $temporaryPath = tempnam($directory, '.hedge-quotes-');
            if ($temporaryPath !== false) {
                if (file_put_contents($temporaryPath, $encoded, LOCK_EX) !== false) @rename($temporaryPath, $path);
                else @unlink($temporaryPath);
            }
        }
        return $quotes;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

try {
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $longSymbol = strtoupper(trim((string) ($_GET['long_symbol'] ?? '')));
    $shortSymbol = strtoupper(trim((string) ($_GET['short_symbol'] ?? '')));
    $tickerSetId = trim((string) ($_GET['ticker_set'] ?? ''));
    $enabledSymbols = selectedTickerSet($config, $tickerSetId)['symbols'];
    if (($longSymbol === '' && $shortSymbol === '') || ($longSymbol !== '' && !in_array($longSymbol, $enabledSymbols, true)) || ($shortSymbol !== '' && !in_array($shortSymbol, $enabledSymbols, true)) || ($longSymbol !== '' && $longSymbol === $shortSymbol)) {
        hedge_respond(['status' => 'error', 'message' => 'Выберите хотя бы один тикер из списка; для двух позиций нужны разные тикеры.'], 400);
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
    foreach (array_filter([$longSymbol, $shortSymbol]) as $selectedSymbol) {
        $latestPriceStatement->execute([$config['category'], $selectedSymbol]);
        $currentPrices[$selectedSymbol] = $latestPriceStatement->fetch() ?: null;
        $latestPriceStatement->closeCursor();
        if (!is_array($currentPrices[$selectedSymbol])) throw new RuntimeException('Нет сохранённой текущей котировки для ' . $selectedSymbol . '; невозможно рассчитать минимальный размер позиции.');
    }
    $zeroMinimum = ['minimum_balance_usdt'=>'0.00'];
    $longMinimum = $longSymbol === '' ? $zeroMinimum : rankingMinimumOrderBudget(rankingCurrentBitgetContract($config['category'], $longSymbol), (string) $currentPrices[$longSymbol]['ask_price'], bcdiv($feePercent, '100', 24));
    $shortMinimum = $shortSymbol === '' ? $zeroMinimum : rankingMinimumOrderBudget(rankingCurrentBitgetContract($config['category'], $shortSymbol), (string) $currentPrices[$shortSymbol]['bid_price'], bcdiv($feePercent, '100', 24));
    $requestedStartingBalance = $balance;
    $minimumStartingBalance = bcadd($longMinimum['minimum_balance_usdt'], $shortMinimum['minimum_balance_usdt'], 2);
    if ($balanceAutoManaged || bccomp($balance, $minimumStartingBalance, 2) < 0) $balance = $minimumStartingBalance;
    $minimumOrderRequirements = [
        'long' => ['symbol' => $longSymbol] + $longMinimum,
        'short' => ['symbol' => $shortSymbol] + $shortMinimum,
    ];
    $intervalMode = (string) ($_GET['interval'] ?? 'none');
    if (!in_array($intervalMode, ['none', 'week', 'two_weeks', 'month'], true)) $intervalMode = 'none';
    $cacheDirectory = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-hedge-data-cache';
    if (!is_dir($cacheDirectory) && !mkdir($cacheDirectory, 0700, true) && !is_dir($cacheDirectory)) {
        throw new RuntimeException('Не удалось подготовить кэш данных хеджирования.');
    }
    $resultKey = hash('sha256', json_encode([
        'hedge-pair-result-v1', $config['category'], $tickerSetId, $longSymbol, $shortSymbol,
        $startValue, $endValue, (float) $balance, (float) $feePercent, $intervalMode,
    ], JSON_THROW_ON_ERROR));
    $resultCachePath = $cacheDirectory . '/pair-' . $resultKey . '.json';
    if (is_file($resultCachePath)) {
        $cachedResult = json_decode((string) file_get_contents($resultCachePath), true);
        if (is_array($cachedResult) && ($cachedResult['status'] ?? '') === 'ready') {
            $cachedResult['requested_starting_balance'] = (float) $requestedStartingBalance;
            $cachedResult['starting_balance'] = (float) $balance;
            $cachedResult['minimum_starting_balance'] = (float) $minimumStartingBalance;
            $cachedResult['minimum_order_requirements'] = $minimumOrderRequirements;
            $cachedResult['cache_hit'] = true;
            hedge_respond($cachedResult);
        }
    }
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $quotes = [''=>[]];
    $quoteCounts = [''=>0];
    $samplingSteps = [''=>1];
    foreach (array_filter([$longSymbol, $shortSymbol]) as $symbol) {
        $samplingSteps[$symbol] = 1;
        $quotes[$symbol] = hedge_load_quote_history($pdo, $cacheDirectory, $config['category'], $tickerSetId, $symbol, $startUtc, $endUtc, $startValue, $endValue);
        $quoteCounts[$symbol] = count($quotes[$symbol]);
    }
    $pdo = null;

    $simulation = simulate_cross_hedge(
        $quotes[$longSymbol], $quotes[$shortSymbol], (float) $balance, (float) $feePercent / 100.0, PHP_INT_MAX, $longSymbol !== '', $shortSymbol !== '',
    );
    $intervalRows = [];
    if ($intervalMode !== 'none') {
        $periodStarts = [];
        $periodEnds = [];
        foreach (array_filter([$longSymbol, $shortSymbol]) as $symbol) {
            if ($quotes[$symbol] !== []) {
                $periodStarts[] = (int) $quotes[$symbol][0]['_timestamp_ms'];
                $periodEnds[] = (int) $quotes[$symbol][count($quotes[$symbol]) - 1]['_timestamp_ms'];
            }
        }
        if (count($periodStarts) === ((int) ($longSymbol !== '') + (int) ($shortSymbol !== ''))) {
            $coverageStart = max($periodStarts);
            $coverageEnd = min($periodEnds);
            if ($coverageEnd > $coverageStart) {
                $zone = new DateTimeZone('Europe/Kyiv');
                $lastQuoteDay = (new DateTimeImmutable('@' . intdiv($coverageEnd, 1000)))->setTimezone($zone)->setTime(0, 0);
                // Treat the day containing the last quote as incomplete and use
                // its midnight as an exclusive end for full-calendar-day windows.
                $cursor = $lastQuoteDay;
                $firstQuoteDay = (new DateTimeImmutable('@' . intdiv($coverageStart, 1000)))->setTimezone($zone)->setTime(0, 0);
                $floor = $firstQuoteDay;
                $step = match ($intervalMode) { 'week' => 'P7D', 'two_weeks' => 'P14D', 'month' => 'P1M', default => null };
                if ($step !== null) {
                    while ($cursor > $floor) {
                        $previous = $cursor->sub(new DateInterval($step));
                        if ($previous < $floor) break;
                        $fromMs = $previous->getTimestamp() * 1000;
                        $toMs = $cursor->getTimestamp() * 1000;
                        $rowsForInterval = static function (array $rows) use ($fromMs, $toMs): array {
                            $selected = [];
                            $previous = null;
                            foreach ($rows as $row) {
                                $timestampMs = (int) $row['_timestamp_ms'];
                                if ($timestampMs < $fromMs) {
                                    $previous = $row;
                                    continue;
                                }
                                if ($timestampMs >= $toMs) break;
                                $selected[] = $row;
                            }
                            if ($previous !== null) {
                                $previous['_timestamp_ms'] = $fromMs;
                                $previous['received_at_utc'] = gmdate('Y-m-d H:i:s', intdiv($fromMs, 1000));
                                array_unshift($selected, $previous);
                            }
                            return $selected;
                        };
                        $longRows = $rowsForInterval($quotes[$longSymbol]);
                        $shortRows = $rowsForInterval($quotes[$shortSymbol]);
                        $part = simulate_cross_hedge($longRows, $shortRows, (float) $balance, (float) $feePercent / 100.0, PHP_INT_MAX, $longSymbol !== '', $shortSymbol !== '');
                        if ($part['summary'] !== null) {
                            $s = $part['summary'];
                            $intervalRows[] = ['start_ms'=>$fromMs,'end_ms'=>$toMs,'long_pnl'=>round($s['long_pnl'], 8),'short_pnl'=>round($s['short_pnl'], 8),'net_pnl'=>round($s['net_pnl'], 8),'maximum_net_pnl'=>round($s['maximum_net_pnl'], 8),'maximum_drawdown'=>round($s['maximum_drawdown'], 8),'maximum_drawdown_percent'=>$s['maximum_drawdown_percent'],'peak_drawdown_ratio'=>$s['maximum_drawdown'] > 0 ? round($s['maximum_net_pnl'] / $s['maximum_drawdown'], 8) : null];
                        }
                        $cursor = $previous;
                    }
                }
            }
        }
    }
    if ($simulation['summary'] === null) {
        hedge_respond([
            'status' => 'empty',
            'message' => 'Недостаточно котировок для выбранных позиций.',
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
    $result = [
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
        'interval_rows' => $intervalRows,
        'cache_hit' => false,
    ];
    hedge_write_json_cache($resultCachePath, $result);
    hedge_respond($result);
} catch (InvalidArgumentException $error) {
    hedge_respond(['status' => 'error', 'message' => $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log('Hedge view failed: ' . $error->getMessage());
    hedge_respond(['status' => 'error', 'message' => 'Не удалось получить котировки для выбранной пары.'], 503);
}
