<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/ranking-parameters.php';
require_once __DIR__ . '/ranking-snapshot.php';
require_once __DIR__ . '/ticker-settings-lib.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function returnRunWorkerStatus(string $cacheDir, string $preferredStatusPath, string $returnRunKey): array
{
    if (is_file($preferredStatusPath)) {
        $preferred = json_decode((string) file_get_contents($preferredStatusPath), true);
        if (is_array($preferred) && ($preferred['status'] ?? '') === 'pending'
            && time() - (int) filemtime($preferredStatusPath) <= 30) {
            $preferred['_status_path'] = $preferredStatusPath;
            return $preferred;
        }
    }
    $paths = [$preferredStatusPath];
    foreach (glob($cacheDir . '/portfolio-*.status.json') ?: [] as $path) {
        if ($path !== $preferredStatusPath) $paths[] = $path;
    }
    usort($paths, static fn(string $left, string $right): int => ((int) @filemtime($right)) <=> ((int) @filemtime($left)));
    foreach ($paths as $path) {
        if (!is_file($path)) continue;
        $status = json_decode((string) file_get_contents($path), true);
        if (is_array($status) && ($status['return_run_key'] ?? '') === $returnRunKey) {
            $status['_status_path'] = $path;
            return $status;
        }
    }
    return [];
}

function portfolioRankingWorkerBusy(string $cacheDir): bool
{
    $probe = fopen($cacheDir . '/ranking-worker.lock', 'c');
    if ($probe === false) return false;
    if (flock($probe, LOCK_EX | LOCK_NB)) {
        flock($probe, LOCK_UN);
        fclose($probe);
        return false;
    }
    fclose($probe);
    return true;
}

function reusableReturnRanking(string $cacheDir, string $currentPath, string $returnRunKey, array $metadata): ?array
{
    $parameters = $metadata['parameters'];
    foreach (glob($cacheDir . '/portfolio-*.meta.json') ?: [] as $metadataPath) {
        $candidateMetadata = json_decode((string) @file_get_contents($metadataPath), true);
        if (!is_array($candidateMetadata)
            || ($candidateMetadata['cache_key'] ?? '') === basename($currentPath, '.json')
            || ($candidateMetadata['ticker_set']['id'] ?? '') !== ($metadata['ticker_set']['id'] ?? '')
            || ($candidateMetadata['start_day'] ?? '') !== ($metadata['start_day'] ?? '')
            || ($candidateMetadata['end_day'] ?? '') !== ($metadata['end_day'] ?? '')) continue;
        $candidateParameters = $candidateMetadata['parameters'] ?? [];
        foreach (['category', 'balance', 'fee', 'min_trades', 'ranking', 'martingale', 'simulation_costs', 'contract_rules_identity', 'cache_generation', 'snapshot_revision'] as $key) {
            if (($candidateParameters[$key] ?? null) !== ($parameters[$key] ?? null)) continue 2;
        }
        $path = $cacheDir . '/portfolio-' . basename((string) $candidateMetadata['cache_key']) . '.json';
        if (!is_file($path)) continue;
        $candidate = json_decode((string) @file_get_contents($path), true);
        if (!is_array($candidate)
            || ($candidate['status'] ?? '') !== 'ready'
            || ($candidate['ranking_data_source'] ?? '') !== 'strategy_return_points'
            || ($candidate['return_run_key'] ?? '') !== $returnRunKey
            || (int) ($candidate['min_trades'] ?? 0) !== (int) $parameters['min_trades']) continue;
        $hasTickerResults = false;
        foreach (($candidate['ticker_leaders'] ?? []) as $leader) {
            if (isset($leader['pnl']) && is_numeric($leader['pnl'])) {
                $hasTickerResults = true;
                break;
            }
        }
        if ($hasTickerResults) return $candidate;
    }
    return null;
}

