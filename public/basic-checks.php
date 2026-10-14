<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Kyiv');
require_once __DIR__ . '/ranking-parameters.php';
require_once __DIR__ . '/ranking-snapshot.php';
require_once __DIR__ . '/ticker-settings-lib.php';
$config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
$favoritesPath = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-favorites.json';
$favoriteView = false;
$favorite = null;
$favoriteId = trim((string) ($_GET['favorite_id'] ?? ''));
if ($favoriteId !== '' && is_file($favoritesPath)) {
    $storedFavorites = json_decode((string) file_get_contents($favoritesPath), true);
    if (is_array($storedFavorites)) {
        foreach ($storedFavorites as $storedFavorite) {
            if (is_array($storedFavorite) && (string) ($storedFavorite['id'] ?? '') === $favoriteId
                && in_array((string) ($storedFavorite['type'] ?? 'strategy'), ['strategy', 'global_top', 'ticker_leader'], true)
                && is_array($storedFavorite['params'] ?? null)) {
                $favorite = $storedFavorite;
                $_GET = array_merge($_GET, $storedFavorite['params']);
                $favoriteView = true;
                break;
            }
        }
    }
}
$tickerSetSelection = selectedTickerSet($config, trim((string) ($_GET['ticker_set'] ?? '')));
$tickerSetId = $tickerSetSelection['id'];
$tickerSetOptions = $tickerSetSelection['sets'];
$enabledSymbols = $tickerSetSelection['symbols'];
$demoAvailableSymbols = [];
$rankingCacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
$rankingGenerationPath = $rankingCacheDir . '/strategy-ranking-generation.txt';
$rankingGeneration = is_file($rankingGenerationPath) ? trim((string) file_get_contents($rankingGenerationPath)) : 'initial';
if ($rankingGeneration === '') $rankingGeneration = 'initial';
$entryProfileConfig = json_decode((string) file_get_contents(dirname(__DIR__) . '/entry_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
$exitProfileConfig = json_decode((string) file_get_contents(dirname(__DIR__) . '/exit_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
$defaultRankingRanges = rankingParameters([], $entryProfileConfig, $exitProfileConfig)['ranges'];
$rankingError = null;
try {
    $rankingParameters = rankingParameters($_GET, $entryProfileConfig, $exitProfileConfig);
    $martingaleRiskLimits = rankingMartingaleRiskLimits($_GET);
    $simulationCosts = rankingSimulationCosts($_GET);
} catch (InvalidArgumentException $exception) {
    $rankingError = $exception->getMessage();
    $rankingParameters = rankingParameters([], $entryProfileConfig, $exitProfileConfig);
    $martingaleRiskLimits = ['max_chain_loss' => '0', 'max_day_loss' => '0'];
    $simulationCosts = ['include_funding' => true, 'slippage_bps' => '0'];
}
$rankingRanges = $rankingParameters['ranges'];
$rankingModes = $rankingParameters['modes'];
$entryProfiles = $rankingParameters['worker']['entry_profiles'];
$entryThresholdsTenthsPercent = $entryProfiles['both']['thresholds_tenths_percent'];
$entryWindows = $entryProfiles['both']['windows'];
$entryThresholdSummary = implode(', ', array_map(static fn (int $value): string => number_format($value / 10, 1, ',', ' '), $entryThresholdsTenthsPercent));
$immediateEntryText = implode(' и ', array_map(static fn (string $direction): string => 'сразу — 1 заявка на ' . ($direction === 'short' ? 'продажу' : 'покупку'), $entryProfileConfig['immediate_directions'] ?? []));
$exitRangeText = static function (array $ranges): string {
    return implode('; ', array_map(static function (array $range): string {
        $min = number_format($range['from'] / 10, 1, ',', ' ');
        $max = number_format($range['to'] / 10, 1, ',', ' ');
        $step = number_format($range['step'] / 10, 1, ',', ' ');
        return $min . '–' . $max . '% (шаг ' . $step . '%)';
    }, array_is_list($ranges) ? $ranges : [$ranges])) . ' от стартового баланса';
};
$db = $config['mysql'];
$symbols = $enabledSymbols;
$rows = [];
$error = $rankingError;
$timezone = new DateTimeZone('Europe/Kyiv');
$today = new DateTimeImmutable('today', $timezone);
$defaultEndDay = $today->modify('-1 day')->format('Y-m-d');
$symbolWasSubmitted = array_key_exists('symbol', $_GET) && trim((string) $_GET['symbol']) !== '';
$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$startDayAuto = array_key_exists('start_day_auto', $_GET)
    ? (string) $_GET['start_day_auto'] === '1'
    : !array_key_exists('start_day', $_GET) && !array_key_exists('day', $_GET);
$startDayInput = (string) ($_GET['start_day'] ?? $_GET['day'] ?? '2026-10-06');
$endDayInput = (string) ($_GET['end_day'] ?? $_GET['day'] ?? $defaultEndDay);
$pdo = null;
if ($startDayAuto && $enabledSymbols !== []) {
    try {
        $password = getenv($db['password_env']) ?: '';
        if ($password !== '') {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
                $db['user'],
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $earliestQueries = [];
            $earliestParams = [];
            foreach ($enabledSymbols as $enabledSymbol) {
                $earliestQueries[] = 'SELECT MIN(received_at_utc) AS earliest FROM quote_snapshots WHERE category = ? AND symbol = ?';
                $earliestParams[] = $config['category'];
                $earliestParams[] = $enabledSymbol;
            }
            $earliestStatement = $pdo->prepare(
                'SELECT MIN(earliest) FROM (' . implode(' UNION ALL ', $earliestQueries) . ') AS per_symbol'
            );
            $earliestStatement->execute($earliestParams);
            $earliestQuote = $earliestStatement->fetchColumn();
            if (is_string($earliestQuote) && $earliestQuote !== '') {
                $startDayInput = (new DateTimeImmutable($earliestQuote, new DateTimeZone('UTC')))
                    ->setTimezone($timezone)
                    ->format('Y-m-d');
            }
        }
    } catch (Throwable $exception) {
        $pdo = null;
        error_log('Unable to determine default Basic Checks start date: ' . $exception->getMessage());
    }
}
$defaultEntryConfig = in_array(10, $entryWindows, true) && in_array(1, $entryThresholdsTenthsPercent, true)
    ? 'entry:10:1' : 'entry:' . $entryWindows[0] . ':' . $entryThresholdsTenthsPercent[0];
$entrySignalMode = $rankingParameters['worker']['entry_signal_mode'];
$entryDirectionMode = $rankingParameters['worker']['entry_direction_mode'];
$exitConfirmationMode = $rankingParameters['worker']['exit_confirmation_mode'];
$exitConfirmationSnapshots = $rankingParameters['worker']['exit_confirmation_snapshots'];
$takeConfirmationDelay = in_array($exitConfirmationMode, ['both_delayed', 'tp_delayed'], true) ? $exitConfirmationSnapshots : 0;
$stopConfirmationDelay = in_array($exitConfirmationMode, ['both_delayed', 'sl_delayed'], true) ? $exitConfirmationSnapshots : 0;
if ($entrySignalMode === 'within') $defaultEntryConfig .= ':within';
if ($entryDirectionMode === 'reverse') $defaultEntryConfig .= ':reverse';
$entryConfig = (string) ($_GET['entry_config'] ?? $defaultEntryConfig);
$saveMode = (string) ($_GET['save_mode'] ?? '1') !== '0';
$balanceAutoManaged = (string) ($_GET['balance_auto'] ?? '0') === '1';
$startingBalance = trim((string) ($_GET['balance'] ?? '100'));
$balanceAutoAdjustedFrom = null;
$minimumOrderBudgetInfo = null;
$feePercent = trim((string) ($_GET['fee'] ?? '0.06'));
$martingaleMode = (string) ($_GET['martingale_mode'] ?? 'none');
$martingaleContractRules = null;
$martingaleOrderConstraintError = null;
$martingaleTiming = (string) ($_GET['martingale_timing'] ?? 'immediate');
$martingaleAttempts = filter_var($_GET['martingale_attempts'] ?? 3, FILTER_VALIDATE_INT);
if (!in_array($martingaleMode, ['none', 'simple', 'reverse', 'simple_profit', 'reverse_profit'], true)) $martingaleMode = 'none';
if (!in_array($martingaleTiming, ['immediate', 'rules'], true)) $martingaleTiming = 'immediate';
if ($martingaleAttempts === false || $martingaleAttempts < 2 || $martingaleAttempts > 10) $martingaleAttempts = 3;
$defaultFixedValue = (int) ($rankingRanges['fixed_tp']['values_basis_points'][0] ?? 10);
$defaultExitConfig = 'fixed:' . $defaultFixedValue . ':' . $defaultFixedValue;
$exitConfig = $exitConfirmationMode === 'none' ? 'none' : (string) ($_GET['exit_config'] ?? $defaultExitConfig);
$selectTopRankedResult = $exitConfirmationMode !== 'none'
    && (string) ($_GET['select_top_ranked_result'] ?? (!array_key_exists('entry_config', $_GET) ? '1' : '0')) === '1';
$martingaleTargetPolicy = 'scaled';
if (preg_match('/:(scaled|fixed)$/', $exitConfig, $policyMatch)) {
    $martingaleTargetPolicy = $policyMatch[1];
    $exitConfig = substr($exitConfig, 0, -strlen($policyMatch[0]));
}
$symbolSort = (string) ($_GET['symbol_sort'] ?? '') === 'profit' ? 'profit' : '';
$combinationDetail = (string) ($_GET['combination_detail'] ?? '') === '1';
$detailEntryLabel = trim((string) ($_GET['detail_entry_label'] ?? ''));
$detailExitLabel = trim((string) ($_GET['detail_exit_label'] ?? ''));
$detailRank = filter_var($_GET['detail_rank'] ?? null, FILTER_VALIDATE_INT);
$detailTickerPnl = is_numeric($_GET['detail_ticker_pnl'] ?? null) ? (float) $_GET['detail_ticker_pnl'] : null;
$detailMarketAverage = is_numeric($_GET['detail_market_average'] ?? null) ? (float) $_GET['detail_market_average'] : null;
$detailSupport = filter_var($_GET['detail_support'] ?? null, FILTER_VALIDATE_INT);
$detailUniverseSize = filter_var($_GET['detail_universe_size'] ?? null, FILTER_VALIDATE_INT);
$detailTradeCount = filter_var($_GET['detail_trade_count'] ?? null, FILTER_VALIDATE_INT);
$viewParams = ['symbol' => $symbol, 'ticker_set' => $tickerSetId, 'start_day' => $startDayInput, 'start_day_auto' => $startDayAuto ? '1' : '0', 'end_day' => $endDayInput, 'balance' => $startingBalance, 'balance_auto' => $balanceAutoManaged ? '1' : '0', 'fee' => $feePercent, 'include_funding' => $simulationCosts['include_funding'] ? '1' : '0', 'slippage_bps' => $simulationCosts['slippage_bps']];
$hedgingUrl = './hedging.php?' . http_build_query($viewParams);
$isCurrentDay = false;
$decimalPattern = '/^\d{1,12}(?:\.\d{1,12})?$/';
if (!preg_match($decimalPattern, $startingBalance) || (float) $startingBalance <= 0 || (float) $startingBalance > 1000000000) {
    $error = 'Стартовый баланс должен быть больше 0 и не превышать 1 000 000 000 USDT.';
    $startingBalance = '100';
}
$rankingStartingBalance = trim((string) ($_GET['ranking_balance'] ?? $startingBalance));
if (!preg_match($decimalPattern, $rankingStartingBalance) || (float) $rankingStartingBalance <= 0 || (float) $rankingStartingBalance > 1000000000) {
    $rankingStartingBalance = $startingBalance;
}
if (!preg_match($decimalPattern, $feePercent) || (float) $feePercent < 0 || (float) $feePercent > 5) {
    $error = 'Комиссия должна быть от 0 до 5 процентов за сторону.';
    $feePercent = '0.06';
}
$entrySelection = rankingEntryConfig($entryConfig, $rankingParameters['worker'], $entryProfileConfig['immediate_directions'] ?? []);
if ($entrySelection === null) {
    $entryConfig = $defaultEntryConfig;
    $entrySelection = rankingEntryConfig($entryConfig, $rankingParameters['worker'], $entryProfileConfig['immediate_directions'] ?? []);
}
$isImmediateEntry = $entrySelection['type'] === 'immediate';
$immediateDirection = $isImmediateEntry ? $entrySelection['direction'] : null;
$entryDirection = $isImmediateEntry ? 'both' : $entrySelection['family'];
$momentumLookback = $isImmediateEntry ? 0 : $entrySelection['window'];
$momentumThresholdTenthsPercent = $isImmediateEntry ? 0 : $entrySelection['threshold_tenths_percent'];
$momentumThreshold = $momentumThresholdTenthsPercent / 10;
$entryFamilyPrefix = $entryDirection === 'both' ? '±' : ($entryDirection === 'long' ? '+' : '−');
$entryLoadingLabel = 'Загружаю рейтинг порогов входа…';
$profitTarget = bcdiv($startingBalance, '1000', 24);
$lossThreshold = bcsub('0', $profitTarget, 24);
$noExitSelected = $exitConfirmationMode === 'none';
if ($noExitSelected) {
    $profitTarget = null;
    $lossThreshold = null;
    $martingaleMode = 'none';
} elseif (preg_match('/^fixed:(\d+):(\d+)$/', $exitConfig, $matches)
    && rankingHasValue((int) $matches[1], $rankingRanges['fixed_tp'])
    && rankingHasValue((int) $matches[2], $rankingRanges['fixed_sl'])) {
    $profitTarget = bcdiv(bcmul($startingBalance, $matches[1], 24), '10000', 24);
    $lossThreshold = bcdiv(bcmul($startingBalance, (string) (0 - (int) $matches[2]), 24), '10000', 24);
} elseif (preg_match('/^loss:(\d+)$/', $exitConfig, $matches)
    && rankingHasValue((int) $matches[1], $rankingRanges['loss'])) {
    $profitTarget = null;
    $lossThreshold = bcdiv(bcmul($startingBalance, (string) (0 - (int) $matches[1]), 24), '1000', 24);
} elseif (preg_match('/^profit:(\d+)$/', $exitConfig, $matches)
    && rankingHasValue((int) $matches[1], $rankingRanges['profit'])) {
    $profitTarget = bcdiv(bcmul($startingBalance, $matches[1], 24), '1000', 24);
    $lossThreshold = null;
} else {
    $exitConfig = $defaultExitConfig;
}
$formatTargetPercent = static function (?string $amount, string $startingBalance): string {
    if ($amount === null || bccomp($startingBalance, '0', 24) === 0) return '—';
    return rtrim(rtrim(number_format((float) bcmul(bcdiv($amount, $startingBalance, 24), '100', 12), 4, '.', ''), '0'), '.') . '%';
};
$selectedExitConfig = $exitConfig . ($martingaleMode !== 'none' ? ':' . $martingaleTargetPolicy : '');
$entrySelectorSeeds = [];
foreach ($entryProfiles as $family => $profile) {
    foreach ($profile['windows'] as $window) {
        foreach ($profile['thresholds_tenths_percent'] as $threshold) {
            $prefix = $family === 'both' ? 'entry' : 'entry:' . $family;
            $id = $prefix . ':' . $window . ':' . $threshold;
            if ($entrySignalMode === 'within') $id .= ':within';
            if ($entryDirectionMode === 'reverse') $id .= ':reverse';
            $familyLabel = $family === 'both' ? '±' : ($family === 'long' ? '+' : '−');
            $entrySelectorSeeds[] = [
                'id' => $id,
                'label' => $familyLabel . number_format($threshold / 10, 1, ',', ' ') . '% от баланса за ' . $window . ' снимков',
                'best_pnl' => null,
            ];
        }
    }
}
foreach ($rankingParameters['worker']['immediate_directions'] as $direction) {
    $entrySelectorSeeds[] = [
        'id' => 'immediate:' . $direction,
        'label' => 'Сразу · 1 заявка на ' . ($direction === 'short' ? 'продажу' : 'покупку'),
        'best_pnl' => null,
    ];
}
$exitSelectorSeeds = [];
$exitSelectorSeeds[] = ['id' => 'none', 'label' => 'Без выхода · удерживать до конца периода', 'pnl' => null, 'snapshots' => $exitConfirmationSnapshots];
$exitPolicySuffix = $martingaleMode !== 'none' ? ':' . $martingaleTargetPolicy : '';
foreach (rankingValues($rankingRanges['fixed_tp']) as $take) {
    foreach (rankingValues($rankingRanges['fixed_sl']) as $stop) {
        $takeLabel = number_format($take / 100, 2, ',', ' ');
        $stopLabel = number_format($stop / 100, 2, ',', ' ');
        $exitSelectorSeeds[] = ['id' => 'fixed:' . $take . ':' . $stop . $exitPolicySuffix, 'label' => 'TP ' . $takeLabel . '% / SL ' . $stopLabel . '%', 'pnl' => null, 'snapshots' => $exitConfirmationSnapshots];
    }
}
foreach (rankingValues($rankingRanges['profit']) as $take) {
    $exitSelectorSeeds[] = ['id' => 'profit:' . $take . $exitPolicySuffix, 'label' => 'TP ' . number_format($take / 10, 1, ',', ' ') . '% · без SL', 'pnl' => null, 'snapshots' => $exitConfirmationSnapshots];
}
foreach (rankingValues($rankingRanges['loss']) as $stop) {
    $exitSelectorSeeds[] = ['id' => 'loss:' . $stop . $exitPolicySuffix, 'label' => 'SL ' . number_format($stop / 10, 1, ',', ' ') . '% · без TP', 'pnl' => null, 'snapshots' => $exitConfirmationSnapshots];
}
$favoriteEntryLabel = $favoriteView ? trim((string) ($favorite['entry_label'] ?? $entryConfig)) : '';
$favoriteExitLabel = $favoriteView ? trim((string) ($favorite['exit_label'] ?? $selectedExitConfig)) : '';
$favoriteSymbolDisabled = $favoriteView && !in_array(strtoupper((string) ($favorite['params']['symbol'] ?? '')), $enabledSymbols, true);

try {
    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', $startDayInput, $timezone);
    $startDateErrors = DateTimeImmutable::getLastErrors();
    if ($startDay === false || ($startDateErrors !== false && ($startDateErrors['warning_count'] > 0 || $startDateErrors['error_count'] > 0)) || $startDay->format('Y-m-d') !== $startDayInput) {
        throw new InvalidArgumentException('Укажите корректную начальную дату.');
    }
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $endDayInput, $timezone);
    $endDateErrors = DateTimeImmutable::getLastErrors();
    if ($endDay === false || ($endDateErrors !== false && ($endDateErrors['warning_count'] > 0 || $endDateErrors['error_count'] > 0)) || $endDay->format('Y-m-d') !== $endDayInput) {
        throw new InvalidArgumentException('Укажите корректную конечную дату.');
    }
    if ($endDay < $startDay) {
        throw new InvalidArgumentException('Конечная дата не может быть раньше начальной.');
    }
    $isCurrentDay = $endDay->format('Y-m-d') === $today->format('Y-m-d');
    $dayAfterEnd = $endDay->modify('+1 day');
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $dayAfterEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    if ($symbols === [] || !in_array($symbol, $symbols, true)) {
        $symbol = '';
        $symbolWasSubmitted = false;
    }

    if ($favoriteSymbolDisabled) {
        $error = 'Этот тикер выключен в настройках и не участвует в расчётах.';
    } elseif ($symbol !== '') {
        $password = getenv($db['password_env']) ?: '';
        if ($password === '') {
            throw new RuntimeException('Не задан пароль подключения к MySQL.');
        }
        $pdo ??= new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
            $db['user'],
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $demoAvailableSymbols = array_fill_keys(loadDemoTickerAvailability($config, $pdo), true);
            $martingaleContractRules = rankingCurrentBitgetContract($config['category'], $symbol);
            $latestOrderQuoteStatement = $pdo->prepare(
                'SELECT bid_price, ask_price FROM quote_snapshots '
                . 'WHERE category = ? AND symbol = ? ORDER BY received_at_utc DESC, id DESC LIMIT 1'
            );
            $latestOrderQuoteStatement->execute([$config['category'], $symbol]);
            $latestOrderQuote = $latestOrderQuoteStatement->fetch();
            $latestOrderQuoteStatement->closeCursor();
            if (is_array($latestOrderQuote)) {
                $referencePrice = bccomp((string) $latestOrderQuote['ask_price'], (string) $latestOrderQuote['bid_price'], 24) >= 0
                    ? (string) $latestOrderQuote['ask_price'] : (string) $latestOrderQuote['bid_price'];
                $minimumOrderBudgetInfo = rankingMinimumOrderBudget($martingaleContractRules, $referencePrice, bcdiv($feePercent, '100', 24));
                $balanceFloor = $minimumOrderBudgetInfo['minimum_balance_usdt'];
                if ($balanceAutoManaged || bccomp($startingBalance, $balanceFloor, 2) < 0) {
                    $balanceAutoAdjustedFrom = $startingBalance;
                    $startingBalance = $balanceFloor;
                    $viewParams['balance'] = $startingBalance;
                    $viewParams['balance_auto'] = $balanceAutoManaged ? '1' : '0';
                    $hedgingUrl = './hedging.php?' . http_build_query($viewParams);
                }
            }
            if ($martingaleContractRules['symbolStatus'] !== 'normal'
                || bccomp((string) $martingaleContractRules['minLever'], '1', 12) > 0
                || bccomp((string) $martingaleContractRules['maxLever'], '1', 12) < 0) {
                throw new RuntimeException('Текущие правила Bitget не разрешают открыть эту пару с плечом 1×.');
            }
            if (!is_dir($rankingCacheDir) && !mkdir($rankingCacheDir, 0700, true) && !is_dir($rankingCacheDir)) {
                throw new RuntimeException('Не удалось подготовить кэш рейтинга.');
            }
            $snapshotIds = rankingSnapshotIds(
                $pdo, $rankingCacheDir, $config['category'], $startDayInput, $endDayInput,
                $startUtc, $endUtc, $rankingGeneration,
            );
            $snapshotIds = filterEnabledTickerSnapshotIds($snapshotIds, $config, $tickerSetId);
            $maxQuoteId = (int) ($snapshotIds[$symbol] ?? 0);
            if ($martingaleMode === 'none') {
                $stmt = $pdo->prepare("SELECT id, received_at_utc, bid_price, ask_price, last_price, quote_source FROM quote_snapshots WHERE category = ? AND symbol = ? AND (id <= ? OR quote_source = 'historical_candle_close') AND received_at_utc < ? ORDER BY received_at_utc, id");
                $stmt->execute([$config['category'], $symbol, $maxQuoteId, $endUtc]);
            } else {
                $stmt = $pdo->prepare("SELECT id, received_at_utc, bid_price, ask_price, last_price, quote_source FROM quote_snapshots WHERE category = ? AND symbol = ? AND (id <= ? OR quote_source = 'historical_candle_close') AND received_at_utc >= ? AND received_at_utc < ? ORDER BY received_at_utc, id");
                $stmt->execute([$config['category'], $symbol, $maxQuoteId, $startUtc, $endUtc]);
            }
            $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $error ??= ($e instanceof InvalidArgumentException || str_contains($e->getMessage(), 'Bitget'))
        ? $e->getMessage()
        : 'Не удалось загрузить котировки. Проверьте доступность MySQL и настройки подключения.';
}

$cachedStandardTradeEvents = null;
$closedTradeLedgerKey = null;
$closedTradeLedgerRoot = dirname($rankingCacheDir) . '/bitget-closed-trades-v1';
$ledgerFingerprints = [];
$ledgerQuoteCounts = [];
$ledgerLastQuotes = [];
$ledgerSourceDay = null;
if ($martingaleMode === 'none' && $rows !== [] && $error === null) {
    $closedTradeLedgerKey = hash('sha256', json_encode([
        'closed-trade-ledger-v6-range-reset-at-start', $config['category'], $symbol, $startDayInput, $startingBalance,
        $feePercent, $entryConfig, $selectedExitConfig, $exitConfirmationMode, $exitConfirmationSnapshots,
        ...rankingSimulationCostsIdentity($simulationCosts), $martingaleContractRules,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $prefixContext = hash_init('sha256');
    $quoteCount = 0;
    foreach ($rows as $row) {
        $quote = [
            (string) $row['id'], (string) $row['received_at_utc'],
            (string) $row['bid_price'], (string) $row['ask_price'], (string) $row['last_price'],
        ];
        hash_update($prefixContext, implode("\0", $quote) . "\n");
        $quoteCount++;
        $quoteDay = (new DateTimeImmutable($row['received_at_utc'], new DateTimeZone('UTC')))
            ->setTimezone($timezone)->format('Y-m-d');
        $ledgerFingerprints[$quoteDay] = hash_final(hash_copy($prefixContext));
        $ledgerQuoteCounts[$quoteDay] = $quoteCount;
        $ledgerLastQuotes[$quoteDay] = $row;
        $ledgerSourceDay ??= $quoteDay;
    }
    if ($ledgerSourceDay !== null && $endDayInput >= $ledgerSourceDay) {
        $directoryReady = is_dir($closedTradeLedgerRoot)
            || mkdir($closedTradeLedgerRoot, 0700, true)
            || is_dir($closedTradeLedgerRoot);
        if ($directoryReady) {
            $previousFingerprint = null;
            $previousQuoteCount = 0;
            $previousLastQuote = $rows[0];
            foreach ($ledgerFingerprints as $quotedDay => $quotedFingerprint) {
                if ($quotedDay >= max($startDayInput, $ledgerSourceDay)) continue;
                $previousFingerprint = $quotedFingerprint;
                $previousQuoteCount = $ledgerQuoteCounts[$quotedDay];
                $previousLastQuote = $ledgerLastQuotes[$quotedDay];
            }
            $cachedStandardTradeEvents = [];
            $cacheComplete = true;
            $ledgerDay = new DateTimeImmutable(max($startDayInput, $ledgerSourceDay), $timezone);
            $ledgerEndDay = new DateTimeImmutable($endDayInput, $timezone);
            while ($ledgerDay <= $ledgerEndDay) {
                $day = $ledgerDay->format('Y-m-d');
                if (isset($ledgerFingerprints[$day])) {
                    $previousFingerprint = $ledgerFingerprints[$day];
                    $previousQuoteCount = $ledgerQuoteCounts[$day];
                    $previousLastQuote = $ledgerLastQuotes[$day];
                }
                $fingerprint = $previousFingerprint ?? hash('sha256', 'empty:' . $closedTradeLedgerKey);
                $dayQuoteCount = $previousQuoteCount;
                $path = $closedTradeLedgerRoot . '/' . $closedTradeLedgerKey . '-' . str_replace('-', '', $day) . '.json';
                $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
                if (!is_array($payload)
                    || ($payload['version'] ?? null) !== 1
                    || ($payload['day'] ?? null) !== $day
                    || ($payload['quote_count'] ?? null) !== $dayQuoteCount
                    || !hash_equals($fingerprint, (string) ($payload['quote_prefix_sha256'] ?? ''))
                    || !is_array($payload['closed_trades'] ?? null)) {
                    $cacheComplete = false;
                    break;
                }
                foreach ($payload['closed_trades'] as $trade) {
                    if (is_array($trade)) $cachedStandardTradeEvents[] = $trade;
                }
                if ($day === $endDayInput && is_array($payload['open_trade'] ?? null)) {
                    $cachedStandardTradeEvents[] = $payload['open_trade'];
                }
                $ledgerDay = $ledgerDay->modify('+1 day');
            }
            if (!$cacheComplete) $cachedStandardTradeEvents = null;
        }
    }
}

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function cachedTradeIsOpen(array $trade): bool { return str_starts_with((string) ($trade['reason'] ?? ''), 'Открыта ·'); }
function markTradeAtQuote(array $trade, array $quote, string $feeRate, array $fundingEvents, float $slippageBps): array
{
    $short = (bool) $trade['is_short'];
    $entryPrice = (string) $trade['entry_price'];
    $exitPrice = (string) ($short ? $quote['ask_price'] : $quote['bid_price']);
    $slippage = bcdiv((string) $slippageBps, '10000', 16);
    $exitPrice = bcmul($exitPrice, $short ? bcadd('1', $slippage, 16) : bcsub('1', $slippage, 16), 24);
    $move = $short ? bcsub($entryPrice, $exitPrice, 24) : bcsub($exitPrice, $entryPrice, 24);
    $gross = bcmul((string) $trade['quantity'], $move, 24);
    $exitFee = bcmul(bcmul((string) $trade['quantity'], $exitPrice, 24), $feeRate, 24);
    $net = bcsub(bcsub($gross, (string) $trade['entry_fee'], 24), $exitFee, 24);
    $entryTimestamp = (new DateTimeImmutable($trade['entry']['received_at_utc'], new DateTimeZone('UTC')))->getTimestamp();
    $quoteTimestamp = (new DateTimeImmutable($quote['received_at_utc'], new DateTimeZone('UTC')))->getTimestamp();
    $fundingTotal = '0';
    foreach ($fundingEvents as $event) {
        if ($entryTimestamp < $event['timestamp'] && $event['timestamp'] <= $quoteTimestamp) {
            $amount = bcmul(bcmul((string) $trade['quantity'], $event['mark_price'], 24), $event['rate'], 24);
            $cashflow = $short ? $amount : bcsub('0', $amount, 24);
            $fundingTotal = bcadd($fundingTotal, $cashflow, 24);
            $net = bcadd($net, $cashflow, 24);
        }
    }
    return [
        'entry' => $trade['entry'], 'exit' => $quote,
        'quantity' => $trade['quantity'], 'entry_price' => $entryPrice,
        'exit_price' => $exitPrice, 'entry_fee' => $trade['entry_fee'],
        'exit_fee' => $exitFee, 'gross' => $gross, 'funding' => $fundingTotal, 'net' => $net,
        'balance' => '0', 'reason' => 'Открыта · последний снимок выбранного периода',
        'is_short' => $short,
    ];
}
function writeClosedTradeDay(string $path, array $payload): void
{
    $temporaryPath = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($temporaryPath, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false
        || !rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Не удалось сохранить дневные закрытые сделки.');
    }
}
function rankingRangeRow(string $label, string $name, array $range, bool $money, array $defaultRange = [], bool $percent = false): string
{
    $format = static fn (int $value): string => $percent ? number_format($value / 10, 1, '.', '') : ($money ? number_format($value / 100, 2, '.', '') : (string) $value);
    $step = $percent ? '0.1' : ($money ? '0.01' : '1');
    $cells = '';
    foreach (['from' => 'От', 'to' => 'До', 'step' => 'Шаг'] as $key => $caption) {
        $cells .= '<label>' . $caption . '<input class="ranking-range-input" type="number" name="' . $name . '_' . $key
            . '" min="' . $step . '" step="' . $step . '" value="' . h($format($range[$key]))
            . '" data-default-value="' . h($format($defaultRange[$key] ?? $range[$key])) . '" required></label>';
    }
    return '<div class="ranking-range-row"><span>' . h($label) . '</span>' . $cells . '</div>';
}
function rankingSegmentEditor(string $label, string $name, array $segments, array $defaultSegments, bool $percent, int $percentPrecision = 1): string
{
    $scale = 10 ** $percentPrecision;
    $format = static fn (int|float $value): string => $percent ? number_format($value / $scale, $percentPrecision, '.', '') : (string) (int) $value;
    $step = $percent ? number_format(1 / $scale, $percentPrecision, '.', '') : '1';
    $defaultJson = h(json_encode($defaultSegments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $html = '<div class="ranking-segment-editor" data-range-name="' . h($name . '_ranges') . '" data-percent="' . ($percent ? '1' : '0') . '" data-percent-precision="' . ($percent ? $percentPrecision : '0') . '" data-default-segments="' . $defaultJson . '">';
    foreach ($segments as $index => $segment) {
        $html .= '<div class="ranking-segment-row"><span>' . h($label) . ' · диапазон ' . ($index + 1) . '</span>';
        foreach (['from' => 'От', 'to' => 'До', 'step' => 'Шаг'] as $field => $caption) {
            $value = $format($segment[$field]);
            $html .= '<label>' . $caption . '<input class="ranking-range-input" type="number" min="' . $step . '" step="' . $step
                . '" name="' . h($name . '_ranges[' . $index . '][' . $field . ']') . '" data-field="' . $field
                . '" value="' . h($value) . '" data-default-value="' . h($value) . '" required></label>';
        }
        $html .= '<button type="button" class="remove-range-segment" aria-label="Удалить диапазон">−</button></div>';
    }
    return $html . '<button type="button" class="add-range-segment">Добавить диапазон</button></div>';
}
function money(string $value): string { return number_format((float) $value, 6, '.', ' '); }
function formatPrice(string $value): string { return number_format((float) $value, 2, '.', ''); }
function formatKyivTime(string $value, DateTimeZone $timezone): string { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($timezone)->format('d.m H:i:s'); }
function epochMilliseconds(string $value): int { $time = new DateTimeImmutable($value, new DateTimeZone('UTC')); return ((int) $time->format('U')) * 1000 + (int) $time->format('v'); }
$series = ['bid_price' => ['name' => 'Bid', 'color' => '#42d6a4'], 'ask_price' => ['name' => 'Ask', 'color' => '#ff7188'], 'last_price' => ['name' => 'Last', 'color' => '#86a8ff']];
$visibleQuoteCount = 0;
$hasHistoricalCandles = false;
$chartDays = [];
$chartPriceMin = null;
$chartPriceMax = null;
foreach ($rows as $row) {
    $time = (new DateTimeImmutable($row['received_at_utc'], new DateTimeZone('UTC')))->setTimezone($timezone);
    if ($time < $startDay || $time >= $dayAfterEnd) continue;
    $visibleQuoteCount++;
    $hasHistoricalCandles = $hasHistoricalCandles || ($row['quote_source'] ?? 'ticker') === 'historical_candle_close';
    $chartDays[$time->format('Y-m-d')] = true;
    foreach ($series as $key => $meta) {
        $value = (float) $row[$key];
        if (!is_finite($value)) continue;
        $chartPriceMin = $chartPriceMin === null ? $value : min($chartPriceMin, $value);
        $chartPriceMax = $chartPriceMax === null ? $value : max($chartPriceMax, $value);
    }
}
$chartPointLimit = 25000;
$chartPointCount = min($visibleQuoteCount, $chartPointLimit);
$points = [];
$visibleIndex = 0;
$nextChartIndex = 0;
$sampledChartPoints = 0;
foreach ($rows as $row) {
    $time = (new DateTimeImmutable($row['received_at_utc'], new DateTimeZone('UTC')))->setTimezone($timezone);
    if ($time < $startDay || $time >= $dayAfterEnd) continue;
    if ($visibleIndex === $nextChartIndex) {
        foreach ($series as $key => $meta) {
            $value = (float) $row[$key];
            if (is_finite($value)) $points[$key][] = ['t' => $time->getTimestamp() * 1000, 'v' => $value, 'label' => $row[$key]];
        }
        $sampledChartPoints++;
        if ($sampledChartPoints < $chartPointCount && $chartPointCount > 1) {
            $nextChartIndex = (int) round($sampledChartPoints * ($visibleQuoteCount - 1) / ($chartPointCount - 1));
        }
    }
    $visibleIndex++;
}
$chart = [];
foreach ($series as $key => $meta) $chart[$key] = $points[$key] ?? [];
$feeRate = bcdiv($feePercent, '100', 24);
$balance = $startingBalance;
$trades = [];
$tradeEntryIndex = 0;
$rowCount = count($rows);
$rangeStartInstant = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
while ($tradeEntryIndex < $rowCount) {
    $quoteInstant = new DateTimeImmutable($rows[$tradeEntryIndex]['received_at_utc'], new DateTimeZone('UTC'));
    if ($quoteInstant >= $rangeStartInstant) break;
    $tradeEntryIndex++;
}
$rangeStartQuoteIndex = $tradeEntryIndex;
$executionRows = $rows;
$slippageFraction = (float) $simulationCosts['slippage_bps'] / 10000;
if ($slippageFraction > 0) {
    foreach ($executionRows as &$executionRow) {
        $executionRow['bid_price'] = bcmul((string) $executionRow['bid_price'], bcsub('1', (string) $slippageFraction, 16), 24);
        $executionRow['ask_price'] = bcmul((string) $executionRow['ask_price'], bcadd('1', (string) $slippageFraction, 16), 24);
    }
    unset($executionRow);
}
$fundingEvents = [];
if ($simulationCosts['include_funding'] && $rows !== []) {
    try {
        $firstTimestamp = (new DateTimeImmutable($rows[0]['received_at_utc'], new DateTimeZone('UTC')))->getTimestamp() * 1000;
        $lastTimestamp = (new DateTimeImmutable($rows[$rowCount - 1]['received_at_utc'], new DateTimeZone('UTC')))->getTimestamp() * 1000;
        foreach (rankingHistoricalFundingRates($config['category'], $symbol, $firstTimestamp, $lastTimestamp) as [$fundingTimestamp, $fundingRate]) {
            $low = 0;
            $high = $rowCount;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                $quoteTimestamp = (new DateTimeImmutable($rows[$middle]['received_at_utc'], new DateTimeZone('UTC')))->getTimestamp() * 1000;
                if ($quoteTimestamp < (int) $fundingTimestamp * 1000) $low = $middle + 1;
                else $high = $middle;
            }
            if ($low < $rowCount) {
                $markPrice = bcdiv(bcadd((string) $rows[$low]['bid_price'], (string) $rows[$low]['ask_price'], 24), '2', 24);
                $fundingEvents[] = ['timestamp' => (int) $fundingTimestamp, 'quote_index' => $low, 'mark_price' => $markPrice, 'rate' => (string) $fundingRate];
            }
        }
    } catch (Throwable $fundingError) {
        $error = 'Не удалось учесть funding: ' . $fundingError->getMessage();
    }
}
$fundingCashflowAtEvent = static function (string $quantity, bool $isShort, array $event): string {
    $cashflow = bcmul(bcmul($quantity, $event['mark_price'], 24), $event['rate'], 24);
    return $isShort ? $cashflow : bcsub('0', $cashflow, 24);
};
$hasContinuousWindow = static function (int $index) use ($rows, $momentumLookback): bool {
    if ($index < $momentumLookback) return false;
    for ($j = $index - $momentumLookback + 1; $j <= $index; $j++) {
        if (strtotime($rows[$j]['received_at_utc']) - strtotime($rows[$j - 1]['received_at_utc']) > 120) return false;
    }
    return true;
};
$findMomentumEntry = static function (int $fromIndex) use ($rows, $rowCount, $momentumLookback, $momentumThreshold, $entryDirection, $entrySignalMode, $entryDirectionMode, $hasContinuousWindow): ?array {
    for ($i = max($fromIndex, $momentumLookback + 1); $i < $rowCount - 1; $i++) {
        if (!$hasContinuousWindow($i) || !$hasContinuousWindow($i - 1)) continue;
        $priceNow = (float) $rows[$i]['last_price'];
        $pricePrevious = (float) $rows[$i - 1]['last_price'];
        if ($entrySignalMode === 'within') {
            $move = -INF;
            $previousMove = -INF;
            $negativeMove = INF;
            $previousNegativeMove = INF;
            for ($offset = 1; $offset <= $momentumLookback; $offset++) {
                $base = (float) $rows[$i - $offset]['last_price'];
                $previousBase = (float) $rows[$i - 1 - $offset]['last_price'];
                if ($base <= 0 || $previousBase <= 0) continue;
                $relative = ($priceNow / $base - 1) * 100;
                $previousRelative = ($pricePrevious / $previousBase - 1) * 100;
                $move = max($move, $relative);
                $previousMove = max($previousMove, $previousRelative);
                $negativeMove = min($negativeMove, $relative);
                $previousNegativeMove = min($previousNegativeMove, $previousRelative);
            }
        } else {
            $priceBefore = (float) $rows[$i - $momentumLookback]['last_price'];
            $pricePreviousBefore = (float) $rows[$i - $momentumLookback - 1]['last_price'];
            if ($priceBefore <= 0 || $pricePreviousBefore <= 0) continue;
            $move = ($priceNow / $priceBefore - 1) * 100;
            $previousMove = ($pricePrevious / $pricePreviousBefore - 1) * 100;
            $negativeMove = $move;
            $previousNegativeMove = $previousMove;
        }
        if ($entryDirection !== 'short' && $move >= $momentumThreshold && $previousMove < $momentumThreshold) return [$i, $entryDirectionMode === 'reverse'];
        if ($entryDirection !== 'long' && $negativeMove <= -$momentumThreshold && $previousNegativeMove > -$momentumThreshold) return [$i, $entryDirectionMode !== 'reverse'];
    }
    return null;
};
$findCompatibleEntry = static function (int $fromIndex, bool $wantedShort) use ($findMomentumEntry, $isImmediateEntry, $immediateDirection): ?array {
    if ($isImmediateEntry) return null;
    $cursor = $fromIndex;
    while (($candidate = $findMomentumEntry($cursor)) !== null) {
        if ($candidate[1] === $wantedShort) return $candidate;
        $cursor = $candidate[0] + 1;
    }
    return null;
};
if ($error !== null) {
    $trades = [];
    $balance = $startingBalance;
} elseif ($cachedStandardTradeEvents !== null) {
    $trades = $cachedStandardTradeEvents;
    $balance = $startingBalance;
} elseif ($martingaleMode !== 'none') {
    $chainAttempt = 0;
    $appendLegTrades = static function (array $legs, int $exitIndex, string $reason, string &$currentBalance) use (&$trades, &$chainAttempt, $executionRows, $feeRate, $martingaleMode): void {
        $exit = $executionRows[$exitIndex];
        foreach ($legs as $leg) {
            $short = $leg['is_short'];
            $exitPrice = $short ? $exit['ask_price'] : $exit['bid_price'];
            $move = $short ? bcsub($leg['entry_price'], $exitPrice, 24) : bcsub($exitPrice, $leg['entry_price'], 24);
            $gross = bcmul($leg['quantity'], $move, 24);
            $exitFee = bcmul(bcmul($leg['quantity'], $exitPrice, 24), $feeRate, 24);
            $net = bcadd(bcsub(bcsub($gross, $leg['entry_fee'], 24), $exitFee, 24), $leg['funding_paid'], 24);
            $currentBalance = bcadd($currentBalance, $net, 24);
            $trades[] = ['entry' => $leg['entry'], 'exit' => $exit, 'quantity' => $leg['quantity'], 'entry_price' => $leg['entry_price'], 'exit_price' => $exitPrice, 'entry_fee' => $leg['entry_fee'], 'exit_fee' => $exitFee, 'gross' => $gross, 'funding' => $leg['funding_paid'], 'net' => $net, 'balance' => $currentBalance, 'reason' => $reason, 'is_short' => $short, 'chain_id' => $chainAttempt];
        }
    };
    $finalScale = (string) (2 ** ($martingaleAttempts - 1));
    if (str_ends_with($martingaleMode, '_profit') || $lossThreshold === null) {
        $baseStake = bcdiv($startingBalance, $finalScale, 24);
    } else {
        $risk = bcmul(bccomp($lossThreshold, '0', 24) < 0 ? bcsub('0', $lossThreshold, 24) : $lossThreshold, '1', 24);
        if ($martingaleMode === 'simple') {
            $previousScale = (string) (2 ** ($martingaleAttempts - 2));
            $reservedLoss = bcmul($risk, $martingaleTargetPolicy === 'scaled' ? $previousScale : '1', 24);
            $requiredPerBase = bcadd($finalScale, bcmul($feeRate, $previousScale, 24), 24);
        } else {
            $lossSteps = $martingaleTargetPolicy === 'scaled'
                ? bcsub($finalScale, '1', 24)
                : (string) ($martingaleAttempts - 1);
            $reservedLoss = bcmul($risk, $lossSteps, 24);
            $requiredPerBase = bcmul($finalScale, bcadd('1', $feeRate, 24), 24);
        }
        $availableForStakes = bcsub($startingBalance, $reservedLoss, 24);
        $baseStake = bccomp($availableForStakes, '0', 24) > 0 ? bcdiv($availableForStakes, $requiredPerBase, 24) : '0';
    }
    $dailyRealizedByDay = [];
    while (bccomp($balance, '0', 24) > 0 && $chainAttempt < 100000 && $martingaleOrderConstraintError === null) {
        $signal = $isImmediateEntry
            ? ($chainAttempt === 0 && $rangeStartQuoteIndex < $rowCount - 1 ? [$rangeStartQuoteIndex, $immediateDirection === 'short'] : null)
            : $findMomentumEntry($tradeEntryIndex);
        if ($signal === null) break;
        [$tradeEntryIndex, $tradeIsShort] = $signal;
        $entryDay = (new DateTimeImmutable($executionRows[$tradeEntryIndex]['received_at_utc'], new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
        if ((float) $martingaleRiskLimits['max_day_loss'] > 0
            && isset($dailyRealizedByDay[$entryDay])
            && bccomp($dailyRealizedByDay[$entryDay], bcsub('0', $martingaleRiskLimits['max_day_loss'], 24), 24) <= 0) {
            $tradeEntryIndex++;
            continue;
        }
        $entryPrice = $tradeIsShort ? $executionRows[$tradeEntryIndex]['bid_price'] : $executionRows[$tradeEntryIndex]['ask_price'];
        if (bccomp($entryPrice, '0', 24) <= 0) { $tradeEntryIndex++; continue; }
        $availableInitialStake = bcdiv($balance, bcadd('1', $feeRate, 24), 24);
        $stake = bccomp($baseStake, $availableInitialStake, 24) < 0 ? $baseStake : $availableInitialStake;
        $chainBaseStake = $stake;
        $chainRealizedPnl = '0';
        $legs = [];
        $positionNotional = '0';
        $stage = 1;
        $addLeg = static function (int $index, bool $short, string &$notional) use (&$legs, &$positionNotional, &$martingaleOrderConstraintError, $executionRows, $feeRate, $martingaleContractRules): bool {
            $entry = $executionRows[$index];
            $price = $short ? $entry['bid_price'] : $entry['ask_price'];
            if (bccomp($price, '0', 24) <= 0 || bccomp($notional, '0', 24) <= 0) return false;
            $reservedBalance = bcadd($notional, bcmul($notional, $feeRate, 24), 24);
            $order = rankingQuantizeBitgetMarketOrder($notional, $price, $martingaleContractRules ?? [], $feeRate, $reservedBalance);
            if ($order === null) {
                $martingaleOrderConstraintError = 'Один из ордеров мартингейла не проходит текущие правила Bitget или не помещается в доступный баланс. Вариант исключён.';
                return false;
            }
            $notional = $order['notional'];
            $qty = $order['quantity'];
            $entryFee = bcmul($notional, $feeRate, 24);
            $legs[] = ['entry' => $entry, 'entry_price' => $price, 'quantity' => $qty, 'entry_fee' => $entryFee, 'notional' => $notional, 'is_short' => $short, 'entry_timestamp' => (new DateTimeImmutable($entry['received_at_utc'], new DateTimeZone('UTC')))->getTimestamp(), 'funding_paid' => '0'];
            $positionNotional = bcadd($positionNotional, $notional, 24);
            return true;
        };
        if (!$addLeg($tradeEntryIndex, $tradeIsShort, $stake)) break;
        $chainBaseStake = $stake;
        $fundingCursor = 0;
        while ($fundingCursor < count($fundingEvents) && $fundingEvents[$fundingCursor]['quote_index'] <= $tradeEntryIndex) $fundingCursor++;

        while ($legs !== []) {
            $exitIndex = $rowCount - 1;
            $exitReason = $isCurrentDay ? 'Последний доступный снимок' : 'Конец выбранного периода';
            $stageScale = bcdiv($positionNotional, $chainBaseStake, 24);
            $targetScale = $martingaleTargetPolicy === 'scaled' ? $stageScale : '1';
            $stageTarget = $profitTarget === null ? null : bcmul($profitTarget, $targetScale, 24);
            $stageLoss = $lossThreshold === null ? null : bcmul($lossThreshold, $targetScale, 24);
            $takeConfirmationIndex = null;
            $stopConfirmationIndex = null;
            $foundStop = false;
            $hitRiskLimit = false;
            for ($i = $tradeEntryIndex + 1; $i < $rowCount; $i++) {
                while ($fundingCursor < count($fundingEvents) && $fundingEvents[$fundingCursor]['quote_index'] <= $i) {
                    $event = $fundingEvents[$fundingCursor];
                    foreach ($legs as &$fundingLeg) {
                        if ($fundingLeg['entry_timestamp'] < $event['timestamp']) {
                            $fundingLeg['funding_paid'] = bcadd($fundingLeg['funding_paid'], $fundingCashflowAtEvent($fundingLeg['quantity'], $tradeIsShort, $event), 24);
                        }
                    }
                    unset($fundingLeg);
                    $fundingCursor++;
                }
                $exitQuote = $tradeIsShort ? $executionRows[$i]['ask_price'] : $executionRows[$i]['bid_price'];
                $grossNow = '0'; $entryFeesNow = '0'; $totalQty = '0';
                $fundingNetNow = '0';
                foreach ($legs as $leg) {
                    $move = $tradeIsShort ? bcsub($leg['entry_price'], $exitQuote, 24) : bcsub($exitQuote, $leg['entry_price'], 24);
                    $grossNow = bcadd($grossNow, bcmul($leg['quantity'], $move, 24), 24);
                    $entryFeesNow = bcadd($entryFeesNow, $leg['entry_fee'], 24);
                    $totalQty = bcadd($totalQty, $leg['quantity'], 24);
                    $fundingNetNow = bcadd($fundingNetNow, $leg['funding_paid'], 24);
                }
                $exitFeeNow = bcmul(bcmul($totalQty, $exitQuote, 24), $feeRate, 24);
                $netNow = bcadd(bcsub(bcsub($grossNow, $entryFeesNow, 24), $exitFeeNow, 24), $fundingNetNow, 24);
                $quoteDay = (new DateTimeImmutable($executionRows[$i]['received_at_utc'], new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
                $projectedChainPnl = bcadd($chainRealizedPnl, $netNow, 24);
                $projectedDayPnl = bcadd((string) ($dailyRealizedByDay[$quoteDay] ?? '0'), $projectedChainPnl, 24);
                if ((float) $martingaleRiskLimits['max_chain_loss'] > 0
                    && bccomp($projectedChainPnl, bcsub('0', $martingaleRiskLimits['max_chain_loss'], 24), 24) <= 0) {
                    $exitIndex = $i;
                    $exitReason = 'Мартингейл · лимит убытка цепочки';
                    $hitRiskLimit = true;
                    break;
                }
                if ((float) $martingaleRiskLimits['max_day_loss'] > 0
                    && bccomp($projectedDayPnl, bcsub('0', $martingaleRiskLimits['max_day_loss'], 24), 24) <= 0) {
                    $exitIndex = $i;
                    $exitReason = 'Мартингейл · дневной лимит убытка';
                    $hitRiskLimit = true;
                    break;
                }
                $takeCondition = $stageTarget !== null && bccomp($netNow, $stageTarget, 24) >= 0;
                $stopCondition = $stageLoss !== null && bccomp($netNow, $stageLoss, 24) <= 0;
                if ($takeCondition && $takeConfirmationIndex === null) $takeConfirmationIndex = $i + $takeConfirmationDelay;
                if ($stopCondition && $stopConfirmationIndex === null) $stopConfirmationIndex = $i + $stopConfirmationDelay;
                if ($takeConfirmationDelay === 0 && $takeCondition) {
                    $exitIndex = $i; $exitReason = 'Мартингейл · цель чистой прибыли ' . $formatTargetPercent($stageTarget, $startingBalance) . ' от стартового баланса';
                    $foundStop = str_ends_with($martingaleMode, '_profit');
                    break;
                }
                if ($stopConfirmationDelay === 0 && $stopCondition) {
                    $exitIndex = $i; $exitReason = 'Мартингейл · стоп чистого убытка ' . $formatTargetPercent(ltrim($stageLoss, '-'), $startingBalance) . ' от стартового баланса';
                    $foundStop = !str_ends_with($martingaleMode, '_profit');
                    break;
                }
                if ($takeConfirmationIndex !== null && $i >= $takeConfirmationIndex) {
                    if ($takeCondition) {
                        $exitIndex = $i; $exitReason = 'Мартингейл · цель чистой прибыли ' . $formatTargetPercent($stageTarget, $startingBalance) . ' от стартового баланса';
                        $foundStop = str_ends_with($martingaleMode, '_profit');
                        break;
                    }
                    $takeConfirmationIndex = null;
                }
                if ($stopConfirmationIndex !== null && $i >= $stopConfirmationIndex) {
                    if ($stopCondition) {
                        $exitIndex = $i; $exitReason = 'Мартингейл · стоп чистого убытка ' . $formatTargetPercent(ltrim($stageLoss, '-'), $startingBalance) . ' от стартового баланса';
                        $foundStop = !str_ends_with($martingaleMode, '_profit');
                        break;
                    }
                    $stopConfirmationIndex = null;
                }
            }
            if ($hitRiskLimit || !$foundStop || $stage >= $martingaleAttempts) {
                $balanceBeforeClose = $balance;
                $appendLegTrades($legs, $exitIndex, $exitReason, $balance);
                $closedNet = bcsub($balance, $balanceBeforeClose, 24);
                $exitDay = (new DateTimeImmutable($executionRows[$exitIndex]['received_at_utc'], new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d');
                $dailyRealizedByDay[$exitDay] = bcadd(
                    (string) ($dailyRealizedByDay[$exitDay] ?? '0'),
                    bcadd($chainRealizedPnl, $closedNet, 24),
                    24,
                );
                $legs = [];
                $tradeEntryIndex = $exitIndex + 1;
                break;
            }

            $exitQuote = $tradeIsShort ? $executionRows[$exitIndex]['ask_price'] : $executionRows[$exitIndex]['bid_price'];
            $grossNow = '0'; $entryFeesNow = '0'; $totalQty = '0'; $fundingNetNow = '0';
            foreach ($legs as $leg) {
                $move = $tradeIsShort ? bcsub($leg['entry_price'], $exitQuote, 24) : bcsub($exitQuote, $leg['entry_price'], 24);
                $grossNow = bcadd($grossNow, bcmul($leg['quantity'], $move, 24), 24);
                $entryFeesNow = bcadd($entryFeesNow, $leg['entry_fee'], 24);
                $totalQty = bcadd($totalQty, $leg['quantity'], 24);
                $fundingNetNow = bcadd($fundingNetNow, $leg['funding_paid'], 24);
            }
            $unrealized = bcadd(bcsub(bcsub($grossNow, $entryFeesNow, 24), bcmul(bcmul($totalQty, $exitQuote, 24), $feeRate, 24), 24), $fundingNetNow, 24);
            if ($martingaleMode === 'simple_profit') {
                $closedNotional = $positionNotional;
                $appendLegTrades($legs, $exitIndex, $exitReason, $balance);
                $legs = [];
                $nextIndex = $exitIndex;
                if ($martingaleTiming === 'rules') {
                    $nextSignal = $findCompatibleEntry($exitIndex + 1, $tradeIsShort);
                    if ($nextSignal === null) { $tradeEntryIndex = $rowCount; break; }
                    [$nextIndex] = $nextSignal;
                }
                $nextStake = bcmul($closedNotional, '2', 24);
                $availableStake = bcdiv($balance, bcadd('1', $feeRate, 24), 24);
                if (bccomp($nextStake, $availableStake, 24) > 0) $nextStake = $availableStake;
                if (!$addLeg($nextIndex, $tradeIsShort, $nextStake)) { $tradeEntryIndex = $exitIndex + 1; break; }
                $positionNotional = $nextStake;
                $stage++;
                $tradeEntryIndex = $nextIndex;
                continue;
            }

            if ($martingaleMode === 'simple') {
                $equity = bcadd($balance, $unrealized, 24);
                $freeMargin = bcsub($equity, $positionNotional, 24);
                $maxAffordable = bcdiv($freeMargin, bcadd('1', $feeRate, 24), 24);
                $addNotional = bccomp($maxAffordable, $positionNotional, 24) < 0 ? $maxAffordable : $positionNotional;
                if ($martingaleTiming === 'rules') {
                    $nextSignal = $findCompatibleEntry($exitIndex + 1, $tradeIsShort);
                    $nextIndex = $nextSignal === null ? $rowCount : $nextSignal[0];
                    $takeBeforeSignal = null;
                    if ($stageTarget !== null) {
                        for ($i = $exitIndex + 1; $i < min($nextIndex, $rowCount); $i++) {
                            while ($fundingCursor < count($fundingEvents) && $fundingEvents[$fundingCursor]['quote_index'] <= $i) {
                                $event = $fundingEvents[$fundingCursor];
                                foreach ($legs as &$fundingLeg) {
                                    if ($fundingLeg['entry_timestamp'] < $event['timestamp']) {
                                        $fundingLeg['funding_paid'] = bcadd($fundingLeg['funding_paid'], $fundingCashflowAtEvent($fundingLeg['quantity'], $tradeIsShort, $event), 24);
                                    }
                                }
                                unset($fundingLeg);
                                $fundingCursor++;
                            }
                            $waitQuote = $tradeIsShort ? $executionRows[$i]['ask_price'] : $executionRows[$i]['bid_price'];
                            $waitGross = '0'; $waitEntryFees = '0'; $waitQuantity = '0';
                            foreach ($legs as $leg) {
                                $waitMove = $tradeIsShort ? bcsub($leg['entry_price'], $waitQuote, 24) : bcsub($waitQuote, $leg['entry_price'], 24);
                                $waitGross = bcadd($waitGross, bcmul($leg['quantity'], $waitMove, 24), 24);
                                $waitEntryFees = bcadd($waitEntryFees, $leg['entry_fee'], 24);
                                $waitQuantity = bcadd($waitQuantity, $leg['quantity'], 24);
                            }
                            $waitNet = bcsub(
                                bcsub($waitGross, $waitEntryFees, 24),
                                bcmul(bcmul($waitQuantity, $waitQuote, 24), $feeRate, 24),
                                24,
                            );
                            foreach ($legs as $leg) $waitNet = bcadd($waitNet, $leg['funding_paid'], 24);
                            if (bccomp($waitNet, $stageTarget, 24) >= 0) {
                                $takeBeforeSignal = $i;
                                break;
                            }
                        }
                    }
                    if ($takeBeforeSignal !== null) {
                        $appendLegTrades($legs, $takeBeforeSignal, 'Мартингейл · цель прибыли при ожидании сигнала', $balance);
                        $legs = [];
                        $tradeEntryIndex = $takeBeforeSignal + 1;
                        break;
                    }
                    if ($nextSignal === null) {
                        $appendLegTrades($legs, $rowCount - 1, $isCurrentDay ? 'Мартингейл · ожидание сигнала · последний снимок' : 'Мартингейл · ожидание сигнала · конец периода', $balance);
                        $legs = []; $tradeEntryIndex = $rowCount; break;
                    }
                    $signalQuote = $tradeIsShort ? $executionRows[$nextIndex]['ask_price'] : $executionRows[$nextIndex]['bid_price'];
                    $signalGross = '0'; $signalFees = '0'; $signalQty = '0';
                    foreach ($legs as $leg) {
                        $signalMove = $tradeIsShort ? bcsub($leg['entry_price'], $signalQuote, 24) : bcsub($signalQuote, $leg['entry_price'], 24);
                        $signalGross = bcadd($signalGross, bcmul($leg['quantity'], $signalMove, 24), 24);
                        $signalFees = bcadd($signalFees, $leg['entry_fee'], 24);
                        $signalQty = bcadd($signalQty, $leg['quantity'], 24);
                    }
                    $signalNet = bcsub(bcsub($signalGross, $signalFees, 24), bcmul(bcmul($signalQty, $signalQuote, 24), $feeRate, 24), 24);
                    foreach ($legs as $leg) $signalNet = bcadd($signalNet, $leg['funding_paid'], 24);
                    $signalEquity = bcadd($balance, $signalNet, 24);
                    $signalFreeMargin = bcsub($signalEquity, $positionNotional, 24);
                    $maxSignalAffordable = bcdiv($signalFreeMargin, bcadd('1', $feeRate, 24), 24);
                    $addNotional = bccomp($maxSignalAffordable, $positionNotional, 24) < 0 ? $maxSignalAffordable : $positionNotional;
                } else $nextIndex = $exitIndex;
                if (bccomp($addNotional, '0', 24) <= 0 || !$addLeg($nextIndex, $tradeIsShort, $addNotional)) {
                    $appendLegTrades($legs, $exitIndex, 'Недостаточно свободного баланса для следующего шага мартингейла', $balance);
                    $legs = []; $tradeEntryIndex = $exitIndex + 1; break;
                }
                $stage++;
                $tradeEntryIndex = $nextIndex;
                continue;
            }

            $closedNotional = $positionNotional;
            $appendLegTrades($legs, $exitIndex, $exitReason, $balance);
            $legs = [];
            $positionNotional = '0';
            $reverseSide = !$tradeIsShort;
            if ($martingaleTiming === 'rules') {
                $nextSignal = $findCompatibleEntry($exitIndex + 1, $reverseSide);
                if ($nextSignal === null) { $tradeEntryIndex = $rowCount; break; }
                [$nextIndex] = $nextSignal;
            } else $nextIndex = $exitIndex;
            $nextStake = bcmul($closedNotional, '2', 24);
            $availableStake = bcdiv($balance, bcadd('1', $feeRate, 24), 24);
            if (bccomp($nextStake, $availableStake, 24) > 0) $nextStake = $availableStake;
            if (!$addLeg($nextIndex, $reverseSide, $nextStake)) { $tradeEntryIndex = $exitIndex + 1; break; }
            $tradeIsShort = $reverseSide;
            $stage++;
            $tradeEntryIndex = $nextIndex;
        }
        $chainAttempt++;
    }
    if ($martingaleOrderConstraintError !== null) {
        $trades = [];
        $balance = $startingBalance;
    }
} else {
$ordinaryOrderConstraintError = null;
while (bccomp($balance, '0', 24) > 0) {
    if ($isImmediateEntry) {
        $signal = $tradeEntryIndex === $rangeStartQuoteIndex && $rangeStartQuoteIndex < $rowCount - 1
            ? [$rangeStartQuoteIndex, $immediateDirection === 'short'] : null;
    } else {
        $signal = $findMomentumEntry($tradeEntryIndex);
    }
    if ($signal === null) break;
    [$tradeEntryIndex, $tradeIsShort] = $signal;

    $entry = $executionRows[$tradeEntryIndex];
    $entryPrice = $tradeIsShort ? $entry['bid_price'] : $entry['ask_price'];
    $requestedNotional = bcdiv($balance, bcadd('1', $feeRate, 24), 24);
    if (bccomp($requestedNotional, $startingBalance, 24) > 0) $requestedNotional = $startingBalance;
    $order = rankingCheckBitgetMarketOrder($requestedNotional, $entryPrice, $martingaleContractRules ?? [], $feeRate, $balance);
    if (isset($order['error'])) {
        $ordinaryOrderConstraintError = 'В симуляции заявка ' . $symbol . ' на ' . $entry['received_at_utc'] . ' UTC не проходит проверку: ' . $order['error'] . '. Вариант исключён.';
        break;
    }
    $quantity = $order['quantity'];
    $entryFee = bcmul($order['notional'], $feeRate, 24);
    $entryTimestamp = (new DateTimeImmutable($entry['received_at_utc'], new DateTimeZone('UTC')))->getTimestamp();
    $fundingPaid = '0';
    $fundingCursor = 0;
    while ($fundingCursor < count($fundingEvents) && $fundingEvents[$fundingCursor]['quote_index'] <= $tradeEntryIndex) $fundingCursor++;
    $exitIndex = $rowCount - 1;
    $exitReason = 'Открыта · последний снимок выбранного периода';
    $tradeNet = '0';
    $takeConfirmationIndex = null;
    $stopConfirmationIndex = null;
    for ($i = $tradeEntryIndex + 1; $i < $rowCount; $i++) {
        while ($fundingCursor < count($fundingEvents) && $fundingEvents[$fundingCursor]['quote_index'] <= $i) {
            $event = $fundingEvents[$fundingCursor];
            if ($entryTimestamp < $event['timestamp']) $fundingPaid = bcadd($fundingPaid, $fundingCashflowAtEvent($quantity, $tradeIsShort, $event), 24);
            $fundingCursor++;
        }
        $exitPrice = $tradeIsShort ? $executionRows[$i]['ask_price'] : $executionRows[$i]['bid_price'];
        $priceMove = $tradeIsShort ? bcsub($entryPrice, $exitPrice, 24) : bcsub($exitPrice, $entryPrice, 24);
        $gross = bcmul($quantity, $priceMove, 24);
        $exitFee = bcmul(bcmul($quantity, $exitPrice, 24), $feeRate, 24);
        $tradeNet = bcadd(bcsub(bcsub($gross, $entryFee, 24), $exitFee, 24), $fundingPaid, 24);
        $takeCondition = $profitTarget !== null && bccomp($tradeNet, $profitTarget, 24) >= 0;
        $stopCondition = $lossThreshold !== null && bccomp($tradeNet, $lossThreshold, 24) <= 0;
        if ($takeCondition && $takeConfirmationIndex === null) $takeConfirmationIndex = $i + $takeConfirmationDelay;
        if ($stopCondition && $stopConfirmationIndex === null) $stopConfirmationIndex = $i + $stopConfirmationDelay;
        if ($takeConfirmationIndex !== null && $i >= $takeConfirmationIndex) {
            if ($takeCondition) {
                $exitIndex = $i;
                $exitReason = 'Цель: чистая прибыль ' . $formatTargetPercent($profitTarget, $startingBalance) . ' от стартового баланса';
                break;
            }
            $takeConfirmationIndex = null;
        }
        if ($stopConfirmationIndex !== null && $i >= $stopConfirmationIndex) {
            if ($stopCondition) {
                $exitIndex = $i;
                $exitReason = 'Стоп: чистый убыток ' . $formatTargetPercent(ltrim($lossThreshold, '-'), $startingBalance) . ' от стартового баланса';
                break;
            }
            $stopConfirmationIndex = null;
        }
    }
    $exit = $executionRows[$exitIndex];
    $exitPrice = $tradeIsShort ? $exit['ask_price'] : $exit['bid_price'];
    $priceMove = $tradeIsShort ? bcsub($entryPrice, $exitPrice, 24) : bcsub($exitPrice, $entryPrice, 24);
    $gross = bcmul($quantity, $priceMove, 24);
    $exitFee = bcmul(bcmul($quantity, $exitPrice, 24), $feeRate, 24);
    $tradeNet = bcadd(bcsub(bcsub($gross, $entryFee, 24), $exitFee, 24), $fundingPaid, 24);
    $balance = bcadd($balance, $tradeNet, 24);
    $trades[] = ['entry' => $entry, 'exit' => $exit, 'quantity' => $quantity, 'entry_price' => $entryPrice, 'exit_price' => $exitPrice, 'entry_fee' => $entryFee, 'exit_fee' => $exitFee, 'gross' => $gross, 'funding' => $fundingPaid, 'net' => $tradeNet, 'balance' => $balance, 'reason' => $exitReason, 'is_short' => $tradeIsShort];

    // Wait for a new momentum threshold crossing after each closed trade.
    $tradeEntryIndex = $exitIndex + 1;
}
if ($ordinaryOrderConstraintError !== null) {
    $trades = [];
    $balance = $startingBalance;
    $error = $ordinaryOrderConstraintError;
}
}
$martingaleChainResults = [];
if ($martingaleMode !== 'none') {
    foreach ($trades as $trade) {
        $chainId = (int) ($trade['chain_id'] ?? 0);
        $martingaleChainResults[$chainId] = bcadd($martingaleChainResults[$chainId] ?? '0', $trade['net'], 24);
    }
    ksort($martingaleChainResults, SORT_NUMERIC);
}
$martingaleChainCount = count($martingaleChainResults);
$martingaleWorstChain = $martingaleChainCount > 0 ? min($martingaleChainResults) : null;
$martingaleMedianChain = null;
$martingaleMaxDrawdown = '0';
if ($martingaleChainCount > 0) {
    $sortedChainResults = array_values($martingaleChainResults);
    usort($sortedChainResults, static fn (string $a, string $b): int => bccomp($a, $b, 24));
    $middle = intdiv($martingaleChainCount, 2);
    $martingaleMedianChain = $martingaleChainCount % 2 === 1
        ? $sortedChainResults[$middle]
        : bcdiv(bcadd($sortedChainResults[$middle - 1], $sortedChainResults[$middle], 24), '2', 24);
    $chainEquity = $startingBalance;
    $chainPeak = $startingBalance;
    foreach ($martingaleChainResults as $chainResult) {
        $chainEquity = bcadd($chainEquity, $chainResult, 24);
        if (bccomp($chainEquity, $chainPeak, 24) > 0) $chainPeak = $chainEquity;
        $drawdown = bcsub($chainPeak, $chainEquity, 24);
        if (bccomp($drawdown, $martingaleMaxDrawdown, 24) > 0) $martingaleMaxDrawdown = $drawdown;
    }
}
if ($martingaleMode === 'none' && $cachedStandardTradeEvents === null
    && $closedTradeLedgerKey !== null && $ledgerSourceDay !== null) {
    try {
        $closedByDay = [];
        foreach ($trades as $trade) {
            if (cachedTradeIsOpen($trade)) continue;
            $closeDay = (new DateTimeImmutable($trade['exit']['received_at_utc'], new DateTimeZone('UTC')))
                ->setTimezone($timezone)->format('Y-m-d');
            $closedByDay[$closeDay][] = $trade;
        }
        $previousFingerprint = null;
        $previousQuoteCount = 0;
        $lastQuote = $rows[0];
        $ledgerDay = new DateTimeImmutable($ledgerSourceDay, $timezone);
        $ledgerEndDay = new DateTimeImmutable($endDayInput, $timezone);
        while ($ledgerDay <= $ledgerEndDay) {
            $day = $ledgerDay->format('Y-m-d');
            if (isset($ledgerFingerprints[$day])) {
                $previousFingerprint = $ledgerFingerprints[$day];
                $previousQuoteCount = $ledgerQuoteCounts[$day];
                $lastQuote = $ledgerLastQuotes[$day];
            }
            $dayFingerprint = $previousFingerprint ?? hash('sha256', 'empty:' . $closedTradeLedgerKey);
            $openTrade = null;
            $lastQuoteAt = new DateTimeImmutable($lastQuote['received_at_utc'], new DateTimeZone('UTC'));
            foreach ($trades as $trade) {
                $entryAt = new DateTimeImmutable($trade['entry']['received_at_utc'], new DateTimeZone('UTC'));
                $exitAt = new DateTimeImmutable($trade['exit']['received_at_utc'], new DateTimeZone('UTC'));
                if ($entryAt <= $lastQuoteAt
                    && (cachedTradeIsOpen($trade) || $exitAt > $lastQuoteAt)) {
                    $openTrade = markTradeAtQuote($trade, $lastQuote, $feeRate, $fundingEvents, (float) $simulationCosts['slippage_bps']);
                    break;
                }
            }
            $path = $closedTradeLedgerRoot . '/' . $closedTradeLedgerKey . '-' . str_replace('-', '', $day) . '.json';
            writeClosedTradeDay($path, [
                'version' => 1,
                'day' => $day,
                'quote_count' => $previousQuoteCount,
                'quote_prefix_sha256' => $dayFingerprint,
                'closed_trades' => $closedByDay[$day] ?? [],
                'open_trade' => $openTrade,
            ]);
            $ledgerDay = $ledgerDay->modify('+1 day');
        }
    } catch (Throwable $ledgerWriteError) {
        error_log('Closed trade ledger write failed: ' . $ledgerWriteError->getMessage());
    }
}
if ($martingaleMode === 'none') {
    $rangeTrades = [];
    $rangeBalance = $startingBalance;
    $rangeStartAt = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
    $rangeEndAt = new DateTimeImmutable($endUtc, new DateTimeZone('UTC'));
    foreach ($trades as $trade) {
        $openedAt = new DateTimeImmutable($trade['entry']['received_at_utc'], new DateTimeZone('UTC'));
        $closedAt = new DateTimeImmutable($trade['exit']['received_at_utc'], new DateTimeZone('UTC'));
        if ($openedAt < $rangeStartAt || $openedAt >= $rangeEndAt
            || $closedAt < $rangeStartAt || $closedAt >= $rangeEndAt) {
            continue;
        }
        $rangeBalance = bcadd($rangeBalance, $trade['net'], 24);
        $trade['balance'] = $rangeBalance;
        $rangeTrades[] = $trade;
    }
    $trades = $rangeTrades;
    $balance = $rangeBalance;
}
$totalPnl = bcsub($balance, $startingBalance, 24);
$isOpenTrade = static fn (array $trade): bool => str_starts_with($trade['reason'], 'Открыта ·');
$openTradeCount = count(array_filter($trades, $isOpenTrade));
$closedTradeCount = count($trades) - $openTradeCount;
$entrySideLabel = 'Сторона · цена';
$exitSideLabel = 'Сторона · цена';
$dateRangeLabel = $startDayInput === $endDayInput ? $startDayInput : $startDayInput . ' — ' . $endDayInput;
$autotradingUrl = 'autotrading.php?' . http_build_query([
    'category' => $config['category'], 'symbol' => $symbol, 'start_date' => $startDayInput, 'end_date' => $endDayInput,
    'entry_config' => $entryConfig, 'entry_signal_mode' => $entrySignalMode, 'entry_direction_mode' => $entryDirectionMode,
    'exit_config' => $exitConfig, 'martingale_mode' => $martingaleMode, 'martingale_timing' => $martingaleTiming,
    'martingale_attempts' => $martingaleAttempts, 'martingale_target_policy' => $martingaleTargetPolicy,
    'max_chain_loss_usdt' => $martingaleRiskLimits['max_chain_loss'], 'strategy_max_day_loss_usdt' => $martingaleRiskLimits['max_day_loss'],
    'exit_confirmation_mode' => $exitConfirmationMode, 'exit_confirmation_snapshots' => $exitConfirmationSnapshots,
    'balance' => $startingBalance, 'fee' => $feePercent, 'slippage_bps' => $simulationCosts['slippage_bps'],
    'include_funding' => $simulationCosts['include_funding'] ? '1' : '0', 'net_result_usdt' => $totalPnl, 'trade_count' => $closedTradeCount,
]);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bitget — история котировок</title>
<style>
:root{color-scheme:dark;--bg:#0b1020;--panel:#131a2d;--muted:#8995ad;--text:#e9eefb;--line:#273149}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 15% 0%,#1b2850 0,transparent 45%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1200px;margin:0 auto;padding:42px 24px}.eyebrow{color:#8ea5ff;text-transform:uppercase;letter-spacing:.13em;font-size:12px;font-weight:700}h1{font-size:clamp(26px,4vw,40px);margin:5px 0 16px;letter-spacing:-.04em}.collector-status{display:inline-flex;align-items:center;gap:9px;margin:0 0 22px;padding:7px 11px;border:1px solid #34415e;border-radius:999px;background:#0c1222;color:#b7c1d5;font-size:13px}.collector-status:before{content:"";width:9px;height:9px;border-radius:50%;background:#8995ad;box-shadow:0 0 0 3px #8995ad22}.collector-status[data-status="active"]{color:#a8f0cf;border-color:#28654f}.collector-status[data-status="active"]:before{background:#42d6a4;box-shadow:0 0 0 3px #42d6a422}.collector-status[data-status="delayed"]{color:#ffe0a1;border-color:#705b2e}.collector-status[data-status="delayed"]:before{background:#f4c15f;box-shadow:0 0 0 3px #f4c15f22}.collector-status[data-status="fresh-yellow"],.collector-status[data-status="delayed"]{color:#ffe0a1;border-color:#705b2e}.collector-status[data-status="fresh-yellow"]:before{background:#f4c15f;box-shadow:0 0 0 3px #f4c15f22}.collector-status[data-status="stale-green"]{color:#a8f0cf;border-color:#28654f}.collector-status[data-status="stale-green"]:before{background:#42d6a4;box-shadow:0 0 0 3px #42d6a422}.collector-status[data-status="stale-red"]{color:#ffb3bf;border-color:#733b49}.collector-status[data-status="stale-red"]:before{background:#ff7188;box-shadow:0 0 0 3px #ff718822}.collector-status[data-status="stopped"],.collector-status[data-status="unknown"]{color:#ffb3bf;border-color:#733b49}.collector-status[data-status="stopped"]:before,.collector-status[data-status="unknown"]:before{background:#ff7188;box-shadow:0 0 0 3px #ff718822}.panel{background:color-mix(in srgb,var(--panel) 95%,transparent);border:1px solid var(--line);border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:0 18px 50px #0003}.filters{display:flex;align-items:end;gap:14px;flex-wrap:wrap}.field{display:grid;gap:7px;color:var(--muted);font-size:13px;font-weight:600}select,input,button{font:inherit;color:var(--text);background:#0c1222;border:1px solid #34415e;border-radius:10px;padding:11px 13px;min-width:190px}select option.demo-available-ticker{color:#f2d77d;background:#292716}button{background:#607df4;border:0;font-weight:700;cursor:pointer;min-width:130px}button:hover{background:#7890ff}.hint{color:var(--muted);margin:15px 0 0;font-size:13px}.chart-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:8px}.chart-head h2{font-size:17px;margin:0}.count{color:var(--muted);font-size:13px}.legend{display:flex;gap:18px;color:#b7c1d5;font-size:13px;margin:14px 0}.legend span{display:inline-flex;gap:7px;align-items:center}.dot{width:9px;height:9px;border-radius:50%}#chart{width:100%;height:clamp(180px,34vh,300px);display:block;overflow:visible}.chart-wrap{position:relative}.chart-panel-sticky{position:sticky;top:10px;z-index:5;background:#131a2d;box-shadow:0 12px 32px #0009}#tooltip{position:absolute;z-index:2;display:none;min-width:180px;padding:10px 12px;border:1px solid #394765;border-radius:10px;background:#0b1020f2;box-shadow:0 10px 30px #0008;pointer-events:none;font-size:12px;white-space:nowrap}#tooltip .tip-time{color:#dce5ff;font-weight:700;margin-bottom:5px}#tooltip .tip-row{display:flex;justify-content:space-between;gap:18px;font-variant-numeric:tabular-nums}#tooltip b{font-weight:700}.empty{display:grid;place-items:center;min-height:230px;color:var(--muted);text-align:center}.error{border-color:#733b49;color:#ffb3bf}.footer{color:var(--muted);font-size:12px;margin:0 4px}#results{padding-bottom:12px}#results .footer{margin:7px 4px 0}@media(max-width:600px){.wrap{padding:25px 14px}.panel{padding:16px}select,input{width:100%}.field{width:100%}button{width:100%}#chart{height:clamp(150px,27vh,220px)}.chart-panel-sticky{top:4px}.chart-head{align-items:flex-start;flex-direction:column}}
.filters .field select,.filters .field input{min-width:150px}.strategy-link{color:#a9b9ff;text-decoration:none}.strategy-link:hover{text-decoration:underline}.summary{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 16px}.stat{flex:1;min-width:150px;background:#0c1222;border:1px solid var(--line);border-radius:12px;padding:13px 15px}.stat span{display:block;color:var(--muted);font-size:12px}.stat strong{font-size:19px;font-variant-numeric:tabular-nums}.table-wrap{overflow:auto;border-radius:10px}table{width:100%;min-width:1000px;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}th,td{text-align:right;padding:11px 10px;border-bottom:1px solid var(--line);white-space:nowrap}th{color:#aab5cb;font-size:11px;text-transform:uppercase;letter-spacing:.04em;background:#0d1425;position:sticky;top:0}th:first-child,td:first-child,td.reason{text-align:left}.trade-positive td{background:#123126;color:#b9f2dc}.trade-negative td{background:#351e2a;color:#ffd0d5}.trade-open-positive td{background:#06291d;color:#83e8b8}.trade-open-negative td{background:#290810;color:#ff929d}.trade-flat td{background:#222b3d}.trade-positive td:first-child,.trade-open-positive td:first-child{box-shadow:inset 3px 0 #48d6a4}.trade-negative td:first-child,.trade-open-negative td:first-child{box-shadow:inset 3px 0 #ff7788}.trade-row{cursor:crosshair}.trade-row:hover td,.trade-row:focus td{filter:brightness(1.22)}.small-note{font-size:12px;color:var(--muted);margin:12px 0 0}.loss{color:#ff7788}.gain{color:#48d6a4}
.refresh-symbols{display:grid;place-items:center;flex:0 0 42px;width:42px;height:42px;min-width:42px;padding:4px;font-size:20px;line-height:1}.refresh-symbols.is-loading{cursor:progress;background:#273653}.refresh-progress{position:relative;display:grid;place-items:center;width:34px;height:34px;font-size:9px;font-weight:700;font-variant-numeric:tabular-nums}.refresh-progress:before{position:absolute;inset:1px;border:2px solid #52617d;border-top-color:#a9b9ff;border-radius:50%;content:"";animation:ranking-spin .8s linear infinite}.refresh-progress-value{position:relative;z-index:1}@keyframes ranking-spin{to{transform:rotate(360deg)}}
.history-import-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:0 0 100%;width:100%;min-width:0}.history-import-button{min-width:0;min-height:42px;padding:9px 12px;white-space:nowrap;font-size:13px}.history-import-button[hidden]{display:none}.history-import-button:disabled{cursor:progress;opacity:.65}.history-import-progress{display:flex;flex-direction:column;align-items:flex-start;gap:8px;flex:0 0 100%;width:100%;min-width:0;margin-top:8px}.history-import-status{width:100%;min-width:0;min-height:0;margin:0;color:var(--muted);font-size:12px;white-space:pre-line;overflow-wrap:anywhere}.history-import-status:empty{display:none}.history-import-status[data-status="error"]{color:#ff9eaa}.history-import-status[data-status="ready"]{color:#a8f0cf}
.filters>.history-import-status{flex:0 0 100%;width:100%}
.refresh-today-snapshot[hidden]{display:none}.refresh-today-snapshot:disabled{cursor:progress;opacity:.65}
.page-heading{display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:22px}.page-heading .eyebrow{white-space:nowrap}.header-actions{display:flex;align-items:center;justify-content:flex-end;gap:14px;flex-wrap:wrap}.collector-status{margin:0;min-width:0;padding:7px 11px;border:1px solid #34415e;background:#0c1222;font-weight:400;white-space:nowrap;cursor:pointer}.collector-status:hover{background:#0c1222;filter:brightness(1.2)}.collector-status:disabled{cursor:progress;opacity:.7}.save-mode-toggle{display:inline-flex;align-items:center;gap:9px;color:var(--text);font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap}.save-mode-toggle input{position:absolute;opacity:0;width:1px;height:1px;min-width:0}.save-mode-switch{position:relative;width:38px;height:22px;border:1px solid #52617d;border-radius:999px;background:#263149;transition:.2s}.save-mode-switch:after{content:"";position:absolute;left:2px;top:2px;width:16px;height:16px;border-radius:50%;background:#b7c1d5;transition:.2s}.save-mode-toggle input:checked+.save-mode-switch{background:#176647;border-color:#42d6a4}.save-mode-toggle input:checked+.save-mode-switch:after{transform:translateX(16px);background:#42d6a4}.save-mode-toggle input:focus-visible+.save-mode-switch{outline:2px solid #8ea5ff;outline-offset:2px}.save-mode-detail{color:var(--muted);font-weight:400}@media(max-width:700px){.page-heading{align-items:flex-start;flex-wrap:wrap;gap:12px}.header-actions{justify-content:flex-start}}
.wrap{max-width:none;padding-left:20px;padding-right:20px;padding-bottom:12px}.chart-wrap{container-type:inline-size}#chart{height:clamp(400px,27vw,440px)}.chart-head.chart-info-line{justify-content:flex-start;align-items:center;gap:18px;min-width:0;overflow-x:auto;white-space:nowrap;scrollbar-width:thin}.chart-info-line h2,.chart-info-line .count,.chart-info-item{flex:0 0 auto;white-space:nowrap}.chart-info-line h2{font-size:16px}.chart-info-item{display:inline-flex;align-items:center;gap:7px;color:#b7c1d5;font-size:13px}.chart-info-item .dot{flex:0 0 9px}.chart-info-item .session-marker{width:9px;height:9px;border:1px solid #48536c;border-radius:2px;background:#080d19;flex:0 0 9px}@media(max-width:600px){#chart{height:clamp(320px,58vw,360px)}.wrap{padding-bottom:10px}.chart-head.chart-info-line{align-items:center;flex-direction:row;gap:14px}}
.entry-strategy-group{flex-basis:100%;min-width:0;padding:13px 14px;border:1px solid #34415e;border-radius:12px;background:#10182a}.entry-strategy-heading{margin:0 0 10px;color:var(--text);font-size:14px;font-weight:700}.entry-strategy-fields{display:flex;align-items:end;gap:14px;flex-wrap:wrap}.entry-strategy-fields>.field{flex:1 1 190px;min-width:150px}.entry-strategy-fields .small-note{flex-basis:100%;margin:0}@media(max-width:600px){.entry-strategy-fields>.field{flex-basis:100%;width:100%}}
.exit-strategy-group{flex-basis:100%;min-width:0;padding:13px 14px;border:1px solid #34415e;border-radius:12px;background:#10182a}.exit-strategy-heading{margin:0 0 10px;color:var(--text);font-size:14px;font-weight:700}.exit-strategy-fields{display:flex;align-items:end;gap:14px;flex-wrap:wrap}.exit-strategy-fields>.field:first-child{flex:1 1 260px}.exit-strategy-fields>.field:nth-child(2){flex:1 1 220px}.exit-strategy-fields>.field:nth-child(3),.exit-strategy-fields>.field:nth-child(4){flex:1 1 220px}.exit-strategy-fields .small-note{flex-basis:100%;margin:0}@media(max-width:600px){.exit-strategy-fields>.field{flex-basis:100%;width:100%}}
.ranking-table{min-width:760px}.ranking-table td,.ranking-table th{text-align:left}.ranking-table td.numeric,.ranking-table th.numeric{text-align:right}.ranking-subheading{margin:22px 0 8px}.ranking-status{margin:0 0 12px;color:var(--muted)}.ranking-history{margin:14px 0;padding:12px;border:1px solid var(--line);border-radius:10px;background:#0c1222}.ranking-history-header{display:flex;align-items:center;justify-content:space-between;gap:10px}.ranking-history-title{margin:0 0 8px;font-size:14px}.ranking-history-delete{padding:6px 10px;color:#ffd0d0;border-color:#7c4149}.ranking-history-delete:disabled{cursor:progress;opacity:.65}.ranking-history-status{margin:0;color:var(--muted);font-size:13px}.ranking-history-list{display:grid;gap:8px;max-height:380px;overflow:auto;margin:8px 0 0;padding:0;list-style:none}.ranking-history-item{display:grid;grid-template-columns:minmax(180px,1fr) auto;align-items:start;gap:10px;padding:10px;border:1px solid #34415e;border-radius:9px;background:#10182a}.ranking-history-item strong{font-variant-numeric:tabular-nums}.ranking-history-item p{margin:4px 0;color:var(--muted);font-size:12px}.ranking-history-item details{grid-column:1/-1;color:var(--muted);font-size:12px}.ranking-history-item pre{max-height:220px;overflow:auto;margin:6px 0 0;padding:9px;border-radius:7px;background:#080d19;color:#d6def1;white-space:pre-wrap;overflow-wrap:anywhere;font:11px/1.45 ui-monospace,monospace}.ranking-history-actions{display:flex;grid-column:2;grid-row:1;gap:8px}.ranking-history-item .ranking-history-actions button{min-width:100px;padding:7px 10px}.ranking-history-item .ranking-history-delete-one{color:#ffd0d0;border-color:#7c4149}.ranking-history-item button:disabled{cursor:progress;opacity:.65}@media(max-width:600px){.ranking-history-item{grid-template-columns:1fr}.ranking-history-actions{grid-column:1;grid-row:auto;justify-self:start;flex-wrap:wrap}}
.chart-display{display:flex;align-items:stretch;gap:12px}.chart-wrap{flex:1 1 auto;min-width:0}.chart-day-controls{display:flex;flex:0 0 104px;flex-direction:column;gap:7px;max-height:clamp(400px,27vw,440px);overflow-y:auto;padding:2px 3px 2px 0;scrollbar-width:thin}.chart-day-button{display:flex;align-items:center;justify-content:center;gap:5px;width:100%;min-width:0;padding:8px 5px;border:1px solid #34415e;border-radius:8px;background:#0c1222;color:#b7c1d5;font-size:12px;line-height:1.25;font-variant-numeric:tabular-nums;white-space:nowrap}.chart-day-swatch{flex:0 0 7px;width:7px;height:7px;border:1px solid #ffffff55;border-radius:50%}.chart-day-button:hover{background:#202d4b;color:#fff}.chart-day-button[aria-pressed="true"]{background:#263b78;color:#fff;box-shadow:inset 0 0 0 1px #8ea5ff}.chart-day-button:focus-visible{outline:2px solid #8ea5ff;outline-offset:2px}@media(max-width:600px){.chart-display{gap:7px}.chart-day-controls{flex-basis:68px;max-height:clamp(320px,58vw,360px);gap:5px}.chart-day-button{padding:7px 2px;font-size:10px}}
.combination-detail h1{margin-top:0}.combination-detail dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:18px 0 0}.combination-detail dl>div{padding:12px;border:1px solid var(--line);border-radius:9px}.combination-detail dt{color:var(--muted);font-size:13px}.combination-detail dd{margin:6px 0 0;font-weight:700;font-variant-numeric:tabular-nums}.ranking-detail-link{color:#b8c7ff;text-decoration:underline;text-decoration-color:#6576ad;text-underline-offset:3px}.ranking-detail-link:hover{color:#fff}
#portfolio-ranking-panel .small-note{font-size:15px;margin:0 0 12px}
.calculation-queue-heading{display:flex;align-items:flex-start;gap:12px}.calculation-queue{flex:1;min-width:0}.calculation-queue>summary{cursor:pointer;font-weight:700}.calculation-queue-count{margin-left:8px;color:var(--muted);font-weight:400}.calculation-queue-columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:12px}.calculation-queue-columns h3{margin:8px 0}.calculation-queue-list{display:grid;gap:8px;margin:0;padding:0;list-style:none}.calculation-queue-list li{display:grid;gap:4px;padding:10px 12px;border:1px solid var(--line);border-radius:9px}.calculation-queue-list span,.calculation-queue-note{color:var(--muted);font-size:13px}.calculation-queue-empty{color:var(--muted)}.reset-calculation-queue{flex:0 0 38px;width:38px;height:38px;min-width:38px;padding:0;background:transparent;color:var(--muted);font-size:20px;line-height:1}.reset-calculation-queue:hover{background:#273653;color:var(--text)}.reset-calculation-queue:disabled{cursor:progress;opacity:.65}.stop-history-import{flex:0 0 auto;width:auto;min-width:0;padding:8px 12px;background:#7c4149;color:#ffd0d0;font-size:13px}.stop-history-import:hover{background:#96535b}.stop-history-import[hidden]{display:none}.stop-history-import:disabled{cursor:progress;opacity:.65}@media(max-width:600px){.calculation-queue-columns{grid-template-columns:minmax(0,1fr)}.history-import-progress>.stop-history-import{width:auto;flex:0 0 auto}}
.ranking-table th.sortable{padding:0 10px}.ranking-sort-button{width:100%;min-width:0;padding:11px 0;border:0;border-radius:0;background:transparent;color:inherit;font:inherit;text-align:inherit;text-transform:inherit;letter-spacing:inherit;cursor:pointer}.ranking-sort-button:hover{background:transparent;color:var(--text)}.ranking-sort-button:focus-visible{outline:2px solid #8ea5ff;outline-offset:-2px}.ranking-favorite-button{width:34px;min-width:34px;padding:6px 4px;background:#263968;color:#fff}.ranking-favorite-button:hover{background:#405bad}
.portfolio-ranking-head{margin-bottom:0}.portfolio-ranking-head .ranking-title-toggle{display:flex;align-items:center;gap:9px;flex:1;min-width:0;padding:0;border:0;border-radius:0;background:transparent;color:var(--text);font-size:17px;font-weight:700;text-align:left;cursor:pointer}.portfolio-ranking-head .ranking-title-toggle:hover{background:transparent;color:#b9c7ff}.ranking-disclosure-arrow{display:inline-block;flex:0 0 auto;font-size:13px;line-height:1;transform:rotate(90deg);transition:transform .16s ease}.ranking-title-toggle[aria-expanded="false"] .ranking-disclosure-arrow{transform:rotate(0deg)}.portfolio-ranking-head .refresh-symbols{margin-left:0}@media(max-width:600px){.portfolio-ranking-head{align-items:center;flex-direction:row}}
.ranking-timer{flex-basis:100%;margin:0;color:var(--muted);font-size:13px;font-variant-numeric:tabular-nums}
.portfolio-ranking-diagnostics{margin:0 0 14px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:#0c1222;color:var(--muted);font-size:13px;white-space:pre-line;font-variant-numeric:tabular-nums}
.symbol-date-row{display:flex;align-items:end;gap:14px;flex:0 0 100%;width:100%;min-width:0}.symbol-date-row .symbol-select-block{flex:1 1 360px;min-width:220px}.symbol-date-row>.date-field{flex:0 0 190px;width:190px}.symbol-date-row>.history-import-button{flex:0 1 auto}.symbol-date-row .field select,.symbol-date-row .field input{width:100%}.symbol-select-block{display:grid;gap:6px}.symbol-select-heading{display:flex;align-items:center;justify-content:space-between;gap:8px}.symbol-select-heading label{color:var(--muted);font-size:13px}.symbol-select-heading button{padding:5px 10px;font-size:12px;white-space:nowrap}@media(max-width:700px){.symbol-date-row{flex-wrap:wrap;gap:8px}.symbol-date-row .symbol-select-block{flex:0 0 100%;width:100%}.symbol-date-row>.date-field{flex:1 1 calc(50% - 4px);width:auto;min-width:0}.symbol-date-row>.date-field input{min-width:0;padding-left:8px;padding-right:8px}.symbol-date-row>.history-import-button{flex:1 1 auto}}
.ranking-parameters{position:relative;--ranking-label-column:260px;--ranking-column-gap:8px;flex-basis:100%;border:1px solid var(--line);border-radius:12px;padding:12px 14px}.ranking-parameters summary{padding-right:44px;cursor:pointer;color:var(--text);font-weight:700}.reset-ranking-parameters{position:absolute;top:7px;right:12px;flex:0 0 36px;width:36px;height:36px;min-width:36px;padding:0;background:transparent;color:var(--muted);font-size:21px;line-height:1}.reset-ranking-parameters:hover{background:#273653;color:var(--text)}.ranking-parameters h3{margin:16px 0 8px;font-size:14px}.ranking-range-row{display:grid;grid-template-columns:var(--ranking-label-column) repeat(3,minmax(90px,130px));justify-content:start;gap:var(--ranking-column-gap);align-items:end;margin:8px 0}.ranking-range-row>span{padding-bottom:11px}.ranking-range-row label{display:grid;gap:4px;color:var(--muted);font-size:12px}.ranking-range-row input{width:100%;min-width:0}.ranking-segment-editor{margin:10px 0}.ranking-segment-row{display:grid;grid-template-columns:var(--ranking-label-column) repeat(3,minmax(90px,130px)) 36px;justify-content:start;gap:var(--ranking-column-gap);align-items:end;margin:8px 0}.ranking-segment-row>span{padding-bottom:11px;color:#b7c1d5;font-size:12px}.ranking-segment-row label{display:grid;gap:4px;color:var(--muted);font-size:12px}.ranking-segment-row input{width:100%;min-width:0}.ranking-segment-row button,.add-range-segment{min-width:36px;width:36px;padding:7px;background:#263653;color:#dce5ff}.ranking-segment-row button:disabled{opacity:.45;cursor:not-allowed}.add-range-segment{width:auto;min-width:0;margin:2px 0 10px}.ranking-parameters .small-note{margin:8px 0}@media(max-width:650px){.ranking-range-row{grid-template-columns:repeat(3,minmax(0,120px));justify-content:start}.ranking-range-row>span{grid-column:1/-1;padding:0}.ranking-range-row input{padding:9px 6px}.ranking-segment-row{grid-template-columns:repeat(3,minmax(0,120px)) 36px}.ranking-segment-row>span{grid-column:1/-1;padding:0}.ranking-segment-row input{padding:9px 6px}}
.martingale-block{flex-basis:100%;min-width:0;margin:4px 0 0;padding:12px 14px;border:1px solid var(--line);border-radius:12px}.martingale-block>summary{color:var(--text);font-weight:700;cursor:pointer}.martingale-controls{display:flex;align-items:end;gap:14px;flex-wrap:wrap;margin-top:12px}.martingale-block .field select{min-width:190px}.martingale-block #apply-martingale-settings{align-self:end;margin-bottom:1px}@media(max-width:600px){.martingale-block,.martingale-block .field{width:100%}.martingale-controls,.martingale-block .field{width:100%}.martingale-block .field select{width:100%}}
.ranking-mode-group{display:grid;grid-template-columns:var(--ranking-label-column) max-content;align-items:center;column-gap:var(--ranking-column-gap)}.ranking-mode-group .ranking-range-heading{display:contents}.ranking-mode-group>.small-note,.ranking-mode-group>.ranking-custom{grid-column:1/-1}.ranking-mode-label{display:grid;gap:5px;width:auto;color:var(--muted);font-size:12px}.ranking-mode-label select{min-width:0}.ranking-custom[hidden]{display:none}
.ranking-parameters select{justify-self:start;margin-right:auto;width:auto;max-width:100%}
.ranking-mode-group h3{margin:16px 0 8px}@media(max-width:480px){.ranking-mode-group{grid-template-columns:minmax(0,1fr);align-items:start}.ranking-mode-group .ranking-mode-label{width:100%;margin-bottom:8px}}
.view-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 20px;padding:5px;border:1px solid var(--line);border-radius:12px;background:#0c1222;width:max-content;max-width:100%}.view-nav a{color:#b7c1d5;text-decoration:none;padding:9px 14px;border-radius:8px}.view-nav a:hover{background:#1b2843;color:var(--text)}.view-nav a[aria-current=page]{background:#263968;color:#fff}.favorite-readonly-note{margin:0 0 16px;padding:10px 12px;border:1px solid #53659b;border-radius:10px;background:#172343;color:#dce5ff}.favorite-readonly select,.favorite-readonly input,.favorite-readonly button{opacity:.72}.favorite-readonly .refresh-today-snapshot,.favorite-readonly .reset-ranking-parameters,.favorite-readonly .apply-ranking-ranges,.favorite-readonly #apply-martingale-settings,.favorite-readonly .collector-status{display:none}@media(max-width:600px){.view-nav{width:100%}.view-nav a{flex:1;text-align:center}}
.ticker-set-panel{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0 0 18px;padding:14px 18px}.ticker-set-panel label{font-weight:700}.ticker-set-panel select{min-width:240px;padding:10px 12px;border:1px solid #34415e;border-radius:9px;background:#0c1222;color:var(--text);font:inherit}.ticker-set-panel .small-note{margin:0}

.strategy-return-actions,.strategy-return-selectors{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:10px 0}.strategy-return-actions button{padding:9px 13px}.strategy-return-selectors .field{min-width:180px}.strategy-return-selectors select{width:100%}.strategy-return-chart-wrap{width:100%;height:340px;overflow:hidden}.strategy-return-chart-wrap svg{display:block;width:100%;height:100%;background:#0c1222;border:1px solid var(--line);border-radius:10px}.return-chart-placeholder{display:grid;place-items:center;width:100%;min-height:200px;padding:20px;border:1px dashed var(--line);border-radius:10px;color:var(--muted);text-align:center}.return-chart-placeholder[hidden]{display:none}.strategy-return-panel[hidden]{display:none}.chart-mode-switch{display:flex;gap:4px;padding:3px;background:#0c1222;border:1px solid var(--line);border-radius:9px}.chart-mode-switch button{padding:7px 11px;border:0;border-radius:6px;background:transparent;color:var(--muted);font:inherit;cursor:pointer}.chart-mode-switch button[aria-pressed="true"]{background:#263968;color:#fff}.chart-panel-sticky .chart-mode-switch{position:sticky}.return-point-provisional td{background:#3a2d18;color:#ffe0a3}.return-point-positive td:last-child{color:#48d6a4}.return-point-negative td:last-child{color:#ff7788}
#exit-config-field[hidden]{display:grid!important;visibility:hidden}
.portfolio-ranking-head{flex-wrap:wrap}.calculate-return-history{padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:#17233a;color:var(--text);font:inherit;cursor:pointer}.calculate-return-history:disabled{opacity:.65;cursor:wait}
.portfolio-ranking-head .refresh-symbols{flex:0 0 auto;width:auto;min-width:0;height:42px;padding:8px 13px;font-size:14px;line-height:1}
.portfolio-ranking-head .refresh-symbols[hidden]{display:none}
.compare-portfolio-rankings{min-width:0;padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:#17233a;color:var(--text);font:inherit;cursor:pointer}.compare-portfolio-rankings[hidden]{display:none}.ranking-comparison[hidden]{display:none}.ranking-comparison{margin:16px 0;padding:14px;border:1px solid var(--line);border-radius:12px;background:#0c1222}.ranking-comparison h3{margin:0 0 6px}.ranking-comparison-note{margin:0 0 10px;color:var(--muted);font-size:13px}.ranking-comparison-table{width:max-content;min-width:100%;border-collapse:collapse;font-size:12px}.ranking-comparison-table th:first-child,.ranking-comparison-table td:first-child{position:sticky;left:0;z-index:1;background:#111a2c;text-align:left}.ranking-comparison-table th{vertical-align:top;white-space:normal;min-width:220px;max-width:280px}.ranking-comparison-table td{vertical-align:top;white-space:normal;min-width:220px;max-width:280px}.ranking-comparison-value{font-weight:700;font-variant-numeric:tabular-nums}.ranking-comparison-detail{display:block;margin-top:4px;color:var(--muted);font-size:11px;line-height:1.35}
.symbol-select-control{position:relative;width:100%}.ticker-sort-indicator[hidden]{display:none}.ticker-sort-indicator{position:absolute;top:50%;right:38px;transform:translateY(-50%);display:flex;align-items:center;gap:1px;padding:3px 6px;border-radius:6px;background:#0c1222;color:#a9b9ff;font-size:11px;font-weight:600;line-height:1.2;pointer-events:none}.ticker-sort-indicator .ticker-sort-dot{animation:ticker-sort-pulse 1.1s infinite;opacity:.25}.ticker-sort-indicator .ticker-sort-dot:nth-child(3){animation-delay:.18s}.ticker-sort-indicator .ticker-sort-dot:nth-child(4){animation-delay:.36s}@keyframes ticker-sort-pulse{0%,60%,100%{opacity:.25}30%{opacity:1}}@media(prefers-reduced-motion:reduce){.ticker-sort-indicator .ticker-sort-dot{animation:none;opacity:1}}
.ranked-select-control{position:relative;width:100%}.ranked-select-control select{width:100%}.ranked-select-indicator[hidden]{display:none}.ranked-select-indicator{position:absolute;top:50%;right:38px;transform:translateY(-50%);display:flex;align-items:center;gap:1px;padding:3px 6px;border-radius:6px;background:#0c1222;color:#a9b9ff;font-size:11px;font-weight:600;line-height:1.2;pointer-events:none}.ranked-select-indicator .ticker-sort-dot{animation:ticker-sort-pulse 1.1s infinite;opacity:.25}.ranked-select-indicator .ticker-sort-dot:nth-child(3){animation-delay:.18s}.ranked-select-indicator .ticker-sort-dot:nth-child(4){animation-delay:.36s}@media(prefers-reduced-motion:reduce){.ranked-select-indicator .ticker-sort-dot{animation:none;opacity:1}}
.ranked-select-heading{display:flex;flex-direction:column;align-items:flex-start;gap:6px;margin-bottom:5px}.ranked-select-heading>label{color:var(--muted);font-size:13px}.selector-order-lock{display:inline-flex;align-items:flex-start;gap:7px;max-width:100%;padding:4px 7px;border:1px solid #34415e;border-radius:6px;background:#0c1222;color:#c8d2e8;font-size:11px;line-height:1.35;text-align:left;white-space:normal}.selector-sort-dot{flex:0 0 7px;width:7px;height:7px;margin-top:4px;border-radius:50%;background:#3bd286}.selector-order-lock[aria-pressed="false"] .selector-sort-dot{background:#f36c78}.selector-order-lock:focus-visible{outline:2px solid #8ea5ff;outline-offset:2px}
</style>
</head><body><main class="wrap">
<header class="page-heading"><div class="eyebrow">Market data · <?= h($config['category']) ?></div><div class="header-actions"><label class="save-mode-toggle" for="save-mode-toggle"><input type="checkbox" id="save-mode-toggle" <?= $saveMode ? 'checked' : '' ?>><span class="save-mode-switch" aria-hidden="true"></span><span>SAVE MODE</span><span class="save-mode-detail" id="save-mode-detail"><?= $saveMode ? 'минимум 3 сделки' : 'от 1 сделки' ?></span></label><button class="collector-status" id="collector-status" type="button" data-status="unknown" aria-label="Загрузить последние данные для активных тикеров выбранного списка" title="Нажмите, чтобы загрузить минутные данные активных тикеров выбранного списка от последних сохранённых котировок до последней доступной минуты">Загружаю последнюю котировку…</button></div></header>
<nav class="view-nav" aria-label="Разделы"><a href="index.php">Главная</a><a href="<?= h('basic-checks.php?' . http_build_query($viewParams)) ?>" aria-current="page">Базовые проверки</a><a href="<?= h($hedgingUrl) ?>">Хеджирование</a><a href="favorites.php">Избранное</a><a href="settings.php">Настройки</a><a href="<?= h($autotradingUrl . '&mode=paper') ?>">Автоторговля (Paper)</a><a href="<?= h($autotradingUrl . '&mode=demo') ?>">Автоторговля (Тест)</a><a href="<?= h($autotradingUrl . '&mode=real') ?>">Автоторговля (реал)</a><a href="autotrading-pi.php">Raspberry Pi</a></nav>
<section class="panel ticker-set-panel" aria-label="Выбранный список тикеров"><label for="ticker-set-select">Выбранный список тикеров</label><select id="ticker-set-select" <?= $favoriteView ? 'disabled' : '' ?>><?php foreach ($tickerSetOptions as $tickerSet): ?><option value="<?= h((string) ($tickerSet['id'] ?? '')) ?>" <?= ($tickerSet['id'] ?? '') === $tickerSetId ? 'selected' : '' ?>><?= h((string) ($tickerSet['name'] ?? '')) ?></option><?php endforeach; ?></select><span class="small-note"><?= count($enabledSymbols) ?> активных тикеров в списке</span></section>
<?php if ($favoriteView): ?><p class="favorite-readonly-note">Просмотр сохранённой стратегии «<?= h((string) ($favorite['name'] ?? $favoriteId)) ?>». Параметры доступны только для чтения. <a class="strategy-link" href="favorites.php">← Вернуться к избранному</a></p><?php endif; ?>
<?php if ($combinationDetail): ?>
<section class="panel combination-detail" aria-labelledby="combination-detail-heading">
<h1 id="combination-detail-heading">Детальный результат · <?= h($symbol !== '' ? $symbol : 'тикер') ?></h1>
<p><strong>Порог входа:</strong> <?= h($detailEntryLabel !== '' ? $detailEntryLabel : $entryConfig) ?></p>
<p><strong>TakeProfit &amp; StopLoss:</strong> <?= h($detailExitLabel !== '' ? $detailExitLabel : $selectedExitConfig) ?></p>
<dl>
<div><dt>Место в общем рейтинге</dt><dd><?= $detailRank !== false && $detailRank !== null ? (string) $detailRank : '—' ?></dd></div>
<div><dt>Итог тикера</dt><dd><?= $detailTickerPnl !== null ? h(($detailTickerPnl > 0 ? '+' : '') . number_format($detailTickerPnl, 4, ',', ' ') . ' USDT') : '—' ?></dd></div>
<div><dt>Средний итог этой пары на рынке</dt><dd><?= $detailMarketAverage !== null ? h(($detailMarketAverage > 0 ? '+' : '') . number_format($detailMarketAverage, 4, ',', ' ') . ' USDT') : '—' ?></dd></div>
<div><dt>Поддержка</dt><dd><?= $detailSupport !== false && $detailSupport !== null && $detailUniverseSize !== false && $detailUniverseSize !== null ? $detailSupport . ' из ' . $detailUniverseSize : '—' ?></dd></div>
<div><dt>Сделок</dt><dd><?= $detailTradeCount !== false && $detailTradeCount !== null ? (string) $detailTradeCount : '—' ?></dd></div>
</dl>
<p class="small-note">График и журнал ниже построены по выбранным котировкам и этой комбинации.</p>
</section>
<?php endif; ?>
<?php if ($error !== null): ?><section class="panel error"><?= h($error) ?></section><?php endif; ?>
<?php if ($martingaleOrderConstraintError !== null): ?><section class="panel error"><?= h($martingaleOrderConstraintError) ?></section><?php endif; ?>
<section class="panel"><form class="filters<?= $favoriteView ? ' favorite-readonly' : '' ?>" method="get">
<input type="hidden" name="ticker_set" value="<?= h($tickerSetId) ?>">
<input type="hidden" name="balance_auto" value="<?= $balanceAutoManaged ? '1' : '0' ?>">
<input type="hidden" name="ranking_balance" value="<?= h($rankingStartingBalance) ?>">
<input type="hidden" name="start_day_auto" value="<?= $startDayAuto ? '1' : '0' ?>">
<input type="hidden" name="ranking_generation" id="ranking-generation" value="<?= h($rankingGeneration) ?>">
<?php if ($combinationDetail): ?>
<?php foreach (['combination_detail' => '1', 'detail_entry_label' => $detailEntryLabel, 'detail_exit_label' => $detailExitLabel, 'detail_rank' => $detailRank !== false && $detailRank !== null ? (string) $detailRank : '', 'detail_ticker_pnl' => $detailTickerPnl !== null ? (string) $detailTickerPnl : '', 'detail_market_average' => $detailMarketAverage !== null ? (string) $detailMarketAverage : '', 'detail_support' => $detailSupport !== false && $detailSupport !== null ? (string) $detailSupport : '', 'detail_universe_size' => $detailUniverseSize !== false && $detailUniverseSize !== null ? (string) $detailUniverseSize : '', 'detail_trade_count' => $detailTradeCount !== false && $detailTradeCount !== null ? (string) $detailTradeCount : ''] as $detailName => $detailValue): ?><input type="hidden" name="<?= h($detailName) ?>" value="<?= h($detailValue) ?>"><?php endforeach; ?>
<?php endif; ?>
<input type="hidden" name="symbol_sort" id="symbol-sort-mode" value="<?= h($symbolSort) ?>">
<input type="hidden" name="save_mode" id="save-mode-value" value="<?= $saveMode ? '1' : '0' ?>">
<input type="hidden" name="select_top_ranked_result" id="select-top-ranked-result" value="<?= $selectTopRankedResult ? '1' : '0' ?>">
<input type="hidden" name="martingale_mode" value="<?= h($martingaleMode) ?>">
<input type="hidden" name="martingale_timing" value="<?= h($martingaleTiming) ?>">
<input type="hidden" name="martingale_attempts" value="<?= h((string) $martingaleAttempts) ?>">
<input type="hidden" name="martingale_max_chain_loss" value="<?= h($martingaleRiskLimits['max_chain_loss']) ?>">
<input type="hidden" name="martingale_max_day_loss" value="<?= h($martingaleRiskLimits['max_day_loss']) ?>">
<div class="symbol-date-row"><div class="field symbol-select-block"><div class="symbol-select-heading"><label for="symbol-select">Торговая пара</label><?php if (!$favoriteView): ?><button type="button" id="add-favorite">☆ В избранное</button><?php endif; ?></div><div class="symbol-select-control"><select name="symbol" id="symbol-select"><?php if ($symbols === []): ?><option value="">Нет включённых тикеров — откройте «Настройки»</option><?php else: ?><option value="" <?= !$symbolWasSubmitted ? 'selected' : '' ?>>Выберите торговую пару</option><?php endif; ?><?php foreach ($symbols as $option): ?><option value="<?= h($option) ?>" class="<?= isset($demoAvailableSymbols[$option]) ? 'demo-available-ticker' : '' ?>" <?= $symbolWasSubmitted && $option === $symbol ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?></select><span class="ticker-sort-indicator" id="ticker-sort-indicator" role="status" aria-label="Сортирую торговые пары по доходности" aria-live="polite" title="Сортирую торговые пары по доходности" hidden>Сортирую<span id="ticker-sort-progress"></span><span class="ticker-sort-dot">.</span><span class="ticker-sort-dot">.</span><span class="ticker-sort-dot">.</span></span></div></div><label class="field date-field">С даты<input type="date" lang="en-GB" name="start_day" value="<?= h($startDayInput) ?>" required></label><label class="field date-field">По дату<input type="date" lang="en-GB" name="end_day" value="<?= h($endDayInput) ?>" required></label><button class="history-import-button" type="button" id="import-selected-symbol" data-import-scope="symbol" title="Скачать минутные свечи выбранной пары из Bitget за выбранный диапазон, сохранить в БД и обновить график и расчёты" hidden>Выгрузить сырые данные по <?= h($symbol) ?></button><button class="history-import-button" type="button" id="import-all-active-symbols" data-import-scope="all" data-symbol-count="<?= count($enabledSymbols) ?>" title="Скачать минутные свечи всех активных пар из Bitget за выбранный диапазон, сохранить в БД и обновить график и расчёты" hidden>Выгрузить сырые данные по выбранному списку тикеров</button></div><p class="small-note" id="ticker-return-sort-status" aria-live="polite"></p><?php if (!$favoriteView): ?><p class="small-note" id="favorite-status" aria-live="polite"></p><?php endif; ?>
<div class="history-import-actions"><div class="history-import-progress"><p class="history-import-status" id="history-import-status" data-status="" role="status" aria-live="polite"></p><button class="stop-history-import" type="button" id="stop-history-import" hidden>■ Остановить загрузку</button></div></div>

<section class="entry-strategy-group" aria-labelledby="entry-strategy-heading"><h2 class="entry-strategy-heading" id="entry-strategy-heading">Стратегия входа</h2><div class="entry-strategy-fields">
<label class="field">Тип входа<select name="entry_signal_mode" id="entry-signal-mode"><option value="exact" <?= $entrySignalMode === 'exact' ? 'selected' : '' ?>>За X снимков назад</option><option value="within" <?= $entrySignalMode === 'within' ? 'selected' : '' ?>>За X снимков внутри</option></select></label>
<label class="field">Направление входа<select name="entry_direction_mode" id="entry-direction-mode"><option value="follow" <?= $entryDirectionMode === 'follow' ? 'selected' : '' ?>>По направлению движения</option><option value="reverse" <?= $entryDirectionMode === 'reverse' ? 'selected' : '' ?>>Против направления</option></select></label>
<div class="field"><div class="ranked-select-heading"><label for="entry-config">Порог входа</label><button class="selector-order-lock" id="entry-order-lock" type="button" aria-pressed="true"><span class="selector-sort-dot" aria-hidden="true"></span><span>Сортировка по максимальной доходности</span></button></div><div class="ranked-select-control"><select name="entry_config" id="entry-config" <?= !$symbolWasSubmitted ? 'disabled' : '' ?>><option value="<?= h($symbolWasSubmitted ? $entryConfig : '') ?>" <?= !$symbolWasSubmitted ? 'disabled selected' : 'selected' ?>><?= h(!$symbolWasSubmitted ? 'Выберите торговую пару' : ($favoriteView ? $favoriteEntryLabel : $entryLoadingLabel)) ?></option></select><span class="ranked-select-indicator" id="entry-sort-indicator" role="status" aria-label="Сортирую пороги входа по доходности" aria-live="polite" hidden>Сортирую<span class="ranked-sort-progress"></span><span class="ticker-sort-dot">.</span><span class="ticker-sort-dot">.</span><span class="ticker-sort-dot">.</span></span></div></div>
<p class="small-note">«Назад» сравнивает цену с ровно X снимками до текущего. «Внутри» открывает позицию при первом пересечении порога относительно любого из последних X снимков.</p>
</div></section>
<section class="exit-strategy-group" aria-labelledby="exit-strategy-heading"><h2 class="exit-strategy-heading" id="exit-strategy-heading">Стратегия выхода</h2><div class="exit-strategy-fields">
<label class="field">Тип выхода<select name="exit_confirmation_mode" id="exit-confirmation-mode"><option value="immediate" <?= $exitConfirmationMode === 'immediate' ? 'selected' : '' ?>>TP&amp;SL (сразу)</option><option value="both_delayed" <?= $exitConfirmationMode === 'both_delayed' ? 'selected' : '' ?>>TP&amp;SL с учетом интервалов</option><option value="tp_delayed" <?= $exitConfirmationMode === 'tp_delayed' ? 'selected' : '' ?>>TP с учетом интервалов, SL сразу</option><option value="sl_delayed" <?= $exitConfirmationMode === 'sl_delayed' ? 'selected' : '' ?>>TP сразу, SL с учетом интервалов</option><option value="none" <?= $exitConfirmationMode === 'none' ? 'selected' : '' ?>>Без выхода</option></select></label>
<div class="field" id="exit-config-field" <?= $noExitSelected ? 'hidden' : '' ?>><div class="ranked-select-heading"><label for="exit-config">TakeProfit &amp; StopLoss</label><button class="selector-order-lock" id="exit-order-lock" type="button" aria-pressed="true"><span class="selector-sort-dot" aria-hidden="true"></span><span>Сортировка по максимальной доходности</span></button></div><div class="ranked-select-control"><select name="exit_config" id="exit-config" <?= (!$symbolWasSubmitted || $noExitSelected) ? 'disabled' : '' ?>><option value="<?= h($symbolWasSubmitted ? $selectedExitConfig : '') ?>" <?= !$symbolWasSubmitted ? 'disabled selected' : 'selected' ?>><?= h(!$symbolWasSubmitted ? 'Выберите торговую пару' : ($noExitSelected ? 'Не применяется · позиция удерживается до конца периода' : ($favoriteView ? $favoriteExitLabel : 'Загружаю варианты…'))) ?></option></select><span class="ranked-select-indicator" id="exit-sort-indicator" role="status" aria-label="Сортирую варианты TakeProfit и StopLoss по доходности" aria-live="polite" hidden>Сортирую<span class="ranked-sort-progress"></span><span class="ticker-sort-dot">.</span><span class="ticker-sort-dot">.</span><span class="ticker-sort-dot">.</span></span></div></div>
<input type="hidden" name="exit_confirmation_snapshots" id="exit-confirmation-snapshots" value="<?= h((string) $exitConfirmationSnapshots) ?>">
<p class="small-note"><?= $noExitSelected ? 'Без TP и SL позиция остаётся открытой до последней котировки периода; результат показывается как предварительный, незакрытая сделка.' : 'Варианты TP/SL сгруппированы по числу интервалов проверки. При достижении TP или SL позиция закроется, если порог всё ещё достигнут через указанное число интервалов.' ?></p>
</div></section>
<p class="ranking-timer" id="strategy-ranking-timer" aria-live="polite" hidden></p>
<label class="field">Стартовый баланс (USDT)<input type="number" name="balance" min="0.01" max="1000000000" step="0.01" value="<?= h($startingBalance) ?>" required></label>
<p class="small-note" id="common-ranking-balance-note">Общий рейтинг всех тикеров рассчитывается от единого стартового баланса <?= h($rankingStartingBalance) ?> USDT<?= bccomp($rankingStartingBalance, $startingBalance, 2) !== 0 ? '; баланс выбранного тикера для симуляции — ' . h($startingBalance) . ' USDT' : '' ?>.</p>
<?php if ($balanceAutoAdjustedFrom !== null && is_array($minimumOrderBudgetInfo)): ?><p class="small-note">Баланс <?= bccomp($startingBalance, $balanceAutoAdjustedFrom, 2) < 0 ? 'уменьшен' : 'увеличен' ?> с <?= h($balanceAutoAdjustedFrom) ?> до <?= h($startingBalance) ?> USDT для выбранного тикера <?= h($symbol) ?>: минимум <?= h($minimumOrderBudgetInfo['minimum_quantity']) ?> по шагу <?= h($minimumOrderBudgetInfo['quantity_step']) ?> при цене <?= h($minimumOrderBudgetInfo['reference_price']) ?> USDT, с учётом комиссии Bitget за вход.</p><?php endif; ?>
<label class="field">Комиссия за сторону (%)<input type="number" name="fee" min="0" max="5" step="0.001" value="<?= h($feePercent) ?>" required></label>
<label class="field">Проскальзывание (б.п.)<input type="number" name="slippage_bps" min="0" max="9999.9999" step="0.1" value="<?= h($simulationCosts['slippage_bps']) ?>" required></label>
<label class="save-mode-toggle"><input type="hidden" name="include_funding" value="0"><input type="checkbox" name="include_funding" value="1" <?= $simulationCosts['include_funding'] ? 'checked' : '' ?>><span class="save-mode-detail">Учитывать funding</span></label>
<p class="small-note">Проскальзывание ухудшает цену исполнения каждой стороны: покупка дороже, продажа дешевле. Сигналы строятся по сохранённой Last.</p>
<details class="martingale-block" <?= $martingaleMode !== 'none' ? 'open' : '' ?>><summary>Мартингейл</summary><div class="martingale-controls"><label class="field">Режим<select data-martingale-setting="martingale_mode"><option value="none" <?= $martingaleMode === 'none' ? 'selected' : '' ?>>Без мартингейла</option><option value="simple" <?= $martingaleMode === 'simple' ? 'selected' : '' ?>>Простой по лоссу</option><option value="reverse" <?= $martingaleMode === 'reverse' ? 'selected' : '' ?>>Обратный по лоссу</option><option value="simple_profit" <?= $martingaleMode === 'simple_profit' ? 'selected' : '' ?>>Простой по профиту</option><option value="reverse_profit" <?= $martingaleMode === 'reverse_profit' ? 'selected' : '' ?>>Обратный по профиту</option></select></label><label class="field">Следующий шаг<select data-martingale-setting="martingale_timing"><option value="immediate" <?= $martingaleTiming === 'immediate' ? 'selected' : '' ?>>Сразу</option><option value="rules" <?= $martingaleTiming === 'rules' ? 'selected' : '' ?>>По порогу входа</option></select></label><label class="field">Количество попыток<select data-martingale-setting="martingale_attempts"><?php for ($attempt = 2; $attempt <= 10; $attempt++): ?><option value="<?= $attempt ?>" <?= $martingaleAttempts === $attempt ? 'selected' : '' ?>><?= $attempt ?></option><?php endfor; ?></select></label><label class="field">Макс. убыток цепочки (USDT)<input type="number" min="0" max="1000000000" step="0.01" data-martingale-setting="martingale_max_chain_loss" value="<?= h($martingaleRiskLimits['max_chain_loss']) ?>"></label><label class="field">Макс. убыток за день (USDT)<input type="number" min="0" max="1000000000" step="0.01" data-martingale-setting="martingale_max_day_loss" value="<?= h($martingaleRiskLimits['max_day_loss']) ?>"></label><button type="button" id="apply-martingale-settings" disabled>Применить</button><p class="small-note">Ноль отключает лимит. Лимит цепочки закрывает её на первом снимке, где чистый убыток достигает суммы. Дневной лимит учитывает результат закрытых цепочек по дате закрытия и запрещает новые цепочки до следующего дня; лимит цепочки ограничивает текущую позицию.</p><p class="small-note">По лоссу следующий шаг запускается после Stop Loss, по профиту — после Take Profit. Простой режим продолжает в ту же сторону (при лоссе усредняет открытую позицию); обратный закрывает позицию и меняет сторону. TP/SL задаются от стартового баланса или масштабируются вместе с позицией.</p><p class="small-note">Перед расчётом каждый ордер сверяется с актуальными правилами контракта Bitget: минимумом суммы и количества, шагом количества, максимальным размером рыночного ордера и доступным балансом.</p><p class="small-note">Предварительная сумма первого ордера по выбранным параметрам: <strong id="martingale-initial-notional">Рассчитываю…</strong></p><p class="small-note" id="martingale-draft-status" aria-live="polite" hidden></p><p class="small-note" id="martingale-cache-status" aria-live="polite" hidden></p></div></details>
<details class="ranking-parameters"><summary>Параметры расчёта · диапазоны от / до / шаг</summary><button class="reset-ranking-parameters" type="button" id="reset-ranking-parameters" aria-label="Сбросить параметры расчёта по умолчанию" title="Сбросить параметры расчёта по умолчанию">↺</button>
<div class="ranking-mode-group">
<?php
$sharedThresholdMode = $rankingModes['threshold'];
$defaultThresholdSegments = $defaultRankingRanges['both_threshold'];
$sharedThresholdRanges = $sharedThresholdMode === 'range' ? $rankingRanges['threshold'] : $defaultThresholdSegments;
$thresholdValuesText = implode(', ', array_map(static fn (int $value): string => '±' . number_format($value / 10, 1, ',', ' '), $entryThresholdsTenthsPercent));
?>
<div class="ranking-range-heading"><h3>Порог входа · % от баланса <small>общий набор для ±, + и −</small></h3><label class="ranking-mode-label"><select aria-label="Общий режим порогов входа" class="ranking-mode-input" name="threshold_mode" data-default-value="default" data-range="threshold"><option value="default" <?= $sharedThresholdMode === 'default' ? 'selected' : '' ?>>Текущие значения</option><option value="range" <?= $sharedThresholdMode === 'range' ? 'selected' : '' ?>>Свой диапазон</option></select></label></div>
<p class="small-note">Порог входа задаёт ожидаемое движение в доле стартового баланса; при плече 1× и позиции на весь баланс это соответствует такому же проценту движения Last. Значения по умолчанию: <?= h($thresholdValuesText) ?>%. В режиме «Свой диапазон» шаг и значения применяются ко всем трём направлениям.</p>
<div class="ranking-custom" id="threshold-custom" <?= $sharedThresholdMode === 'range' ? '' : 'hidden' ?>><?= rankingSegmentEditor('Порог входа (%)', 'threshold', $sharedThresholdRanges, $defaultThresholdSegments, true) ?></div>
<?php
$sharedWindowMode = $rankingModes['window'];
$defaultWindowSegments = $defaultRankingRanges['both_window'];
$sharedWindowRanges = $sharedWindowMode === 'range' ? $rankingRanges['window'] : $defaultWindowSegments;
$windowValuesText = implode(', ', array_map('strval', $entryWindows));
?>
<div class="ranking-range-heading"><h3>Порог входа · окно (число снимков) <small>общее для ±, + и −</small></h3><label class="ranking-mode-label"><select aria-label="Общий режим окна входа" class="ranking-mode-input" name="window_mode" data-default-value="default" data-range="window"><option value="default" <?= $sharedWindowMode === 'default' ? 'selected' : '' ?>>Текущие значения</option><option value="range" <?= $sharedWindowMode === 'range' ? 'selected' : '' ?>>Свой диапазон</option></select></label></div>
<p class="small-note">Значения по умолчанию: <?= h($windowValuesText) ?> снимков. Общие окна применяются к симметричному режиму ±, росту + и падению −.</p>
<div class="ranking-custom" id="window-custom" <?= $sharedWindowMode === 'range' ? '' : 'hidden' ?>><?= rankingSegmentEditor('Окно снимков', 'window', $sharedWindowRanges, $defaultWindowSegments, false) ?></div>
</div>
<h3>TakeProfit &amp; StopLoss (% от стартового баланса)</h3>
<p class="small-note">Совместный расчёт: каждый Take Profit проверяется с каждым Stop Loss.</p>
<div class="ranking-range-heading"><h3>Take Profit + Stop Loss · совместный расчёт <small>шаг меняется по диапазонам</small></h3><label class="ranking-mode-label"><select aria-label="Сетка совместного расчёта TP и SL" class="ranking-mode-input" name="fixed_grid_mode" data-default-value="default" data-range="fixed-grid"><option value="default" <?= $rankingModes['fixed_grid'] === 'default' ? 'selected' : '' ?>>Текущая сетка</option><option value="custom" <?= $rankingModes['fixed_grid'] === 'custom' ? 'selected' : '' ?>>Свой диапазон</option></select></label></div>
<div id="fixed-grid-custom" <?= $rankingModes['fixed_grid'] === 'custom' ? '' : 'hidden' ?>>
<?= rankingSegmentEditor('Take Profit · свой диапазон (%)', 'fixed_tp', $rankingModes['fixed_grid'] === 'custom' ? $rankingRanges['fixed_tp']['segments'] : $defaultRankingRanges['fixed_tp']['segments'], $defaultRankingRanges['fixed_tp']['segments'], true, 2) ?>
<?= rankingSegmentEditor('Stop Loss · свой диапазон (%)', 'fixed_sl', $rankingModes['fixed_grid'] === 'custom' ? $rankingRanges['fixed_sl']['segments'] : $defaultRankingRanges['fixed_sl']['segments'], $defaultRankingRanges['fixed_sl']['segments'], true, 2) ?>
</div>
<?= rankingSegmentEditor('Только Take Profit (%)', 'profit', $rankingRanges['profit'], $defaultRankingRanges['profit'], true) ?>
<?= rankingSegmentEditor('Только Stop Loss (%)', 'loss', $rankingRanges['loss'], $defaultRankingRanges['loss'], true) ?>
<p class="small-note">Для каждого параметра можно добавить несколько диапазонов. Внутри каждого задайте «от», «до» и шаг; граничные значения включаются.</p><button class="apply-ranking-ranges" type="submit" id="apply-ranking-ranges">Применить диапазоны</button>
<p class="hint">Фильтры применяются автоматически после изменения. Время на графике указано по Киеву; загружаются только сохраненные записи.</p><p class="hint"><strong>Пороги входа:</strong> общий набор порогов <?= h($entryThresholdSummary) ?>% от баланса и окна снимков <?= h(implode(', ', array_map('strval', $entryWindows))) ?> для режимов ±, + и −. Каждый порог проверяется на каждом общем окне.</p><p class="hint"><strong>Дополнительные варианты входа:</strong> <?= h($immediateEntryText) ?>. В обычном режиме вход привязан к первому сохраненному снимку тикера, а диапазон дат отбирает сделки по дню закрытия; в мартингейле вход остается привязан к началу выбранного периода.</p><p class="hint">Список «Порог входа» отсортирован по лучшему чистому результату с учетом списка «TakeProfit &amp; StopLoss». Симметричные пороги ± работают в обе стороны; + открывает покупки только при росте, а − открывает продажи только при падении. Take Profit и Stop Loss выбираются во втором списке. Для исполнения используются действующие правила контракта Bitget (плечо 1×, шаг количества, минимумы и максимум рыночной заявки), комиссия за market/taker и указанное проскальзывание. К историческим сделкам применяются текущие правила контракта. <a class="strategy-link" href="strategies.php">Описание Пользовательской Стратегии №1 →</a></p><p class="hint"><strong>Сетка совместного TP/SL:</strong> TP и SL проверяются с шагами 0,1% от 0,1% до 1%; 0,2% от 1% до 2%; 0,25% от 2% до 3%; 0,5% от 3% до 10%; 1% от 10% до 20%; 2,5% от 20% до 50%. Общие границы входят один раз; конечные значения диапазонов включаются. Значения проверяются с точностью до 0,01%. Одиночные TP и SL используют прежние диапазоны.</p><p class="hint"><strong>Одиночные диапазоны TakeProfit &amp; StopLoss:</strong></p><ul class="hint"><li>Только Take Profit: <?= h($exitRangeText($rankingRanges['profit'])) ?>, без фиксации убытка.</li><li>Только Stop Loss: <?= h($exitRangeText($rankingRanges['loss'])) ?>, без фиксации прибыли.</li></ul></details></form></section>
<p class="panel empty" id="strategy-results-placeholder" <?= $symbolWasSubmitted ? '' : 'hidden' ?>><?= $symbolWasSubmitted ? 'Загружаю «Порог входа» и «TakeProfit &amp; StopLoss»…' : 'Выберите торговую пару — график и расчёт стратегии появятся после загрузки данных.' ?></p>
<section class="panel"><div class="calculation-queue-heading"><details class="calculation-queue" id="calculation-queue"><summary>Очередь расчётов <span class="calculation-queue-count" id="calculation-queue-count">Загружаю…</span></summary><p class="calculation-queue-note">↻ останавливает текущие расчёты рейтинга и очищает очередь. В «Настройках» можно дополнительно сбросить кэш рейтингов.</p><div class="calculation-queue-columns"><div><h3>Выполняется</h3><ul class="calculation-queue-list" id="calculation-queue-active"></ul></div><div><h3>Ожидают</h3><ul class="calculation-queue-list" id="calculation-queue-waiting"></ul></div></div><p class="calculation-queue-note" id="calculation-queue-updated" aria-live="polite"></p></details><button class="reset-calculation-queue" type="button" id="stop-calculation-queue" aria-label="Остановить текущие расчёты и очистить очередь" title="Остановить текущие расчёты и очистить очередь">↻</button></div></section>
<section class="panel" id="strategy-range-comparison" hidden>
<div class="chart-head"><h3>Доходность выбранной стратегии по соседним диапазонам</h3></div>
<p class="small-note" id="strategy-range-comparison-selection"></p>
<p class="small-note" id="strategy-range-comparison-status" role="status" aria-live="polite"></p>
<div class="table-wrap" id="strategy-range-comparison-table"></div>
<p class="small-note">Текущий диапазон — (0). До пяти диапазонов назад и вперёд, той же длины, без пересечения дат. Будущие даты исключены. Итог — накопленная доходность по сохранённым закрытиям сделок; отсутствие закрытий не означает нулевую доходность.</p>
</section>
<section class="panel" id="portfolio-ranking-panel"><div class="chart-head portfolio-ranking-head"><button class="ranking-title-toggle" type="button" id="ranking-collapse-toggle" aria-expanded="true" aria-controls="portfolio-ranking-content" aria-label="Свернуть общий рейтинг" title="Нажмите, чтобы свернуть рейтинг"><span class="ranking-disclosure-arrow" aria-hidden="true">▸</span>Общий рейтинг порогов входа и TakeProfit &amp; StopLoss</button><button class="calculate-return-history" type="button" id="calculate-return-history" <?= $favoriteView ? 'disabled' : '' ?>>Рассчитать доходность для «<?= h($tickerSetSelection['name']) ?>»</button><button class="refresh-symbols" type="button" id="refresh-symbols" hidden aria-label="Составить общий рейтинг по таблице доходности" title="Составить общий рейтинг из уже рассчитанной доходности">Составить рейтинг</button><button class="compare-portfolio-rankings" type="button" id="compare-portfolio-rankings" hidden>Сравнить готовые рейтинги</button></div><p class="ranking-timer" id="portfolio-ranking-timer" aria-live="polite" hidden></p><div id="portfolio-ranking-content" hidden><section class="ranking-history" id="portfolio-ranking-history" aria-live="polite" hidden><div class="ranking-history-header"><h3 class="ranking-history-title">Сохранённые общие рейтинги</h3><button class="ranking-history-delete" type="button" id="delete-portfolio-ranking-history" hidden>Удалить все</button></div><p class="ranking-history-status">Откройте вкладку, чтобы загрузить список расчётов для выбранного списка тикеров.</p></section><p class="ranking-status" id="portfolio-ranking-status" aria-live="polite"></p><p class="portfolio-ranking-diagnostics" id="portfolio-ranking-diagnostics" aria-live="polite" hidden></p><section class="ranking-comparison" id="ranking-comparison" hidden><h3>Сравнение сохранённых рейтингов · <span id="ranking-comparison-period"></span></h3><p class="ranking-comparison-note">Столбцы показывают полный сценарий запуска. В ячейке — лучший результат пары и комбинация, которая его дала. Таблица использует только завершённые рейтинги с одинаковым списком, периодом и прочими фильтрами.</p><div class="table-wrap" id="ranking-comparison-table"></div></section><div class="table-wrap" id="portfolio-global-table"></div><h3 class="ranking-subheading">Лучшая комбинация каждого тикера</h3><p class="small-note">Сначала рассчитайте доходность списка. Рейтинг ниже строится из сохранённых результатов и не запускает симуляцию повторно. TP/SL с масштабированием и фиксированные TP/SL остаются отдельными вариантами.</p><div class="table-wrap" id="portfolio-ticker-table"></div></div></section>
<section class="panel<?= $trades !== [] ? ' chart-panel-sticky' : '' ?>" id="chart-panel" data-rendered-entry="<?= h($entryConfig) ?>" data-rendered-exit="<?= h($selectedExitConfig) ?>" data-rendered-exit-snapshots="<?= h((string) $exitConfirmationSnapshots) ?>" <?= $symbolWasSubmitted ? 'hidden' : '' ?>><div class="chart-head chart-info-line"><h2 id="active-chart-title"><?= $symbol !== '' ? h($symbol) : 'Котировки' ?> · <?= h($dateRangeLabel) ?></h2><div class="chart-mode-switch" role="group" aria-label="Режим нижнего графика"><button type="button" id="show-quote-chart" aria-pressed="true">Котировки</button><button type="button" id="show-return-chart" aria-pressed="false">Доходность</button></div><span class="count quote-chart-info" title="<?= $visibleQuoteCount ?> исходных снимков; <?= $chartPointCount ?> точек в графике"><?= $visibleQuoteCount ?> снимков<?php if ($chartPointCount < $visibleQuoteCount): ?> · график <?= $chartPointCount ?><?php endif; ?></span><span class="chart-info-item quote-chart-info"><i class="dot" style="background:#86a8ff"></i>Last · цвет по дням</span><span class="chart-info-item quote-chart-info"><i class="session-marker"></i>Биржевые часы США · 16:30–23:00 Киев</span></div>
<div id="return-history-controls" hidden><p class="small-note">График и таблица используют выбранные выше пару, порог входа и TakeProfit &amp; StopLoss. Доходность сохраняется при расчёте вариантов стратегии и показывает результат по закрытым сделкам; открытая позиция отмечена как временная.</p><p class="history-import-status" id="strategy-return-status" role="status" aria-live="polite"></p></div>
<?php if ($hasHistoricalCandles): ?><p class="hint quote-chart-info">Исторические минутные свечи: Bid, Ask и Last восстановлены по цене Close; биржевой спред в этих данных отсутствует.</p><?php endif; ?>
<div id="quote-chart-view"><?php if ($rows === []): ?><div class="empty">За выбранный период сохраненных данных пока нет.</div><?php else: ?>
<div class="chart-display"><div class="chart-wrap"><svg id="chart" role="img" aria-label="График bid, ask и last для <?= h($symbol) ?>"></svg><div id="tooltip" aria-hidden="true"></div></div><nav class="chart-day-controls" id="chart-day-controls" aria-label="День на графике"><button class="chart-day-button" type="button" data-chart-day="" aria-pressed="true">Весь график</button></nav></div>
<?php endif; ?></div><div id="return-chart-view" hidden><div class="return-chart-placeholder" id="return-chart-placeholder" hidden></div><div class="strategy-return-chart-wrap" id="return-chart-plot" hidden><svg id="strategy-return-chart" role="img" aria-label="График накопленной доходности стратегии"></svg></div><p class="small-note" id="strategy-return-summary">Доходность для выбранного списка и периода ещё не сохранена. Нажмите «Рассчитать доходность» в блоке общего рейтинга.</p></div></section>
<script>
(() => {
    const key = 'bitget-basic-checks-date-range-v1';
    let saved;
    try { saved = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) { return; }
    if (!saved || typeof saved !== 'object') return;
    const params = new URLSearchParams(location.search);
    let changed = false;
    if (!params.has('start_day') && saved.start_day_auto === '0' && /^\d{4}-\d{2}-\d{2}$/.test(saved.start_day || '')) {
        params.set('start_day', saved.start_day);
        params.set('start_day_auto', '0');
        changed = true;
    }
    if (!params.has('end_day') && /^\d{4}-\d{2}-\d{2}$/.test(saved.end_day || '')) {
        params.set('end_day', saved.end_day);
        changed = true;
    }
    if (!changed) return;
    document.documentElement.style.visibility = 'hidden';
    location.replace(`${location.pathname}?${params.toString()}${location.hash}`);
})();
</script>
<section class="panel" id="results" <?= $symbolWasSubmitted ? 'hidden' : '' ?>><div class="chart-head"><h2 id="results-title">Сделки стратегии</h2><span class="count" id="ticker-results-count"><?= $closedTradeCount ?> закрытых сделок<?php if ($openTradeCount > 0): ?> · <?= $openTradeCount ?> открыто на последнем снимке<?php endif; ?></span></div>
<div id="ticker-results-view">
<div class="summary"><div class="stat"><span>Стартовый баланс</span><strong><?= money($startingBalance) ?> USDT</strong></div><div class="stat"><span>Итог за период</span><strong class="<?= bccomp($totalPnl, '0', 24) > 0 ? 'gain' : (bccomp($totalPnl, '0', 24) < 0 ? 'loss' : '') ?>"><?= bccomp($totalPnl, '0', 24) > 0 ? '+' : '' ?><?= money($totalPnl) ?> USDT</strong></div><div class="stat"><span>Текущий баланс</span><strong><?= money($balance) ?> USDT</strong></div><?php if ($martingaleMode !== 'none'): ?><div class="stat"><span>Цепочки в расчёте</span><strong><?= $martingaleChainCount ?></strong></div><div class="stat"><span>Медианный результат цепочки</span><strong><?= $martingaleMedianChain === null ? '—' : (($martingaleMedianChain[0] === '-' ? '' : '+') . money($martingaleMedianChain)) ?> USDT</strong></div><div class="stat"><span>Худшая цепочка</span><strong class="<?= $martingaleWorstChain !== null && bccomp($martingaleWorstChain, '0', 24) < 0 ? 'loss' : '' ?>"><?= $martingaleWorstChain === null ? '—' : money($martingaleWorstChain) ?> USDT</strong></div><div class="stat"><span>Просадка по результатам цепочек</span><strong><?= money($martingaleMaxDrawdown) ?> USDT</strong></div><?php endif; ?></div>
<?php if ($simulationCosts['include_funding'] || (float) $simulationCosts['slippage_bps'] > 0): ?><p class="small-note">В расчёте: funding <?= $simulationCosts['include_funding'] ? 'учтён по истории Bitget; mark price заменён midpoint первой доступной котировки после settlement' : 'отключён' ?> · проскальзывание <?= h($simulationCosts['slippage_bps']) ?> б.п. в неблагоприятную сторону. Применены действующие правила контракта Bitget.</p><?php endif; ?><?php if ($martingaleMode !== 'none' && $martingaleChainCount > 0): ?><p class="small-note">Метрики сгруппированы по одной мартингейл-попытке. Просадка рассчитана по балансу после каждой цепочки; позиция, оставшаяся открытой на последнем снимке, учитывается по этому снимку.</p><?php endif; ?>
<?php if (count($rows) < 2): ?><div class="empty">Нужно минимум два снимка в выбранном периоде, чтобы выполнить симуляцию.</div>
<?php elseif ($trades === []): ?><div class="empty"><?= $isImmediateEntry ? 'Для немедленной заявки нужны минимум два снимка в выбранном периоде.' : 'В выбранном периоде не было сигнала на движение ' . ($entryDirection === 'both' ? '±' : ($entryDirection === 'long' ? '+' : '−')) . number_format($momentumThreshold, 1, ',', ' ') . '% ' . ($entrySignalMode === 'within' ? 'в пределах ' : 'за ') . $momentumLookback . ' снимков.' ?></div>
<?php else: ?><div class="table-wrap"><table><thead><tr><th>#</th><th>Вход · Киев</th><th><?= h($entrySideLabel) ?></th><th>Выход · Киев</th><th><?= h($exitSideLabel) ?></th><th>Количество</th><th>Сумма входа</th><th>Валовая прибыль</th><th>Комиссия вход + выход</th><th>Funding</th><th>Итог сделки</th><th>Баланс после</th><th>Причина выхода</th></tr></thead><tbody>
<?php foreach ($trades as $index => $trade): $resultCompare = bccomp($trade['net'], '0', 24); $tradeIsOpen = $isOpenTrade($trade); $rowClass = $tradeIsOpen ? ($resultCompare > 0 ? 'trade-open-positive' : ($resultCompare < 0 ? 'trade-open-negative' : 'trade-flat')) : ($resultCompare > 0 ? 'trade-positive' : ($resultCompare < 0 ? 'trade-negative' : 'trade-flat')); $totalFees = bcadd($trade['entry_fee'], $trade['exit_fee'], 24); $tradeNotional = bcmul($trade['quantity'], $trade['entry_price'], 24); $tradeEntryLabel = $trade['is_short'] ? 'Продажа Bid' : 'Покупка Ask'; $tradeExitLabel = $trade['is_short'] ? 'Покупка Ask' : 'Продажа Bid'; ?>
<tr class="<?= $rowClass ?> trade-row" tabindex="0" data-entry-time="<?= epochMilliseconds($trade['entry']['received_at_utc']) ?>" data-exit-time="<?= epochMilliseconds($trade['exit']['received_at_utc']) ?>" data-entry-price="<?= h((string) $trade['entry_price']) ?>" data-exit-price="<?= h((string) $trade['exit_price']) ?>" data-is-short="<?= $trade['is_short'] ? '1' : '0' ?>" data-net="<?= h((string) $trade['net']) ?>"><td><?= $index + 1 ?></td><td><?= h(formatKyivTime($trade['entry']['received_at_utc'], $timezone)) ?></td><td><?= h($tradeEntryLabel . ' · ' . formatPrice((string) $trade['entry_price'])) ?></td><td><?= h(formatKyivTime($trade['exit']['received_at_utc'], $timezone)) ?></td><td><?= h($tradeExitLabel . ' · ' . formatPrice((string) $trade['exit_price'])) ?></td><td><?= money($trade['quantity']) ?></td><td><?= money($tradeNotional) ?> USDT</td><td><?= money($trade['gross']) ?></td><td><?= money($totalFees) ?></td><td><?= money($trade['funding'] ?? '0') ?></td><td><?= $resultCompare > 0 ? '+' : '' ?><?= money($trade['net']) ?></td><td><?= money($trade['balance']) ?></td><td class="reason"><?= $tradeIsOpen ? 'Открыта · результат по последнему снимку' : h($trade['reason']) ?></td></tr>
<?php endforeach; ?></tbody></table></div><p class="small-note">Зелёная строка — прибыльная закрытая сделка; красная — убыточная. Результат открытой позиции по последнему снимку включён в итог и может измениться. Между снимками результат закрытой сделки может превысить заданный порог.</p><?php endif; ?><p class="footer">Источник: локальная база <?= h($db['database']) ?> · шаг сбора <?= (int) $config['poll_interval_seconds'] ?> сек.</p></div><div id="return-results-view" hidden><div class="chart-head"><h3>Сохранённые точки доходности</h3><span class="count" id="return-points-count"></span></div><p class="small-note">Эта таблица показывает те же сохранённые точки, по которым построен график выше. Закрытия за один день объединены; временная открытая позиция отмечена отдельно.</p><div class="table-wrap" id="return-points-table"><div class="empty">Сначала выберите сохранённую доходность.</div></div></div></section>
</main>
<?php if ($rows !== []): ?><script>
const datasets = <?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
const chartDays = <?= json_encode(array_keys($chartDays), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
const colors = {bid_price:'#42d6a4',ask_price:'#ff7188',last_price:'#86a8ff'};
const svg=document.querySelector('#chart'), ns='http://www.w3.org/2000/svg',H=390,p={l:88,r:80,t:20,b:42};let W=H*svg.clientWidth/Math.max(1,svg.clientHeight);
const all=Object.values(datasets).flat(); const xs=all.map(x=>x.t);let xmin=Math.min(...xs),xmax=Math.max(...xs),ymin=<?= json_encode($chartPriceMin) ?>,ymax=<?= json_encode($chartPriceMax) ?>;if(xmin===xmax){xmin-=30000;xmax+=30000}if(ymin===ymax){ymin*=.999;ymax*=1.001}const fullXmin=xmin,fullXmax=xmax;let viewMin=xmin,viewMax=xmax;const pad=(ymax-ymin)*.12;ymin-=pad;ymax+=pad;
const X=x=>p.l+(x-viewMin)/(viewMax-viewMin)*(W-p.l-p.r),Y=y=>p.t+(ymax-y)/(ymax-ymin)*(H-p.t-p.b);
function el(tag,attrs={},text=''){const n=document.createElementNS(ns,tag);for(const[k,v]of Object.entries(attrs))n.setAttribute(k,v);if(text)n.textContent=text;svg.appendChild(n);return n}
svg.setAttribute('viewBox',`0 0 ${W} ${H}`);svg.setAttribute('preserveAspectRatio','none');
const yGrid=[];for(let i=0;i<5;i++){const v=ymin+(ymax-ymin)*i/4,y=Y(v);yGrid.push(el('line',{x1:p.l,y1:y,x2:W-p.r,y2:y,stroke:'#273149','stroke-width':1}));el('text',{x:p.l-12,y:y+4,'text-anchor':'end',fill:'#8995ad','font-size':12},Number(v).toPrecision(7).replace(/\.?0+$/,''))}
let xTicks=[];function buildXAxis(){for(const tick of xTicks){tick.line.remove();tick.label.remove()}xTicks=[];const tickCount=Math.max(4,Math.floor(svg.clientWidth/180));for(let i=0;i<=tickCount;i++){const line=el('line',{y1:p.t,y2:H-p.b,stroke:'#202a40','stroke-width':1}),label=el('text',{y:H-12,'text-anchor':'middle',fill:'#8995ad','font-size':12});xTicks.push({line,label,index:i/tickCount})}}function renderXAxis(){const day=(t)=>new Date(t).toLocaleDateString('en-CA',{timeZone:'Europe/Kyiv'}),showDate=day(viewMin)!==day(viewMax);for(const tick of xTicks){const t=viewMin+(viewMax-viewMin)*tick.index,x=X(t),date=new Date(t);tick.line.setAttribute('x1',x);tick.line.setAttribute('x2',x);tick.label.setAttribute('x',x);const time=date.toLocaleTimeString('ru-RU',{timeZone:'Europe/Kyiv',hour:'2-digit',minute:'2-digit'});tick.label.textContent=showDate?`${date.toLocaleDateString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit'})} ${time}`:time}}buildXAxis();renderXAxis();
const tradingHoursGroup=el('g',{'pointer-events':'none','aria-hidden':'true'});
function kyivWallTimeToEpoch(day,hour,minute){const[y,m,d]=day.split('-').map(Number),utc=Date.UTC(y,m-1,d,hour,minute),parts=new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/Kyiv',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date(utc)),values=Object.fromEntries(parts.map(part=>[part.type,part.value])),asUtc=Date.UTC(Number(values.year),Number(values.month)-1,Number(values.day),Number(values.hour),Number(values.minute),Number(values.second));return utc-(asUtc-utc)}
function renderTradingHours(){tradingHoursGroup.replaceChildren();let day=new Date(viewMin).toLocaleDateString('en-CA',{timeZone:'Europe/Kyiv'}),lastDay=new Date(viewMax).toLocaleDateString('en-CA',{timeZone:'Europe/Kyiv'});while(day<=lastDay){const start=Math.max(viewMin,kyivWallTimeToEpoch(day,16,30)),end=Math.min(viewMax,kyivWallTimeToEpoch(day,23,0));if(end>start){const band=document.createElementNS(ns,'rect');band.setAttribute('x',X(start));band.setAttribute('y',p.t);band.setAttribute('width',Math.max(0,X(end)-X(start)));band.setAttribute('height',H-p.t-p.b);band.setAttribute('fill','#080d19');band.setAttribute('opacity','.28');tradingHoursGroup.appendChild(band)}const next=new Date(day+'T12:00:00Z');next.setUTCDate(next.getUTCDate()+1);day=next.toISOString().slice(0,10)}}renderTradingHours();
const tradeInterval=el('rect',{y:p.t,height:H-p.t-p.b,fill:'#c4a7ff',opacity:.18,visibility:'hidden','pointer-events':'none'}),tradeEntryLine=el('line',{y1:p.t,y2:H-p.b,stroke:'#d9c6ff','stroke-width':2,'stroke-dasharray':'5 4',visibility:'hidden','pointer-events':'none'}),tradeExitLine=el('line',{y1:p.t,y2:H-p.b,stroke:'#d9c6ff','stroke-width':2,'stroke-dasharray':'5 4',visibility:'hidden','pointer-events':'none'});
const tradeEntryArrow=el('text',{x:0,y:0,'text-anchor':'middle','dominant-baseline':'central','font-size':17,'font-weight':800,'paint-order':'stroke','stroke':'#0b1020','stroke-width':2.5,visibility:'hidden','pointer-events':'none'});
const tradeEntryPriceLabel=el('text',{x:0,y:0,'text-anchor':'middle','dominant-baseline':'central','font-size':11,'font-weight':700,'fill':'#e9eefb','paint-order':'stroke','stroke':'#0b1020','stroke-width':4,'stroke-linejoin':'round',visibility:'hidden','pointer-events':'none'}),tradeExitPriceLabel=el('text',{x:0,y:0,'text-anchor':'middle','dominant-baseline':'central','font-size':11,'font-weight':700,'fill':'#e9eefb','paint-order':'stroke','stroke':'#0b1020','stroke-width':4,'stroke-linejoin':'round',visibility:'hidden','pointer-events':'none'});
const tradeExitBadge=el('g',{visibility:'hidden','pointer-events':'none'}),tradeExitBadgeCircle=document.createElementNS(ns,'circle'),tradeFaceLeftEye=document.createElementNS(ns,'circle'),tradeFaceRightEye=document.createElementNS(ns,'circle'),tradeFaceMouth=document.createElementNS(ns,'path'),tradeExitBadgeTitle=document.createElementNS(ns,'title');
tradeExitBadgeCircle.setAttribute('r','9');tradeExitBadgeCircle.setAttribute('fill','#0b1020');tradeExitBadgeCircle.setAttribute('stroke-width','1.8');for(const eye of [tradeFaceLeftEye,tradeFaceRightEye]){eye.setAttribute('cy','-2');eye.setAttribute('r','1.1')}tradeFaceLeftEye.setAttribute('cx','-3');tradeFaceRightEye.setAttribute('cx','3');tradeFaceMouth.setAttribute('fill','none');tradeFaceMouth.setAttribute('stroke-width','1.5');tradeFaceMouth.setAttribute('stroke-linecap','round');tradeExitBadge.append(tradeExitBadgeCircle,tradeFaceLeftEye,tradeFaceRightEye,tradeFaceMouth,tradeExitBadgeTitle);svg.appendChild(tradeExitBadge);
const defs=el('defs'),clipPath=document.createElementNS(ns,'clipPath'),clipRect=document.createElementNS(ns,'rect'),clipId='chart-plot-clip';clipPath.setAttribute('id',clipId);clipRect.setAttribute('x',p.l);clipRect.setAttribute('y',p.t);clipRect.setAttribute('width',W-p.l-p.r);clipRect.setAttribute('height',H-p.t-p.b);clipPath.appendChild(clipRect);defs.appendChild(clipPath);
const lastDayPalette=['#86a8ff','#ffc857','#b69cff','#55d6be','#ff8f70','#6ed0ff','#d6a6ff','#a8df65'];
const lastDayKey=t=>new Date(t).toLocaleDateString('en-CA',{timeZone:'Europe/Kyiv'}),lastDayColors=new Map(),lastDaySegments=[];
for(const day of chartDays)lastDayColors.set(day,lastDayPalette[lastDayColors.size%lastDayPalette.length]);
for(const q of datasets.last_price){const day=lastDayKey(q.t);if(!lastDayColors.has(day))lastDayColors.set(day,lastDayPalette[lastDayColors.size%lastDayPalette.length]);let segment=lastDaySegments[lastDaySegments.length-1];if(!segment||segment.day!==day){segment={day,color:lastDayColors.get(day),points:lastDaySegments.length?[lastDaySegments[lastDaySegments.length-1].points.slice(-1)[0]]:[]};lastDaySegments.push(segment)}segment.points.push(q)}
const chartDayControls=document.querySelector('#chart-day-controls'),chartDayBounds=new Map();
for(const day of chartDays){const nextDay=new Date(day+'T12:00:00Z');nextDay.setUTCDate(nextDay.getUTCDate()+1);const min=Math.max(fullXmin,kyivWallTimeToEpoch(day,0,0)),max=Math.min(fullXmax,kyivWallTimeToEpoch(nextDay.toISOString().slice(0,10),0,0)-1);chartDayBounds.set(day,{min,max});const button=document.createElement('button');button.type='button';button.className='chart-day-button';button.dataset.chartDay=day;const swatch=document.createElement('span');swatch.className='chart-day-swatch';swatch.setAttribute('aria-hidden','true');swatch.style.backgroundColor=lastDayColors.get(day);button.append(swatch,document.createTextNode(new Date(day+'T12:00:00Z').toLocaleDateString('ru-RU',{timeZone:'UTC',day:'2-digit',month:'2-digit'})));button.title=new Date(day+'T12:00:00Z').toLocaleDateString('ru-RU',{timeZone:'UTC',day:'numeric',month:'long',year:'numeric'});button.setAttribute('aria-label',`Показать ${button.title}`);button.setAttribute('aria-pressed','false');chartDayControls.appendChild(button)}
const markers={},paths={};for(const[key]of Object.entries(datasets)){if(key==='last_price'){paths[key]=lastDaySegments.map(segment=>el('path',{d:'',fill:'none',stroke:segment.color,'stroke-width':2.7,'stroke-linejoin':'round','stroke-linecap':'round',opacity:.98,'clip-path':`url(#${clipId})`}))}else paths[key]=[];markers[key]=el('circle',{r:5,fill:colors[key],stroke:'#0b1020','stroke-width':2,visibility:'hidden','pointer-events':'none'})}
function renderSeries(){lastDaySegments.forEach((segment,index)=>paths.last_price[index].setAttribute('d',segment.points.map((q,i)=>`${i?'L':'M'}${X(q.t).toFixed(2)},${Y(q.v).toFixed(2)}`).join(' ')))}renderSeries();
const lastLegend=document.querySelector('.legend span:first-child');if(lastLegend)lastLegend.title='Цвет линии Last соответствует календарному дню по Киеву; при наведении видны Bid, Ask и Last';
const crosshair=el('line',{y1:p.t,y2:H-p.b,stroke:'#d5defa','stroke-width':1,'stroke-dasharray':'4 5',opacity:.65,visibility:'hidden','pointer-events':'none'}),priceCrosshair=el('line',{x1:p.l,x2:W-p.r,stroke:'#d5defa','stroke-width':1,'stroke-dasharray':'4 5',opacity:.75,visibility:'hidden','pointer-events':'none'}),priceCrosshairBadge=el('rect',{x:W-p.r+3,y:p.t,width:p.r-6,height:20,rx:4,fill:'#607df4',visibility:'hidden','pointer-events':'none'}),priceCrosshairLabel=el('text',{x:W-p.r+8,y:p.t+14,fill:'#fff','font-size':11,'font-weight':700,visibility:'hidden','pointer-events':'none'}),tip=document.querySelector('#tooltip'),wrap=document.querySelector('.chart-wrap'),sampleTimes=datasets.last_price.map(q=>q.t);
function nearestIndex(t){let lo=0,hi=sampleTimes.length;while(lo<hi){const m=(lo+hi)>>1;if(sampleTimes[m]<t)lo=m+1;else hi=m}if(lo===0)return 0;if(lo>=sampleTimes.length)return sampleTimes.length-1;return t-sampleTimes[lo-1]<=sampleTimes[lo]-t?lo-1:lo}
let selectedChartDay=null,tradeChartDayRange=null;
function chartDayRangeForTrade(row){const entryDay=lastDayKey(Number(row.dataset.entryTime)),exitDay=lastDayKey(Number(row.dataset.exitTime));return entryDay<=exitDay?{start:entryDay,end:exitDay}:{start:exitDay,end:entryDay}}
function selectedChartBounds(){if(selectedChartDay===null)return{min:fullXmin,max:fullXmax};if(tradeChartDayRange){const days=chartDays.filter(day=>day>=tradeChartDayRange.start&&day<=tradeChartDayRange.end);const first=chartDayBounds.get(days[0]),last=chartDayBounds.get(days[days.length-1]);if(first&&last)return{min:first.min,max:last.max}}return chartDayBounds.get(selectedChartDay)}
function updateChartDayButtons(){for(const button of chartDayControls.querySelectorAll('.chart-day-button')){const day=button.dataset.chartDay;const active=tradeChartDayRange&&day!==''?day>=tradeChartDayRange.start&&day<=tradeChartDayRange.end:day===(selectedChartDay??'');button.setAttribute('aria-pressed',String(active))}}
function selectChartDay(day,{tradeRow=null,animate=true}={}){selectedChartDay=day;tradeChartDayRange=day!==null&&tradeRow?chartDayRangeForTrade(tradeRow):null;if(tradeChartDayRange?.start===tradeChartDayRange?.end)tradeChartDayRange=null;updateChartDayButtons();cancelTradeMotion();activeTradeRow=tradeRow;displayedTradeRow=tradeRow;tradePriceLabelsVisible=false;if(!tradeRow)drawTradeInterval(null);const target=selectedChartBounds();if(!animate){viewMin=target.min;viewMax=target.max;renderTradeView();return}animateTradeView(target.min,target.max,3000,()=>{if(tradeRow&&displayedTradeRow===tradeRow){drawTradeInterval(tradeRow);scheduleTradeZoom(tradeRow)}})}
function tradeViewBounds(start,end){const base=selectedChartBounds(),span=base.max-base.min,duration=Math.max(0,end-start),durationRatio=Math.min(1,duration/span);if(durationRatio>=1)return base;const contextRatio=Math.max(.02,Math.min(.4,Math.sqrt(durationRatio)*1.4)),targetSpan=Math.min(span,Math.max(duration,span*contextRatio)),center=(start+end)/2,min=Math.max(base.min,Math.min(center-targetSpan/2,base.max-targetSpan));return{min,max:min+targetSpan}}
let activeTradeRow=null,displayedTradeRow=null,hoveredTradeRow=null,focusedTradeRow=null,tradeDelayTimer=null,tradeHideTimer=null,tradeAnimationFrame=null,tradePriceLabelsVisible=false;
function drawTradeInterval(row=displayedTradeRow){if(!row){tradeInterval.setAttribute('visibility','hidden');tradeEntryLine.setAttribute('visibility','hidden');tradeExitLine.setAttribute('visibility','hidden');tradeEntryArrow.setAttribute('visibility','hidden');tradeEntryPriceLabel.setAttribute('visibility','hidden');tradeExitPriceLabel.setAttribute('visibility','hidden');tradeExitBadge.setAttribute('visibility','hidden');return}const start=Number(row.dataset.entryTime),end=Number(row.dataset.exitTime),x1=X(start),x2=X(end),entryPrice=Number(row.dataset.entryPrice),exitPrice=Number(row.dataset.exitPrice),net=Number(row.dataset.net),isShort=row.dataset.isShort==='1';tradeInterval.setAttribute('x',x1);tradeInterval.setAttribute('width',Math.max(2,x2-x1));tradeInterval.setAttribute('visibility','visible');tradeEntryLine.setAttribute('x1',x1);tradeEntryLine.setAttribute('x2',x1);tradeExitLine.setAttribute('x1',x2);tradeExitLine.setAttribute('x2',x2);tradeEntryLine.setAttribute('visibility','visible');tradeExitLine.setAttribute('visibility','visible');tradeEntryArrow.textContent=isShort?'↓':'↑';tradeEntryArrow.setAttribute('x',x1);tradeEntryArrow.setAttribute('y',Math.max(p.t+10,Y(entryPrice)-40));tradeEntryArrow.setAttribute('fill',isShort?'#ff7188':'#42d6a4');tradeEntryArrow.setAttribute('visibility','visible');tradeEntryPriceLabel.textContent=row.dataset.entryPrice;tradeEntryPriceLabel.setAttribute('x',x1);tradeEntryPriceLabel.setAttribute('y',Math.min(H-p.b-5,Y(entryPrice)+30));tradeEntryPriceLabel.setAttribute('visibility',tradePriceLabelsVisible?'visible':'hidden');tradeExitPriceLabel.textContent=row.dataset.exitPrice;tradeExitPriceLabel.setAttribute('x',x2);tradeExitPriceLabel.setAttribute('y',Math.min(H-p.b-5,Y(exitPrice)+30));tradeExitPriceLabel.setAttribute('visibility',tradePriceLabelsVisible?'visible':'hidden');const outcomeColor=net>0?'#42d6a4':(net<0?'#ff7188':'#aab5cb');tradeExitBadge.setAttribute('transform',`translate(${x2} ${Math.max(p.t+10,Y(exitPrice)-40)})`);for(const facePart of [tradeExitBadgeCircle,tradeFaceLeftEye,tradeFaceRightEye,tradeFaceMouth])facePart.setAttribute('stroke',outcomeColor);tradeFaceLeftEye.setAttribute('fill',outcomeColor);tradeFaceRightEye.setAttribute('fill',outcomeColor);tradeFaceMouth.setAttribute('d',net>=0?'M -4 2 Q 0 6 4 2':'M -4 5 Q 0 1 4 5');tradeFaceMouth.setAttribute('stroke',outcomeColor);tradeExitBadgeTitle.textContent=`${net>0?'Прибыль':(net<0?'Убыток':'Без прибыли и убытка')}: ${row.dataset.net} USDT`;tradeExitBadge.setAttribute('visibility','visible')}
function renderTradeView(){renderXAxis();renderTradingHours();renderSeries();drawTradeInterval()}
function cancelTradeMotion(){if(tradeDelayTimer!==null){clearTimeout(tradeDelayTimer);tradeDelayTimer=null}if(tradeHideTimer!==null){clearTimeout(tradeHideTimer);tradeHideTimer=null}if(tradeAnimationFrame!==null){cancelAnimationFrame(tradeAnimationFrame);tradeAnimationFrame=null}}
function animateTradeView(targetMin,targetMax,duration,done){if(tradeAnimationFrame!==null)cancelAnimationFrame(tradeAnimationFrame);const fromMin=viewMin,fromMax=viewMax,started=performance.now();function frame(now){const raw=Math.min(1,(now-started)/duration),progress=raw*raw*(3-2*raw);viewMin=fromMin+(targetMin-fromMin)*progress;viewMax=fromMax+(targetMax-fromMax)*progress;renderTradeView();if(raw<1)tradeAnimationFrame=requestAnimationFrame(frame);else{tradeAnimationFrame=null;done?.()}}tradeAnimationFrame=requestAnimationFrame(frame)}
function tradeZoomDelay(row){const duration=Math.max(0,Number(row.dataset.exitTime)-Number(row.dataset.entryTime)),minimum=10*60*1000,maximum=24*60*60*1000;if(duration<=minimum)return 1000;const progress=Math.min(1,Math.log(duration/minimum)/Math.log(maximum/minimum));return Math.round((1+4*progress)*1000)}
function scheduleTradeZoom(row){tradeDelayTimer=setTimeout(()=>{tradeDelayTimer=null;if(activeTradeRow!==row)return;const target=tradeViewBounds(Number(row.dataset.entryTime),Number(row.dataset.exitTime));animateTradeView(target.min,target.max,3000,()=>{if(activeTradeRow!==row)return;tradePriceLabelsVisible=true;drawTradeInterval()})},tradeZoomDelay(row))}
function activateTradeRow(row){if(tradeHideTimer!==null){clearTimeout(tradeHideTimer);tradeHideTimer=null}if(activeTradeRow===row)return;const rowDay=lastDayKey(Number(row.dataset.entryTime));if(selectedChartDay!==null&&selectedChartDay!==rowDay){selectChartDay(rowDay,{tradeRow:row});return}cancelTradeMotion();activeTradeRow=row;displayedTradeRow=row;tradePriceLabelsVisible=false;tradeChartDayRange=selectedChartDay!==null?chartDayRangeForTrade(row):null;if(tradeChartDayRange?.start===tradeChartDayRange?.end)tradeChartDayRange=null;updateChartDayButtons();const base=selectedChartBounds();viewMin=base.min;viewMax=base.max;renderTradeView();scheduleTradeZoom(row)}
function scheduleTradeHide(){if(tradeHideTimer!==null)clearTimeout(tradeHideTimer);tradeHideTimer=setTimeout(()=>{tradeHideTimer=null;if(hoveredTradeRow||focusedTradeRow)return;cancelTradeMotion();activeTradeRow=null;tradeChartDayRange=null;updateChartDayButtons();tradePriceLabelsVisible=false;drawTradeInterval();const base=selectedChartBounds();animateTradeView(base.min,base.max,3000,()=>{if(hoveredTradeRow||focusedTradeRow)return;displayedTradeRow=null;drawTradeInterval()})},0)}
let pinnedTradeRow=null;
function toggleTradeRowFilter(row){pinnedTradeRow=pinnedTradeRow===row?null:row;for(const tradeRow of document.querySelectorAll('.trade-row')){const hidden=pinnedTradeRow!==null&&tradeRow!==pinnedTradeRow;tradeRow.hidden=hidden;tradeRow.classList.toggle('trade-row-selected',!hidden&&pinnedTradeRow===tradeRow)}if(pinnedTradeRow===row)row.focus()}
chartDayControls.addEventListener('click',event=>{const button=event.target.closest('[data-chart-day]');if(!button)return;selectChartDay(button.dataset.chartDay||null)});
document.querySelectorAll('.trade-row').forEach(row=>{row.addEventListener('pointerenter',()=>{hoveredTradeRow=row;activateTradeRow(row)});row.addEventListener('pointerleave',()=>{if(hoveredTradeRow===row)hoveredTradeRow=null;scheduleTradeHide()});row.addEventListener('focus',()=>{focusedTradeRow=row;activateTradeRow(row)});row.addEventListener('blur',()=>{if(focusedTradeRow===row)focusedTradeRow=null;scheduleTradeHide()});row.addEventListener('click',()=>toggleTradeRowFilter(row));row.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleTradeRowFilter(row)}})});
svg.addEventListener('pointermove',event=>{const rect=svg.getBoundingClientRect(),viewX=(event.clientX-rect.left)/rect.width*W,viewY=(event.clientY-rect.top)/rect.height*H;if(viewY<p.t||viewY>H-p.b)return;const plotX=Math.max(p.l,Math.min(W-p.r,viewX)),t=viewMin+(plotX-p.l)/(W-p.l-p.r)*(viewMax-viewMin),idx=nearestIndex(t),time=sampleTimes[idx],px=X(time),price=ymin+(H-p.b-viewY)/(H-p.t-p.b)*(ymax-ymin),priceY=Math.max(p.t,Math.min(H-p.b,viewY)),badgeY=Math.max(p.t,Math.min(H-p.b-20,priceY-10));crosshair.setAttribute('x1',px);crosshair.setAttribute('x2',px);crosshair.setAttribute('visibility','visible');priceCrosshair.setAttribute('y1',priceY);priceCrosshair.setAttribute('y2',priceY);priceCrosshair.setAttribute('visibility','visible');priceCrosshairBadge.setAttribute('x',W-p.r+3);priceCrosshairBadge.setAttribute('y',badgeY);priceCrosshairBadge.setAttribute('width',p.r-6);priceCrosshairBadge.setAttribute('visibility','visible');priceCrosshairLabel.setAttribute('x',W-p.r+8);priceCrosshairLabel.setAttribute('y',badgeY+14);priceCrosshairLabel.textContent=Number(price).toPrecision(7).replace(/\.?0+$/,'');priceCrosshairLabel.setAttribute('visibility','visible');let markup=`<div class="tip-time">${new Date(time).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'})} · Киев</div>`;for(const[key,arr]of Object.entries(datasets)){const q=arr[idx];markers[key].setAttribute('cx',X(q.t));markers[key].setAttribute('cy',Y(q.v));markers[key].setAttribute('fill',key==='last_price'?lastDayColors.get(lastDayKey(q.t)):colors[key]);markers[key].setAttribute('visibility','visible');const name={bid_price:'Bid',ask_price:'Ask',last_price:'Last'}[key];markup+=`<div class="tip-row"><span style="color:${key==='last_price'?lastDayColors.get(lastDayKey(q.t)):colors[key]}">${name}</span><b>${q.label}</b></div>`}tip.innerHTML=markup;tip.style.display='block';const wr=wrap.getBoundingClientRect(),left=event.clientX-wr.left+14;tip.style.left=`${Math.min(left,wr.width-tip.offsetWidth-4)}px`;tip.style.top=`${Math.max(4,Math.min(event.clientY-wr.top-20,wr.height-tip.offsetHeight-4))}px`});
svg.addEventListener('pointerleave',()=>{tip.style.display='none';crosshair.setAttribute('visibility','hidden');priceCrosshair.setAttribute('visibility','hidden');priceCrosshairBadge.setAttribute('visibility','hidden');priceCrosshairLabel.setAttribute('visibility','hidden');for(const m of Object.values(markers))m.setAttribute('visibility','hidden')});
function resizeQuoteChart(){if(!svg.clientWidth||!svg.clientHeight)return;W=H*svg.clientWidth/Math.max(1,svg.clientHeight);svg.setAttribute('viewBox',`0 0 ${W} ${H}`);priceCrosshair.setAttribute('x2',W-p.r);priceCrosshairBadge.setAttribute('x',W-p.r+3);priceCrosshairBadge.setAttribute('width',p.r-6);priceCrosshairLabel.setAttribute('x',W-p.r+8);for(const line of yGrid)line.setAttribute('x2',W-p.r);clipRect.setAttribute('width',W-p.l-p.r);buildXAxis();renderXAxis();renderTradingHours();renderSeries()}window.addEventListener('resize',resizeQuoteChart);new ResizeObserver(resizeQuoteChart).observe(svg);
</script><?php endif; ?>
<script>
const filtersForm = document.querySelector('.filters');
const basicChecksDateRangeStorageKey = 'bitget-basic-checks-date-range-v1';
function persistBasicChecksDateRange() {
    if (!filtersForm) return;
    const startDay = filtersForm.elements.namedItem('start_day')?.value || '';
    const endDay = filtersForm.elements.namedItem('end_day')?.value || '';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(startDay) || !/^\d{4}-\d{2}-\d{2}$/.test(endDay)) return;
    try {
        sessionStorage.setItem(basicChecksDateRangeStorageKey, JSON.stringify({
            start_day: filtersForm.elements.namedItem('start_day_auto')?.value === '0' ? startDay : '',
            start_day_auto: filtersForm.elements.namedItem('start_day_auto')?.value || '1',
            end_day: endDay,
        }));
    } catch (_) {}
}
if (new URLSearchParams(location.search).has('start_day') || new URLSearchParams(location.search).has('end_day')) {
    persistBasicChecksDateRange();
}
filtersForm?.addEventListener('input', event => {
    if (event.target.name === 'start_day') filtersForm.elements.namedItem('start_day_auto').value = '0';
    if (event.target.name === 'start_day' || event.target.name === 'end_day') persistBasicChecksDateRange();
}, true);
filtersForm?.addEventListener('change', event => {
    if (event.target.name === 'start_day') filtersForm.elements.namedItem('start_day_auto').value = '0';
    if (event.target.name === 'start_day' || event.target.name === 'end_day') persistBasicChecksDateRange();
}, true);

const get_returnStatus=()=>document.querySelector('#strategy-return-status');
const get_returnChart=()=>document.querySelector('#strategy-return-chart');
const get_returnChartPlaceholder=()=>document.querySelector('#return-chart-placeholder');
const get_returnSummary=()=>document.querySelector('#strategy-return-summary');
const get_returnHistoryControls=()=>document.querySelector('#return-history-controls');
const get_returnChartPlot=()=>document.querySelector('#return-chart-plot');
const get_chartPanel=()=>document.querySelector('#chart-panel');
const get_quoteChartView=()=>document.querySelector('#quote-chart-view');
const get_returnChartView=()=>document.querySelector('#return-chart-view');
const get_tickerResultsView=()=>document.querySelector('#ticker-results-view');
const get_returnResultsView=()=>document.querySelector('#return-results-view');
const get_returnPointsTable=()=>document.querySelector('#return-points-table');
const get_returnPointsCount=()=>document.querySelector('#return-points-count');
function setChartMode(mode){
    const returns=mode==='returns';
    if(get_chartPanel())get_chartPanel().classList.toggle('return-mode',returns);
    if(get_quoteChartView())get_quoteChartView().hidden=returns;
    if(get_returnChartView())get_returnChartView().hidden=!returns;
    if(get_returnHistoryControls())get_returnHistoryControls().hidden=!returns;
    if(get_tickerResultsView())get_tickerResultsView().hidden=returns;
    if(get_returnResultsView())get_returnResultsView().hidden=!returns;
    document.querySelector('#show-quote-chart')?.setAttribute('aria-pressed',String(!returns));
    document.querySelector('#show-return-chart')?.setAttribute('aria-pressed',String(returns));
    const title=document.querySelector('#results-title');
    const count=document.querySelector('#ticker-results-count');
    if(title)title.textContent=returns?'Точки доходности стратегии':'Сделки стратегии';
    if(count)count.hidden=returns;
    document.querySelectorAll('.quote-chart-info').forEach(item=>item.hidden=returns);
    const chartTitle=document.querySelector('#active-chart-title');
    if(chartTitle&&returns){
        const symbol=document.querySelector('#symbol-select')?.value||'';
        const entry=document.querySelector('#entry-config');
        const exit=document.querySelector('#exit-config');
        chartTitle.textContent=symbol&&entry?.value&&exit?.value?`${symbol} · ${entry.selectedOptions[0]?.textContent||entry.value} · ${exit.selectedOptions[0]?.textContent||exit.value}`:'Доходность стратегии';
    }
    if(returns){
        if(activeReturnRunKey){
            setReturnStatus('Загружаю доходность выбранной стратегии…');
            if(get_returnSummary())get_returnSummary().textContent='Загружаю сохранённые точки доходности…';
            if(get_returnPointsTable())get_returnPointsTable().innerHTML='<div class="empty">Загружаю сохранённые точки…</div>';
            loadReturnChart(activeReturnRunKey,document.querySelector('#symbol-select')?.value||'')
                .catch(error=>setReturnStatus(error instanceof Error?error.message:'Не удалось обновить график.'));
        }else{
            setReturnStatus('Ищу расчёт для выбранных пары и стратегии…');
            if(get_returnSummary())get_returnSummary().textContent='Ищу сохранённые точки доходности для выбранной стратегии…';
            if(get_returnChartPlaceholder()){get_returnChartPlaceholder().textContent='Ищу сохранённые точки доходности для выбранной стратегии…';get_returnChartPlaceholder().hidden=false;}
            scheduleTickerReturnHistoryCheck();
        }
    }
}
document.addEventListener('click',event=>{
    const button=event.target.closest('#show-quote-chart, #show-return-chart');
    if(!button)return;
    setChartMode(button.id==='show-return-chart'?'returns':'quotes');
});
let activeReturnRunKey = '';
let selectorReturnRunKey = '';
let unusableSelectorReturnRunKey = '';
let returnRankedSymbol = '';
filtersForm?.addEventListener('change',event=>{
    if(!event.target.matches('#symbol-select, #entry-config, #exit-config'))selectorReturnRunKey='';
},true);
let returnChartLoadVersion = 0;
const returnStorageKey = scope => {
    const params = new URLSearchParams(new FormData(filtersForm));
    params.delete('end_day');
    params.delete('symbol');
    params.delete('entry_config');
    params.delete('exit_config');
    const stable = [...params.entries()].sort(([a],[b]) => a.localeCompare(b));
    return 'bitget-strategy-return:' + (filtersForm.querySelector('[name="ticker_set"]')?.value || '') + ':' + scope + ':' + JSON.stringify(stable);
};
function setReturnStatus(text){ if(get_returnStatus()) get_returnStatus().textContent=text; }
async function requestReturnData(runKey, symbol='', entryId='', exitId=''){
    const query = new URLSearchParams({run_key:runKey});
    const startDay=filtersForm?.elements.namedItem('start_day')?.value||'';
    const endDay=filtersForm?.elements.namedItem('end_day')?.value||'';
    if(startDay)query.set('start_day',startDay);
    if(endDay)query.set('end_day',endDay);
    if(symbol) query.set('symbol',symbol);
    if(entryId) query.set('entry_id',entryId);
    if(exitId) query.set('exit_id',exitId);
    const response = await fetch('strategy-return-data.php?' + query.toString(), {headers:{Accept:'application/json'},cache:'no-store'});
    const data = await response.json();
    if(!response.ok || data.status==='error') throw new Error(data.message || 'Не удалось загрузить график доходности.');
    return data;
}
async function refreshSelectorsFromReturnHistory(runKey, preferredEntry='', selectBestEntry=false){
    const requestVersion=exitRankingRequestVersion;
    const symbol=document.querySelector('#symbol-select')?.value||'';
    const selectedEntry=preferredEntry||document.querySelector('#entry-config')?.value||'';
    if(!symbol)return false;
    const query=new URLSearchParams({run_key:runKey,symbol,entry_id:selectedEntry,rank_options:'1',min_trades:filtersForm.querySelector('[name="save_mode"]')?.value==='0'?'1':'3'});
    query.set('start_day',filtersForm.elements.namedItem('start_day')?.value||'');
    query.set('end_day',filtersForm.elements.namedItem('end_day')?.value||'');
    query.set('exit_confirmation_mode',filtersForm.elements.namedItem('exit_confirmation_mode')?.value||'immediate');
    query.set('exit_confirmation_snapshots',filtersForm.elements.namedItem('exit_confirmation_snapshots')?.value||'60');
    query.set('rank_progress','1');
    if(selectBestEntry&&!entryOrderLocked)query.set('prefer_best_entry','1');
    const response=await fetch('strategy-return-data.php?'+query.toString(),{headers:{Accept:'application/json'},cache:'no-store'});
    let data;
    if(response.headers.get('Content-Type')?.includes('application/x-ndjson')&&response.body){
        const reader=response.body.getReader(), decoder=new TextDecoder();
        let buffered='';
        const consume=line=>{
            if(!line.trim())return;
            const item=JSON.parse(line);
            if(item.status==='progress'){
                if(requestVersion===exitRankingRequestVersion)setStrategySortIndicators(true,item.progress,item.progress);
            }else data=item;
        };
        while(true){
            const {value,done}=await reader.read();
            buffered+=done?decoder.decode():decoder.decode(value,{stream:true});
            const lines=buffered.split('\n');buffered=lines.pop();lines.forEach(consume);
            if(done){consume(buffered);break;}
            if(requestVersion!==exitRankingRequestVersion){await reader.cancel();return false;}
        }
    }else data=await response.json();
    if(requestVersion!==exitRankingRequestVersion)return false;
    if(!data)throw new Error('Не удалось получить результат сортировки.');
    if(!response.ok||data.status!=='ready')throw new Error(data.message||'Не удалось отсортировать пороги по сохранённой доходности.');
    const hasRankedEntries=Array.isArray(data.ranked_entries)&&data.ranked_entries.length>0;
    const hasRankedExits=Array.isArray(data.ranked_exits)&&data.ranked_exits.length>0;
    if(hasRankedEntries){
        const bestEntry=data.ranked_entries.reduce((best,item)=>!best||Number(item.best_pnl)>Number(best.best_pnl)?item:best,null);
        const effectiveEntry=selectBestEntry&&!entryOrderLocked?data.selected_entry_id:selectedEntry;
        const selectedAvailable=data.ranked_entries.some(item=>item.id===effectiveEntry);
        renderEntryOptions(data.ranked_entries,selectedAvailable?effectiveEntry:(bestEntry?.id||selectedEntry),'%');
    }
    if(hasRankedExits){
        const bestExit=data.ranked_exits.reduce((best,item)=>!best||Number(item.pnl)>Number(best.pnl)?item:best,null);
        renderExitOptions(data.ranked_exits.map(item=>({id:item.id,label:item.label,pnl:Number(item.pnl),snapshots:Number(document.querySelector('#exit-confirmation-snapshots')?.value)||60})),bestExit?.id||document.querySelector('#exit-config')?.value||'',Number(document.querySelector('#exit-confirmation-snapshots')?.value)||60);
    }
    return hasRankedEntries&&hasRankedExits;
}
function renderReturnPointsTable(points, pointTimes=new Map()){
    const rows=Array.isArray(points)?points:[];
    if(get_returnPointsCount())get_returnPointsCount().textContent=`${rows.length.toLocaleString('ru-RU')} событий`;
    if(!get_returnPointsTable())return;
    if(!rows.length){get_returnPointsTable().innerHTML='<div class="empty">Для выбранной пары и стратегии нет сохранённых событий сделок.</div>';return;}
    const table=document.createElement('table');
    const hasTradeTimes=rows.some(point=>point.entry_at_utc||point.exit_at_utc);
    table.innerHTML=hasTradeTimes
        ? '<thead><tr><th>#</th><th>Вход · Киев</th><th>Выход / расчёт · Киев</th><th>Изменение от предыдущей точки</th><th>Накопленная доходность</th><th>Статус</th></tr></thead>'
        : '<thead><tr><th>#</th><th>Дата и время · Киев</th><th>Тип точки</th><th>Накопленный результат</th><th>Доходность</th><th>Закрытых сделок</th></tr></thead>';
    const body=document.createElement('tbody');
    rows.forEach((point,index)=>{
        const provisional=point.point_type==='provisional', pnl=Number(point.cumulative_return_percent??point.cumulative_pnl), percent=Number(point.return_percent);
        const row=document.createElement('tr');
        row.className=provisional?'return-point-provisional':(pnl>0?'return-point-positive':(pnl<0?'return-point-negative':''));
        const day=String(point.point_day).slice(0,10),timestamp=pointTimes.get(day);
        const dateLabel=timestamp?new Date(timestamp).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'}):day;
        const formatPercent=value=>`${value>=0?'+':''}${value.toLocaleString('ru-RU',{minimumFractionDigits:4,maximumFractionDigits:6})}%`;
        const values=hasTradeTimes
            ? [String(index+1),new Date(point.entry_at_utc).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'}),new Date(point.exit_at_utc).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'}),formatPercent(percent),formatPercent(Number(point.cumulative_return_percent??pnl)),provisional?'Предварительная · открыта':'Закрыта']
            : [String(index+1),dateLabel,provisional?'Временная · открытая позиция': 'Закрытые сделки',`${pnl>=0?'+':''}${pnl.toLocaleString('ru-RU',{minimumFractionDigits:4,maximumFractionDigits:6})} USDT`,formatPercent(percent),Number(point.closed_trade_count||0).toLocaleString('ru-RU')];
        values.forEach((value,column)=>{const cell=document.createElement('td');cell.textContent=value;if(column===2)cell.className='reason';row.append(cell);});
        body.append(row);
    });
    table.append(body);get_returnPointsTable().replaceChildren(table);
}
let disposeReturnChartInteraction=()=>{};
function drawReturnChart(data){
    disposeReturnChartInteraction();
    if(!get_returnChart()) return;
    get_returnChart().replaceChildren();
    const chartTitle=document.querySelector('#active-chart-title');
    if(get_chartPanel()?.classList.contains('return-mode')&&chartTitle)chartTitle.textContent=`${data.selected.symbol} · ${data.selected.entry_label||data.selected.entry_id} · ${data.selected.exit_label||data.selected.exit_id}`;
    const chart=get_returnChart();
    const ns='http://www.w3.org/2000/svg', width=Math.max(500,chart.clientWidth||800), height=340, p={l:112,r:22,t:22,b:72};
    get_returnChart().setAttribute('viewBox',`0 0 ${width} ${height}`);
    const pointTimes=new Map();
    const kyivDay=value=>new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Kyiv',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(value));
    document.querySelectorAll('#results .trade-row').forEach(row=>{
        const timestamp=Number(row.dataset.exitTime);
        if(!Number.isFinite(timestamp)||timestamp<=0)return;
        const day=kyivDay(timestamp);
        if(row.classList.contains('trade-open-positive')||row.classList.contains('trade-open-negative'))pointTimes.set(day,timestamp);
        else if(!pointTimes.has(day)||timestamp>pointTimes.get(day))pointTimes.set(day,timestamp);
    });
    const points=(data.points||[]).map(point=>{const day=String(point.point_day).slice(0,10),timestamp=point.exit_at_utc?Date.parse(point.exit_at_utc):pointTimes.get(day);return {...point,day,value:Number(point.cumulative_return_percent??point.cumulative_pnl),timestamp:Number.isFinite(timestamp)?timestamp:Date.parse(day+'T12:00:00Z'),hasTradeTime:Boolean(point.exit_at_utc||timestamp)};}).filter(point=>Number.isFinite(point.value));
    renderReturnPointsTable(data.points||[],pointTimes);
    if(!points.length){if(get_returnChartPlot())get_returnChartPlot().hidden=true;if(get_returnChartPlaceholder()){get_returnChartPlaceholder().textContent='Для этой пары и стратегии в выбранном периоде нет закрытых сделок или открытой позиции.';get_returnChartPlaceholder().hidden=false;}get_returnSummary().textContent='Сохранённых точек доходности нет.';return;}
    if(get_returnChartPlaceholder())get_returnChartPlaceholder().hidden=true;
    if(get_returnChartPlot())get_returnChartPlot().hidden=false;
    const chartRange=data.chart_range||data.run;
    const start=Date.parse(String(chartRange.start_day)+'T00:00:00Z'), end=Date.parse(String(chartRange.end_day)+'T23:59:59Z');
    let minX=Number.isFinite(start)?start:Date.parse(points[0].day+'T12:00:00Z'), maxX=Number.isFinite(end)?end:Date.parse(points.at(-1).day+'T12:00:00Z');
    minX=Math.min(minX,...points.map(point=>point.timestamp));maxX=Math.max(maxX,...points.map(point=>point.timestamp));
    if(maxX<=minX)maxX=minX+86400000;
    const values=[0,...points.map(point=>point.value)], minY=Math.min(...values), maxY=Math.max(...values), pad=Math.max((maxY-minY)*.12,.05), y0=minY-pad, y1=maxY+pad;
    const x=value=>p.l+(value-minX)/(maxX-minX)*(width-p.l-p.r), y=value=>height-p.b-(value-y0)/(y1-y0)*(height-p.t-p.b);
    const el=(tag,attrs={},text='')=>{const node=document.createElementNS(ns,tag);for(const [key,value] of Object.entries(attrs))node.setAttribute(key,String(value));if(text)node.textContent=text;return node;};
    get_returnChart().append(el('line',{x1:p.l,y1:y(0),x2:width-p.r,y2:y(0),stroke:'#53617b','stroke-dasharray':'4 4'}));
    get_returnChart().append(el('line',{x1:p.l,y1:p.t,x2:p.l,y2:height-p.b,stroke:'#53617b'}));
    get_returnChart().append(el('line',{x1:p.l,y1:height-p.b,x2:width-p.r,y2:height-p.b,stroke:'#53617b'}));
    const money=value=>`${value>=0?'+':''}${value.toLocaleString('ru-RU',{minimumFractionDigits:2,maximumFractionDigits:2})}%`;
    const preciseMoney=value=>`${value>=0?'+':''}${value.toLocaleString('ru-RU',{minimumFractionDigits:6,maximumFractionDigits:6})}%`;
    const yTickCount=4;
    for(let index=0;index<=yTickCount;index++){
        const value=y0+(y1-y0)*index/yTickCount,py=y(value);
        chart.append(el('line',{x1:p.l,x2:width-p.r,y1:py,y2:py,stroke:'#29364f'}));
        chart.append(el('text',{x:p.l-10,y:py+4,'text-anchor':'end',fill:'#b7c1d5','font-size':11},money(value)));
    }
    const dateFormatter=new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'});
    const tickFormatter=new Intl.DateTimeFormat('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',...(maxX-minX<86400000?{hour:'2-digit',minute:'2-digit'}:{})});
    const xTickCount=Math.max(1,Math.min(8,Math.floor((width-p.l-p.r)/110)));
    for(let index=0;index<=xTickCount;index++){
        const time=minX+(maxX-minX)*index/xTickCount,px=x(time);
        chart.append(el('text',{x:px,y:height-p.b+17,'text-anchor':index===0?'start':index===xTickCount?'end':'middle',fill:'#b7c1d5','font-size':11},tickFormatter.format(new Date(time))));
    }
    const coords=[{day:String(data.run.start_day),value:0,point_type:'baseline',timestamp:minX,hasTradeTime:false},...points].map(point=>({point,...{x:x(point.timestamp),y:y(point.value)}}));
    const closedCoords=coords.filter(item=>item.point.point_type!=='provisional');
    if(closedCoords.length>1){let path=`M ${closedCoords[0].x} ${closedCoords[0].y}`;for(const item of closedCoords.slice(1))path+=` H ${item.x} V ${item.y}`;get_returnChart().append(el('path',{d:path,fill:'none',stroke:'#86a8ff','stroke-width':2.5,'stroke-linejoin':'round'}));}
    const provisionalCoords=coords.filter(item=>item.point.point_type==='provisional');
    if(provisionalCoords.length){const provisional=provisionalCoords.at(-1),previous=closedCoords.at(-1)||coords[0];get_returnChart().append(el('path',{d:`M ${previous.x} ${previous.y} H ${provisional.x} V ${provisional.y}`,fill:'none',stroke:'#ffbd59','stroke-width':2,'stroke-dasharray':'5 4'}));}
    for(const item of coords.slice(1)){
        const provisional=item.point.point_type==='provisional';
        const circle=el('circle',{cx:item.x,cy:item.y,r:provisional?5:3.5,fill:provisional?'#ffbd59':'#86a8ff',stroke:'#10182b','stroke-width':1.5});
        const labelDate=item.point.hasTradeTime?new Date(item.point.timestamp).toLocaleString('ru-RU',{timeZone:'Europe/Kyiv',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}):item.point.day;
        circle.append(el('title',{},`${labelDate}: ${preciseMoney(item.point.value)} · ${provisional?'временная открытая сделка':'закрытые сделки'}`));
        chart.append(circle);
    }
    const hoverVertical=el('line',{y1:p.t,y2:height-p.b,stroke:'#d5defa','stroke-width':1,'stroke-dasharray':'4 5',opacity:.72,visibility:'hidden','pointer-events':'none'});
    const hoverHorizontal=el('line',{x1:p.l,x2:width-p.r,stroke:'#d5defa','stroke-width':1,'stroke-dasharray':'4 5',opacity:.72,visibility:'hidden','pointer-events':'none'});
    const hoverValueBg=el('rect',{x:4,width:p.l-10,height:20,rx:4,fill:'#607df4',visibility:'hidden','pointer-events':'none'});
    const hoverValueLabel=el('text',{x:8,fill:'#fff','font-size':11,'font-weight':700,visibility:'hidden','pointer-events':'none'});
    const dateBadgeWidth=154;
    const hoverDateBg=el('rect',{width:dateBadgeWidth,height:21,rx:4,fill:'#607df4',visibility:'hidden','pointer-events':'none'});
    const hoverDateLabel=el('text',{fill:'#fff','font-size':11,'font-weight':700,'text-anchor':'middle',visibility:'hidden','pointer-events':'none'});
    const activePoint=el('circle',{r:7,fill:'#ffbd59',stroke:'#fff','stroke-width':2,'pointer-events':'none'});
    chart.append(hoverVertical,hoverHorizontal,hoverValueBg,hoverValueLabel,hoverDateBg,hoverDateLabel,activePoint);
    const pointCoords=coords.slice(1);
    let activeIndex=0,hovering=false;
    const showPoint=index=>{
        activeIndex=index;
        const item=pointCoords[index],pointerX=item.x,pointerY=item.y;
        activePoint.setAttribute('cx',pointerX);activePoint.setAttribute('cy',pointerY);
        activePoint.setAttribute('fill',item.point.point_type==='provisional'?'#ffbd59':'#86a8ff');
        hoverVertical.setAttribute('x1',pointerX);hoverVertical.setAttribute('x2',pointerX);hoverVertical.setAttribute('visibility','visible');
        hoverHorizontal.setAttribute('y1',pointerY);hoverHorizontal.setAttribute('y2',pointerY);hoverHorizontal.setAttribute('visibility','visible');
        const valueY=Math.max(p.t,Math.min(height-p.b-20,pointerY-10));
        hoverValueBg.setAttribute('y',valueY);hoverValueBg.setAttribute('visibility','visible');
        hoverValueLabel.setAttribute('y',valueY+14);hoverValueLabel.textContent=preciseMoney(item.point.value);hoverValueLabel.setAttribute('visibility','visible');
        const dateX=Math.max(p.l,Math.min(width-p.r-dateBadgeWidth,pointerX-dateBadgeWidth/2));
        hoverDateBg.setAttribute('x',dateX);hoverDateBg.setAttribute('y',height-p.b+29);hoverDateBg.setAttribute('visibility','visible');
        hoverDateLabel.setAttribute('x',dateX+dateBadgeWidth/2);hoverDateLabel.setAttribute('y',height-p.b+44);
        hoverDateLabel.textContent=dateFormatter.format(new Date(item.point.timestamp));
        hoverDateLabel.setAttribute('visibility','visible');
    };
    showPoint(0);
    chart.onpointerenter=()=>{hovering=true;};
    chart.onpointermove=event=>{
        hovering=true;
        const screenTransform=chart.getScreenCTM();
        if(!screenTransform)return;
        const pointer=chart.createSVGPoint();pointer.x=event.clientX;pointer.y=event.clientY;
        const localPoint=pointer.matrixTransform(screenTransform.inverse()),pointerX=localPoint.x,pointerY=localPoint.y;
        let nearest=0,minimumDistance=Infinity;
        pointCoords.forEach((item,index)=>{
            const dx=item.x-pointerX,dy=item.y-pointerY;
            const screenX=dx*screenTransform.a+dy*screenTransform.c,screenY=dx*screenTransform.b+dy*screenTransform.d;
            const distance=screenX*screenX+screenY*screenY;
            if(distance<minimumDistance){minimumDistance=distance;nearest=index;}
        });
        showPoint(nearest);
    };
    chart.onpointerleave=()=>{hovering=false;};
    const animation=window.setInterval(()=>{
        if(!chart.isConnected){disposeReturnChartInteraction();return;}
        if(hovering||document.hidden||!chart.getClientRects().length)return;
        showPoint((activeIndex+1)%pointCoords.length);
    },3000);
    disposeReturnChartInteraction=()=>{
        window.clearInterval(animation);
        chart.onpointerenter=null;chart.onpointermove=null;chart.onpointerleave=null;
    };
    const last=points.at(-1), provisional=last.point_type==='provisional';
    get_returnSummary().textContent=`${data.selected.symbol} · ${data.selected.entry_label||data.selected.entry_id} · ${data.selected.exit_label||data.selected.exit_id} · накопленная доходность ${preciseMoney(last.value)}${provisional?` · предварительная сделка ${Number(last.return_percent)>=0?'+':''}${Number(last.return_percent).toFixed(4)}%, позиция открыта`:''}`;
}
async function loadReturnChart(runKey, preferSymbol=''){
    const loadVersion=++returnChartLoadVersion;
    let data;
    setReturnStatus('Загружаю сохранённые точки выбранной стратегии…');
    if(get_returnChartPlaceholder()){get_returnChartPlaceholder().textContent='Загружаю сохранённые точки доходности…';get_returnChartPlaceholder().hidden=false;}
    do{
        const entryId=document.querySelector('#entry-config')?.value||'';
        const exitId=document.querySelector('#exit-config')?.value||'';
        if(!entryId||!exitId){setReturnStatus('Сначала дождитесь загрузки выбранных порога входа и TakeProfit & StopLoss.');return false;}
        data=await requestReturnData(runKey,preferSymbol,entryId,exitId);
        if(loadVersion!==returnChartLoadVersion)return false;
        if(data.status==='running'){
            setReturnStatus('Варианты TP/SL готовы. Сохраняю точки графика доходности…');
            if(get_returnChartPlaceholder()){get_returnChartPlaceholder().textContent='Доходность для выбранной стратегии ещё сохраняется. График появится после завершения записи событий сделок.';get_returnChartPlaceholder().hidden=false;}
            await new Promise(resolve=>window.setTimeout(resolve,1500));
        }
    }while(data.status==='running'&&loadVersion===returnChartLoadVersion);
    if(data.status==='missing'){setReturnStatus('Сохранённый расчёт не найден.');if(get_returnChartPlaceholder()){get_returnChartPlaceholder().textContent='Сохранённая история сделок для этой пары и периода не найдена.';get_returnChartPlaceholder().hidden=false;}return false;}
    if(data.status==='rebuild_required'){const message=data.message||'Пересчитайте доходность, чтобы сохранить время каждой сделки.';setReturnStatus(message);if(get_returnChartPlaceholder()){get_returnChartPlaceholder().textContent=message;get_returnChartPlaceholder().hidden=false;}return false;}
    if(data.status!=='ready'){setReturnStatus('Расчёт ещё выполняется.');if(get_returnChartPlaceholder()){get_returnChartPlaceholder().textContent='Расчёт ещё выполняется; точки доходности пока не готовы.';get_returnChartPlaceholder().hidden=false;}return false;}
    activeReturnRunKey=runKey;
    selectorReturnRunKey=runKey;
    if(loadVersion!==returnChartLoadVersion)return false;
    setReturnStatus(`Для выбранной стратегии загружено событий сделок: ${Number(data.points?.length||0).toLocaleString('ru-RU')}.`);
    drawReturnChart(data);
    return true;
}
function rememberPortfolioReturnHistory(ranking, scope='set'){
    const runKey=ranking?.return_run_key;
    if(!runKey)return;
    activeReturnRunKey=runKey;
    try{
        localStorage.setItem(returnStorageKey(scope),runKey);
        localStorage.setItem(returnStorageKey('last'),JSON.stringify({scope,runKey}));
    }catch{}
    setReturnStatus(`Сохранено событий сделок: ${Number(ranking.return_point_count||0).toLocaleString('ru-RU')}.`);
    loadReturnChart(runKey,document.querySelector('#symbol-select')?.value||'').catch(error=>setReturnStatus(error instanceof Error?error.message:'Не удалось загрузить историю доходности.'));
}
async function refreshReturnSelection(){
    if(!activeReturnRunKey)return;
    try{
        const symbol=document.querySelector('#symbol-select')?.value||'';
        const entry=document.querySelector('#entry-config')?.value||'';
        const exit=document.querySelector('#exit-config')?.value||'';
        if(!entry||!exit)return;
        const data=await requestReturnData(activeReturnRunKey,symbol,entry,exit);
        if(data.status==='ready')drawReturnChart(data);
    }catch(error){setReturnStatus(error instanceof Error?error.message:'Не удалось обновить график.');}
}
window.addEventListener('resize',()=>{if(activeReturnRunKey)refreshReturnSelection();});
let strategyRangeComparisonVersion = 0;
let strategyRangeComparisonTimer = null;
let strategyRangeComparisonRequest = null;
function scheduleStrategyRangeComparison() {
    ++strategyRangeComparisonVersion;
    window.clearTimeout(strategyRangeComparisonTimer);
    strategyRangeComparisonRequest?.abort();
    const panel = document.querySelector('#strategy-range-comparison');
    if (!panel) return;
    panel.hidden = !document.querySelector('#symbol-select')?.value;
    if (panel.hidden) return;
    document.querySelector('#strategy-range-comparison-table').replaceChildren();
    document.querySelector('#strategy-range-comparison-status').textContent = 'Загружаю доходность соседних диапазонов…';
    strategyRangeComparisonTimer = window.setTimeout(loadStrategyRangeComparison, 300);
}
async function loadStrategyRangeComparison() {
    const version = strategyRangeComparisonVersion;
    const params = new URLSearchParams(new FormData(filtersForm));
    const status = document.querySelector('#strategy-range-comparison-status');
    const tableWrap = document.querySelector('#strategy-range-comparison-table');
    const selection = document.querySelector('#strategy-range-comparison-selection');
    selection.textContent = [params.get('symbol'), document.querySelector('#entry-config')?.selectedOptions[0]?.textContent,
        document.querySelector('#exit-config')?.selectedOptions[0]?.textContent].filter(Boolean).join(' · ');
    if (!params.get('entry_config') || !params.get('exit_config')) {
        status.textContent = 'Выберите порог входа и TakeProfit & StopLoss.';
        return;
    }
    strategyRangeComparisonRequest = new AbortController();
    try {
        const response = await fetch('strategy-range-comparison.php?' + params.toString(), {
            headers:{Accept:'application/json'}, cache:'no-store', signal:strategyRangeComparisonRequest.signal,
        });
        const data = await response.json();
        if (version !== strategyRangeComparisonVersion) return;
        if (!response.ok || data.status !== 'ready') throw new Error(data.message || 'Не удалось загрузить сравнение диапазонов.');
        const table = document.createElement('table');
        table.innerHTML = '<thead><tr><th>Диапазон</th><th>С даты</th><th>По дату</th><th>Дней</th><th>Доходность</th><th>Закрытых сделок</th></tr></thead>';
        const body = document.createElement('tbody');
        const dateText = value => new Date(value + 'T12:00:00Z').toLocaleDateString('ru-RU', {day:'2-digit',month:'short',year:'numeric',timeZone:'UTC'});
        for (const range of data.ranges) {
            const row = document.createElement('tr');
            if (range.offset === 0) { row.style.background = '#263968'; row.setAttribute('aria-current', 'true'); }
            const cells = [`(${range.offset > 0 ? '+' : ''}${range.offset})${range.offset === 0 ? ' · текущий' : ''}`,
                dateText(range.start_day),dateText(range.end_day),String(range.days) + (range.partial ? ' · до сегодня' : ''),
                range.return_percent === null ? 'Нет сохранённых закрытий' : optionPercent(Number(range.return_percent)),
                Number(range.trade_count).toLocaleString('ru-RU')];
            cells.forEach((text, index) => {
                const cell = document.createElement('td');
                cell.textContent = text;
                if (index === 4 && range.return_percent !== null) cell.style.color = Number(range.return_percent) >= 0 ? '#3bd286' : '#f36c78';
                row.append(cell);
            });
            body.append(row);
        }
        table.append(body);
        tableWrap.replaceChildren(table);
        status.textContent = `Длина выбранного диапазона: ${data.range_days} дн. · диапазонов: ${data.ranges.length} из 11`;
    } catch (error) {
        if (version !== strategyRangeComparisonVersion || error.name === 'AbortError') return;
        status.textContent = error.message || 'Не удалось загрузить сравнение диапазонов.';
    }
}
filtersForm?.addEventListener('change', scheduleStrategyRangeComparison);
scheduleStrategyRangeComparison();
async function restoreReturnChart(){
    const symbol=document.querySelector('#symbol-select')?.value||'', scopes=symbol?['ticker:'+symbol,'set']:['set'];
    try{const last=JSON.parse(localStorage.getItem(returnStorageKey('last'))||'null');if(last?.runKey){const restored=await loadReturnChart(last.runKey,symbol);if(restored)return;}}catch{}
    for(const scope of scopes){const key=localStorage.getItem(returnStorageKey(scope));if(!key)continue;try{const restored=await loadReturnChart(key,symbol);if(restored)return;}catch{} }
}
restoreReturnChart();

filtersForm.elements.namedItem('balance').addEventListener('input', event => {
    filtersForm.elements.namedItem('balance_auto').value = '0';
    filtersForm.elements.namedItem('ranking_balance').value = event.currentTarget.value;
    const note = document.querySelector('#common-ranking-balance-note');
    if (note && event.currentTarget.value !== '') note.textContent = `Общий рейтинг всех тикеров рассчитывается от единого стартового баланса ${Number(event.currentTarget.value).toLocaleString('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2})} USDT.`;
});
const tickerSetSelect = document.querySelector('#ticker-set-select');
tickerSetSelect?.addEventListener('change', () => {
    const params = filtersForm ? new URLSearchParams(new FormData(filtersForm)) : new URLSearchParams(location.search);
    params.set('ticker_set', tickerSetSelect.value);
    params.delete('symbol');
    location.href = location.pathname + '?' + params.toString();
});
const favoriteView = <?= $favoriteView ? 'true' : 'false' ?>;
if (favoriteView && filtersForm) {
    filtersForm.querySelectorAll('select, input, button').forEach(control => { control.disabled = true; });
}
async function postFavorite(params, button, status = null) {
    if (!button) return;
    button.disabled = true;
    const originalText = button.textContent;
    if (status) status.textContent = 'Сохраняю в избранное…';
    const body = new URLSearchParams(params);
    try {
        const response = await fetch('favorites.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json'}, body});
        const result = await response.json();
        if (!response.ok || result.status !== 'ok') throw new Error(result.error || 'Не удалось сохранить стратегию');
        if (status) status.textContent = 'Добавлено в избранное.';
        button.textContent = '✓ Добавлено';
        button.title = 'Сохранено в избранном';
    } catch (error) {
        if (status) status.textContent = error.message || 'Не удалось сохранить стратегию';
        button.title = error.message || 'Не удалось сохранить стратегию';
        button.textContent = originalText;
        button.disabled = false;
    }
}
document.querySelector('#add-favorite')?.addEventListener('click', () => {
    if (!filtersForm) return;
    const params = new URLSearchParams(new FormData(filtersForm));
    params.set('favorite_type', 'strategy');
    params.set('favorite_entry_label', document.querySelector('#entry-config')?.selectedOptions[0]?.textContent || '');
    const exitLabel = document.querySelector('#exit-config')?.selectedOptions[0]?.textContent || '';
    params.set('favorite_exit_label', exitLabel);
    const resultMatch = exitLabel.match(/итог\s+([+-]?\d+(?:[.,]\d+)?)\s*USDT/i);
    if (resultMatch) params.set('favorite_result', resultMatch[1].replace(',', '.'));
    params.set('favorite_result_label', 'Итог');
    params.set('favorite_name', symbolSelect.value + ' · текущая стратегия');
    postFavorite(params, document.querySelector('#add-favorite'), document.querySelector('#favorite-status'));
});
function syncViewNavigation() {
    if (!filtersForm) return;
    const values = new URLSearchParams(new FormData(filtersForm));
    const basicLink = document.querySelector('.view-nav a[aria-current="page"]');
    const hedgeLink = document.querySelector('.view-nav a[href*="hedging.php"]');
    if (basicLink) basicLink.href = 'basic-checks.php?' + values.toString();
    if (hedgeLink) hedgeLink.href = 'hedging.php?' + values.toString();
}
syncViewNavigation();
filtersForm?.addEventListener('input', event => {
    if (event.target.name === 'start_day') filtersForm.elements.namedItem('start_day_auto').value = '0';
    syncViewNavigation();
    invalidateTickerReturnSummary(event);
});
filtersForm?.addEventListener('change', event => {
    if (event.target.name === 'start_day') filtersForm.elements.namedItem('start_day_auto').value = '0';
    syncViewNavigation();
    invalidateTickerReturnSummary(event);
});
const rankingGenerationInput = document.querySelector('#ranking-generation');
const saveModeToggle = document.querySelector('#save-mode-toggle');
const saveModeValue = document.querySelector('#save-mode-value');
const symbolSortMode = document.querySelector('#symbol-sort-mode');
const symbolSelect = document.querySelector('#symbol-select');
const tickerReturnSortStatus = document.querySelector('#ticker-return-sort-status');
const tickerSortIndicator = document.querySelector('#ticker-sort-indicator');
const tickerSortProgress = document.querySelector('#ticker-sort-progress');
const demoAvailableTickerSymbols = new Set(<?= json_encode(array_keys($demoAvailableSymbols), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
const knownTickerBestResults = new Map();
let tickerSortShownAt = 0;
let tickerSortHideTimer = null;
let tickerSortVisibilityVersion = 0;
let tickerReturnSummaryRunKey = '';
let tickerReturnSummaryRequestVersion = 0;
let tickerReturnSummaryCheckTimer = null;
function invalidateTickerReturnSummary(event) {
    if (['symbol', 'entry_config', 'exit_config'].includes(event.target?.name)) return;
    tickerReturnSummaryRequestVersion++;
    tickerReturnSummaryRunKey = '';
    setTickerReturnSortPending(true);
    if (tickerReturnSortStatus) tickerReturnSortStatus.textContent = 'Проверяю доходность перед сортировкой торговых пар…';
    renderTickerBestResults([]);
    window.clearTimeout(tickerReturnSummaryCheckTimer);
    tickerReturnSummaryCheckTimer = window.setTimeout(() => void checkReturnHistoryActionState(), 300);
}
function setTickerReturnSortPending(pending, progress = null) {
    if (!tickerSortIndicator) return;
    const version = ++tickerSortVisibilityVersion;
    window.clearTimeout(tickerSortHideTimer);
    if (pending) {
        if (tickerSortIndicator.hidden) tickerSortShownAt = performance.now();
        tickerSortIndicator.hidden = false;
        if (tickerSortProgress) tickerSortProgress.textContent = ` (${Math.max(0,Math.min(100,Number(progress)||0)).toLocaleString('ru-RU',{maximumFractionDigits:1})}%) `;
        return;
    }
    // Cached summaries can finish before a browser paints the loading state.
    // Keep the indicator visible briefly so the sort action is still apparent.
    const remaining = Math.max(0, 650 - (performance.now() - tickerSortShownAt));
    tickerSortHideTimer = window.setTimeout(() => {
        if (version !== tickerSortVisibilityVersion) return;
        tickerSortIndicator.hidden = true;
        if (tickerSortProgress) tickerSortProgress.textContent = '';
    }, remaining);
}
function renderTickerBestResults(items) {
    knownTickerBestResults.clear();
    const currentSymbol = symbolSelect.value;
    const options = new Map([...symbolSelect.options].map(option => [option.value, option]));
    for (const [symbol, option] of options) {
        if (symbol === '') {
            option.textContent = 'Выберите торговую пару';
            option.removeAttribute('title');
            continue;
        }
        option.textContent = symbol;
        option.classList.toggle('demo-available-ticker', demoAvailableTickerSymbols.has(symbol));
        option.removeAttribute('title');
    }
    for (const item of items) {
        if (item.pnl === null || item.pnl === undefined || item.pnl === '') continue;
        const pnl = Number(item.pnl);
        if (!item.symbol || !Number.isFinite(pnl)) continue;
        knownTickerBestResults.set(item.symbol, {pnl, unit: item.unit || 'USDT', title: item.title || 'Лучший сохранённый результат тикера'});
    }
    const ranked = [...knownTickerBestResults.entries()]
        .filter(([symbol]) => options.has(symbol))
        .sort(([leftSymbol, left], [rightSymbol, right]) => (right.pnl - left.pnl) || leftSymbol.localeCompare(rightSymbol));
    const ordered = [];
    for (const [symbol, result] of ranked) {
        const option = options.get(symbol);
        option.textContent = symbol + ' · ' + pnlText(result.pnl, result.unit);
        option.title = result.title;
        ordered.push(option);
    }
    for (const option of options.values()) if (option.value !== '' && !ordered.includes(option)) ordered.push(option);
    if (options.has('')) ordered.unshift(options.get(''));
    symbolSelect.replaceChildren(...ordered);
    symbolSelect.value = currentSymbol;
}
async function loadCachedTickerBestResults() {
    const summaryKeyAtStart = tickerReturnSummaryRunKey;
    try {
        const params = new URLSearchParams(new FormData(filtersForm));
        params.set('balance', params.get('ranking_balance') || params.get('balance') || '100');
        const response = await fetch('cached-strategy-symbols.php?' + params.toString(), {headers:{Accept:'application/json'}, cache:'no-store'});
        const result = await response.json();
        if (summaryKeyAtStart === '' && tickerReturnSummaryRunKey !== '') return;
        if (response.ok && result.status === 'ready' && Array.isArray(result.symbols)) {
            renderTickerBestResults(result.symbols.map(item => ({
                symbol: item.symbol,
                pnl: item.best_pnl,
                title: item.entry_label ? 'Лучший сохранённый вариант входа: ' + item.entry_label : 'Лучший сохранённый результат тикера',
            })));
        }
    } catch (_) {}
}
async function loadTickerReturnSummary(runKey) {
    if (favoriteView) return;
    const params = new URLSearchParams(new FormData(filtersForm));
    const symbols = [...symbolSelect.options].map(option => option.value).filter(Boolean);
    const summaryIdentity = JSON.stringify([runKey, params.get('start_day'), params.get('end_day'), params.get('save_mode'), params.get('exit_confirmation_mode'), params.get('exit_confirmation_snapshots'), symbols]);
    if (tickerReturnSummaryRunKey === summaryIdentity) return;
    tickerReturnSummaryRunKey = summaryIdentity;
    const requestVersion = ++tickerReturnSummaryRequestVersion;
    setTickerReturnSortPending(true);
    params.set('run_key', runKey);
    params.set('symbols', JSON.stringify(symbols));
    if (tickerReturnSortStatus) tickerReturnSortStatus.textContent = 'Подготавливаю доходность по каждой торговой паре…';
    try {
        while (requestVersion === tickerReturnSummaryRequestVersion) {
            const response = await fetch('ticker-return-summary.php', {
                method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded', Accept:'application/json'},
                body: params.toString(), cache: 'no-store',
            });
            const result = await response.json();
            if (requestVersion !== tickerReturnSummaryRequestVersion) return;
            if (!response.ok || result.status === 'error') throw new Error(result.message || 'Не удалось отсортировать торговые пары.');
            if (result.status === 'ready') {
                setTickerReturnSortPending(false);
                const tickerResults = (result.symbols || []).map(item => ({
                    symbol: item.symbol, pnl: item.pnl, unit: '%',
                    title: item.entry_label ? `Лучший результат: ${item.entry_label} · ${item.exit_label || 'TP/SL не указаны'} · ${Number(item.trade_count || 0).toLocaleString('ru-RU')} сделок` : 'Лучший результат по сохранённым сделкам',
                }));
                renderTickerBestResults(tickerResults);
                if (tickerReturnSortStatus) tickerReturnSortStatus.textContent = `Пары отсортированы по доходности за ${result.start_day} — ${result.end_day}: есть результат у ${Number(result.ranked_count || 0).toLocaleString('ru-RU')} из ${Number(result.universe_size || 0).toLocaleString('ru-RU')}.`;
                return;
            }
            if (result.status === 'missing') {
                setTickerReturnSortPending(false);
                if (tickerReturnSortStatus) tickerReturnSortStatus.textContent = 'Сохранённая доходность для этих дат и списка не найдена.';
                return;
            }
            setTickerReturnSortPending(true, result.progress);
            if (tickerReturnSortStatus) tickerReturnSortStatus.textContent = result.message || 'Сортирую пары по рассчитанной доходности…';
            await wait(2500);
        }
    } catch (error) {
        if (requestVersion === tickerReturnSummaryRequestVersion && tickerReturnSortStatus) {
            setTickerReturnSortPending(false);
            tickerReturnSortStatus.textContent = error.message || 'Не удалось отсортировать торговые пары.';
        }
    }
}
const refreshSymbolsButton = document.querySelector('#refresh-symbols');
const calculateReturnHistoryButton = document.querySelector('#calculate-return-history');
let returnActionCheckVersion = 0;
let returnActionCheckTimer = null;
let tickerReturnCheckVersion = 0;
let tickerReturnCheckTimer = null;
const comparePortfolioRankingsButton = document.querySelector('#compare-portfolio-rankings');
const rankingComparisonSection = document.querySelector('#ranking-comparison');
const rankingComparisonTable = document.querySelector('#ranking-comparison-table');
const rankingComparisonPeriod = document.querySelector('#ranking-comparison-period');
let rankingComparisonData = null;
let rankingComparisonRequestVersion = 0;
function clearRankingComparison() {
    rankingComparisonRequestVersion++;
    rankingComparisonData = null;
    if (comparePortfolioRankingsButton) comparePortfolioRankingsButton.hidden = true;
    if (rankingComparisonSection) rankingComparisonSection.hidden = true;
    if (rankingComparisonTable) rankingComparisonTable.replaceChildren();
}
async function checkPortfolioRankingComparisons(cacheKey) {
    clearRankingComparison();
    const checkVersion = ++rankingComparisonRequestVersion;
    try {
        const response = await fetch('portfolio-ranking-comparison.php?' + new URLSearchParams({cache_key: cacheKey}), {
            headers: {Accept: 'application/json'}, cache: 'no-store',
        });
        const data = await response.json();
        if (checkVersion !== rankingComparisonRequestVersion) return;
        if (!response.ok || data.status !== 'ready' || !Array.isArray(data.runs) || data.runs.length < 2) return;
        rankingComparisonData = data;
        comparePortfolioRankingsButton.hidden = false;
        comparePortfolioRankingsButton.textContent = `Сравнить готовые рейтинги (${data.runs.length})`;
    } catch (_) {}
}
function renderPortfolioRankingComparison(data) {
    if (!rankingComparisonTable || !Array.isArray(data?.runs)) return;
    rankingComparisonPeriod.textContent = `${data.start_day} — ${data.end_day} · ${data.ticker_count} пар`;
    const leadersByRun = data.runs.map(run => new Map((run.ticker_leaders || []).map(item => [item.symbol, item])));
    const symbols = [...new Set(leadersByRun.flatMap(map => [...map.keys()]))].sort((left, right) => left.localeCompare(right));
    const table = document.createElement('table');
    table.className = 'ranking-comparison-table';
    const head = document.createElement('thead');
    const headRow = document.createElement('tr');
    const symbolHeading = document.createElement('th');
    symbolHeading.textContent = 'Торговая пара';
    headRow.appendChild(symbolHeading);
    for (const run of data.runs) {
        const cell = document.createElement('th');
        cell.textContent = run.scenario.label;
        cell.title = `Сценарий расчёта · ${run.cache_key}`;
        headRow.appendChild(cell);
    }
    head.appendChild(headRow);
    table.appendChild(head);
    const body = document.createElement('tbody');
    for (const symbol of symbols) {
        const row = document.createElement('tr');
        const name = document.createElement('th');
        name.scope = 'row';
        name.textContent = symbol;
        row.appendChild(name);
        for (const leaders of leadersByRun) {
            const cell = document.createElement('td');
            const leader = leaders.get(symbol);
            if (!leader || leader.pnl === null || leader.pnl === undefined || !Number.isFinite(Number(leader.pnl))) {
                cell.textContent = '—';
            } else {
                const value = Number(leader.pnl);
                const result = document.createElement('span');
                result.className = 'ranking-comparison-value';
                result.textContent = `${value > 0 ? '+' : ''}${value.toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 4})}%`;
                cell.appendChild(result);
                const detail = document.createElement('span');
                detail.className = 'ranking-comparison-detail';
                detail.textContent = `${leader.entry_label || 'Вход не указан'} · ${leader.exit_label || 'Выход не указан'} · ${Number(leader.trade_count) || 0} сделок`;
                cell.appendChild(detail);
            }
            row.appendChild(cell);
        }
        body.appendChild(row);
    }
    table.appendChild(body);
    rankingComparisonTable.replaceChildren(table);
}
comparePortfolioRankingsButton?.addEventListener('click', () => {
    if (!rankingComparisonData) return;
    const show = rankingComparisonSection.hidden;
    if (show) renderPortfolioRankingComparison(rankingComparisonData);
    rankingComparisonSection.hidden = !show;
    comparePortfolioRankingsButton.setAttribute('aria-expanded', String(show));
});
function setReturnHistoryActionState(state) {
    const ready = state === 'ready';
    refreshSymbolsButton.hidden = !ready || favoriteView;
    calculateReturnHistoryButton.hidden = ready || favoriteView;
    calculateReturnHistoryButton.disabled = favoriteView || state === 'running';
    calculateReturnHistoryButton.textContent = state === 'running'
        ? 'Доходность рассчитывается…'
        : `Рассчитать доходность для «${tickerSetSelect?.selectedOptions[0]?.textContent || 'списка'}»`;
}
async function checkReturnHistoryActionState() {
    const version = ++returnActionCheckVersion;
    if (favoriteView) { setReturnHistoryActionState('missing'); return; }
    setTickerReturnSortPending(true);
    if (tickerReturnSortStatus) tickerReturnSortStatus.textContent = 'Проверяю доходность перед сортировкой торговых пар…';
    const params = new URLSearchParams(new FormData(filtersForm));
    params.set('balance', params.get('ranking_balance') || params.get('balance') || '100');
    params.set('return_history_status', '1');
    try {
        while (version === returnActionCheckVersion) {
            const response = await fetch('portfolio-ranking.php?' + params.toString(), {headers:{Accept:'application/json'}, cache:'no-store'});
            const result = await response.json();
            if (!response.ok || result.status === 'error') throw new Error(result.message || 'Не удалось проверить расчёт доходности');
            if (version !== returnActionCheckVersion) return;
            if (result.status === 'running') {
                setReturnHistoryActionState('running');
                let progress = result.progress_stale ? null : (Number(result.progress) || 0);
                let label = result.progress_stale ? 'Расчёт доходности продолжается, статус давно не обновлялся' : 'Рассчитываю доходность';
                if (!result.progress_stale && result.phase === 'loading_quotes' && Number(result.total_quote_symbols) > 0) {
                    const done = Number(result.loaded_quote_symbols) || 0;
                    const total = Number(result.total_quote_symbols);
                    progress = Math.round(done * 5 / total);
                    label = `Загружаю котировки: ${done} из ${total}${result.current_quote_symbol ? ` · ${result.current_quote_symbol}` : ''}`;
                } else if (!result.progress_stale && result.phase === 'building_signals' && Number(result.total_signal_symbols) > 0) {
                    const done = Number(result.signals_built_symbols) || 0;
                    const total = Number(result.total_signal_symbols);
                    progress = 5 + Math.round(done * 5 / total);
                    label = `Строю сигналы: ${done} из ${total}${result.current_signal_symbol ? ` · ${result.current_signal_symbol}` : ''}`;
                } else if (!result.progress_stale && result.phase === 'simulating_candidates') {
                    progress = 10 + Math.round((Number(result.progress) || 0) * 0.8);
                    label = `Рассчитываю стратегии: ${Number(result.completed_entry_profiles || 0)} из ${Number(result.total_entry_profiles || 0)} порогов${result.entry_profile ? ` · ${result.entry_profile}` : ''}`;
                } else if (!result.progress_stale && result.phase === 'finalizing_return_history') {
                    progress = 99;
                    label = 'Завершаю сохранение событий сделок';
                } else if (!result.progress_stale && result.phase === 'saving_return_history') {
                    progress = result.history_progress === null || result.history_progress === undefined
                        ? null : 90 + Math.round(Number(result.history_progress) * 0.1);
                    label = result.history_progress === null || result.history_progress === undefined
                        ? 'Подготавливаю сохранение выбранных результатов'
                        : `Сохраняю выбранные результаты: ${Number(result.history_completed || 0)} из ${Number(result.history_total || 0)}`;
                }
                calculateReturnHistoryButton.textContent = progress === null ? 'Доходность рассчитывается…' : `Доходность рассчитывается… ${progress}%`;
                if (!portfolioRankingStatusPolling) {
                    portfolioRankingPanel.hidden = false;
                    portfolioRankingStatus.textContent = `${label}${progress === null ? '' : ` · общий прогресс ${progress}%`}${result.progress_stale ? ' · последнее подтверждённое состояние' : ''}`;
                    setRefreshProgress(progress, portfolioRankingStatus.textContent);
                }
                await wait(2000);
                continue;
            }
            setReturnHistoryActionState(result.status === 'ready' ? 'ready' : 'missing');
            if (result.status === 'ready') {
                if (result.run_key && activeReturnRunKey !== result.run_key) {
                    rememberPortfolioReturnHistory({return_run_key: result.run_key, return_point_count: result.point_count || 0});
                }
                if (result.run_key) void loadTickerReturnSummary(result.run_key);
                else setTickerReturnSortPending(false);
            } else if (result.status === 'missing') {
                if (!activeReturnRunKey) {
                    setReturnStatus('Доходность для выбранного списка и дат ещё не рассчитана. Нажмите «Рассчитать доходность» выше.');
                    const summary = document.querySelector('#strategy-return-summary');
                    if (summary) summary.textContent = 'Точки доходности появятся здесь после отдельного расчёта истории для выбранного списка и периода.';
                }
                void loadTickerReturnSummary('');
            } else setTickerReturnSortPending(false);
            return;
        }
    } catch (error) {
        if (version === returnActionCheckVersion) {
            setTickerReturnSortPending(false);
            setReturnHistoryActionState('missing');
            if (error?.message) setReturnStatus(error.message);
        }
    }
}
function scheduleReturnHistoryActionCheck() {
    clearTimeout(returnActionCheckTimer);
    returnActionCheckTimer = window.setTimeout(checkReturnHistoryActionState, 250);
}
async function checkTickerReturnHistory() {
    const version=++tickerReturnCheckVersion;
    const params=new URLSearchParams(new FormData(filtersForm));
    const symbol=params.get('symbol')||'',entry=params.get('entry_config')||'',exit=params.get('exit_config')||'';
    if(!symbol||!entry||!exit||params.get('martingale_mode')!=='none')return;
    params.set('return_history_status','1');
    try{
        const response=await fetch('strategy-ranking.php?'+params.toString(),{headers:{Accept:'application/json'},cache:'no-store'});
        const result=await response.json();
        if(version!==tickerReturnCheckVersion||!response.ok)return;
        if(result.status==='ready'&&result.return_run_key){
            if(activeReturnRunKey!==result.return_run_key){rememberPortfolioReturnHistory({return_run_key:result.return_run_key,return_point_count:result.return_point_count||0},'ticker:'+symbol);}
            else if(get_chartPanel()?.classList.contains('return-mode'))refreshReturnSelection();
        }else if(result.status==='running'){
            setReturnStatus('Сохраняю доходность для выбранной стратегии…');
            if(get_returnChartPlaceholder()){get_returnChartPlaceholder().textContent='События сделок для доходности ещё сохраняются. График появится после завершения записи.';get_returnChartPlaceholder().hidden=false;}
            clearTimeout(tickerReturnCheckTimer);
            tickerReturnCheckTimer=window.setTimeout(checkTickerReturnHistory,2000);
        }else if(result.status==='missing'&&get_chartPanel()?.classList.contains('return-mode')){
            setReturnStatus('Для выбранных пары, периода и параметров ещё нет сохранённой доходности. Дождитесь завершения расчёта вариантов стратегии.');
            if(get_returnChartPlaceholder()){get_returnChartPlaceholder().textContent='Сохранённая история сделок для выбранной пары и стратегии ещё не готова.';get_returnChartPlaceholder().hidden=false;}
            if(get_returnSummary())get_returnSummary().textContent='История доходности для выбранной комбинации пока не сохранена.';
        }
    }catch(_){}
}
function scheduleTickerReturnHistoryCheck(){
    clearTimeout(tickerReturnCheckTimer);
    tickerReturnCheckTimer=window.setTimeout(checkTickerReturnHistory,400);
}
filtersForm?.addEventListener('change',()=>{clearRankingComparison();scheduleReturnHistoryActionCheck();scheduleTickerReturnHistoryCheck();},true);
filtersForm?.addEventListener('input',()=>{clearRankingComparison();scheduleReturnHistoryActionCheck();scheduleTickerReturnHistoryCheck();},true);
scheduleTickerReturnHistoryCheck();
const stopCalculationQueueButton = document.querySelector('#stop-calculation-queue');
const portfolioRankingPanel = document.querySelector('#portfolio-ranking-panel');
const portfolioRankingContent = document.querySelector('#portfolio-ranking-content');
const portfolioRankingHistory = document.querySelector('#portfolio-ranking-history');
const deletePortfolioRankingHistoryButton = document.querySelector('#delete-portfolio-ranking-history');
const rankingCollapseToggle = document.querySelector('#ranking-collapse-toggle');
let portfolioRankingCollapsed = true;
let portfolioRankingHistorySetId = '';
let portfolioRankingHistoryRequest = 0;
let portfolioRankingExactCacheReady = false;
let portfolioRankingCacheChecked = false;
let portfolioRankingCacheLookupPromise = null;
function setPortfolioRankingCollapsed(collapsed) {
    portfolioRankingCollapsed = collapsed;
    portfolioRankingContent.hidden = collapsed;
    rankingCollapseToggle?.setAttribute('aria-expanded', String(!collapsed));
    rankingCollapseToggle?.setAttribute('aria-label', collapsed ? 'Развернуть общий рейтинг' : 'Свернуть общий рейтинг');
    rankingCollapseToggle?.setAttribute('title', collapsed ? 'Развернуть общий рейтинг' : 'Свернуть общий рейтинг');
    if (!collapsed) {
        ensurePortfolioRankingCacheChecked().then((matched) => {
            if (portfolioRankingCollapsed) return;
            if (matched) portfolioRankingHistory.hidden = true;
            else loadPortfolioRankingHistory();
        });
    }
}
rankingCollapseToggle?.addEventListener('click', () => setPortfolioRankingCollapsed(!portfolioRankingCollapsed));
setPortfolioRankingCollapsed(portfolioRankingCollapsed);
const portfolioRankingStatus = document.querySelector('#portfolio-ranking-status');
const portfolioRankingDiagnostics = document.querySelector('#portfolio-ranking-diagnostics');
const calculationQueueCount = document.querySelector('#calculation-queue-count');
const calculationQueueActive = document.querySelector('#calculation-queue-active');
const calculationQueueWaiting = document.querySelector('#calculation-queue-waiting');
const calculationQueueUpdated = document.querySelector('#calculation-queue-updated');
const stopHistoryImportButton = document.querySelector('#stop-history-import');
const endDayInput = filtersForm.elements.namedItem('end_day');
const historyImportStatus = document.querySelector('#history-import-status');
const selectedSymbolImportButton = document.querySelector('#import-selected-symbol');
const latestQuoteControl = document.querySelector('#collector-status');
const allSymbolsImportButton = document.querySelector('#import-all-active-symbols');
const historyImportButtons = [selectedSymbolImportButton, allSymbolsImportButton, latestQuoteControl].filter(Boolean);
const historyImportJobStorageKey = 'bitget-history-import-job-v1';
const historyImportResultStorageKey = 'bitget-history-import-result-v1';
let activeHistoryImportJobId = '';
function updateHistoryImportLabels() {
    if (!selectedSymbolImportButton) return;
    selectedSymbolImportButton.textContent = 'Выгрузить сырые данные по ' + (symbolSelect.value || 'выбранной паре');
    const start = filtersForm.elements.namedItem('start_day').value;
    const end = endDayInput.value;
    selectedSymbolImportButton.title = `Скачать минутные свечи ${symbolSelect.value} из Bitget за период ${start} — ${end} включительно, сохранить в БД и обновить график и расчёты. Для пропущенных минут Close свечи используется как цена.`;
    if (allSymbolsImportButton) {
        const tickerSetName = tickerSetSelect?.selectedOptions[0]?.textContent || 'выбранного списка';
        const tickerCount = Number(allSymbolsImportButton.dataset.symbolCount || 0);
        const lastTwo = tickerCount % 100;
        const last = tickerCount % 10;
        const tickerWord = lastTwo >= 11 && lastTwo <= 14 ? 'тикеров' : last === 1 ? 'тикер' : last >= 2 && last <= 4 ? 'тикера' : 'тикеров';
        allSymbolsImportButton.textContent = `Выгрузить сырые данные по ${tickerSetName} (${tickerCount} ${tickerWord})`;
        allSymbolsImportButton.title = `Скачать минутные свечи всех активных пар списка «${tickerSetName}» из Bitget за период ${start} — ${end} включительно, сохранить в БД и обновить график и расчёты. Для пропущенных минут Close свечи используется как цена.`;
    }
}
updateHistoryImportLabels();
let candleCompletenessVersion = 0;
let candleCompletenessTimer = null;
async function refreshCandleImportVisibility() {
    const version = ++candleCompletenessVersion;
    const start = filtersForm.elements.namedItem('start_day').value;
    const end = endDayInput.value;
    const symbol = symbolSelect.value;
    selectedSymbolImportButton.hidden = true;
    allSymbolsImportButton.hidden = true;
    if (!start || !end || end < start) return;
    const tickerSet = filtersForm.querySelector('[name="ticker_set"]')?.value || '';
    const query = new URLSearchParams({start_day:start, end_day:end, symbol, ticker_set:tickerSet});
    try {
        const response = await fetch('candle-completeness.php?' + query.toString(), {headers:{Accept:'application/json'}, cache:'no-store'});
        const result = await response.json();
        if (!response.ok || result.status !== 'ready' || version !== candleCompletenessVersion) return;
        selectedSymbolImportButton.hidden = !symbol || !result.symbol_incomplete;
        allSymbolsImportButton.hidden = !result.all_incomplete;
    } catch (_) {
        // Keep import controls hidden if completeness cannot be checked.
    }
}
function scheduleCandleImportVisibility() {
    clearTimeout(candleCompletenessTimer);
    candleCompletenessTimer = setTimeout(refreshCandleImportVisibility, 180);
}
symbolSelect.addEventListener('change', updateHistoryImportLabels);
filtersForm.elements.namedItem('start_day').addEventListener('change', updateHistoryImportLabels);
endDayInput.addEventListener('change', updateHistoryImportLabels);
symbolSelect.addEventListener('change', scheduleCandleImportVisibility);
filtersForm.elements.namedItem('start_day').addEventListener('change', scheduleCandleImportVisibility);
endDayInput.addEventListener('change', scheduleCandleImportVisibility);
refreshCandleImportVisibility();
const savedImportResult = sessionStorage.getItem(historyImportResultStorageKey);
if (savedImportResult && historyImportStatus) {
    try {
        const result = JSON.parse(savedImportResult);
        historyImportStatus.dataset.status = result.status || '';
        historyImportStatus.textContent = (result.message || '') + (result.timeInfo ? `\n${result.timeInfo}` : '');
    } catch (_) {}
    sessionStorage.removeItem(historyImportResultStorageKey);
}
const sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
function formatHistoryImportDate(timestamp) {
    return new Intl.DateTimeFormat('ru-RU', {timeZone:'Europe/Kyiv', year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit', hourCycle:'h23'}).format(new Date(timestamp));
}
function formatHistoryImportDuration(milliseconds) {
    let seconds = Math.max(0, Math.floor(milliseconds / 1000));
    const days = Math.floor(seconds / 86400); seconds %= 86400;
    const hours = Math.floor(seconds / 3600); seconds %= 3600;
    const minutes = Math.floor(seconds / 60); seconds %= 60;
    if (days) return `${days} д ${hours} ч ${minutes} мин`;
    if (hours) return `${hours} ч ${minutes} мин ${seconds} сек`;
    return `${minutes} мин ${seconds} сек`;
}
function historyImportTiming(status, now = Date.now()) {
    const startedAt = Number(status.started_at_ms);
    if (!Number.isFinite(startedAt) || startedAt <= 0) return '';
    const running = status.status === 'running';
    const finishedAt = running ? now : (Number(status.finished_at_ms) || now);
    const elapsed = Math.max(0, finishedAt - startedAt);
    const parts = [`Старт: ${formatHistoryImportDate(startedAt)}`];
    if (running) {
        const progress = Number(status.progress || 0);
        if (progress > 0 && progress < 100) {
            const estimatedTotal = elapsed * 100 / progress;
            parts.push(`Ожидаемое окончание: ${formatHistoryImportDate(now + Math.max(0, estimatedTotal - elapsed))} (оценка)`);
        } else {
            parts.push('Окончание: оценка появится после первых страниц');
        }
        parts.push(`Выполняется: ${formatHistoryImportDuration(elapsed)}`);
    } else {
        parts.push(`Завершено: ${formatHistoryImportDate(finishedAt)}`);
        parts.push(`Длительность: ${formatHistoryImportDuration(elapsed)}`);
    }
    return parts.join(' · ');
}
function renderHistoryImportProgress(status) {
    historyImportStatus.dataset.status = status.status || 'running';
    if (status.status === 'running') {
        const stopButton = document.querySelector('#stop-history-import');
        if (stopButton) stopButton.hidden = false;
    }
    let message = status.message || 'Загружаю исторические свечи…';
    if (status.status === 'running') {
        const overall = Number(status.progress || 0).toLocaleString('ru-RU', {minimumFractionDigits:1, maximumFractionDigits:2});
        const symbolProgress = Number.isFinite(Number(status.symbol_progress))
            ? Number(status.symbol_progress)
            : Number(status.current_page || 0) * 100 / Math.max(1, Number(status.symbol_pages || 1));
        message += ` · по паре ${symbolProgress.toLocaleString('ru-RU', {maximumFractionDigits:1})}% · всего ${overall}%`;
        if (Number.isFinite(Number(status.candles_received))) {
            const received = Number(status.candles_received).toLocaleString('ru-RU');
            const pairReceived = Number(status.symbol_candles_received);
            message += Number.isFinite(pairReceived)
                ? ` · свечей получено: ${pairReceived.toLocaleString('ru-RU')} по ${status.current_symbol} / ${received} всего`
                : ` · свечей получено всего: ${received}`;
        }
    }
    if (Number.isFinite(Number(status.snapshots_inserted))) {
        const totalSnapshots = Number(status.snapshots_inserted).toLocaleString('ru-RU');
        const pairSnapshots = Number(status.symbol_snapshots_inserted);
        message += Number.isFinite(pairSnapshots)
            ? ` · добавлено минутных котировок в БД: ${pairSnapshots.toLocaleString('ru-RU')} по ${status.current_symbol} / ${totalSnapshots} всего за загрузку`
            : ` · добавлено минутных котировок в БД всего за загрузку: ${totalSnapshots}`;
    }
    const timing = historyImportTiming(status);
    if (timing) message += `\n${timing}`;
    historyImportStatus.textContent = message;
}
async function pollHistoryImport(jobId, importRequest) {
    activeHistoryImportJobId = jobId;
    if (stopHistoryImportButton) stopHistoryImportButton.hidden = false;
    historyImportButtons.forEach(button => { button.disabled = true; });
    try {
        while (true) {
            await sleep(1200);
            const response = await fetch('import-candles.php?job_id=' + encodeURIComponent(jobId), {headers:{Accept:'application/json'}, cache:'no-store'});
            const status = await response.json();
            if (!response.ok || status.status === 'error') throw new Error(status.message || 'Не удалось загрузить историю.');
            renderHistoryImportProgress(status);
            if (status.status === 'cancelled') {
                sessionStorage.removeItem(historyImportJobStorageKey);
                activeHistoryImportJobId = '';
                historyImportButtons.forEach(button => { button.disabled = false; });
                if (stopHistoryImportButton) {
                    stopHistoryImportButton.hidden = true;
                    stopHistoryImportButton.disabled = false;
                    stopHistoryImportButton.textContent = '■ Остановить загрузку';
                }
                return;
            }
            if (status.status !== 'ready' && status.status !== 'partial') continue;
            activeHistoryImportJobId = '';
            if (stopHistoryImportButton) stopHistoryImportButton.hidden = true;

            const refreshBody = new URLSearchParams({start_day: importRequest.start_day, end_day: importRequest.end_day});
            const refreshResponse = await fetch('refresh-current-day-snapshot.php', {method:'POST', body:refreshBody, headers:{Accept:'application/json'}, cache:'no-store'});
            const refreshResult = await refreshResponse.json();
            if (!refreshResponse.ok || refreshResult.status !== 'ready') throw new Error(refreshResult.message || 'Свечи загружены, но не удалось обновить срез расчётов.');
            symbolSelect.value = importRequest.symbol;
            filtersForm.elements.namedItem('start_day').value = importRequest.start_day;
            endDayInput.value = importRequest.end_day;
            document.querySelector('#symbol-sort-mode').value = 'profit';
            const imported = Number(status.snapshots_inserted || 0).toLocaleString('ru-RU');
            let message = `Загрузка завершена. Минутных котировок добавлено в БД для графика и расчётов: ${imported}.`;
            if (status.status === 'partial') message += ' Ошибки у пар: ' + (status.failures || []).map(item => item.symbol).join(', ') + '.';
            sessionStorage.setItem(historyImportResultStorageKey, JSON.stringify({status: status.status, message, timeInfo: historyImportTiming(status)}));
            sessionStorage.removeItem(historyImportJobStorageKey);
            filtersForm.requestSubmit();
            return;
        }
    } catch (error) {
        sessionStorage.removeItem(historyImportJobStorageKey);
        historyImportStatus.dataset.status = 'error';
        historyImportStatus.textContent = error.message || 'Не удалось загрузить историю из Bitget.';
        historyImportButtons.forEach(button => { button.disabled = false; });
        activeHistoryImportJobId = '';
        if (stopHistoryImportButton) {
            stopHistoryImportButton.hidden = true;
            stopHistoryImportButton.disabled = false;
            stopHistoryImportButton.textContent = '■ Остановить загрузку';
        }
    }
}
document.addEventListener('click', async event => {
    const stopButton = event.target.closest('#stop-history-import');
    if (!stopButton) return;
    if (stopButton.disabled) return;
    const jobId = activeHistoryImportJobId || (() => {
        try { return JSON.parse(sessionStorage.getItem(historyImportJobStorageKey) || 'null')?.job_id || ''; }
        catch (_) { return ''; }
    })();
    if (!jobId) return;
    stopButton.disabled = true;
    stopButton.textContent = 'Останавливаю…';
    historyImportStatus.dataset.status = 'running';
    historyImportStatus.textContent = 'Останавливаю загрузку исторических свечей…';
    try {
        const response = await fetch('import-candles.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded', Accept:'application/json'},
            body: new URLSearchParams({action:'cancel', job_id:jobId}),
            cache: 'no-store',
        });
        const result = await response.json();
        if (response.ok && (result.status === 'ready' || result.status === 'partial')) {
            historyImportStatus.dataset.status = result.status;
            historyImportStatus.textContent = 'Загрузка уже завершилась; обновляю данные…';
            stopButton.hidden = true;
            stopButton.disabled = false;
            stopButton.textContent = '■ Остановить загрузку';
            return;
        }
        if (!response.ok || result.status !== 'cancelled') throw new Error(result.message || 'Не удалось остановить загрузку.');
        sessionStorage.removeItem(historyImportJobStorageKey);
        activeHistoryImportJobId = '';
        historyImportStatus.dataset.status = 'cancelled';
        historyImportStatus.textContent = result.message || 'Загрузка остановлена.';
        historyImportButtons.forEach(button => { button.disabled = false; });
        stopButton.hidden = true;
        stopButton.disabled = false;
        stopButton.textContent = '■ Остановить загрузку';
    } catch (error) {
        historyImportStatus.dataset.status = 'error';
        historyImportStatus.textContent = error.message || 'Не удалось остановить загрузку.';
        stopButton.disabled = false;
        stopButton.textContent = '■ Остановить загрузку';
    }
});
async function startHistoryImport(scope) {
    const latestMode = scope === 'latest';
    if (!latestMode && !filtersForm.checkValidity()) {
        filtersForm.reportValidity();
        return;
    }
    updateHistoryImportLabels();
    const request = {
        scope,
        symbol: symbolSelect.value,
        start_day: filtersForm.elements.namedItem('start_day').value,
        end_day: endDayInput.value,
        ticker_set: filtersForm.querySelector('[name="ticker_set"]')?.value || '',
    };
    historyImportButtons.forEach(button => { button.disabled = true; });
    historyImportStatus.dataset.status = 'running';
    historyImportStatus.textContent = latestMode
        ? `Загружаю минутные данные активных пар списка «${tickerSetSelect?.selectedOptions[0]?.textContent || 'выбранный список'}» от последних сохранённых котировок…`
        : scope === 'all'
            ? 'Отправляю запрос на загрузку всех активных пар…'
            : `Отправляю запрос на загрузку ${request.symbol}…`;
    try {
        const response = await fetch('import-candles.php', {
            method: 'POST', body: new URLSearchParams(request),
            headers: {'Content-Type':'application/x-www-form-urlencoded', Accept:'application/json'}, cache:'no-store',
        });
        const result = await response.json();
        if (!response.ok || !result.job_id) throw new Error(result.message || 'Не удалось запустить загрузку истории.');
        sessionStorage.setItem(historyImportJobStorageKey, JSON.stringify({job_id: result.job_id, request}));
        pollHistoryImport(result.job_id, request);
    } catch (error) {
        historyImportStatus.dataset.status = 'error';
        historyImportStatus.textContent = error.message || 'Не удалось запустить загрузку истории.';
        historyImportButtons.forEach(button => { button.disabled = false; });
    }
}
selectedSymbolImportButton?.addEventListener('click', () => startHistoryImport('symbol'));
allSymbolsImportButton?.addEventListener('click', () => startHistoryImport('all'));
latestQuoteControl?.addEventListener('click', () => startHistoryImport('latest'));
try {
    const savedImportJob = JSON.parse(sessionStorage.getItem(historyImportJobStorageKey) || 'null');
    if (savedImportJob?.job_id && savedImportJob?.request) {
        pollHistoryImport(savedImportJob.job_id, savedImportJob.request);
    } else {
        fetch('import-candles.php?active=1', {headers:{Accept:'application/json'}, cache:'no-store'})
            .then(response => response.json())
            .then(status => {
                if (status.status === 'running' && status.job_id) {
                    const request = {
                        scope:status.scope || 'all',
                        symbol:status.symbol || symbolSelect.value,
                        ticker_set:status.ticker_set || '',
                        start_day:status.request_start_day || status.start_day,
                        end_day:status.request_end_day || status.end_day,
                    };
                    sessionStorage.setItem(historyImportJobStorageKey, JSON.stringify({job_id:status.job_id, request}));
                    pollHistoryImport(status.job_id, request);
                }
            })
            .catch(() => {});
    }
} catch (_) { sessionStorage.removeItem(historyImportJobStorageKey); }
function createElapsedTimer(element, label) {
    let currentLabel = label;
    let startedAt = null;
    let elapsedMs = 0;
    let intervalId = null;
    let finished = false;
    let persistedKey = null;
    let previousElapsedMs = null;
    let pendingSeen = false;
    const render = () => {
        const totalSeconds = Math.floor((startedAt === null ? elapsedMs : performance.now() - startedAt) / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const time = hours > 0
            ? `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
            : `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        element.textContent = `${currentLabel}: ${time}`;
    };
    return {
        start(nextPersistedKey = null) {
            if (nextPersistedKey !== null) {
                persistedKey = nextPersistedKey;
                try {
                    const saved = window.sessionStorage.getItem(persistedKey);
                    previousElapsedMs = saved === null ? null : Number(saved);
                    if (!Number.isFinite(previousElapsedMs) || previousElapsedMs < 0) previousElapsedMs = null;
                    pendingSeen = false;
                } catch (_) {
                    previousElapsedMs = null;
                }
            }
            if (startedAt !== null || finished) return;
            elapsedMs = 0;
            startedAt = performance.now();
            element.hidden = false;
            render();
            intervalId = window.setInterval(render, 1000);
        },
        setLabel(nextLabel) {
            currentLabel = nextLabel;
            if (startedAt !== null || elapsedMs > 0 || finished) render();
        },
        finish(finalLabel = null, options = {}) {
            if (finalLabel !== null) currentLabel = finalLabel;
            if (startedAt === null) {
                if (elapsedMs > 0) render();
                return;
            }
            elapsedMs = performance.now() - startedAt;
            if (options.useStored === true && previousElapsedMs !== null) elapsedMs = previousElapsedMs;
            else if (!pendingSeen && Number.isFinite(options.fallbackElapsedMs) && options.fallbackElapsedMs > 0) elapsedMs = options.fallbackElapsedMs;
            startedAt = null;
            finished = true;
            window.clearInterval(intervalId);
            intervalId = null;
            if (persistedKey !== null && options.persist !== false) {
                try { window.sessionStorage.setItem(persistedKey, String(elapsedMs)); } catch (_) {}
            }
            render();
        },
        markPending() {
            pendingSeen = true;
            previousElapsedMs = null;
            if (persistedKey !== null) {
                try { window.sessionStorage.removeItem(persistedKey); } catch (_) {}
            }
        },
        reset() {
            window.clearInterval(intervalId);
            intervalId = null;
            startedAt = null;
            elapsedMs = 0;
            finished = false;
            persistedKey = null;
            previousElapsedMs = null;
            pendingSeen = false;
            element.textContent = '';
            element.hidden = true;
        },
    };
}
const strategyRankingTimer = createElapsedTimer(document.querySelector('#strategy-ranking-timer'), 'Время расчёта выбранной комбинации');
const portfolioRankingTimer = createElapsedTimer(document.querySelector('#portfolio-ranking-timer'), 'Время общего рейтинга');
let portfolioRankingRequestVersion = 0;
let portfolioRankingStatusPolling = false;
const martingaleCacheStatus = document.querySelector('#martingale-cache-status');
const martingaleDraftStatus = document.querySelector('#martingale-draft-status');
const martingaleInitialNotional = document.querySelector('#martingale-initial-notional');
const martingaleApplyButton = document.querySelector('#apply-martingale-settings');
const martingaleControls = [...filtersForm.querySelectorAll('[data-martingale-setting]')];
const martingaleAppliedControls = ['martingale_mode', 'martingale_timing', 'martingale_attempts', 'martingale_max_chain_loss', 'martingale_max_day_loss']
    .map(name => filtersForm.elements.namedItem(name));
function readMartingaleSettings() {
    return Object.fromEntries(martingaleControls.map(control => [control.dataset.martingaleSetting, control.value]));
}
function readAppliedMartingaleSettings() {
    return Object.fromEntries(martingaleAppliedControls.map(control => [control.name, control.value]));
}
function writeAppliedMartingaleSettings(settings) {
    martingaleAppliedControls.forEach(control => { control.value = settings[control.name]; });
}
function updateMartingaleDraftState() {
    const hasDraftChanges = JSON.stringify(readMartingaleSettings()) !== JSON.stringify(lastMartingaleSettings);
    martingaleApplyButton.disabled = !hasDraftChanges || martingaleSettingsResetPending;
    martingaleDraftStatus.hidden = !hasDraftChanges;
    martingaleDraftStatus.textContent = hasDraftChanges ? 'Есть неприменённые настройки мартингейла. Расчёты используют последние применённые параметры.' : '';
}
let lastMartingaleSettings = readAppliedMartingaleSettings();
let martingaleSettingsResetPending = false;
function updateMartingaleInitialNotional() {
    if (!martingaleInitialNotional) return;
    const settings = readMartingaleSettings();
    if (settings.martingale_mode === 'none') {
        martingaleInitialNotional.textContent = 'Мартингейл выключен';
        return;
    }
    const balance = Number(filtersForm.elements.namedItem('balance').value);
    const fee = Number(filtersForm.elements.namedItem('fee').value) / 100;
    const attempts = Number(settings.martingale_attempts);
    const selectedExit = exitConfigSelect.value;
    const policyMatch = selectedExit.match(/:(scaled|fixed)$/);
    const policy = policyMatch?.[1] || 'scaled';
    const exitId = selectedExit.replace(/:(scaled|fixed)$/, '');
    let stopLoss = null;
    let fixedMatch = exitId.match(/^fixed:\d+:(\d+)$/);
    let lossMatch = exitId.match(/^loss:(\d+)$/);
    if (fixedMatch) stopLoss = balance * Number(fixedMatch[1]) / 10000;
    else if (lossMatch) stopLoss = balance * Number(lossMatch[1]) / 1000;

    if (!(balance > 0) || !(attempts >= 2) || !Number.isFinite(fee)) {
        martingaleInitialNotional.textContent = 'Проверьте баланс и комиссию';
        return;
    }
    const finalScale = 2 ** (attempts - 1);
    const previousScale = 2 ** (attempts - 2);
    let reservedLoss = 0;
    let requiredPerInitialDollar = finalScale;
    if (settings.martingale_mode.endsWith('_profit')) {
        // Profit-triggered progressions are sized to fit the planned geometric
        // sequence from the starting balance.
    } else if (stopLoss !== null) {
        if (settings.martingale_mode === 'simple') {
            reservedLoss = stopLoss * (policy === 'scaled' ? previousScale : 1);
            requiredPerInitialDollar += fee * previousScale;
        } else {
            reservedLoss = stopLoss * (policy === 'scaled' ? finalScale - 1 : attempts - 1);
            requiredPerInitialDollar *= 1 + fee;
        }
    }
    let initialNotional = Math.max(0, (balance - reservedLoss) / requiredPerInitialDollar);
    initialNotional = Math.min(initialNotional, balance / (1 + fee));
    if (initialNotional <= 0) {
        martingaleInitialNotional.textContent = '0,00 USDT · баланса не хватает на всю серию';
        return;
    }
    martingaleInitialNotional.textContent = new Intl.NumberFormat('ru-RU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 6,
    }).format(initialNotional) + ' USDT';
}
function setRefreshProgress(progress, title) {
    const progressKnown = progress !== null && progress !== undefined && Number.isFinite(Number(progress));
    const percent = progressKnown ? Math.max(0, Math.min(100, Math.round(Number(progress)))) : null;
    refreshSymbolsButton.disabled = true;
    refreshSymbolsButton.classList.add('is-loading');
    refreshSymbolsButton.setAttribute('aria-busy', 'true');
    refreshSymbolsButton.setAttribute('aria-label', progressKnown ? `Расчет общего рейтинга: ${percent}%` : 'Расчет общего рейтинга выполняется');
    refreshSymbolsButton.title = title || (progressKnown ? `Расчет общего рейтинга: ${percent}%` : 'Расчет общего рейтинга выполняется');
    let indicator = refreshSymbolsButton.querySelector('.refresh-progress');
    if (!indicator) {
        indicator = document.createElement('span');
        indicator.className = 'refresh-progress';
        indicator.setAttribute('aria-hidden', 'true');
        const value = document.createElement('span');
        value.className = 'refresh-progress-value';
        indicator.appendChild(value);
        refreshSymbolsButton.replaceChildren(indicator);
    }
    indicator.querySelector('.refresh-progress-value').textContent = progressKnown ? `${percent}%` : '…';
}
function resetRefreshButton(title = 'Составить общий рейтинг из таблицы доходности') {
    refreshSymbolsButton.disabled = false;
    refreshSymbolsButton.classList.remove('is-loading');
    refreshSymbolsButton.removeAttribute('aria-busy');
    refreshSymbolsButton.setAttribute('aria-label', 'Составить общий рейтинг по рассчитанной доходности');
    refreshSymbolsButton.title = title;
    refreshSymbolsButton.replaceChildren(document.createTextNode('Составить рейтинг'));
}
function resetPortfolioRankingForRelevantFilterChange() {
    portfolioRankingRequestVersion++;
    portfolioRankingExactCacheReady = false;
    portfolioRankingCacheChecked = false;
    portfolioRankingHistorySetId = '';
    portfolioRankingHistory.hidden = true;
    portfolioRankingTimer.finish();
    symbolSortMode.value = '';
    resetRefreshButton('Пересчитать рейтинг для измененных фильтров');
}
document.querySelector('#apply-ranking-ranges').addEventListener('click', () => {
    document.querySelector('#select-top-ranked-result').value = '1';
    resetPortfolioRankingForRelevantFilterChange();
});
function reindexRangeEditor(editor) {
    const rows = [...editor.querySelectorAll('.ranking-segment-row')];
    rows.forEach((row, index) => {
        row.querySelectorAll('input[data-field]').forEach(input => {
            input.name = `${editor.dataset.rangeName}[${index}][${input.dataset.field}]`;
        });
        const remove = row.querySelector('.remove-range-segment');
        remove.disabled = rows.length === 1;
    });
}
function restoreRangeEditorDefaults(editor) {
    const defaults = JSON.parse(editor.dataset.defaultSegments || '[]');
    const precision = Number(editor.dataset.percentPrecision) || 0;
    const scale = 10 ** precision;
    editor.querySelectorAll('.ranking-segment-row').forEach(row => row.remove());
    const addButton = editor.querySelector('.add-range-segment');
    defaults.forEach((segment, index) => {
        const row = document.createElement('div');
        row.className = 'ranking-segment-row';
        const label = document.createElement('span');
        label.textContent = `Диапазон ${index + 1}`;
        row.appendChild(label);
        for (const [field, caption] of [['from', 'От'], ['to', 'До'], ['step', 'Шаг']]) {
            const wrapper = document.createElement('label');
            wrapper.append(caption);
            const input = document.createElement('input');
            input.className = 'ranking-range-input';
            input.type = 'number';
            input.min = precision ? (1 / scale).toFixed(precision) : '1';
            input.step = input.min;
            input.dataset.field = field;
            input.dataset.defaultValue = precision ? (Number(segment[field]) / scale).toFixed(precision) : String(segment[field]);
            input.value = input.dataset.defaultValue;
            input.required = true;
            wrapper.appendChild(input);
            row.appendChild(wrapper);
        }
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'remove-range-segment';
        remove.setAttribute('aria-label', 'Удалить диапазон');
        remove.textContent = '−';
        row.appendChild(remove);
        editor.insertBefore(row, addButton);
    });
    reindexRangeEditor(editor);
}
document.querySelectorAll('.ranking-segment-editor').forEach(editor => {
    reindexRangeEditor(editor);
    editor.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.classList.contains('remove-range-segment')) {
            const rows = editor.querySelectorAll('.ranking-segment-row');
            if (rows.length > 1) button.closest('.ranking-segment-row').remove();
            reindexRangeEditor(editor);
        } else if (button.classList.contains('add-range-segment')) {
            const rows = [...editor.querySelectorAll('.ranking-segment-row')];
            const previous = rows.at(-1);
            const values = Object.fromEntries([...previous.querySelectorAll('input[data-field]')].map(input => [input.dataset.field, Number(input.value)]));
            const precision = Number(editor.dataset.percentPrecision) || 0;
            const step = values.step || (precision ? 1 / (10 ** precision) : 1);
            const from = values.to + step;
            const row = previous.cloneNode(true);
            const label = row.querySelector('span');
            label.textContent = `Диапазон ${rows.length + 1}`;
            const inputs = Object.fromEntries([...row.querySelectorAll('input[data-field]')].map(input => [input.dataset.field, input]));
            inputs.from.value = precision ? from.toFixed(precision) : String(Math.round(from));
            inputs.to.value = inputs.from.value;
            inputs.step.value = precision ? step.toFixed(precision) : String(Math.round(step));
            row.querySelectorAll('input').forEach(input => { input.dataset.defaultValue = ''; });
            editor.insertBefore(row, editor.querySelector('.add-range-segment'));
            reindexRangeEditor(editor);
        }
    });
});
document.querySelectorAll('.ranking-mode-input').forEach(control => {
    const update = () => {
        const custom = document.querySelector('#' + control.dataset.range + '-custom');
        const enabled = control.value === 'range' || control.value === 'custom';
        custom.hidden = !enabled;
        custom.querySelectorAll('input').forEach(input => { input.disabled = !enabled; });
    };
    control.addEventListener('change', update);
    update();
});
document.querySelector('#reset-ranking-parameters').addEventListener('click', () => {
    document.querySelectorAll('.ranking-segment-editor').forEach(restoreRangeEditorDefaults);
    document.querySelectorAll('.ranking-range-input').forEach(input => {
        input.value = input.dataset.defaultValue;
    });
    document.querySelectorAll('.ranking-mode-input').forEach(control => {
        control.value = control.dataset.defaultValue;
        control.dispatchEvent(new Event('change', {bubbles:true}));
    });
    document.querySelector('#select-top-ranked-result').value = '1';
    resetPortfolioRankingForRelevantFilterChange();
    filtersForm.requestSubmit();
});
saveModeToggle.addEventListener('change', () => {
    saveModeValue.value = saveModeToggle.checked ? '1' : '0';
    document.querySelector('#save-mode-detail').textContent = saveModeToggle.checked ? 'минимум 3 сделки' : 'от 1 сделки';
    resetPortfolioRankingForRelevantFilterChange();
    filtersForm.requestSubmit();
});
let filterSubmitTimer;
let replayRequest;
document.querySelector('#exit-confirmation-mode')?.addEventListener('change', event => {
    const noExit = event.target.value === 'none';
    const exitConfigField = document.querySelector('#exit-config-field');
    if (exitConfigField) exitConfigField.hidden = noExit;
    if (exitConfigSelect) {
        if (noExit) {
            exitConfigSelect.replaceChildren(new Option('Не применяется · позиция удерживается до конца периода', 'none', true, true));
        } else if (exitConfigSelect.value === 'none') {
            exitConfigSelect.replaceChildren(...initialExitOptions.filter(item => item.id !== 'none').map(item => new Option(item.label, item.id)));
            if (exitConfigSelect.options.length) exitConfigSelect.selectedIndex = 0;
        }
        exitConfigSelect.disabled = noExit || !symbolSelect?.value;
    }
    if (noExit && selectTopRankedResultInput) selectTopRankedResultInput.value = '0';
    filtersForm.requestSubmit();
});
function revealStrategyResults() {
    document.querySelector('#chart-panel')?.removeAttribute('hidden');
    document.querySelector('#results')?.removeAttribute('hidden');
    const placeholder = document.querySelector('#strategy-results-placeholder');
    if (placeholder) placeholder.hidden = true;
}
function serverRenderedSelectionMatches() {
    const chart = document.querySelector('#chart-panel');
    return Boolean(chart
        && entryConfigSelect?.value
        && exitConfigSelect?.value
        && chart.dataset.renderedEntry === entryConfigSelect.value
        && chart.dataset.renderedExit === exitConfigSelect.value
        && chart.dataset.renderedExitSnapshots === (document.querySelector('#exit-confirmation-snapshots')?.value || ''));
}
function clearStrategyResults() {
    document.querySelector('#chart-panel')?.setAttribute('hidden', '');
    document.querySelector('#results')?.setAttribute('hidden', '');
    const placeholder = document.querySelector('#strategy-results-placeholder');
    if (placeholder) { placeholder.hidden = false; placeholder.textContent = 'Фильтры изменены. Загружаю результаты для нового выбора…'; }
}
filtersForm?.addEventListener('input', event => { if (event.target.matches('select, input')) clearStrategyResults(); }, true);
filtersForm?.addEventListener('change', event => { if (event.target.matches('select, input')) clearStrategyResults(); }, true);
async function refreshStrategyResults() {
    clearStrategyResults();
    if (!symbolSelect?.value) return;
    const restoreReturnMode = document.querySelector('#chart-panel')?.classList.contains('return-mode') === true;
    replayRequest?.abort();
    replayRequest = new AbortController();
    const params = new URLSearchParams(new FormData(filtersForm));
    const url = location.pathname + '?' + params.toString();
    history.replaceState(null, '', url);
    try {
        const response = await fetch(url, {headers:{Accept:'text/html'}, cache:'no-store', signal:replayRequest.signal});
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const html = await response.text();
        const updatedPage = new DOMParser().parseFromString(html, 'text/html');
        const updatedChart = updatedPage.querySelector('#chart-panel');
        const updatedResults = updatedPage.querySelector('#results');
        if (!updatedChart || !updatedResults) throw new Error('Не удалось получить результаты симуляции');

        document.querySelector('#chart-panel').replaceWith(updatedChart);
        document.querySelector('#results').replaceWith(updatedResults);
        updatedChart.hidden = false;
        updatedResults.hidden = false;
        const placeholder = document.querySelector('#strategy-results-placeholder');
        if (placeholder) placeholder.hidden = true;
        const chartScript = [...updatedPage.scripts].find(script => script.textContent.includes('const datasets ='));
        if (chartScript) new Function(chartScript.textContent)();
        if (restoreReturnMode) setChartMode('returns');
    } catch (error) {
        if (error.name !== 'AbortError') {
            console.error('Не удалось обновить результаты стратегии', error);
            const placeholder = document.querySelector('#strategy-results-placeholder');
            if (placeholder) { placeholder.hidden = false; placeholder.textContent = 'Не удалось загрузить расчёт стратегии. Измените фильтры или повторите выбор.'; }
        }
    }
}
filtersForm.querySelectorAll('select, input').forEach(control => {
    control.addEventListener('change', () => {
        if (control.classList.contains('ranking-range-input') || control.classList.contains('ranking-mode-input')) return;
        if (martingaleControls.includes(control)) {
            updateMartingaleInitialNotional();
            updateMartingaleDraftState();
            return;
        }
        if (control.id === 'entry-config') {
            updateMartingaleInitialNotional();
            refreshAfterExitRanking = true;
            const selectedExit = exitConfigSelect.value;
            exitRankingRequestVersion++;
            renderExitOptions(initialExitOptions, selectedExit, Number(document.querySelector('#exit-confirmation-snapshots')?.value) || 60);
            loadExitRanking(selectedExit);
            return;
        }
        if (control.id === 'entry-signal-mode' || control.id === 'entry-direction-mode') {
            selectTopRankedResultInput.value = '1';
        }
        if (control.id === 'exit-config') {
            const selectedOption = control.selectedOptions[0];
            if (selectedOption?.dataset.confirmationSnapshots) {
                document.querySelector('#exit-confirmation-snapshots').value = selectedOption.dataset.confirmationSnapshots;
            }
            updateMartingaleInitialNotional();
            refreshStrategyResults();
            return;
        }
        if (control.id === 'symbol-select') {
            selectTopRankedResultInput.value = '1';
        }
        clearTimeout(filterSubmitTimer);
        if (control.name === 'start_day' || control.name === 'end_day') {
            selectTopRankedResultInput.value = '1';
            resetPortfolioRankingForRelevantFilterChange();
        }
        filtersForm.requestSubmit();
    });
    if (control.type === 'number' && !control.classList.contains('ranking-range-input')) {
        control.addEventListener('input', () => {
            updateMartingaleInitialNotional();
            if (martingaleControls.includes(control)) {
                updateMartingaleDraftState();
                return;
            }
            clearTimeout(filterSubmitTimer);
            filterSubmitTimer = setTimeout(() => {
                if (control.value !== '' && control.checkValidity()) {
                    filtersForm.requestSubmit();
                }
            }, 700);
        });
    }
});
const exitConfigSelect = document.querySelector('#exit-config');
const entryConfigSelect = document.querySelector('#entry-config');
const entryOrderLock = document.querySelector('#entry-order-lock');
const exitOrderLock = document.querySelector('#exit-order-lock');
function setSelectorOrderLock(button, locked, key) {
    if (!button) return;
    button.setAttribute('aria-pressed', String(!locked));
    const label = locked ? 'Сортировка выключена для сравнения в диапазонах' : 'Сортировка по максимальной доходности';
    button.replaceChildren();
    const dot = document.createElement('span');
    dot.className = 'selector-sort-dot';
    dot.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span');
    text.textContent = label;
    button.append(dot, text);
    button.title = locked ? 'Включить сортировку по максимальной доходности' : 'Выключить сортировку для сравнения в диапазонах';
    button.setAttribute('aria-label', `${label}: ${key === 'entry' ? 'Порог входа' : 'TakeProfit & StopLoss'}`);
}
function readSelectorOrderLock(key) {
    try { return localStorage.getItem(`basic-checks-selector-order-${key}`) === '1'; } catch (_) { return false; }
}
let entryOrderLocked = readSelectorOrderLock('entry');
let exitOrderLocked = readSelectorOrderLock('exit');
setSelectorOrderLock(entryOrderLock, entryOrderLocked, 'entry');
setSelectorOrderLock(exitOrderLock, exitOrderLocked, 'exit');
entryOrderLock?.addEventListener('click', () => {
    entryOrderLocked = !entryOrderLocked;
    try { localStorage.setItem('basic-checks-selector-order-entry', entryOrderLocked ? '1' : '0'); if (entryOrderLocked) localStorage.setItem('basic-checks-selector-order-entry-values', JSON.stringify([...entryConfigSelect.options].map(option => option.value))); } catch (_) {}
    setSelectorOrderLock(entryOrderLock, entryOrderLocked, 'entry');
});
exitOrderLock?.addEventListener('click', () => {
    exitOrderLocked = !exitOrderLocked;
    try { localStorage.setItem('basic-checks-selector-order-exit', exitOrderLocked ? '1' : '0'); if (exitOrderLocked) localStorage.setItem('basic-checks-selector-order-exit-values', JSON.stringify([...exitConfigSelect.options].map(option => option.value))); } catch (_) {}
    setSelectorOrderLock(exitOrderLock, exitOrderLocked, 'exit');
});
function preserveSelectorOrder(items, select, locked) {
    if (!locked || !select?.options.length) return items;
    let values = [...select.options].map(option => option.value);
    try {
        const saved = JSON.parse(localStorage.getItem(`basic-checks-selector-order-${select === entryConfigSelect ? 'entry' : 'exit'}-values`));
        if (Array.isArray(saved) && saved.length) values = saved;
    } catch (_) {}
    const currentOrder = new Map(values.map((value, index) => [value, index]));
    return [...items].sort((left, right) => {
        const leftIndex = currentOrder.get(String(left.id));
        const rightIndex = currentOrder.get(String(right.id));
        if (leftIndex === undefined) return rightIndex === undefined ? 0 : 1;
        if (rightIndex === undefined) return -1;
        return leftIndex - rightIndex;
    });
}
const entrySortIndicator = document.querySelector('#entry-sort-indicator');
const exitSortIndicator = document.querySelector('#exit-sort-indicator');
function setRankedSelectSort(indicator, pending, progress=null) {
    if (!indicator) return;
    indicator.hidden = !pending;
    const progressNode=indicator.querySelector('.ranked-sort-progress');
    const percentage=Number(progress)||0;
    progressNode.textContent=pending
        ?` (${Math.max(0,Math.min(100,percentage)).toLocaleString('ru-RU',{maximumFractionDigits:1})}%) `:'';
}
function setStrategySortIndicators(pending, entryProgress=null, exitProgress=null) {
    setRankedSelectSort(entrySortIndicator,pending,entryProgress);
    setRankedSelectSort(exitSortIndicator,pending,exitProgress);
}
const initialEntryOptions = <?= json_encode($entrySelectorSeeds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?>;
const initialExitOptions = <?= json_encode($exitSelectorSeeds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?>;
const selectTopRankedResultInput = document.querySelector('#select-top-ranked-result');
let selectTopRankedResult = selectTopRankedResultInput.value === '1';
const optionPercent = value => `${Number(value)>=0?'+':''}${Number(value).toLocaleString('ru-RU',{minimumFractionDigits:4,maximumFractionDigits:4})}%`;
const selectorBalance = () => Number(filtersForm.elements.namedItem('balance')?.value)||100;
function renderEntryOptions(entries, selected, resultUnit='USDT') {
    if (!Array.isArray(entries) || entries.length === 0) return;
    if (entryOrderLocked && entryConfigSelect.value) {
        selected = entryConfigSelect.value;
        if (!entries.some(item => item.id === selected)) {
            entries = [...entries, {id:selected, label:entryConfigSelect.selectedOptions[0].textContent.replace(/\s*·\s*[+-]?[\d.,]+%\s*$/, ''), best_pnl:null}];
        }
    }
    entries = preserveSelectorOrder(entries, entryConfigSelect, entryOrderLocked);
    entryConfigSelect.replaceChildren(...entries.map(item=>{
        const option = new Option(item.label
            .replaceAll('от стартового баланса', 'от баланса')
            .replaceAll('в пределах окна', 'внутри')
            .replace(/\s*·\s*лучший результат\s+[+-]?[\d., ]+\s*(?:USDT|%)/gi,''), item.id, false, item.id === selected);
        if (item.best_pnl !== null && item.best_pnl !== undefined && Number.isFinite(Number(item.best_pnl))) {
            const percent=resultUnit==='%'?Number(item.best_pnl):Number(item.best_pnl)*100/selectorBalance();
            option.textContent += ` · ${optionPercent(percent)}`;
        }
        return option;
    }));
    if (![...entryConfigSelect.options].some(option => option.value === selected)) {
        entryConfigSelect.options[0].selected = true;
    }
}
function renderExitOptions(items, selected, selectedSnapshots=null) {
    if (document.querySelector('#exit-confirmation-mode')?.value === 'none') {
        exitConfigSelect.replaceChildren(new Option('Не применяется · позиция удерживается до конца периода', 'none', true, true));
        exitConfigSelect.disabled = true;
        return;
    }
    if(!Array.isArray(items)||!items.length)return;
    if (exitOrderLocked && exitConfigSelect.value && exitConfigSelect.value !== 'none') {
        selected = exitConfigSelect.value;
        selectedSnapshots = Number(document.querySelector('#exit-confirmation-snapshots')?.value)||60;
        items = items.map(item => item.id === selected ? {...item, snapshots:selectedSnapshots} : item);
        if (!items.some(item => item.id === selected)) {
            items = [...items, {id:selected, label:exitConfigSelect.selectedOptions[0].textContent.replace(/\s*·\s*[+-]?[\d.,]+%\s*$/, ''), snapshots:selectedSnapshots}];
        }
    }
    items = preserveSelectorOrder(items, exitConfigSelect, exitOrderLocked);
    exitConfigSelect.replaceChildren(...items.map(item=>{
        const snapshots=Number(item.snapshots)||Number(document.querySelector('#exit-confirmation-snapshots')?.value)||60;
        const label=item.label.replace(/([+-]?\d+(?:[.,]\d+)?)\s*USDT/g,(_,value)=>optionPercent(Number(value.replace(',','.'))*100/selectorBalance()));
        const option=new Option(label,item.id,false,item.id===selected&&(selectedSnapshots===null||snapshots===selectedSnapshots));
        option.dataset.confirmationSnapshots=String(snapshots);
        return option;
    }));
    if(![...exitConfigSelect.options].some(option=>option.selected)&&exitConfigSelect.options.length)exitConfigSelect.options[0].selected=true;
    const selectedItem=items.find(item=>item.id===exitConfigSelect.value
        &&(selectedSnapshots===null||Number(item.snapshots)===selectedSnapshots))
        ||items.find(item=>item.id===exitConfigSelect.value);
    if(selectedItem?.snapshots)document.querySelector('#exit-confirmation-snapshots').value=String(selectedItem.snapshots);
    scheduleStrategyRangeComparison();
}
renderEntryOptions(initialEntryOptions,entryConfigSelect.value);
renderExitOptions(initialExitOptions,exitConfigSelect.value,Number(document.querySelector('#exit-confirmation-snapshots')?.value)||60);
if (symbolSelect?.value && serverRenderedSelectionMatches()) revealStrategyResults();
let exitRankingRequestVersion = 0;
let refreshAfterExitRanking = false;
let rankingMaintenancePending = false;
async function handleMartingaleSettingsChange() {
    const nextSettings = readAppliedMartingaleSettings();
    if (JSON.stringify(nextSettings) === JSON.stringify(lastMartingaleSettings) || martingaleSettingsResetPending) return;
    const previousSettings = lastMartingaleSettings;
    lastMartingaleSettings = nextSettings;
    updateMartingaleInitialNotional();
    const martingaleWasActive = previousSettings.martingale_mode !== 'none';
    const martingaleIsActive = nextSettings.martingale_mode !== 'none';
    if (!martingaleWasActive && !martingaleIsActive) {
        filtersForm.requestSubmit();
        return;
    }

    martingaleSettingsResetPending = true;
    rankingMaintenancePending = true;
    exitRankingRequestVersion++;
    replayRequest?.abort();
    resetPortfolioRankingForRelevantFilterChange();
    entryConfigSelect.disabled = true;
    exitConfigSelect.disabled = true;
    martingaleControls.forEach(control => { control.disabled = true; });
    martingaleApplyButton.disabled = true;
    stopCalculationQueueButton.disabled = true;
    martingaleCacheStatus.hidden = false;
    martingaleCacheStatus.textContent = 'Останавливаю старые расчёты и обновляю рейтинг мартингейла…';
    let resetSucceeded = false;
    try {
        const response = await fetch('reset-strategy-ranking-cache.php', {
            method: 'POST',
            headers: {Accept: 'application/json'},
            cache: 'no-store',
        });
        const result = await response.json();
        if (!response.ok || result.status !== 'ready') {
            throw new Error(result.message || 'Не удалось сбросить предыдущие рейтинги');
        }
        rankingGenerationInput.value = result.generation;
        resetSucceeded = true;
    } catch (error) {
        writeAppliedMartingaleSettings(previousSettings);
        martingaleControls.forEach(control => { control.value = previousSettings[control.dataset.martingaleSetting]; });
        lastMartingaleSettings = previousSettings;
        updateMartingaleInitialNotional();
        martingaleCacheStatus.textContent = (error.message || 'Не удалось сбросить предыдущие рейтинги') + '. Настройки восстановлены.';
    } finally {
        rankingMaintenancePending = false;
        martingaleSettingsResetPending = false;
        entryConfigSelect.disabled = false;
        exitConfigSelect.disabled = false;
        martingaleControls.forEach(control => { control.disabled = false; });
        stopCalculationQueueButton.disabled = false;
        updateMartingaleDraftState();
    }
    if (resetSucceeded) filtersForm.requestSubmit();
}
martingaleApplyButton.addEventListener('click', () => {
    writeAppliedMartingaleSettings(readMartingaleSettings());
    handleMartingaleSettingsChange();
});
const exitConfirmationSnapshotCounts = [1, 2, 3, 5, 10, 20, 30, 40, 60, 80];
async function loadExitRanking(preferredExit = null, isPoll = false) {
    if (rankingMaintenancePending) return;
    const params = new URLSearchParams(new FormData(filtersForm));
    if (!(params.get('symbol') || '').trim()) {
        setStrategySortIndicators(false);
        strategyRankingTimer.reset();
        entryConfigSelect.replaceChildren();
        const entryPrompt = new Option('Выберите торговую пару для загрузки рейтинга', '', true, true);
        entryPrompt.disabled = true;
        entryConfigSelect.appendChild(entryPrompt);
        exitConfigSelect.replaceChildren();
        const exitPrompt = new Option('Выберите торговую пару для загрузки TP/SL', '', true, true);
        exitPrompt.disabled = true;
        exitConfigSelect.appendChild(exitPrompt);
        entryConfigSelect.disabled = true;
        exitConfigSelect.disabled = true;
        return;
    }
    setStrategySortIndicators(true,0,0);
    if (!selectorReturnRunKey && (params.get('martingale_mode') || 'none') === 'none') {
        try {
            const historyParams = new URLSearchParams(params);
            historyParams.delete('symbol');
            historyParams.set('return_history_status', '1');
            const historyResponse = await fetch('portfolio-ranking.php?' + historyParams.toString(), {
                headers:{Accept:'application/json'}, cache:'no-store',
            });
            const historyState = await historyResponse.json();
            const unusableKey=historyState.run_key+'|'+(params.get('symbol')||'');
            if (historyResponse.ok && historyState.status === 'ready' && historyState.run_key
                && unusableKey!==unusableSelectorReturnRunKey) {
                selectorReturnRunKey = historyState.run_key;
            }
        } catch (_) {}
    }
    if(selectorReturnRunKey || (params.get('martingale_mode') || 'none') === 'none'){
        const historyRequestVersion=exitRankingRequestVersion;
        try{
            const selectedSymbol=params.get('symbol')||'';
            const symbolChanged=returnRankedSymbol!==selectedSymbol;
            const refreshed=await refreshSelectorsFromReturnHistory(selectorReturnRunKey,'',symbolChanged);
            if(historyRequestVersion!==exitRankingRequestVersion)return;
            if(refreshed){
                setStrategySortIndicators(false);
                returnRankedSymbol=selectedSymbol;
                refreshAfterExitRanking=false;
                if(selectorReturnRunKey) activeReturnRunKey=selectorReturnRunKey;
                await refreshStrategyResults();
                if(selectorReturnRunKey) await loadReturnChart(selectorReturnRunKey,params.get('symbol')||'');
                return;
            }
        }catch(_){/* Use the normal selector ranking when saved options cannot be loaded. */}
        unusableSelectorReturnRunKey=selectorReturnRunKey+'|'+(params.get('symbol')||'');
        selectorReturnRunKey='';
    }
    entryConfigSelect.disabled = false;
    exitConfigSelect.disabled = false;
    if (!isPoll) params.set('retry_cancelled', '1');
    if (!isPoll) {
        strategyRankingTimer.reset();
        strategyRankingTimer.setLabel('Ожидание ответа · всего прошло');
        const timerParams = new URLSearchParams([...params].filter(([key]) => !['exit_config', 'symbol_sort'].includes(key)));
        strategyRankingTimer.start('strategy-ranking-elapsed:' + timerParams.toString());
    }
    const requestVersion = exitRankingRequestVersion;
    const mode = params.get('exit_confirmation_mode') || 'immediate';
    const selectedSnapshots = Number(params.get('exit_confirmation_snapshots')) || 60;
    const snapshotCounts = mode === 'immediate' ? [selectedSnapshots] : exitConfirmationSnapshotCounts;
    const currentExit = preferredExit ?? exitConfigSelect.value;
    if (entryConfigSelect.options.length === 1 && entryConfigSelect.options[0].value === '') {
        entryConfigSelect.options[0].textContent = 'Загружаю рейтинг порогов входа (% от баланса)…';
    }
    try {
        const responses = await Promise.all(snapshotCounts.map(async snapshots => {
            const rankingParams = new URLSearchParams(params);
            rankingParams.set('exit_confirmation_snapshots', String(snapshots));
            const response = await fetch(`strategy-ranking.php?${rankingParams.toString()}`, {headers:{Accept:'application/json'}});
            const ranking = await response.json();
            return {snapshots, ranking, httpOk: response.ok};
        }));
        if (requestVersion !== exitRankingRequestVersion || rankingMaintenancePending) return;

        const pending = responses.filter(({ranking}) => ranking.status === 'pending');
        const selectorProgress=responses.length
            ? responses.reduce((sum,{ranking})=>sum+(ranking.status==='pending'?Number(ranking.progress)||0:100),0)/responses.length
            : null;
        const entryProgress=selectorProgress;
        const exitProgress=selectorProgress;
        setStrategySortIndicators(pending.length>0,entryProgress,exitProgress);
        const elapsedSeconds = Math.max(0, ...responses.map(({ranking}) => Number(ranking.elapsed_seconds) || 0));
        if (pending.length) {
            strategyRankingTimer.markPending();
            strategyRankingTimer.setLabel(pending.some(({ranking}) => ranking.queued)
                ? `В очереди · наборов интервалов готово ${responses.length - pending.length} из ${responses.length}`
                : `Расчёт · наборов интервалов готово ${responses.length - pending.length} из ${responses.length}`);
            strategyRankingTimer.start();
        } else {
            const completedStatus = responses.every(({ranking}) => ['ready', 'insufficient_data', 'entry_unavailable'].includes(ranking.status));
            strategyRankingTimer.finish('Общее время до ответа', {
                useStored: completedStatus,
                fallbackElapsedMs: elapsedSeconds * 1000,
                persist: completedStatus,
            });
        }

        const entriesById = new Map();
        for (const {ranking} of responses) {
            for (const entry of ranking.entries || []) {
                const existing = entriesById.get(entry.id);
                if (!existing || Number(entry.best_pnl) > Number(existing.best_pnl)) entriesById.set(entry.id, entry);
            }
        }
        const rankedEntries = [...entriesById.values()].sort((left, right) => Number(right.best_pnl) - Number(left.best_pnl));
        if (rankedEntries.length) renderEntryOptions(rankedEntries, params.get('entry_config'));
        else if (entryConfigSelect.options.length === 1 && entryConfigSelect.options[0].value === '') entryConfigSelect.options[0].textContent = 'Рейтинг порогов входа (% от баланса) рассчитывается…';

        if (!pending.length && selectTopRankedResult && !entryOrderLocked && rankedEntries[0] && params.get('entry_config') !== rankedEntries[0].id) {
            clearStrategyResults();
            entryConfigSelect.value = rankedEntries[0].id;
            loadExitRanking(null, true);
            return;
        }

        const bestCandidatesByExit = new Map();
        const loadingMessages = [];
        for (const {snapshots, ranking, httpOk} of responses) {
            if (ranking.status === 'ready' && Array.isArray(ranking.groups)) {
                for (const group of ranking.groups) {
                    for (const item of group.items || []) {
                        const label = item.label
                            .replace(/ · TP (?:через \d+ (?:снимков|интервалов)|сразу) \/ SL (?:через \d+ (?:снимков|интервалов)|сразу)/, '')
                            .replaceAll('от стартового баланса', 'от баланса');
                        const candidate = {id: item.id, snapshots, pnl: Number(item.pnl) || 0, label};
                        const previous = bestCandidatesByExit.get(item.id);
                        const pinnedExit = exitOrderLocked && candidate.id === currentExit;
                        if (!previous || (pinnedExit
                            ? candidate.snapshots === selectedSnapshots
                            : candidate.pnl > previous.pnl)) bestCandidatesByExit.set(item.id, candidate);
                    }
                }
            } else {
                loadingMessages.push(ranking.status === 'pending'
                    ? `Загружаю варианты… ${ranking.queued ? 'в очереди' : `${ranking.progress || 0}%`}`
                    : ranking.status === 'entry_unavailable'
                        ? ranking.message || 'Нет вариантов для этого порога'
                        : ranking.status === 'insufficient_data'
                            ? ranking.message || 'Недостаточно сделок'
                            : ranking.message || (!httpOk ? 'Не удалось загрузить рейтинг' : 'Рейтинг временно недоступен'));
            }
        }
        const candidates = [...bestCandidatesByExit.values()]
            .sort((left, right) => right.pnl - left.pnl || left.id.localeCompare(right.id));
        // Keep the complete server-rendered TP/SL grid available while this
        // entry's ranked subset is still loading or the ranking request fails.
        // Replacing these selectors with a loading/error option made every
        // configured value disappear for the whole calculation window.
        let selectedCandidate = null;
        if (selectTopRankedResult && !exitOrderLocked && candidates.length) {
            selectedCandidate = candidates.reduce((best, candidate) => candidate.pnl > best.pnl ? candidate : best);
        } else {
            selectedCandidate = candidates.find(candidate => candidate.id === currentExit && candidate.snapshots === selectedSnapshots)
                || candidates.find(candidate => candidate.id === currentExit && mode === 'immediate');
        }
        if (!selectedCandidate) selectedCandidate = candidates[0] || null;
        if (selectedCandidate) {
            if (selectedCandidate.id !== currentExit || selectedCandidate.snapshots !== selectedSnapshots) {
                clearStrategyResults();
            }
            renderExitOptions(candidates,selectedCandidate.id,selectedCandidate.snapshots);
            if (!exitOrderLocked) document.querySelector('#exit-confirmation-snapshots').value = String(selectedCandidate.snapshots);
        }

        if (allInsufficient || (!pending.length && responses.every(({ranking}) => ranking.status === 'entry_unavailable'))) {
            setStrategySortIndicators(false);
            if (selectTopRankedResult && !entryOrderLocked && rankedEntries[0]) {
                if (entryConfigSelect.value !== rankedEntries[0].id) clearStrategyResults();
                entryConfigSelect.value = rankedEntries[0].id;
                loadExitRanking(null, true);
                return;
            }
            if (refreshAfterExitRanking) {
                refreshAfterExitRanking = false;
                void refreshStrategyResults();
            } else {
                revealStrategyResults();
            }
            return;
        }
        if (pending.length) {
            window.setTimeout(() => {
                if (requestVersion === exitRankingRequestVersion && !rankingMaintenancePending) loadExitRanking(null, true);
            }, 1500);
            return;
        }
        setStrategySortIndicators(false);
        const returnRunKey = responses.find(({ranking}) => ranking.status === 'ready' && ranking.return_run_key)?.ranking.return_run_key;
        if (returnRunKey) {
            rememberPortfolioReturnHistory({return_run_key: returnRunKey, return_point_count: 0}, 'ticker:' + (params.get('symbol') || ''));
        }
        if (!candidates.length) {
            if (!exitConfigSelect.options.length) {
                const exitMessage = document.createElement('option');
                exitMessage.textContent = 'Не удалось загрузить рейтинг вариантов';
                exitMessage.disabled = true;
                exitMessage.selected = true;
                exitConfigSelect.appendChild(exitMessage);
            }
            if (refreshAfterExitRanking) {
                refreshAfterExitRanking = false;
                void refreshStrategyResults();
            } else {
                revealStrategyResults();
            }
            return;
        }

        const appliedAutomaticSelection = selectTopRankedResult && mode !== 'none';
        if (selectTopRankedResult) {
            selectTopRankedResult = false;
            selectTopRankedResultInput.value = '0';
            const finalParams = new URLSearchParams(new FormData(filtersForm));
            finalParams.delete('select_top_ranked_result');
            history.replaceState(null, '', location.pathname + '?' + finalParams.toString());
        }
        updateMartingaleInitialNotional();
        if (appliedAutomaticSelection || refreshAfterExitRanking) {
            refreshAfterExitRanking = false;
            void refreshStrategyResults();
        } else {
            revealStrategyResults();
        }
        void loadCachedTickerBestResults();
    } catch (_) {
        if (requestVersion !== exitRankingRequestVersion || rankingMaintenancePending) return;
        setStrategySortIndicators(false);
        strategyRankingTimer.finish('Общее время до ошибки', {persist: false});
        renderExitOptions(initialExitOptions, exitConfigSelect.value, Number(document.querySelector('#exit-confirmation-snapshots')?.value) || 60);
        if (refreshAfterExitRanking) {
            refreshAfterExitRanking = false;
            void refreshStrategyResults();
        } else if (serverRenderedSelectionMatches()) {
            revealStrategyResults();
        }
    }
}
stopCalculationQueueButton.addEventListener('click', async () => {
    if (rankingMaintenancePending) return;
    rankingMaintenancePending = true;
    let stoppedProcesses = 0;
    let cancelledJobs = 0;
    let stopError = '';
    exitRankingRequestVersion++;
    portfolioRankingRequestVersion++;
    strategyRankingTimer.finish('Остановлено кнопкой очереди', {persist: false});
    portfolioRankingTimer.finish('Остановлено кнопкой очереди');
    refreshSymbolsButton.disabled = true;
    portfolioRankingStatus.textContent = 'Останавливаю расчёты и очищаю очередь…';
    stopCalculationQueueButton.disabled = true;
    stopCalculationQueueButton.textContent = '…';
    stopCalculationQueueButton.title = 'Останавливаю расчёты и очищаю очередь…';
    try {
        const response = await fetch('stop-ranking-jobs.php', {
            method: 'POST',
            headers: {Accept: 'application/json'},
            cache: 'no-store',
        });
        const result = await response.json();
        if (!response.ok || result.status !== 'ready') {
            throw new Error(result.message || 'Не удалось остановить расчёты и очистить очередь');
        }
        stoppedProcesses = Number(result.stopped_processes) || 0;
        cancelledJobs = Number(result.cancelled_jobs) || 0;
    } catch (error) {
        stopError = error.message || 'Не удалось остановить расчёты и очистить очередь';
    } finally {
        rankingMaintenancePending = false;
        stopCalculationQueueButton.disabled = false;
        stopCalculationQueueButton.textContent = '↻';
        stopCalculationQueueButton.title = stopError || `Остановлено расчётов: ${stoppedProcesses}; удалено из очереди: ${cancelledJobs}. Кэш сохранён.`;
        resetRefreshButton(stopError || 'Расчёты остановлены; очередь очищена. Кэш сохранён.');
        void refreshCalculationQueue();
    }
});
const wait = ms => new Promise(resolve => window.setTimeout(resolve, ms));
async function sortSymbolsByProfit() {
    if (!filtersForm.checkValidity()) {
        filtersForm.reportValidity();
        return;
    }
    setRefreshProgress(0, 'Расчет рейтинга пар…');
    try {
        const params = new URLSearchParams(new FormData(filtersForm));
        params.set('balance', params.get('ranking_balance') || params.get('balance') || '100');
        let ranking;
        let retryCancelled = true;
        do {
            const requestParams = new URLSearchParams(params);
            if (retryCancelled) requestParams.set('retry_cancelled', '1');
            retryCancelled = false;
            const response = await fetch(`symbol-ranking.php?${requestParams.toString()}`, {headers:{Accept:'application/json'}});
            ranking = await response.json();
            if (!response.ok || ranking.status === 'error') throw new Error(ranking.message || 'Не удалось отсортировать пары');
            if (ranking.status === 'pending') {
                const progressLabel = ranking.queued ? 'в очереди' : `${ranking.progress || 0}%`;
                setRefreshProgress(ranking.progress, `Расчет рейтинга пар… ${progressLabel}${ranking.symbol ? ` · ${ranking.symbol}` : ''}`);
                await wait(1500);
            }
        } while (ranking.status === 'pending');
        if (ranking.status === 'cancelled') {
            resetRefreshButton(ranking.message || 'Расчёт рейтинга пар остановлен кнопкой сброса кэша.');
            return;
        }
        if (ranking.status !== 'ready' || !Array.isArray(ranking.symbols)) throw new Error(ranking.message || 'Рейтинг пар пока недоступен');
        const currentSymbol = symbolSelect.value;
        const options = new Map([...symbolSelect.options].map(option => [option.value, option]));
        const sortedOptions = [];
        let rankedCount = 0;
        for (const item of ranking.symbols) {
            const option = options.get(item.symbol);
            if (!option) continue;
            if (item.best_pnl === null || item.best_pnl === undefined) {
                option.textContent = `${item.symbol} · нет надежного рейтинга`;
                option.title = params.get('save_mode') === '0' ? 'Нет варианта выхода хотя бы с одной сделкой' : 'Ни один вариант не дал минимум три сделки';
            } else {
                rankedCount++;
                const pnl = Number(item.best_pnl);
                option.textContent = `${item.symbol} · ${pnl > 0 ? '+' : ''}${new Intl.NumberFormat('ru-RU',{minimumFractionDigits:4,maximumFractionDigits:4}).format(pnl)} USDT`;
                option.title = `Лучший результат входа с ${item.best_exit || 'вариантом TakeProfit & StopLoss'}`;
            }
            sortedOptions.push(option);
        }
        for (const option of options.values()) if (!sortedOptions.includes(option)) sortedOptions.push(option);
        symbolSelect.replaceChildren(...sortedOptions);
        symbolSelect.value = currentSymbol;
        resetRefreshButton(`Пары отсортированы по выбранному порогу входа: надежных рейтингов ${rankedCount} из ${sortedOptions.length}.`);
    } catch (error) {
        resetRefreshButton(error.message || 'Не удалось отсортировать пары');
    } finally {
        refreshSymbolsButton.disabled = false;
    }
}
function rankingTable(container, columns, rows, sortOptions = null) {
    if (!rows.length) {
        container.replaceChildren();
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = 'Нет комбинаций с достаточным количеством сделок за выбранный период.';
        container.appendChild(empty);
        return;
    }
    let sortKey = sortOptions?.initialKey || null;
    let sortDirection = sortOptions?.initialDirection || 'asc';
    const render = () => {
        container.replaceChildren();
        const table = document.createElement('table');
        table.className = 'ranking-table';
        const head = document.createElement('thead');
        const headerRow = document.createElement('tr');
        for (const column of columns) {
            const cell = document.createElement('th');
            if (column.numeric) cell.className = 'numeric';
            if (sortOptions && column.sortKey) {
                const active = column.sortKey === sortKey;
                const sortButton = document.createElement('button');
                sortButton.type = 'button';
                sortButton.className = 'ranking-sort-button';
                sortButton.textContent = column.label + (active ? (sortDirection === 'asc' ? ' ↑' : ' ↓') : '');
                sortButton.setAttribute('aria-label', `Сортировать по полю «${column.label}»`);
                sortButton.setAttribute('aria-pressed', active ? 'true' : 'false');
                cell.classList.add('sortable');
                cell.setAttribute('aria-sort', active ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none');
                sortButton.addEventListener('click', () => {
                    if (sortKey === column.sortKey) {
                        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortKey = column.sortKey;
                        sortDirection = column.defaultDirection || 'asc';
                    }
                    render();
                });
                cell.appendChild(sortButton);
            } else {
                cell.textContent = column.label;
            }
            headerRow.appendChild(cell);
        }
        head.appendChild(headerRow);
        table.appendChild(head);
        const body = document.createElement('tbody');
        let sortedRows = rows;
        if (sortOptions && sortKey) {
            sortedRows = [...rows].sort((left, right) => {
                const leftValue = left.sortValues?.[sortKey];
                const rightValue = right.sortValues?.[sortKey];
                if (leftValue === null || leftValue === undefined) return rightValue === null || rightValue === undefined ? 0 : 1;
                if (rightValue === null || rightValue === undefined) return -1;
                const compared = typeof leftValue === 'number'
                    ? leftValue - rightValue
                    : String(leftValue).localeCompare(String(rightValue));
                if (compared !== 0) return sortDirection === 'asc' ? compared : -compared;
                return String(left.sortTieBreak || '').localeCompare(String(right.sortTieBreak || ''));
            });
        }
        for (const row of sortedRows) {
            const tr = document.createElement('tr');
            const values = Array.isArray(row) ? row : row.cells;
            values.forEach((value, index) => {
                const cell = document.createElement('td');
                if (value && typeof value === 'object' && typeof value.href === 'string') {
                    const link = document.createElement('a');
                    link.className = 'ranking-detail-link';
                    link.href = value.href;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = value.text;
                    link.title = 'Открыть результаты этой комбинации по всем тикерам в новой вкладке';
                    cell.appendChild(link);
                } else if (value && typeof value === 'object' && value.favoriteParams) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'ranking-favorite-button';
                    button.textContent = '☆';
                    button.title = 'Добавить эту комбинацию в избранное';
                    button.setAttribute('aria-label', button.title);
                    button.addEventListener('click', () => postFavorite(value.favoriteParams, button));
                    cell.appendChild(button);
                } else if (value && typeof value === 'object' && value.favoriteUnavailable) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'ranking-favorite-button';
                    button.textContent = '☆';
                    button.disabled = true;
                    button.title = 'Для этой строки пока нет готовой стратегии';
                    button.setAttribute('aria-label', button.title);
                    cell.appendChild(button);
                } else {
                    cell.textContent = value === null || value === undefined ? '—' : String(value);
                }
                if (columns[index].numeric) cell.className = 'numeric';
                tr.appendChild(cell);
            });
            body.appendChild(tr);
        }
        table.appendChild(body);
        container.appendChild(table);
    };
    render();
}
function pnlText(value, unit='USDT') {
    if (value === null || value === undefined) return '—';
    const amount = Number(value);
    return (amount > 0 ? '+' : '') + new Intl.NumberFormat('ru-RU',{minimumFractionDigits:4,maximumFractionDigits:4}).format(amount) + ' ' + unit;
}
function portfolioCombinationDetailLink(params, item) {
    if (!item.entry_id || !item.exit_id) return null;
    const detailParams = new URLSearchParams(params);
    detailParams.delete('symbol');
    detailParams.delete('symbol_sort');
    detailParams.set('entry_config', item.entry_id);
    detailParams.set('exit_config', item.exit_id);
    detailParams.set('entry_label', item.entry_label || '');
    detailParams.set('exit_label', item.exit_label || '');
    return {
        entry: {text: item.entry_label || '—', href: 'portfolio-combination.php?' + detailParams.toString()},
        exit: {text: item.exit_label || '—', href: 'portfolio-combination.php?' + detailParams.toString()},
    };
}
function portfolioRankingDiagnosticsText(ranking) {
    const number = value => Number.isFinite(Number(value)) ? Number(value).toLocaleString('ru-RU') : '0';
    const duration = seconds => {
        const total = Math.max(0, Math.floor(Number(seconds) || 0));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const remainder = total % 60;
        return hours ? `${hours} ч ${minutes} мин` : `${minutes} мин ${remainder} с`;
    };
    const estimatedFinish = () => {
        if (ranking.status !== 'pending') return '';
        if (ranking.queued) return 'Ожидаемое окончание: после запуска расчёта из очереди.';
        if (ranking.phase === 'saving_return_history' && ranking.history_progress === null) {
            return 'Ожидаемое окончание: текущий старый процесс не передаёт подробный прогресс сохранения.';
        }
        const elapsedSeconds = Number(ranking.elapsed_seconds);
        const completedCandidates = Number(ranking.completed_candidates);
        const totalCandidates = Number(ranking.total_candidates);
        const completedRatio = totalCandidates > 0 && completedCandidates > 0
            ? completedCandidates / totalCandidates
            : Number(ranking.progress || 0) / 100;
        if (!Number.isFinite(elapsedSeconds) || elapsedSeconds <= 0 || completedRatio <= 0) {
            return 'Ожидаемое окончание: оценка появится после первых завершённых порогов.';
        }
        const ratio = Math.min(0.999, completedRatio);
        const remainingSeconds = elapsedSeconds * (1 - ratio) / ratio;
        const finish = new Date(Date.now() + remainingSeconds * 1000);
        const formatted = new Intl.DateTimeFormat('ru-RU', {
            timeZone: 'Europe/Kyiv', day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
        }).format(finish);
        return `Ожидаемое окончание: ${formatted} (оценка).`;
    };
    const lines = [];
    if (ranking.result_cache_hit) {
        const cachedAt = ranking.result_cached_at ? new Date(ranking.result_cached_at).toLocaleString('ru-RU', {timeZone:'Europe/Kyiv'}) : null;
        lines.push(`Готовый общий рейтинг взят из точного кэша${cachedAt ? ` · сохранен ${cachedAt}` : ''}; новый перебор не выполнялся.`);
    } else if (ranking.status === 'pending' && !ranking.queued && ranking.phase === 'saving_return_history') {
        lines.push('Расчёт комбинаций завершён. Сейчас результаты записываются в таблицу истории доходности; после этого рейтинг будет построен из сохранённых данных.');
    } else if (ranking.status === 'pending' && !ranking.queued && ranking.phase === 'finalizing_return_history') {
        lines.push('Симуляция и запись событий сделок завершены. Завершаю сохранённый расчёт.');
    } else if (ranking.status === 'pending' && !ranking.queued) {
        lines.push('Готового общего рейтинга с этими датами и фильтрами нет. Идет новый проход: worker использует подходящие дневные состояния и результаты симуляций, если их версия котировок и параметры совпадают; остальное рассчитывает заново.');
    }
    if (ranking.phase === 'loading_quotes') {
        lines.push(`Загружаю котировки по тикерам: ${number(ranking.loaded_quote_symbols)} из ${number(ranking.total_quote_symbols)}${ranking.current_quote_symbol ? ` · сейчас ${ranking.current_quote_symbol}` : ''}.`);
    } else if (ranking.phase === 'building_signals') {
        if (ranking.signals_built_symbols !== undefined) {
            lines.push(`Строю сигналы входа по тикерам: ${number(ranking.signals_built_symbols)} из ${number(ranking.total_signal_symbols)}${ranking.current_signal_symbol ? ` · сейчас ${ranking.current_signal_symbol}` : ''}.`);
        } else {
            lines.push('Котировки загружены. Подготавливаю сигналы входа для расчёта.');
        }
    } else if (ranking.phase === 'simulating_candidates') {
        lines.push(`Сигналы готовы. Перебираю ${number(ranking.total_candidates)} комбинаций по ${number(ranking.universe_size)} тикерам.`);
    }
    if (ranking.start_day || ranking.end_day) lines.push(`Диапазон котировок: ${ranking.start_day || '—'} — ${ranking.end_day || '—'}; тикеров: ${number(ranking.universe_size)}.`);
    if (ranking.total_candidates !== undefined) {
        const profileRatio = Number(ranking.total_entry_profiles) > 0
            ? (Number(ranking.completed_entry_profiles) || 0) / Number(ranking.total_entry_profiles) : 0;
        const completedCandidates = ranking.completed_candidates !== undefined
            ? Number(ranking.completed_candidates) : Math.floor(Number(ranking.total_candidates) * profileRatio);
        const estimateLabel = ranking.completed_candidates === undefined ? 'примерно ' : '';
        lines.push(`Комбинаций входа/выхода обработано ${estimateLabel}${number(completedCandidates)} из ${number(ranking.total_candidates)}; порогов входа ${ranking.completed_entry_profiles || 0} из ${ranking.total_entry_profiles || 0}${ranking.entry_profile ? ` · сейчас ${ranking.entry_profile}` : ''}.`);
    }
    if (ranking.total_ticker_evaluations !== undefined) {
        const profileRatio = Number(ranking.total_entry_profiles) > 0
            ? (Number(ranking.completed_entry_profiles) || 0) / Number(ranking.total_entry_profiles) : 0;
        const completedEvaluations = ranking.completed_ticker_evaluations !== undefined
            ? Number(ranking.completed_ticker_evaluations) : Math.floor(Number(ranking.total_ticker_evaluations) * profileRatio);
        const estimateLabel = ranking.completed_ticker_evaluations === undefined ? 'примерно ' : '';
        lines.push(`Объем прохода по тикерам: ${estimateLabel}${number(completedEvaluations)} из ${number(ranking.total_ticker_evaluations)} проверок «тикер × порог × выход».`);
    }
    const checkpointDates = ranking.checkpoint_days_reused && typeof ranking.checkpoint_days_reused === 'object'
        ? Object.entries(ranking.checkpoint_days_reused).sort(([a], [b]) => a.localeCompare(b)) : [];
    const checkpointCount = Number(ranking.checkpoint_result_reuses) || 0;
    if (ranking.phase === 'simulating_candidates' || ranking.checkpoint_result_reuses !== undefined) {
        lines.push(`Дневные состояния использованы для ${number(checkpointCount)} результатов${checkpointDates.length ? `; даты контрольных точек: ${checkpointDates.map(([day, count]) => `${day} (${number(count)} связок тикер/порог)`).join(', ')}` : '; подтвержденные даты контрольных точек пока не записаны'}.`);
    }
    const hits = Number(ranking.pair_cache_hits) || 0;
    const misses = Number(ranking.pair_cache_misses) || 0;
    if (hits + misses > 0 || ranking.pair_cache_hits !== undefined) {
        const percent = hits + misses ? Math.round(hits * 100 / (hits + misses)) : 0;
        lines.push(`Кэш результатов: найдено ${number(hits)} (${percent}%), рассчитано заново ${number(misses)}; сохранены дневные точки для ${number(ranking.checkpoint_writes)} связок тикер/порог${Number(ranking.checkpoint_skips_low_disk) ? `, не сохранены из-за свободного места для ${number(ranking.checkpoint_skips_low_disk)} связок` : ''}.`);
    } else if (ranking.status === 'pending' && !ranking.queued && ranking.phase !== 'saving_return_history' && ranking.phase !== 'finalizing_return_history') lines.push('Счетчики попаданий/промахов кэша появятся в новых расчетах; этот уже запущенный процесс их не записывает.');
    if (ranking.queued) lines.push('Задача пока ждет свободного обработчика; статистика появится после запуска.');
    else if (ranking.elapsed_seconds !== undefined) lines.push(`Прошло: ${duration(ranking.elapsed_seconds)}.`);
    if (ranking.status === 'pending') lines.push(estimatedFinish());
    if (checkpointDates.length || checkpointCount) lines.push('Контрольные точки хранят состояние на конец дня. Если последняя подходящая точка раньше конца периода, хвост котировок пересчитывается; если точки на нужный день нет или проверка версии котировок не прошла, расчет идет с более ранней точки.');
    if (checkpointCount > 0 && checkpointDates.length === 0) lines.push('Этот расчет запущен до добавления отчета с датами контрольных точек; новые запуски покажут даты, которые действительно загрузили.');
    if (ranking.min_trades !== undefined) lines.push(`SAVE MODE = минимум ${ranking.min_trades} сделки: он отсекает неподходящие результаты после расчета и сам по себе не уменьшает число проверяемых TP/SL.`);
    if (ranking.status === 'ready' && Array.isArray(ranking.global_top)) {
        const topOutcomeCounts = new Map();
        for (const item of ranking.global_top) {
            const key = [Number(item.average_pnl).toFixed(6), item.eligible_symbols, item.profitable_symbols].join('|');
            topOutcomeCounts.set(key, (topOutcomeCounts.get(key) || 0) + 1);
        }
        const repeatedTopRows = [...topOutcomeCounts.values()].reduce((sum, count) => sum + Math.max(0, count - 1), 0);
        if (repeatedTopRows) lines.push(`В сохраненном топ-5 есть ${repeatedTopRows} дополнительная комбинация с тем же средним итогом и покрытием — близкие варианты выхода могут давать одинаковый результат на этих котировках.`);
        lines.push('В кэше рейтинга сохраняются топ-5 и лучший вариант каждого тикера, а не таблица всех кандидатов. Этого достаточно для показа победителей, но мало для надежного решения, какие диапазоны можно убрать.');
    }
    portfolioRankingDiagnostics.hidden = lines.length === 0;
    portfolioRankingDiagnostics.textContent = lines.join('\n');
}
async function loadPortfolioRankingHistory(force = false) {
    if (await ensurePortfolioRankingCacheChecked()) {
        portfolioRankingHistory.hidden = true;
        return;
    }
    const tickerSet = filtersForm.querySelector('[name="ticker_set"]')?.value || '';
    if (!force && tickerSet !== '' && portfolioRankingHistorySetId === tickerSet) return;
    portfolioRankingHistorySetId = tickerSet;
    portfolioRankingHistory.hidden = false;
    const requestId = ++portfolioRankingHistoryRequest;
    portfolioRankingHistory.replaceChildren();
    const title = document.createElement('h3');
    title.className = 'ranking-history-title';
    title.textContent = 'Сохранённые общие рейтинги';
    const status = document.createElement('p');
    status.className = 'ranking-history-status';
    status.textContent = 'Загружаю готовые расчёты для выбранного списка тикеров…';
    const header = document.createElement('div');
    header.className = 'ranking-history-header';
    header.append(title, deletePortfolioRankingHistoryButton);
    portfolioRankingHistory.append(header, status);
    try {
        const query = new URLSearchParams({ticker_set: tickerSet});
        const response = await fetch('portfolio-ranking-history.php?' + query.toString(), {headers:{Accept:'application/json'}, cache:'no-store'});
        const payload = await response.json();
        if (requestId !== portfolioRankingHistoryRequest) return;
        if (!response.ok || payload.status !== 'ready') throw new Error(payload.message || 'Не удалось загрузить сохранённые расчёты.');
        const rankings = Array.isArray(payload.rankings) ? payload.rankings : [];
        if (rankings.length === 0) {
            deletePortfolioRankingHistoryButton.hidden = true;
            status.textContent = `Для списка «${payload.ticker_set?.name || 'выбранный список'}» пока нет сохранённых общих рейтингов.`;
            return;
        }
        deletePortfolioRankingHistoryButton.hidden = false;
        status.textContent = `Список «${payload.ticker_set?.name || 'выбранный список'}» · ${Number(payload.ticker_set?.symbols?.length || 0).toLocaleString('ru-RU')} тикеров · готовых расчётов: ${Number(rankings.length).toLocaleString('ru-RU')}.`;
        const list = document.createElement('ul');
        list.className = 'ranking-history-list';
        for (const item of rankings) {
            const row = document.createElement('li');
            row.className = 'ranking-history-item';
            const info = document.createElement('div');
            const range = document.createElement('strong');
            range.textContent = item.start_day && item.end_day ? `${item.start_day} — ${item.end_day}` : 'Диапазон дат не записан';
            const count = document.createElement('p');
            const duplicateCount = Number(item.duplicate_count) || 1;
            count.textContent = `${Number(item.universe_size || 0).toLocaleString('ru-RU')} тикеров · сохранён ${new Date(item.cached_at).toLocaleString('ru-RU')}${duplicateCount > 1 ? ` · одинаковый результат сохранён ${duplicateCount} раза` : ''}`;
            info.append(range, count);
            const actions = document.createElement('div');
            actions.className = 'ranking-history-actions';
            const show = document.createElement('button');
            show.type = 'button';
            show.textContent = 'Показать';
            show.addEventListener('click', () => loadPortfolioRanking({savedCacheKey: item.cache_key}));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ranking-history-delete-one';
            remove.textContent = 'Удалить';
            remove.setAttribute('aria-label', `Удалить сохранённый рейтинг за ${item.start_day || 'неизвестный период'} — ${item.end_day || 'неизвестный период'}`);
            remove.addEventListener('click', () => deletePortfolioRankingHistoryItem(item));
            actions.append(show, remove);
            row.append(info, actions);
            const details = document.createElement('details');
            const summary = document.createElement('summary');
            summary.textContent = item.parameters ? 'Параметры и список тикеров' : 'Список тикеров и параметры';
            const pre = document.createElement('pre');
            pre.textContent = JSON.stringify({
                ticker_set: item.ticker_set || null,
                date_range: {start_day: item.start_day || null, end_day: item.end_day || null},
                parameters: item.parameters,
                request_params: item.request_params,
            }, null, 2);
            details.append(summary, pre);
            row.appendChild(details);
            list.appendChild(row);
        }
        portfolioRankingHistory.appendChild(list);
    } catch (error) {
        if (requestId !== portfolioRankingHistoryRequest) return;
        status.textContent = error.message || 'Не удалось загрузить сохранённые общие рейтинги.';
    }
}
async function deletePortfolioRankingHistoryItem(item) {
    const period = item.start_day && item.end_day ? `${item.start_day} — ${item.end_day}` : 'этот период';
    if (!window.confirm(`Удалить только сохранённый общий рейтинг за ${period}?`)) return;
    const buttons = [...portfolioRankingHistory.querySelectorAll('.ranking-history-actions button')];
    buttons.forEach((button) => { button.disabled = true; });
    const remove = buttons.find((button) => button.classList.contains('ranking-history-delete-one'));
    if (remove) remove.textContent = 'Удаляю…';
    try {
        const tickerSet = filtersForm.querySelector('[name="ticker_set"]')?.value || '';
        const response = await fetch('portfolio-ranking-delete.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json'},
            body: new URLSearchParams({ticker_set: tickerSet, cache_key: item.cache_key}),
            cache: 'no-store',
        });
        const payload = await response.json();
        if (!response.ok || payload.status !== 'ready') throw new Error(payload.message || 'Не удалось удалить сохранённый рейтинг.');
        if (Number(payload.deleted) !== 1) throw new Error('Рейтинг уже удалён, выполняется или не относится к выбранному списку.');
        portfolioRankingHistorySetId = '';
        await loadPortfolioRankingHistory(true);
        const status = portfolioRankingHistory.querySelector('.ranking-history-status');
        if (status) status.textContent = `Сохранённый рейтинг за ${period} удалён.`;
    } catch (error) {
        const status = portfolioRankingHistory.querySelector('.ranking-history-status');
        if (status) status.textContent = error.message || 'Не удалось удалить сохранённый рейтинг.';
        buttons.forEach((button) => { button.disabled = false; });
        if (remove) remove.textContent = 'Удалить';
    }
}
deletePortfolioRankingHistoryButton?.addEventListener('click', async () => {
    if (deletePortfolioRankingHistoryButton.disabled) return;
    const tickerSet = filtersForm.querySelector('[name="ticker_set"]')?.value || '';
    if (!window.confirm('Удалить все сохранённые общие рейтинги для выбранного списка тикеров?')) return;
    deletePortfolioRankingHistoryButton.disabled = true;
    deletePortfolioRankingHistoryButton.textContent = 'Удаляю…';
    try {
        const response = await fetch('portfolio-ranking-delete.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json'},
            body: new URLSearchParams({ticker_set: tickerSet}),
            cache: 'no-store',
        });
        const payload = await response.json();
        if (!response.ok || payload.status !== 'ready') throw new Error(payload.message || 'Не удалось удалить сохранённые рейтинги.');
        portfolioRankingHistorySetId = '';
        await loadPortfolioRankingHistory(true);
        const status = portfolioRankingHistory.querySelector('.ranking-history-status');
        if (status) status.textContent = `Удалено сохранённых рейтингов: ${Number(payload.deleted || 0).toLocaleString('ru-RU')}.`;
    } catch (error) {
        const status = portfolioRankingHistory.querySelector('.ranking-history-status');
        if (status) status.textContent = error.message || 'Не удалось удалить сохранённые рейтинги.';
    } finally {
        deletePortfolioRankingHistoryButton.disabled = false;
        deletePortfolioRankingHistoryButton.textContent = 'Удалить все';
    }
});
async function ensurePortfolioRankingCacheChecked() {
    if (portfolioRankingExactCacheReady) return true;
    if (portfolioRankingCacheChecked) return false;
    if (!portfolioRankingCacheLookupPromise) {
        portfolioRankingCacheLookupPromise = loadPortfolioRanking({cachedOnly: true})
            .then(() => {
                portfolioRankingCacheChecked = true;
                return portfolioRankingExactCacheReady;
            })
            .finally(() => { portfolioRankingCacheLookupPromise = null; });
    }
    return portfolioRankingCacheLookupPromise;
}
async function loadPortfolioRanking({cachedOnly = false, savedCacheKey = '', calculateReturnsOnly = false, selectorsOnly = false} = {}) {
    const requestVersion = ++portfolioRankingRequestVersion;
    portfolioRankingStatusPolling = !selectorsOnly;
    const showingSavedResult = savedCacheKey !== '';
    if (!selectorsOnly) clearRankingComparison();
    portfolioRankingTimer.reset();
    if (!selectorsOnly) {
        portfolioRankingPanel.hidden = false;
        portfolioRankingContent.hidden = portfolioRankingCollapsed;
    }
    if (selectorsOnly) {
        setReturnStatus('Сортирую списки торговых пар и стратегий по сохранённой доходности…');
    } else if (!cachedOnly && !showingSavedResult && !calculateReturnsOnly) {
        portfolioRankingTimer.start();
        portfolioRankingStatus.textContent = 'Подготавливаю общий рейтинг…';
        setRefreshProgress(0, 'Подготавливаю общий рейтинг…');
    } else if (calculateReturnsOnly) {
        calculateReturnHistoryButton.disabled = true;
        calculateReturnHistoryButton.textContent = 'Рассчитываю доходность…';
        portfolioRankingStatus.textContent = 'Подготавливаю расчёт доходности для выбранного списка…';
        portfolioRankingContent.hidden = false;
    } else if (showingSavedResult) {
        portfolioRankingStatus.textContent = 'Загружаю сохранённый общий рейтинг…';
    }
    let buttonTitle = 'Составить рейтинг из таблицы доходности';
    let sawPending = false;
    try {
        const params = new URLSearchParams(new FormData(filtersForm));
        params.set('balance', params.get('ranking_balance') || params.get('balance') || '100');
        let retryCancelled = !cachedOnly;
        if (!cachedOnly && !showingSavedResult) history.replaceState(null, '', location.pathname + '?' + params.toString());
        let ranking;
        do {
            if (requestVersion !== portfolioRankingRequestVersion) return;
            const requestParams = new URLSearchParams(params);
            if (cachedOnly) requestParams.set('cached_only', '1');
            if (!showingSavedResult) {
                if (calculateReturnsOnly) {
                    requestParams.set('persist_returns', '1');
                    requestParams.set('return_history_only', '1');
                } else if (!cachedOnly) {
                    requestParams.set('rank_from_returns', '1');
                }
            }
            if (retryCancelled) requestParams.set('retry_cancelled', '1');
            retryCancelled = false;
            const responseUrl = showingSavedResult
                ? 'portfolio-ranking-result.php?' + new URLSearchParams({cache_key: savedCacheKey, ticker_set: params.get('ticker_set') || ''}).toString()
                : 'portfolio-ranking.php?' + requestParams.toString();
            const response = await fetch(responseUrl, {headers:{Accept:'application/json'}});
            ranking = await response.json();
            if (requestVersion !== portfolioRankingRequestVersion) return;
            if (!response.ok || ranking.status === 'error') throw new Error(ranking.message || 'Не удалось рассчитать общий рейтинг');
            if (cachedOnly && ranking.status === 'missing') {
                portfolioRankingExactCacheReady = false;
                portfolioRankingCacheChecked = true;
                portfolioRankingStatus.textContent = 'Сохранённый общий рейтинг для выбранного периода и параметров не найден. Нажмите ↻, чтобы запустить расчёт.';
                return;
            }
            if (ranking.status === 'missing_return_history') {
                if (cachedOnly) {
                    portfolioRankingExactCacheReady = false;
                    portfolioRankingCacheChecked = true;
                }
                portfolioRankingStatus.textContent = ranking.message || 'Сначала рассчитайте доходность для выбранного списка.';
                if (calculateReturnsOnly) throw new Error(portfolioRankingStatus.textContent);
                setPortfolioRankingCollapsed(false);
                portfolioRankingContent.hidden = false;
                return;
            }
            if (!selectorsOnly) portfolioRankingDiagnosticsText(ranking);
            if (ranking.status === 'pending') {
                sawPending = true;
                const completedProfiles = Number(ranking.completed_entry_profiles);
                const totalProfiles = Number(ranking.total_entry_profiles);
                const profileProgress = Number.isFinite(completedProfiles) && Number.isFinite(totalProfiles) && totalProfiles > 0
                    ? `Порог входа ${completedProfiles} из ${totalProfiles}${ranking.entry_profile ? ` · ${ranking.entry_profile}` : ''}`
                    : '';
                const savedReturnsLabel = ranking.saved_return_points !== undefined
                    ? `уже сохранено событий сделок: ${Number(ranking.saved_return_points).toLocaleString('ru-RU')}`
                    : '';
                const returnHistoryProgress = !ranking.progress_stale && ranking.history_progress !== null && ranking.history_progress !== undefined && Number.isFinite(Number(ranking.history_progress))
                    ? `${Number(ranking.history_progress)}% (${Number(ranking.history_completed || 0).toLocaleString('ru-RU')} из ${Number(ranking.history_total || 0).toLocaleString('ru-RU')} связок тикер/порог${ranking.history_symbol ? ` · ${ranking.history_symbol}` : ''}${ranking.history_entry_profile ? ` · ${ranking.history_entry_profile}` : ''}${ranking.emitted_return_points !== null && ranking.emitted_return_points !== undefined ? ` · сформировано ${Number(ranking.emitted_return_points).toLocaleString('ru-RU')} точек` : ''})`
                    : '';
                let stageProgress = ranking.progress_stale ? null : ranking.progress;
                let stageText = ranking.progress_stale ? 'Расчёт продолжается, статус давно не обновлялся' : '';
                if (ranking.phase === 'aggregating_returns') {
                    stageText = `Подготавливаю сводку: ${Number(ranking.completed_symbols)||0} из ${Number(ranking.total_symbols)||0} пар${ranking.current_symbol ? ' · '+ranking.current_symbol : ''}`;
                } else if (ranking.phase === 'finalizing_return_ranking') {
                    stageText = 'Сортирую готовые итоги стратегий';
                } else if (!ranking.progress_stale && ranking.phase === 'loading_quotes' && Number(ranking.total_quote_symbols) > 0) {
                    const done = Number(ranking.loaded_quote_symbols) || 0;
                    const total = Number(ranking.total_quote_symbols);
                    stageProgress = Math.round(done * 5 / total);
                    stageText = `Загружаю котировки: ${done} из ${total}${ranking.current_quote_symbol ? ` · ${ranking.current_quote_symbol}` : ''}`;
                } else if (!ranking.progress_stale && ranking.phase === 'building_signals' && Number(ranking.total_signal_symbols) > 0) {
                    const done = Number(ranking.signals_built_symbols) || 0;
                    const total = Number(ranking.total_signal_symbols);
                    stageProgress = 5 + Math.round(done * 5 / total);
                    stageText = `Строю сигналы: ${done} из ${total}${ranking.current_signal_symbol ? ` · ${ranking.current_signal_symbol}` : ''}`;
                } else if (!ranking.progress_stale && ranking.phase === 'simulating_candidates') {
                    stageProgress = 10 + Math.round((Number(ranking.progress) || 0) * 0.8);
                    stageText = `Рассчитываю стратегии${profileProgress ? ` · ${profileProgress}` : ''}`;
                } else if (!ranking.progress_stale && ranking.phase === 'saving_return_history') {
                    stageProgress = returnHistoryProgress ? 90 + Math.round(Number(ranking.history_progress) * 0.1) : null;
                    stageText = returnHistoryProgress
                        ? `Сохраняю выбранные результаты · ${returnHistoryProgress}`
                        : 'Подготавливаю сохранение выбранных результатов';
                } else if (!ranking.progress_stale && ranking.phase === 'finalizing_return_history') {
                    stageProgress = 99;
                    stageText = 'Завершаю сохранение событий сделок';
                }
                if (calculateReturnsOnly) {
                    const progress = Number(stageProgress) || 0;
                    const stageProgressKnown = stageProgress !== null && stageProgress !== undefined && Number.isFinite(Number(stageProgress));
                    calculateReturnHistoryButton.textContent = ranking.queued ? 'Доходность в очереди…' : ranking.progress === null
                        ? 'Сохраняю доходность…'
                        : `Рассчитываю доходность… ${progress}%`;
                    portfolioRankingStatus.textContent = ranking.queued
                        ? 'Расчёт доходности ожидает очереди.'
                        : stageText
                            ? `${stageText}${stageProgressKnown ? ` · общий прогресс ${progress}%` : ''}${ranking.progress_stale ? ' · показывается последнее подтверждённое состояние' : ''}`
                            : ranking.phase === 'saving_return_history' || ranking.phase === 'finalizing_return_history'
                            ? `Сохраняю историю доходности для всех пар…${returnHistoryProgress ? ` ${returnHistoryProgress}` : ranking.progress === null ? '' : ` ${progress}%`}${savedReturnsLabel ? ` · ${savedReturnsLabel}` : ''}${ranking.progress_stale ? ' · этап может долго выполняться без обновления статуса' : ''}`
                            : `Сохраняю историю доходности для всех пар… ${progress}%${profileProgress ? ' · ' + profileProgress : ''}`;
                } else if (selectorsOnly) {
                    const progress=Number(ranking.progress)||0;
                    const progressLabel=ranking.progress===null?'':` ${progress}%`;
                    setReturnStatus(ranking.phase==='saving_return_history'||ranking.phase==='finalizing_return_history'
                        ? `Завершаю сохранение событий сделок…${progressLabel}`
                        : 'Ожидаю завершения сохранения доходности…');
                } else {
                    const progress=Number(stageProgress)||0;
                    const progressKnown=stageProgress!==null&&stageProgress!==undefined&&Number.isFinite(Number(stageProgress));
                    const waitingForReturns=ranking.phase==='saving_return_history'||ranking.phase==='finalizing_return_history';
                    const progressLabel=progressKnown?` ${progress}%`:'';
                    const progressText = ranking.queued
                        ? 'Рейтинг ожидает очереди…'
                        : stageText
                            ? `${stageText}${progressKnown ? ` · общий прогресс ${progress}%` : ''}${ranking.progress_stale ? ' · показывается последнее подтверждённое состояние' : ''}`
                        : waitingForReturns
                            ? `Сохраняю точки доходности перед рейтингом…${returnHistoryProgress ? ` ${returnHistoryProgress}` : progressLabel}${savedReturnsLabel ? ` · ${savedReturnsLabel}` : ''}${ranking.progress_stale ? ' · этап может долго выполняться без обновления статуса' : ''}`
                            : progressKnown
                                ? 'Составляю рейтинг по рассчитанной доходности… ' + progress + '%'
                                : 'Расчёт доходности продолжается · подробный процент пока недоступен';
                    portfolioRankingStatus.textContent = ranking.queued
                        ? 'Рейтинг ожидает очереди общего обработчика.'
                        : progressText;
                    setRefreshProgress(progressKnown ? progress : null, progressText);
                }
                await wait(1500);
            }
        } while (ranking.status === 'pending');
        if (ranking.status === 'cancelled') {
            portfolioRankingStatus.textContent = ranking.message || 'Общий расчёт остановлен кнопкой сброса кэша.';
            buttonTitle = 'Расчет остановлен';
            return;
        }
        if (ranking.status !== 'ready') throw new Error(ranking.message || 'Общий рейтинг пока недоступен');
        if (!calculateReturnsOnly && ranking.cache_key) void checkPortfolioRankingComparisons(ranking.cache_key);
        if (calculateReturnsOnly) {
            rememberPortfolioReturnHistory(ranking);
            setReturnHistoryActionState('ready');
            if (ranking.return_run_key) void loadTickerReturnSummary(ranking.return_run_key);
            setReturnStatus(symbolSelect.value
                ? `История доходности рассчитана: ${Number(ranking.return_point_count || 0).toLocaleString('ru-RU')} точек. Для выбора порога загружаю рейтинг выбранной пары.`
                : `История доходности рассчитана: ${Number(ranking.return_point_count || 0).toLocaleString('ru-RU')} точек. Чтобы показать доходность и отсортировать пары, нажмите «Составить рейтинг».`);
            if (symbolSelect.value) {
                selectorReturnRunKey = ranking.return_run_key || '';
                await loadExitRanking(null, true);
            }
            return;
        }
        if (selectorsOnly) {
            const tickerResults=(ranking.ticker_leaders||[]).map(item=>({
                symbol:item.symbol,pnl:item.pnl,
                title:item.entry_label?'Лучший вариант: '+item.entry_label+' · '+(item.exit_label||'TP/SL не указаны'):'Лучший сохранённый результат тикера',
            }));
            renderTickerBestResults(tickerResults);
            selectorReturnRunKey=ranking.return_run_key||'';
            const selectedEntryBefore=entryConfigSelect.value;
            await refreshSelectorsFromReturnHistory(ranking.return_run_key,'',true);
            if(entryConfigSelect.value!==selectedEntryBefore){
                await loadExitRanking(null,true);
                return;
            }
            setReturnStatus(`Селекты отсортированы по сохранённой доходности за ${ranking.start_day} — ${ranking.end_day}.`);
            return;
        }
        if (!cachedOnly && !showingSavedResult) rememberPortfolioReturnHistory(ranking);
        if (!showingSavedResult) {
            portfolioRankingExactCacheReady = true;
            portfolioRankingCacheChecked = true;
            portfolioRankingHistory.hidden = true;
        }
        if (!cachedOnly || sawPending) setPortfolioRankingCollapsed(false);
        portfolioRankingContent.hidden = portfolioRankingCollapsed;
        const canLinkCurrentSettings = !showingSavedResult;
        const rankingUnit = ranking.ranking_data_source === 'strategy_return_points' ? '%' : 'USDT';
        const savedMartingaleMode = ranking.history_metadata?.parameters?.martingale?.mode ?? null;
        const tradeMetricLabel = showingSavedResult
            ? (savedMartingaleMode === null ? 'Сделки / цепочки' : (savedMartingaleMode === 'none' ? 'Сделки' : 'Ордеры · цепочки'))
            : (params.get('martingale_mode') && params.get('martingale_mode') !== 'none' ? 'Ордеры · цепочки' : 'Сделки');
        const favoritePayload = (item, type, symbol) => {
            if (!canLinkCurrentSettings) return {favoriteUnavailable: true};
            if (!item.entry_id || !item.exit_id) return {favoriteUnavailable: true};
            const favoriteParams = new URLSearchParams(params);
            favoriteParams.set('favorite_type', type);
            favoriteParams.set('symbol', symbol || symbolSelect.value);
            favoriteParams.set('favorite_ranking_entry_config', params.get('entry_config') || '');
            favoriteParams.set('entry_config', item.entry_id || '');
            favoriteParams.set('exit_config', item.exit_id || '');
            favoriteParams.set('favorite_entry_label', item.entry_label || '');
            favoriteParams.set('favorite_exit_label', item.exit_label || '');
            if (type === 'ticker_leader' && item.pnl !== null && item.pnl !== undefined && Number.isFinite(Number(item.pnl))) {
                favoriteParams.set('favorite_result', Number(item.pnl).toFixed(8));
                favoriteParams.set('favorite_result_label', 'Итог тикера');
            }
            if (type === 'ticker_leader' && item.market_average_pnl !== null && item.market_average_pnl !== undefined && Number.isFinite(Number(item.market_average_pnl))) {
                favoriteParams.set('favorite_market_average', Number(item.market_average_pnl).toFixed(8));
                favoriteParams.set('favorite_market_average_label', 'Средний итог этой пары на рынке');
            }
            favoriteParams.set('favorite_name', `${type === 'global_top' ? 'Общий топ-5' : symbol} · ${item.entry_label || item.entry_id} · ${item.exit_label || item.exit_id}`);
            return favoriteParams;
        };
        const globalRows = (ranking.global_top || []).map(item => {
            const detailLink = canLinkCurrentSettings ? portfolioCombinationDetailLink(params, item) : null;
            return [
                detailLink?.entry || item.entry_label, detailLink?.exit || item.exit_label, pnlText(item.average_pnl, rankingUnit),
                item.eligible_symbols + ' из ' + ranking.universe_size,
                String(item.profitable_symbols),
                {favoriteParams: favoritePayload(item, 'global_top', symbolSelect.value)},
            ];
        });
        rankingTable(document.querySelector('#portfolio-global-table'), [
            {label:'Порог входа'}, {label:'TakeProfit & StopLoss'},
            {label:`Средний итог на тикер · ${rankingUnit}`,numeric:true}, {label:'Подходят по SAVE MODE',numeric:true}, {label:'В плюсе',numeric:true},
            {label:'Избранное'},
        ], globalRows);
        const tickerRows = (ranking.ticker_leaders || []).map(item => {
            const detailLink = canLinkCurrentSettings ? portfolioCombinationDetailLink(params, item) : null;
            const entryCell = detailLink?.entry || item.entry_label;
            const exitCell = detailLink?.exit || item.exit_label;
            return {
                cells: [
                    item.symbol, entryCell, exitCell,
                    pnlText(item.pnl, rankingUnit), pnlText(item.market_average_pnl, rankingUnit),
                    item.market_eligible_symbols === undefined ? '—' : item.market_eligible_symbols + ' из ' + ranking.universe_size,
                    item.trade_count ? (showingSavedResult
                        ? (savedMartingaleMode === null ? `${item.trade_count} · ${item.chain_count ?? 0}` : (savedMartingaleMode === 'none' ? String(item.trade_count) : `${item.trade_count} · ${item.chain_count ?? 0}`))
                        : (!params.get('martingale_mode') || params.get('martingale_mode') === 'none' ? String(item.trade_count) : `${item.trade_count} · ${item.chain_count ?? 0}`)) : 'нет надежных данных',
                    {favoriteParams: favoritePayload(item, 'ticker_leader', item.symbol)},
                ],
                sortValues: {
                    tickerPnl: item.pnl === null || item.pnl === undefined ? null : Number(item.pnl),
                    marketAverage: item.market_average_pnl === null || item.market_average_pnl === undefined ? null : Number(item.market_average_pnl),
                },
                sortTieBreak: item.symbol,
            };
        });
        rankingTable(document.querySelector('#portfolio-ticker-table'), [
            {label:'Тикер'}, {label:'Лучший порог входа'}, {label:'Лучший TakeProfit & StopLoss'},
            {label:`Итог тикера · ${rankingUnit}`,numeric:true,sortKey:'tickerPnl',defaultDirection:'desc'}, {label:`Средний итог этой пары на рынке · ${rankingUnit}`,numeric:true,sortKey:'marketAverage',defaultDirection:'desc'},
            {label:'Поддержка',numeric:true}, {label:tradeMetricLabel,numeric:true}, {label:'Избранное'},
        ], tickerRows, {initialKey:'tickerPnl',initialDirection:'desc'});
        portfolioRankingStatus.textContent = showingSavedResult
            ? `Показан сохранённый рейтинг за ${ranking.start_day || 'неизвестный период'} — ${ranking.end_day || 'неизвестный период'}; в расчёте ${ranking.universe_size} тикеров. SAVE MODE требует минимум ${ranking.min_trades}${ranking.min_trades === 1 ? ' сделку.' : ' сделки.'}`
            : 'Средний итог рассчитан по '
                + ranking.universe_size
                + ' тикерам с данными за период; пары без подходящей выборки учитываются как 0. SAVE MODE требует минимум '
                + ranking.min_trades + (ranking.min_trades === 1 ? ' сделку.' : ' сделки.');
        portfolioRankingDiagnosticsText(ranking);
        const tickerResults = (ranking.ticker_leaders || []).map(item => ({
            symbol: item.symbol,
            pnl: item.pnl,
            title: item.entry_label
                ? 'Лучшая комбинация: ' + item.entry_label + ' · ' + (item.exit_label || 'TP/SL не указаны')
                : (params.get('save_mode') === '0' ? 'Нет варианта выхода хотя бы с одной сделкой' : 'Нет варианта выхода минимум с двумя сделками'),
        }));
        renderTickerBestResults(tickerResults);
        const rankedCount = tickerResults.filter(item => item.pnl !== null && item.pnl !== undefined).length;
        buttonTitle = 'Пары отсортированы по их лучшему результату: надежных рейтингов ' + rankedCount + ' из ' + symbolSelect.options.length + '.';
    } catch (error) {
        if (selectorsOnly && requestVersion === portfolioRankingRequestVersion) {
            setReturnStatus(error.message || 'Не удалось отсортировать селекты по сохранённой доходности.');
        } else if (!cachedOnly && requestVersion === portfolioRankingRequestVersion) {
            portfolioRankingStatus.textContent = error.message || 'Не удалось рассчитать общий рейтинг';
            buttonTitle = error.message || 'Не удалось рассчитать общий рейтинг';
        }
    } finally {
        if (requestVersion === portfolioRankingRequestVersion) {
            portfolioRankingStatusPolling = false;
            if (selectorsOnly) {
                portfolioRankingTimer.reset();
            } else if (calculateReturnsOnly) {
                calculateReturnHistoryButton.disabled = false;
                calculateReturnHistoryButton.textContent = `Рассчитать доходность для «${tickerSetSelect?.selectedOptions[0]?.textContent || 'списка'}»`;
                portfolioRankingTimer.reset();
            } else if (cachedOnly) {
                portfolioRankingTimer.reset();
            } else {
                portfolioRankingTimer.finish();
                resetRefreshButton(buttonTitle);
            }
        }
    }
}
refreshSymbolsButton.addEventListener('click', () => {
    if (rankingMaintenancePending) return;
    setPortfolioRankingCollapsed(false);
    symbolSortMode.value = 'profit';
    loadPortfolioRanking();
});
calculateReturnHistoryButton?.addEventListener('click', () => {
    if (rankingMaintenancePending) return;
    setPortfolioRankingCollapsed(false);
    if ((filtersForm.elements.namedItem('martingale_mode')?.value || 'none') !== 'none') {
        setPortfolioRankingCollapsed(false);
        portfolioRankingContent.hidden = false;
        portfolioRankingStatus.textContent = 'Для расчёта истории и рейтинга из таблицы выберите обычный режим без мартингейла.';
        return;
    }
    loadPortfolioRanking({calculateReturnsOnly: true});
});
const collectorStatus = latestQuoteControl;
async function refreshCollectorStatus() {
    try {
        const tickerSet = filtersForm.querySelector('[name="ticker_set"]')?.value || '';
        const response = await fetch('collector-status.php?' + new URLSearchParams({ticker_set:tickerSet}), {headers:{Accept:'application/json'}, cache:'no-store'});
        const status = await response.json();
        collectorStatus.dataset.status = status.status || 'unknown';
        collectorStatus.textContent = status.label || 'Не удалось загрузить время котировки';
        collectorStatus.title = (status.title || status.label || 'Статус котировок недоступен') + ' Нажмите, чтобы загрузить минутные данные активных тикеров текущего списка от их последних сохранённых котировок.';
    } catch (_) {
        collectorStatus.dataset.status = 'unknown';
        collectorStatus.textContent = 'Не удалось загрузить время котировки';
        collectorStatus.title = 'Проверьте доступность базы данных';
    }
}
function renderQueueJobs(container, jobs, state) {
    container.replaceChildren();
    if (!jobs.length) {
        const empty = document.createElement('li');
        empty.className = 'calculation-queue-empty';
        empty.textContent = state === 'active' ? 'Сейчас нет выполняющихся расчётов.' : 'Ожидающих расчётов нет.';
        container.appendChild(empty);
        return;
    }
    for (const job of jobs) {
        const item = document.createElement('li');
        const title = document.createElement('strong');
        title.textContent = job.label || 'Расчёт рейтинга';
        const period = document.createElement('span');
        period.textContent = job.start_day && job.end_day
            ? `Период: ${job.start_day} — ${job.end_day}`
            : 'Период не указан';
        const progress = document.createElement('span');
        progress.textContent = state === 'waiting'
            ? (job.duplicate ? 'Повтор того же расчёта · ждёт завершения первого запроса' : 'Ожидает свободный обработчик')
            : `Выполняется · ${job.progress || 0}%`;
        item.append(title, period, progress);
        container.appendChild(item);
    }
}
async function refreshCalculationQueue() {
    try {
        const response = await fetch('ranking-queue.php', {headers:{Accept:'application/json'}, cache:'no-store'});
        const queue = await response.json();
        if (!response.ok) throw new Error(queue.error || 'Не удалось получить очередь');
        const active = Array.isArray(queue.active) ? queue.active : [];
        const waiting = Array.isArray(queue.waiting) ? queue.waiting : [];
        calculationQueueCount.textContent = `${active.length} выполняется · ${waiting.length} ожидает`;
        renderQueueJobs(calculationQueueActive, active, 'active');
        renderQueueJobs(calculationQueueWaiting, waiting, 'waiting');
        calculationQueueUpdated.textContent = `Обновлено: ${new Date(queue.updated_at * 1000).toLocaleTimeString('ru-RU')}`;
    } catch (_) {
        calculationQueueCount.textContent = 'Статус недоступен';
        calculationQueueActive.replaceChildren();
        calculationQueueWaiting.replaceChildren();
        calculationQueueUpdated.textContent = 'Не удалось прочитать состояние фоновых расчётов.';
    }
}
refreshCollectorStatus();
window.setInterval(refreshCollectorStatus, 30000);
refreshCalculationQueue();
window.setInterval(refreshCalculationQueue, 5000);
updateMartingaleInitialNotional();
if (!favoriteView) {
    void ensurePortfolioRankingCacheChecked();
    void checkReturnHistoryActionState();
    if (symbolSelect.value) loadCachedTickerBestResults();
    loadExitRanking();
}
</script></body></html>