function savePortfolioRankingMetadata(string $path, array $metadata): void
{
    $temporaryPath = tempnam(dirname($path), '.portfolio-metadata-');
    if ($temporaryPath === false) throw new RuntimeException('Не удалось подготовить параметры сохранённого рейтинга.');
    try {
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || !rename($temporaryPath, $path)) {
            throw new RuntimeException('Не удалось сохранить параметры рейтинга.');
        }
        @chmod($path, 0600);
    } finally {
        if (is_file($temporaryPath)) @unlink($temporaryPath);
    }
}


try {
    $martingaleRiskLimits = rankingMartingaleRiskLimits($_GET);
    $simulationCosts = rankingSimulationCosts($_GET);
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $entryConfigData = json_decode((string) file_get_contents($root . '/entry_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    $exitConfigData = json_decode((string) file_get_contents($root . '/exit_profiles.json'), true, 512, JSON_THROW_ON_ERROR);
    $ranking = rankingParameters($_GET, $entryConfigData, $exitConfigData)['worker'];
    $timezone = new DateTimeZone('Europe/Kyiv');
    $today = new DateTimeImmutable('today', $timezone);
    $startValue = (string) ($_GET['start_day'] ?? '2026-06-10');
    $endValue = (string) ($_GET['end_day'] ?? $today->format('Y-m-d'));
    $entryConfig = (string) ($_GET['entry_config'] ?? 'entry:10:1');
    $saveMode = (string) ($_GET['save_mode'] ?? '1') !== '0';
    $minTrades = $saveMode ? 3 : 1;
    $balance = (string) ($_GET['balance'] ?? '100');
    $fee = (string) ($_GET['fee'] ?? '0.06');
    $martingaleMode = (string) ($_GET['martingale_mode'] ?? 'none');
    if ($ranking['exit_profiles']['no_exit'] ?? false) {
        // A no-exit strategy has one open position per entry signal profile,
        // marked at the period end. It cannot use a multi-trade threshold.
        $minTrades = 1;
        $martingaleMode = 'none';
    }
    $martingaleTiming = (string) ($_GET['martingale_timing'] ?? 'immediate');
    $martingaleAttempts = (int) ($_GET['martingale_attempts'] ?? 3);
    $persistReturns = (string) ($_GET['persist_returns'] ?? '') === '1';
    $returnHistoryOnly = (string) ($_GET['return_history_only'] ?? '') === '1';
    $rankFromReturns = (string) ($_GET['rank_from_returns'] ?? '') === '1';
    $returnHistoryStatusRequest = (string) ($_GET['return_history_status'] ?? '') === '1';
    if ($returnHistoryOnly && !$persistReturns) {
        respond(['status' => 'error', 'message' => 'Для расчёта истории доходности требуется сохранить результат.'], 400);
    }
    if ($returnHistoryOnly && $rankFromReturns) {
        respond(['status' => 'error', 'message' => 'Выберите расчёт истории или построение рейтинга.'], 400);
    }
    $returnSymbol = strtoupper(trim((string) ($_GET['return_symbol'] ?? '')));
    if (!in_array($martingaleMode, ['none', 'simple', 'reverse', 'simple_profit', 'reverse_profit'], true)
        || !in_array($martingaleTiming, ['immediate', 'rules'], true)
        || $martingaleAttempts < 2 || $martingaleAttempts > 10) {
        respond(['status' => 'error', 'message' => 'Некорректные параметры мартингейла.'], 400);
    }
    if (rankingEntryConfig($entryConfig, $ranking, $entryConfigData['immediate_directions'] ?? []) === null) {
        respond(['status' => 'error', 'message' => 'Неизвестный порог входа.'], 400);
    }
    if (!preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $balance) || (float) $balance <= 0 || (float) $balance > 1000000000) {
        respond(['status' => 'error', 'message' => 'Некорректный стартовый баланс.'], 400);
    }
    if (!preg_match('/^\d{1,12}(?:\.\d{1,12})?$/', $fee) || (float) $fee < 0 || (float) $fee > 5) {
        respond(['status' => 'error', 'message' => 'Некорректная комиссия.'], 400);
    }
    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue, $timezone);
    $startErrors = DateTimeImmutable::getLastErrors();
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', $endValue, $timezone);
    $endErrors = DateTimeImmutable::getLastErrors();
    if ($startDay === false || $endDay === false
        || ($startErrors !== false && ($startErrors['warning_count'] || $startErrors['error_count']))
        || ($endErrors !== false && ($endErrors['warning_count'] || $endErrors['error_count']))
        || $startDay->format('Y-m-d') !== $startValue || $endDay->format('Y-m-d') !== $endValue || $endDay < $startDay) {
        respond(['status' => 'error', 'message' => 'Проверьте выбранный диапазон дат.'], 400);
    }
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') respond(['status' => 'error', 'message' => 'Не задан пароль подключения к MySQL.'], 503);
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $home = getenv('HOME') ?: '/home/alex';
    $cacheDir = $home . '/.ai/home/.local/bitget-backtest-cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
        respond(['status' => 'error', 'message' => 'Не удалось подготовить кэш рейтинга.'], 500);
    }
    $generationLock = fopen($cacheDir . '/strategy-ranking-generation.lock', 'c');
    if ($generationLock === false || !flock($generationLock, LOCK_SH)) {
        if (is_resource($generationLock)) fclose($generationLock);
        respond(['status' => 'error', 'message' => 'Не удалось проверить поколение кэша рейтинга.'], 503);
    }
    $generationPath = $cacheDir . '/strategy-ranking-generation.txt';
    $cacheGeneration = 'initial';
    if (is_file($generationPath)) {
        $generationValue = file_get_contents($generationPath);
        if ($generationValue === false || trim($generationValue) === '') {
            respond(['status' => 'error', 'message' => 'Не удалось прочитать поколение кэша рейтинга.'], 503);
        }
        $cacheGeneration = trim($generationValue);
    }
    $requestedGeneration = (string) ($_GET['ranking_generation'] ?? '');
    if ($requestedGeneration === '' || !hash_equals($cacheGeneration, $requestedGeneration)) {
        respond(['status' => 'cancelled', 'message' => 'Расчёт остановлен: поколение рейтинга уже сброшено.']);
    }
    // Ignore legacy URL snapshot_ids: all ranking types share one frozen
    // snapshot per date range and generation, even across browser tabs.
    $selectedSet = selectedTickerSet($config, trim((string) ($_GET['ticker_set'] ?? '')));
    $maxIds = rankingSnapshotIds($pdo, $cacheDir, $config['category'], $startValue, $endValue, $startUtc, $endUtc, $cacheGeneration);
    $selectedSymbols = array_fill_keys($selectedSet['symbols'], true);
    if ($persistReturns && $martingaleMode !== 'none') {
        respond(['status' => 'error', 'message' => 'Для истории доходности сначала выберите обычный режим без мартингейла.'], 400);
    }
    if ($persistReturns && $returnSymbol !== '') {
        if (!isset($selectedSymbols[$returnSymbol])) respond(['status' => 'error', 'message' => 'Текущий тикер не входит в выбранный список.'], 400);
        $selectedSymbols = [$returnSymbol => true];
    }
    $maxIds = array_intersect_key($maxIds, $selectedSymbols);
    if (($persistReturns || $rankFromReturns) && $maxIds === []) {
        respond(['status' => 'error', 'message' => 'В выбранном списке нет активных тикеров для расчёта доходности.'], 400);
    }
    $snapshotJson = json_encode($maxIds, JSON_THROW_ON_ERROR);
    $entryProfileCount = count($ranking['immediate_directions']);
    foreach ($ranking['entry_profiles'] as $entryProfile) {
        $entryProfileCount += count($entryProfile['thresholds_tenths_percent']) * count($entryProfile['windows']);
    }
    $fixedTpCount = count(rankingValues($ranking['exit_profiles']['fixed_tp']));
    $fixedSlCount = count(rankingValues($ranking['exit_profiles']['fixed_sl']));
    $profitCount = count(rankingValues($ranking['exit_profiles']['profit']));
    $lossCount = count(rankingValues($ranking['exit_profiles']['loss']));
    $baseExitCount = ($ranking['exit_profiles']['no_exit'] ?? false)
        ? 1 : $fixedTpCount * $fixedSlCount + $profitCount + $lossCount;
    $exitCount = $baseExitCount;
    if ($martingaleMode !== 'none') {
        $exitCount += $fixedTpCount * $fixedSlCount + $lossCount;
    }
    $requestDiagnostics = [
        'start_day' => $startValue,
        'end_day' => $endValue,
        'universe_size' => count($maxIds),
        'min_trades' => $minTrades,
        'total_entry_profiles' => $entryProfileCount,
        'total_candidates' => $entryProfileCount * $exitCount,
        'total_ticker_evaluations' => $entryProfileCount * $exitCount * count($maxIds),
    ];
    $keyParts = [
        'portfolio-ranking-v17-futures-contract-quantity', $cacheGeneration, $config['category'], $startValue, $endValue, $balance, $fee,
        $minTrades, $ranking, $martingaleMode, $martingaleTiming, $martingaleAttempts,
    ];
    array_push($keyParts, ...rankingMartingaleCacheIdentity($martingaleMode, $martingaleRiskLimits));
    array_push($keyParts, ...rankingSimulationCostsIdentity($simulationCosts));
    $contractRulesIdentity = rankingContractRulesIdentity($config['category'], array_keys($maxIds));
    array_push($keyParts, ...$contractRulesIdentity);
    $snapshotRevision = rankingSnapshotRevision($cacheDir, $config['category'], $startValue, $endValue, $cacheGeneration);
    if ($snapshotRevision !== null) $keyParts[] = ['refreshed_snapshot' => $snapshotRevision];
    $key = hash('sha256', json_encode($keyParts, JSON_THROW_ON_ERROR));
    $returnScopeId = $returnSymbol !== '' ? 'ticker:' . $returnSymbol : $selectedSet['id'];
    $returnScopeName = $returnSymbol !== '' ? $returnSymbol : $selectedSet['name'];
    $returnRunKey = ($persistReturns || $rankFromReturns || $returnHistoryStatusRequest) ? hash('sha256', json_encode([
        'strategy-return-history-v6-append-only-minute-points', $config['category'], $returnScopeId, $returnSymbol ?: null,
        array_keys($maxIds), $startValue, (float) $balance, (float) $fee, $ranking,
        $martingaleMode, $martingaleTiming, $martingaleAttempts,
        $simulationCosts['include_funding'] ? '1' : '0', (float) $simulationCosts['slippage_bps'], $cacheGeneration,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) : '';
    $resultPath = $cacheDir . '/portfolio-' . $key . '.json';
    $statusPath = $cacheDir . '/portfolio-' . $key . '.status.json';
    $metadataPath = $cacheDir . '/portfolio-' . $key . '.meta.json';
    $metadataRequestKeys = [
        'ticker_set', 'start_day', 'end_day', 'save_mode', 'balance', 'fee',
        'martingale_mode', 'martingale_timing', 'martingale_attempts', 'martingale_max_chain_loss',
        'martingale_max_day_loss', 'entry_signal_mode', 'entry_direction_mode', 'exit_confirmation_mode',
        'exit_confirmation_snapshots', 'threshold_mode', 'threshold_ranges', 'window_mode', 'window_ranges',
        'fixed_grid_mode', 'fixed_tp_ranges', 'fixed_sl_ranges', 'profit_ranges', 'loss_ranges',
        'slippage_bps', 'include_funding',
    ];
    $metadata = [
        'version' => 1,
        'cache_key' => $key,
        'ticker_set' => [
            'id' => $selectedSet['id'],
            'name' => $selectedSet['name'],
            'symbols' => $selectedSet['symbols'],
        ],
        'return_scope_symbol' => $returnSymbol !== '' ? $returnSymbol : null,
        'return_scope_symbols' => array_keys($maxIds),
        'start_day' => $startValue,
        'end_day' => $endValue,
        'request_params' => array_intersect_key($_GET, array_fill_keys($metadataRequestKeys, true)),
        'parameters' => [
            'category' => $config['category'],
            'balance' => $balance,
            'fee' => $fee,
            'min_trades' => $minTrades,
            'ranking' => $ranking,
            'martingale' => [
                'mode' => $martingaleMode,
                'timing' => $martingaleTiming,
                'attempts' => $martingaleAttempts,
                'risk_limits_usdt' => $martingaleRiskLimits,
            ],
            'simulation_costs' => $simulationCosts,
            'contract_rules_identity' => $contractRulesIdentity,
            'cache_generation' => $cacheGeneration,
            'snapshot_revision' => $snapshotRevision,
        ],
    ];
    $sourceReturnRuns = [];
    $persistMetadata = static function () use ($metadataPath, $metadata): void {
        try {
            savePortfolioRankingMetadata($metadataPath, $metadata);
        } catch (Throwable $metadataError) {
            error_log('Unable to save portfolio ranking metadata: ' . $metadataError->getMessage());
        }
    };
    if ($returnHistoryStatusRequest) {
        if ($martingaleMode !== 'none') respond(['status' => 'unsupported', 'message' => 'История доходности списка доступна без мартингейла.']);
        $workerStatus = returnRunWorkerStatus($cacheDir, $statusPath, $returnRunKey);
        if (($workerStatus['status'] ?? '') === 'ready') respond(['status' => 'ready', 'run_key' => $returnRunKey, 'point_count' => (int) ($workerStatus['return_point_count'] ?? 0)]);
        if (($workerStatus['status'] ?? '') === 'pending') respond(array_merge($workerStatus, ['status' => 'running']));
        if (($workerStatus['status'] ?? '') === '') respond(['status' => 'missing']);
        respond(['status' => 'error', 'message' => 'Сохранённый расчёт доходности завершился ошибкой.']);
    }
    if ($rankFromReturns) {
        if ($martingaleMode !== 'none') {
            respond(['status' => 'error', 'message' => 'Рейтинг из таблицы доходности доступен только в обычном режиме без мартингейла.'], 400);
        }
        $returnRunStatus = returnRunWorkerStatus($cacheDir, $statusPath, $returnRunKey);
        if (($returnRunStatus['status'] ?? '') === 'pending') {
            $workerStatus = $returnRunStatus;
            $activeStatusPath = (string) ($workerStatus['_status_path'] ?? $statusPath);
            unset($workerStatus['_status_path']);
            $progressIsCurrent = is_file($activeStatusPath) && time() - (int) filemtime($activeStatusPath) <= 30;
            if (!$progressIsCurrent && !portfolioRankingWorkerBusy($cacheDir)) {
                respond(['status' => 'missing_return_history', 'message' => 'Предыдущий расчёт остановился. Запустите его снова кнопкой расчёта доходности.']);
            }
            respond([
                'status' => 'pending',
                'progress' => $progressIsCurrent ? max(0, min(99, (int) ($workerStatus['progress'] ?? 0))) : null,
                'phase' => $progressIsCurrent ? (string) ($workerStatus['phase'] ?? '') : '',
                'progress_stale' => !$progressIsCurrent,
                'elapsed_seconds' => (int) ($workerStatus['elapsed_seconds'] ?? 0),
                'history_progress' => $workerStatus['history_progress'] ?? null,
                'history_completed' => $workerStatus['history_completed'] ?? null,
                'history_total' => $workerStatus['history_total'] ?? null,
                'history_symbol' => (string) ($workerStatus['history_symbol'] ?? ''),
                'history_entry_profile' => (string) ($workerStatus['history_entry_profile'] ?? ''),
                'emitted_return_points' => $workerStatus['emitted_return_points'] ?? null,
                'message' => 'Сохранение точек доходности ещё выполняется.',
            ]);
        }
        if (($returnRunStatus['status'] ?? '') !== 'ready') {
            respond(['status' => 'missing_return_history', 'message' => 'Сначала рассчитайте доходность для выбранного списка и периода.']);
        }
        if (is_file($resultPath)) {
            $cachedReturnRanking = json_decode((string) file_get_contents($resultPath), true);
            if (is_array($cachedReturnRanking)
                && ($cachedReturnRanking['status'] ?? '') === 'ready'
                && ($cachedReturnRanking['ranking_data_source'] ?? '') === 'strategy_return_points'
                && ($cachedReturnRanking['return_run_key'] ?? '') === $returnRunKey
                && ((int) ($returnRunStatus['return_point_count'] ?? 0) === 0
                    || array_filter($cachedReturnRanking['ticker_leaders'] ?? [], static fn(array $leader): bool => isset($leader['pnl']) && is_numeric($leader['pnl'])) !== [])) {
                respond(array_merge($cachedReturnRanking, $requestDiagnostics, [
                    'cache_key' => $key,
                    'result_cache_hit' => true,
                    'result_cached_at' => gmdate(DATE_ATOM, (int) filemtime($resultPath)),
                ]));
            }
        }
        $sharedReturnRanking = reusableReturnRanking($cacheDir, $resultPath, $returnRunKey, $metadata);
        if ($sharedReturnRanking !== null) {
            // Ticker leaders and the global ranking depend only on the saved
            // return run. A prior request may have cached an empty result for
            // another selected entry threshold even though this run has data.
            $result = array_merge($sharedReturnRanking, $requestDiagnostics, [
                'status' => 'ready',
                'return_run_key' => $returnRunKey,
                'ranking_data_source' => 'strategy_return_points',
                'cache_key' => $key,
            ]);
            $persistMetadata();
            file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            respond(array_merge($result, ['cache_key' => $key, 'result_cache_hit' => true, 'reused_return_run_ranking' => true]));
        }
        $summaryStatusPath = $cacheDir . '/portfolio-' . $key . '.returns.status.json';
        $summaryStatus = is_file($summaryStatusPath) ? json_decode((string) file_get_contents($summaryStatusPath), true) : null;
        if (is_array($summaryStatus) && ($summaryStatus['status'] ?? '') === 'pending') {
            $pid = (int) ($summaryStatus['pid'] ?? 0);
            $alive = $pid > 1 && function_exists('posix_kill') && @posix_kill($pid, 0);
            if ($alive || time() - (int) ($summaryStatus['updated_at'] ?? 0) < 30) {
                respond(array_merge($requestDiagnostics, $summaryStatus, ['result_cache_hit'=>false]));
            }
        }
        if (is_array($summaryStatus) && ($summaryStatus['status'] ?? '') === 'error'
            && (string) ($_GET['retry_cancelled'] ?? '') !== '1') respond($summaryStatus, 503);
        $launchLock = fopen($cacheDir . '/portfolio-' . $key . '.returns.launch.lock', 'c');
        if ($launchLock === false) throw new RuntimeException('Unable to lock return ranking launch.');
        try {
            if (!flock($launchLock, LOCK_EX | LOCK_NB)) {
                respond(['status'=>'pending','progress'=>null,'phase'=>'aggregating_returns','message'=>'Подготавливаю сводку доходности…']);
            }
            // Recheck after acquiring the launch lock: another request may have started the job.
            $summaryStatus = is_file($summaryStatusPath) ? json_decode((string) file_get_contents($summaryStatusPath), true) : null;
            if (is_array($summaryStatus) && ($summaryStatus['status'] ?? '') === 'pending'
                && (((int) ($summaryStatus['pid'] ?? 0) > 1 && function_exists('posix_kill') && @posix_kill((int)$summaryStatus['pid'],0))
                    || time() - (int) ($summaryStatus['updated_at'] ?? 0) < 30)) {
                respond(array_merge($requestDiagnostics,$summaryStatus,['result_cache_hit'=>false]));
            }
            $confirmationMode = (string) ($ranking['exit_confirmation_mode'] ?? 'immediate');
            $job = [
                'run_key'=>$returnRunKey,'start_day'=>$startValue,'end_day'=>$endValue,'symbols'=>array_keys($maxIds),
                'exit_confirmation_mode'=>$confirmationMode,
                'exit_confirmation_snapshots'=>$confirmationMode==='immediate'?0:(int)($ranking['exit_confirmation_snapshots']??0),
                'min_trades'=>$minTrades,'diagnostics'=>$requestDiagnostics,
            ];
            $jobPath = $cacheDir . '/portfolio-' . $key . '.returns.job.json';
            writeReturnSummaryJson($jobPath,$job);
            $summaryStatus = ['status'=>'pending','progress'=>0,'phase'=>'aggregating_returns','completed_symbols'=>0,'total_symbols'=>count($maxIds),'message'=>'Подготавливаю общую сводку доходности…','updated_at'=>time()];
            writeReturnSummaryJson($summaryStatusPath,$summaryStatus);
            $persistMetadata();
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/return-ranking-worker.php') . ' ' . escapeshellarg($jobPath)
                . ' >> ' . escapeshellarg($cacheDir . '/portfolio-' . $key . '.returns.log') . ' 2>&1 < /dev/null &';
            exec($command,$output,$launchCode);
            if ($launchCode !== 0) throw new RuntimeException('Unable to start return ranking worker.');
        } finally {
            flock($launchLock,LOCK_UN); fclose($launchLock);
        }
        respond(array_merge($requestDiagnostics,$summaryStatus,['result_cache_hit'=>false]));
    }
    if (!$persistReturns && !$rankFromReturns && is_file($resultPath)) {
        $result = json_decode((string) file_get_contents($resultPath), true);
        if (is_array($result) && ($result['status'] ?? '') === 'ready') {
            $persistMetadata();
            $finishedStatus = is_file($statusPath)
                ? json_decode((string) file_get_contents($statusPath), true)
                : null;
            $diagnostics = is_array($finishedStatus) ? $finishedStatus : [];
            respond(array_merge($result, $requestDiagnostics, $diagnostics, [
                'status' => 'ready', 'progress' => 100,
                'cache_key' => $key,
                'result_cache_hit' => true,
                'result_cached_at' => gmdate(DATE_ATOM, (int) filemtime($resultPath)),
            ]));
        }
    }

    if ((string) ($_GET['cached_only'] ?? '') === '1') {
        respond(['status' => 'missing']);
    }

    $persistMetadata();

    $workerHistoryStatus = $persistReturns ? returnRunWorkerStatus($cacheDir, $statusPath, $returnRunKey) : [];
    $returnHistoryReady = ($workerHistoryStatus['status'] ?? '') === 'ready';
    if ($returnHistoryOnly && $returnHistoryReady) {
        respond([
            'status' => 'ready', 'progress' => 100, 'return_run_key' => $returnRunKey,
            'return_point_count' => (int) ($workerHistoryStatus['return_point_count'] ?? 0),
            'result_cache_hit' => true,
        ]);
    }
    $status = is_file($statusPath) ? json_decode((string) file_get_contents($statusPath), true) : null;
    $statusAge = is_file($statusPath) ? time() - (int) filemtime($statusPath) : PHP_INT_MAX;
    $workerBusy = false;
    $probe = fopen($cacheDir . '/ranking-worker.lock', 'c');
    if ($probe !== false) {
        if (flock($probe, LOCK_EX | LOCK_NB)) flock($probe, LOCK_UN);
        else $workerBusy = true;
        fclose($probe);
    }
    $pending = is_array($status) && ($status['status'] ?? '') === 'pending';
    $retryCancelled = (string) ($_GET['retry_cancelled'] ?? '') === '1';
    if (is_array($status) && ($status['status'] ?? '') === 'error' && $statusAge < 300
        && !($persistReturns && $retryCancelled && !$returnHistoryReady)) {
        respond($status, 503);
    }
    $stale = !is_array($status) || ($retryCancelled && ($status['status'] ?? '') === 'cancelled')
        || ($pending ? !$workerBusy : $statusAge > 1800 || (($status['status'] ?? '') === 'error' && $statusAge > 300))
        || ($persistReturns && !$returnHistoryReady && !$pending);
    if ($stale) {
        $lock = fopen($cacheDir . '/portfolio-' . $key . '.lock', 'c');
        if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
            file_put_contents($statusPath, json_encode(['status' => 'pending', 'progress' => 0]));
            $python = $root . '/.venv/bin/python';
            if (!is_executable($python)) $python = '/usr/bin/python3';
            $command = 'nohup ' . escapeshellarg($python) . ' ' . escapeshellarg($root . '/launch-ranking-worker.py')
                . ' ' . escapeshellarg($python) . ' ' . escapeshellarg($root . '/portfolio_ranking.py')
                . ' --cache-key ' . escapeshellarg($key)
                . ' --cache-generation ' . escapeshellarg($cacheGeneration)
                . ' --start-day ' . escapeshellarg($startValue)
                . ' --end-day ' . escapeshellarg($endValue)
                . ' --balance ' . escapeshellarg($balance)
                . ' --fee-percent ' . escapeshellarg($fee)
                . ' --min-trades ' . escapeshellarg((string) $minTrades)
                . ' --entry-config ' . escapeshellarg($entryConfig)
                . ' --max-ids ' . escapeshellarg($snapshotJson)
                . ' --cache-dir ' . escapeshellarg($cacheDir)
                . ' --ranking-params ' . escapeshellarg(json_encode($ranking, JSON_THROW_ON_ERROR))
                . ' --source-return-runs ' . escapeshellarg(json_encode((object) $sourceReturnRuns, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
                . ' --martingale-mode ' . escapeshellarg($martingaleMode)
                . ' --martingale-timing ' . escapeshellarg($martingaleTiming)
                . ' --martingale-attempts ' . escapeshellarg((string) $martingaleAttempts)
                . ' --martingale-max-chain-loss ' . escapeshellarg($martingaleRiskLimits['max_chain_loss'])
                . ' --martingale-max-day-loss ' . escapeshellarg($martingaleRiskLimits['max_day_loss'])
                . ' --include-funding ' . escapeshellarg($simulationCosts['include_funding'] ? '1' : '0')
                . ' --slippage-bps ' . escapeshellarg($simulationCosts['slippage_bps'])
                . ($persistReturns
                    ? ' --persist-returns --return-run-key ' . escapeshellarg($returnRunKey)
                        . ' --return-scope-id ' . escapeshellarg($returnScopeId)
                        . ' --return-scope-name ' . escapeshellarg($returnScopeName)
                        . ' --return-scope-symbol ' . escapeshellarg($returnSymbol)
                        . ($returnHistoryOnly ? ' --return-history-only' : '')
                    : '')
                . ' >> ' . escapeshellarg($cacheDir . '/portfolio-' . $key . '.log') . ' 2>&1 < /dev/null &';
            exec($command);
            flock($lock, LOCK_UN);
            fclose($lock);
        } elseif ($lock !== false) fclose($lock);
        $status = ['status' => 'pending', 'progress' => 0];
    }
    respond(array_merge($requestDiagnostics, is_array($status) ? $status : ['status' => 'pending', 'progress' => 0], [
        'result_cache_hit' => false,
        'return_run_key' => $persistReturns ? $returnRunKey : null,
    ]));
} catch (InvalidArgumentException $error) {
    respond(['status' => 'error', 'message' => $error->getMessage()], 400);
} catch (Throwable $error) {
    respond(['status' => 'error', 'message' => 'Не удалось рассчитать общий рейтинг комбинаций.'], 503);
}
