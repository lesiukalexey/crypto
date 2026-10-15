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
    echo json_encode(['type' => 'progress', 'message' => 'Проверяю кэш рейтинга пар…'], JSON_UNESCAPED_UNICODE) . "\n";
    flush();
}

function hedge_ranking_write_cache(string $path, array $payload): void
{
    $directory = dirname($path);
    $temporaryPath = tempnam($directory, '.hedge-ranking-');
    if ($temporaryPath === false
        || file_put_contents($temporaryPath, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
        || !rename($temporaryPath, $path)) {
        if (is_string($temporaryPath)) @unlink($temporaryPath);
        throw new RuntimeException('Не удалось сохранить рейтинг пар в кэш.');
    }
}

function hedge_ranking_progress(string $message): void
{
    echo json_encode(['type' => 'progress', 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @ob_flush();
    flush();
}

function hedge_ranking_write_all($stream, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($stream, substr($data, $offset, 8 * 1024 * 1024));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Не удалось передать снимки котировок Numba-воркеру.');
        }
        $offset += $written;
    }
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

$quoteFilePath = null;
$metadataFilePath = null;
$workerLogPath = null;
$workerProcess = null;
$workerPipes = [];
$pairSidecarTempPath = null;
$pairSidecarHandle = null;
try {
    // Large ticker sets can contain several million quote snapshots.
    set_time_limit(300);
    $root = dirname(__DIR__);
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $tickerSetId = trim((string) ($_GET['ticker_set'] ?? ''));
    $symbols = selectedTickerSet($config, $tickerSetId)['symbols'];
    if (isset($_GET['symbols']) && trim((string) $_GET['symbols']) !== '') {
        $requestedSymbols = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $_GET['symbols'])))));
        $allowedSymbols = array_fill_keys($symbols, true);
        foreach ($requestedSymbols as $requestedSymbol) {
            if (!isset($allowedSymbols[$requestedSymbol])) hedge_ranking_respond(['status'=>'error','message'=>'Список содержит тикер вне выбранного набора.'], 400);
        }
        sort($requestedSymbols, SORT_STRING);
        $symbols = $requestedSymbols;
    }
    // Keep cache keys stable whether the symbols came from the set or from
    // the selector page's explicit list.
    sort($symbols, SORT_STRING);
    if (count($symbols) < 2) {
        hedge_ranking_respond(['status' => 'error', 'message' => 'Для рейтинга нужны минимум два тикера.'], 400);
    }

    $timezone = new DateTimeZone('Europe/Kyiv');
    $intervalMode = (string) ($_GET['interval'] ?? 'none');
    if (!in_array($intervalMode, ['none', 'week', 'two_weeks', 'month'], true)) $intervalMode = 'none';
    $excludedIntervals = filter_var($_GET['exclude_last_intervals'] ?? '1', FILTER_VALIDATE_INT);
    if ($excludedIntervals === false || $excludedIntervals < 0 || $excludedIntervals > 2) $excludedIntervals = 1;
    if ($intervalMode === 'none') $excludedIntervals = 0;
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

    $balance = (string) ($_GET['balance'] ?? '100');
    $feePercent = (string) ($_GET['fee'] ?? '0.06');
    $includeAllPairs = (string) ($_GET['all_pairs'] ?? '') === '1';
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
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]
    );
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $home = getenv('HOME') ?: '/home/alex';
    $cacheDir = $home . '/.ai/home/.local/bitget-hedge-ranking-cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
        hedge_ranking_respond(['status' => 'error', 'message' => 'Не удалось подготовить кэш рейтинга пар.'], 500);
    }
    hedge_ranking_stream_start();
    $intervalWindows = [];
    if ($intervalMode !== 'none') {
        // Use the same calendar windows that the selected-pair table uses.
        // Each pair then contributes only windows for which it has aligned quotes.
        $firstDay = $startDay->setTime(0, 0);
        $cursor = $endDay->setTime(0, 0);
        $step = match ($intervalMode) { 'week'=>'P7D', 'two_weeks'=>'P14D', 'month'=>'P1M' };
        while ($cursor > $firstDay) {
            $previous = $cursor->sub(new DateInterval($step));
            if ($previous < $firstDay) break;
            $intervalWindows[] = ['start_ms'=>$previous->getTimestamp()*1000,'end_ms'=>$cursor->getTimestamp()*1000];
            $cursor = $previous;
        }
        if ($intervalWindows === []) {
            hedge_ranking_respond(['status'=>'error','message'=>'Для выбранного списка недостаточно общего периода котировок для этой разбивки.'], 422);
        }
    }
    $rankingWindows = $intervalWindows;
    if ($excludedIntervals > 0) {
        if (count($intervalWindows) <= $excludedIntervals) {
            hedge_ranking_respond(['status'=>'error','message'=>'После исключения последних интервалов не остаётся периода для рейтинга. Уменьшите число исключаемых интервалов.'], 422);
        }
        $rankingEndMs = (int) $intervalWindows[$excludedIntervals - 1]['start_ms'];
        $rankingWindows = array_slice($intervalWindows, $excludedIntervals);
        $endUtc = (new DateTimeImmutable('@' . intdiv($rankingEndMs, 1000)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
    $cacheKey = hash('sha256', json_encode([
        'hedge-ranking-numba-v14-ignore-recent-intervals', $config['category'], $tickerSetId, $symbols, $startValue, $endValue, $intervalMode, $excludedIntervals, $rankingWindows,
        (float) $balance, (float) $feePercent,
    ], JSON_THROW_ON_ERROR));
    $cachePath = $cacheDir . '/hedge-' . $cacheKey . '.json';
    $pairSidecarPath = $cachePath . '.pairs.json';
    $cacheLock = fopen($cachePath . '.lock', 'c');
    if ($cacheLock === false || !flock($cacheLock, LOCK_EX)) {
        if (is_resource($cacheLock)) fclose($cacheLock);
        hedge_ranking_respond(['status' => 'error', 'message' => 'Не удалось проверить кэш рейтинга пар.'], 503);
    }
    try {
        if (is_file($cachePath)) {
            $cached = json_decode((string) file_get_contents($cachePath), true);
            if (is_array($cached) && ($cached['status'] ?? '') === 'ready') {
                if ($includeAllPairs) {
                    $cachedPairs = is_file($pairSidecarPath) ? json_decode((string) file_get_contents($pairSidecarPath), true) : null;
                    if (is_array($cachedPairs)) $cached['pairs'] = $cachedPairs;
                    else $cached = null;
                }
            }
            if (is_array($cached) && ($cached['status'] ?? '') === 'ready') {
                $cached['cache_hit'] = true;
                $cached['cached_at'] = gmdate(DATE_ATOM, (int) filemtime($cachePath));
                hedge_ranking_progress('Рейтинг найден в кэше.');
                hedge_ranking_result($cached);
            }
        }
    } finally {
        flock($cacheLock, LOCK_UN);
        fclose($cacheLock);
    }
    hedge_ranking_progress('В кэше рейтинга нет. В базе найдено '
        . 'снимки котировок; загружаю их по тикерам через индекс.');
    $quoteFilePath = tempnam($cacheDir, '.hedge-quotes-');
    if ($quoteFilePath === false) {
        throw new RuntimeException('Не удалось подготовить данные для Numba-расчёта.');
    }
    $quoteFile = fopen($quoteFilePath, 'wb');
    if ($quoteFile === false) {
        throw new RuntimeException('Не удалось открыть файл снимков для Numba-расчёта.');
    }
    $statement = $pdo->prepare(
        'SELECT received_at_utc, bid_price, ask_price FROM quote_snapshots '
        . 'WHERE category = ? AND symbol = ? '
        . 'AND received_at_utc >= ? AND received_at_utc < ? '
        . 'ORDER BY received_at_utc, id'
    );
    $quoteCounts = array_fill_keys($symbols, 0);
    $symbolCount = count($symbols);
    $packedChunk = [];
    $loadedQuotes = 0;
    try {
        foreach ($symbols as $symbolIndex => $symbol) {
            $statement->execute([$config['category'], $symbol, $startUtc, $endUtc]);
            while (($row = $statement->fetch()) !== false) {
                $quoteCounts[$symbol]++;
                $date = new DateTimeImmutable((string) $row[0], new DateTimeZone('UTC'));
                $packedChunk[] = pack(
                    'Pee',
                    $date->getTimestamp() * 1000 + (int) $date->format('v'),
                    (float) $row[1],
                    (float) $row[2],
                );
                if (count($packedChunk) >= 2048) {
                    hedge_ranking_write_all($quoteFile, implode('', $packedChunk));
                    $packedChunk = [];
                }
                $loadedQuotes++;
                if ($loadedQuotes % 10000 === 0) {
                    hedge_ranking_progress('Загружены снимки котировок: ' . number_format($loadedQuotes, 0, ',', ' ')
                        . ' (' . (int) floor(($symbolIndex + 1) * 50 / $symbolCount) . '%).');
                    set_time_limit(300);
                }
            }
            set_time_limit(300);
            $statement->closeCursor();
            if ($packedChunk !== []) {
                hedge_ranking_write_all($quoteFile, implode('', $packedChunk));
                $packedChunk = [];
            }
            hedge_ranking_progress('Загружен тикер ' . ($symbolIndex + 1) . ' / ' . $symbolCount
                . '; снимков: ' . number_format($loadedQuotes, 0, ',', ' ') . '.');
        }
    } finally {
        $statement->closeCursor();
        fclose($quoteFile);
    }
    $pdo = null;

    $metadataFilePath = tempnam($cacheDir, '.hedge-meta-');
    if ($metadataFilePath === false) {
        throw new RuntimeException('Не удалось подготовить список котировок для Numba-расчёта.');
    }
    $metadata = [
        'symbols' => $symbols,
        'counts' => array_values(array_map(static fn(string $symbol): int => $quoteCounts[$symbol], $symbols)),
        'balance' => (float) $balance,
        'fee_rate' => (float) $feePercent / 100.0,
        'windows' => $rankingWindows,
    ];
    if (file_put_contents($metadataFilePath, json_encode($metadata, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Не удалось записать параметры Numba-расчёта.');
    }
    $workerLogPath = tempnam($cacheDir, '.hedge-worker-');
    if ($workerLogPath === false) {
        throw new RuntimeException('Не удалось подготовить журнал Numba-расчёта.');
    }
    $pairSidecarTempPath = tempnam($cacheDir, '.hedge-pair-metrics-');
    if ($pairSidecarTempPath === false) throw new RuntimeException('Не удалось подготовить кэш метрик сочетаний.');
    $pairSidecarHandle = fopen($pairSidecarTempPath, 'wb');
    if ($pairSidecarHandle === false) throw new RuntimeException('Не удалось открыть кэш метрик сочетаний.');
    hedge_ranking_write_all($pairSidecarHandle, '[');
    $sidecarPairCount = 0;

    $pairs = [];
    $bestPair = null;
    $bestLongPairs = [];
    $bestShortPairs = [];
    $pairMatrix = array_fill(0, $symbolCount, array_fill(0, $symbolCount, null));
    $ratioMatrix = $pairMatrix;
    $maximumRatioMatrix = $pairMatrix;
    $orderedPairCount = $symbolCount * ($symbolCount - 1);
    $pairIndex = 0;
    $progressInterval = max(1, (int) ceil($orderedPairCount / 100));
    $python = is_executable($root . '/.venv/bin/python') ? $root . '/.venv/bin/python' : '/usr/bin/python3';
    $launcher = $root . '/launch-ranking-worker.py';
    $worker = $root . '/hedge_pair_ranking.py';
    $numbaCacheDir = $cacheDir . '/numba-cache';
    $command = [$python, $launcher, $python, $worker, $quoteFilePath, $metadataFilePath, $numbaCacheDir];
    $workerProcess = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $workerLogPath, 'a']],
        $workerPipes,
        $root,
    );
    if (!is_resource($workerProcess)) {
        throw new RuntimeException('Не удалось запустить Numba-расчёт пар.');
    }
    fclose($workerPipes[0]);
    unset($workerPipes[0]);
    $workerDone = false;
    while (($line = fgets($workerPipes[1])) !== false) {
        $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (($message['type'] ?? '') === 'progress') {
            hedge_ranking_progress((string) ($message['message'] ?? 'Numba рассчитывает доходность пар…'));
            continue;
        }
        if (($message['type'] ?? '') === 'done') {
            $workerDone = (int) ($message['ordered_pair_count'] ?? -1) === $orderedPairCount;
            continue;
        }
        if (($message['type'] ?? '') !== 'chunk') {
            throw new RuntimeException('Numba-расчёт вернул неизвестный тип сообщения.');
        }
        $longIndex = (int) ($message['long_index'] ?? -1);
        $shortStart = (int) ($message['short_start'] ?? -1);
        $values = $message['values'] ?? null;
        $maximums = $message['maximums'] ?? null;
        $drawdowns = $message['drawdowns'] ?? null;
        $worstIntervalValues = $message['worst_interval_values'] ?? null;
        $worstIntervalMaximums = $message['worst_interval_maximums'] ?? null;
        $worstIntervalDrawdowns = $message['worst_interval_drawdowns'] ?? null;
        $worstIntervalIndices = $message['worst_interval_indices'] ?? null;
        $minimumIntervalRatios = $message['minimum_interval_ratios'] ?? null;
        $minimumIntervalRatioIndices = $message['minimum_interval_ratio_indices'] ?? null;
        $maximumIntervalRatios = $message['maximum_interval_ratios'] ?? null;
        $maximumIntervalRatioIndices = $message['maximum_interval_ratio_indices'] ?? null;
        $maximumIntervalDrawdowns = $message['maximum_interval_drawdowns'] ?? null;
        $maximumIntervalDrawdownIndices = $message['maximum_interval_drawdown_indices'] ?? null;
        $drawdownPeaks = $message['drawdown_peaks'] ?? null;
        $maximumIntervalDrawdownPeaks = $message['maximum_interval_drawdown_peaks'] ?? null;
        if ($longIndex < 0 || $longIndex >= $symbolCount || $shortStart < 0
            || !is_array($values) || !is_array($maximums) || !is_array($drawdowns)
            || !is_array($worstIntervalValues) || !is_array($worstIntervalMaximums)
            || !is_array($worstIntervalDrawdowns) || !is_array($worstIntervalIndices)
            || !is_array($minimumIntervalRatios) || !is_array($minimumIntervalRatioIndices)
            || !is_array($maximumIntervalRatios) || !is_array($maximumIntervalRatioIndices)
            || !is_array($maximumIntervalDrawdowns) || !is_array($maximumIntervalDrawdownIndices)
            || !is_array($drawdownPeaks) || !is_array($maximumIntervalDrawdownPeaks)
            || count($maximums) !== count($values) || count($drawdowns) !== count($values)
            || count($worstIntervalValues) !== count($values) || count($worstIntervalMaximums) !== count($values)
            || count($worstIntervalDrawdowns) !== count($values) || count($worstIntervalIndices) !== count($values)
            || count($minimumIntervalRatios) !== count($values) || count($minimumIntervalRatioIndices) !== count($values)
            || count($maximumIntervalRatios) !== count($values) || count($maximumIntervalRatioIndices) !== count($values)
            || count($maximumIntervalDrawdowns) !== count($values) || count($maximumIntervalDrawdownIndices) !== count($values)
            || count($drawdownPeaks) !== count($values) || count($maximumIntervalDrawdownPeaks) !== count($values)
            || count($values) > $symbolCount - $shortStart) {
            throw new RuntimeException('Numba-расчёт вернул некорректный блок пар.');
        }
        $longSymbol = $symbols[$longIndex];
        foreach ($values as $offset => $value) {
            $shortIndex = $shortStart + $offset;
            if (!isset($symbols[$shortIndex]) || $longIndex === $shortIndex) continue;
            $shortSymbol = $symbols[$shortIndex];
            $netPnl = $value === null ? null : (float) $value;
            $maximum = $maximums[$offset] === null ? null : (float) $maximums[$offset];
            $drawdown = $drawdowns[$offset] === null ? null : (float) $drawdowns[$offset];
            $drawdownPeak = $drawdownPeaks[$offset] === null ? null : (float) $drawdownPeaks[$offset];
            $drawdownPercent = $drawdown !== null && $drawdownPeak !== null && (float) $balance + $drawdownPeak > 0
                ? $drawdown / ((float) $balance + $drawdownPeak) * 100.0 : null;
            $overallRatio = $maximum !== null && $drawdown !== null && $drawdown > 0 ? $maximum / $drawdown : null;
            $worstIndex = (int) ($worstIntervalIndices[$offset] ?? -1);
            $worstInterval = null;
            if ($worstIndex >= 0 && isset($rankingWindows[$worstIndex]) && ($worstIntervalValues[$offset] ?? null) !== null) {
                $worstNet = (float) $worstIntervalValues[$offset];
                $worstMaximum = ($worstIntervalMaximums[$offset] ?? null) === null ? null : (float) $worstIntervalMaximums[$offset];
                $worstDrawdown = ($worstIntervalDrawdowns[$offset] ?? null) === null ? null : (float) $worstIntervalDrawdowns[$offset];
                $worstInterval = [
                    'start_ms'=>(int) $rankingWindows[$worstIndex]['start_ms'],
                    'end_ms'=>(int) $rankingWindows[$worstIndex]['end_ms'],
                    'net_pnl'=>$worstNet,
                    'maximum_net_pnl'=>$worstMaximum,
                    'maximum_drawdown'=>$worstDrawdown,
                    'peak_drawdown_ratio'=>$worstMaximum !== null && $worstDrawdown !== null && $worstDrawdown > 0 ? $worstMaximum / $worstDrawdown : null,
                ];
            }
            $minimumRatioIndex = (int) ($minimumIntervalRatioIndices[$offset] ?? -1);
            $minimumRatio = null;
            if ($minimumRatioIndex >= 0 && isset($rankingWindows[$minimumRatioIndex]) && ($minimumIntervalRatios[$offset] ?? null) !== null) {
                $minimumRatio = [
                    'start_ms'=>(int) $rankingWindows[$minimumRatioIndex]['start_ms'],
                    'end_ms'=>(int) $rankingWindows[$minimumRatioIndex]['end_ms'],
                    'peak_drawdown_ratio'=>(float) $minimumIntervalRatios[$offset],
                ];
            }
            $maximumRatioIndex = (int) ($maximumIntervalRatioIndices[$offset] ?? -1);
            $maximumIntervalRatio = null;
            if ($maximumRatioIndex >= 0 && isset($rankingWindows[$maximumRatioIndex]) && ($maximumIntervalRatios[$offset] ?? null) !== null) {
                $maximumIntervalRatio = [
                    'start_ms'=>(int) $rankingWindows[$maximumRatioIndex]['start_ms'],
                    'end_ms'=>(int) $rankingWindows[$maximumRatioIndex]['end_ms'],
                    'peak_drawdown_ratio'=>(float) $maximumIntervalRatios[$offset],
                ];
            }
            $maximumDrawdownIndex = (int) ($maximumIntervalDrawdownIndices[$offset] ?? -1);
            $maximumIntervalDrawdown = null;
            if ($maximumDrawdownIndex >= 0 && isset($rankingWindows[$maximumDrawdownIndex]) && ($maximumIntervalDrawdowns[$offset] ?? null) !== null) {
                $intervalDrawdown = (float) $maximumIntervalDrawdowns[$offset];
                $intervalDrawdownPeak = ($maximumIntervalDrawdownPeaks[$offset] ?? null) === null ? null : (float) $maximumIntervalDrawdownPeaks[$offset];
                $maximumIntervalDrawdown = [
                    'start_ms'=>(int) $rankingWindows[$maximumDrawdownIndex]['start_ms'],
                    'end_ms'=>(int) $rankingWindows[$maximumDrawdownIndex]['end_ms'],
                    'maximum_drawdown'=>$intervalDrawdown,
                    'maximum_drawdown_percent'=>$intervalDrawdownPeak !== null && (float) $balance + $intervalDrawdownPeak > 0
                        ? $intervalDrawdown / ((float) $balance + $intervalDrawdownPeak) * 100.0 : null,
                ];
            }
            $ratio = $intervalMode === 'none' ? $overallRatio : ($minimumRatio['peak_drawdown_ratio'] ?? null);
            $maximumRatio = $intervalMode === 'none' ? $overallRatio : ($maximumIntervalRatio['peak_drawdown_ratio'] ?? null);
            $pair = [
                'long_symbol' => $longSymbol,
                'short_symbol' => $shortSymbol,
                'net_pnl' => $netPnl, 'maximum_net_pnl'=>$maximum, 'maximum_drawdown'=>$drawdown, 'peak_drawdown_ratio'=>$ratio,
                'maximum_drawdown_percent'=>$drawdownPercent,
                'maximum_interval_ratio'=>$maximumRatio,
                'overall_peak_drawdown_ratio'=>$overallRatio,
                'worst_interval'=>$worstInterval,
                'minimum_ratio_interval'=>$minimumRatio,
                'maximum_ratio_interval'=>$maximumIntervalRatio,
                'maximum_drawdown_interval'=>$maximumIntervalDrawdown,
                'return_percent' => $netPnl === null ? null : $netPnl / (float) $balance * 100.0,
            ];
            $pairMatrix[$longIndex][$shortIndex] = $netPnl;
            $ratioMatrix[$longIndex][$shortIndex] = $ratio;
            $maximumRatioMatrix[$longIndex][$shortIndex] = $maximumRatio;
            if (!isset($bestLongPairs[$longSymbol]) || hedge_ranking_pair_is_better($pair, $bestLongPairs[$longSymbol])) {
                $bestLongPairs[$longSymbol] = $pair;
            }
            if (!isset($bestShortPairs[$shortSymbol]) || hedge_ranking_pair_is_better($pair, $bestShortPairs[$shortSymbol])) {
                $bestShortPairs[$shortSymbol] = $pair;
            }
            $compactPair = [
                'long_symbol'=>$longSymbol,
                'short_symbol'=>$shortSymbol,
                'net_pnl'=>$netPnl,
                'maximum_drawdown_percent'=>$drawdownPercent,
                'overall_peak_drawdown_ratio'=>$overallRatio,
                'peak_drawdown_ratio'=>$ratio,
                'maximum_interval_ratio'=>$maximumRatio,
                'worst_interval'=>$worstInterval === null ? null : ['net_pnl'=>$worstInterval['net_pnl']],
                'maximum_drawdown_interval'=>$maximumIntervalDrawdown,
                'return_percent'=>$netPnl === null ? null : $netPnl / (float) $balance * 100.0,
            ];
            $encodedCompactPair = json_encode($compactPair, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            hedge_ranking_write_all($pairSidecarHandle, ($sidecarPairCount > 0 ? ',' : '') . $encodedCompactPair);
            $sidecarPairCount++;
            if ($orderedPairCount > 2000) {
                if ($bestPair === null || hedge_ranking_pair_is_better($pair, $bestPair)) $bestPair = $pair;
            } else {
                $pairs[] = $pair;
            }
            $pairIndex++;
            if ($pairIndex % $progressInterval === 0 || $pairIndex === $orderedPairCount) {
                $percent = 50 + (int) floor($pairIndex * 50 / $orderedPairCount);
                hedge_ranking_progress("Рассчитаны пары: " . number_format($pairIndex, 0, ',', ' ') . ' / ' . number_format($orderedPairCount, 0, ',', ' ') . " ({$percent}%).");
                set_time_limit(300);
            }
        }
    }
    fclose($workerPipes[1]);
    $workerPipes = [];
    $workerExitCode = proc_close($workerProcess);
    $workerProcess = null;
    if ($workerExitCode !== 0 || !$workerDone || $pairIndex !== $orderedPairCount) {
        $diagnostic = is_file($workerLogPath) ? trim((string) file_get_contents($workerLogPath)) : '';
        throw new RuntimeException('Numba-расчёт не завершился корректно.' . ($diagnostic === '' ? '' : ' ' . $diagnostic));
    }
    $sidecarComplete = is_resource($pairSidecarHandle) && $sidecarPairCount === $orderedPairCount;
    if ($sidecarComplete) {
        try { hedge_ranking_write_all($pairSidecarHandle, ']'); }
        catch (Throwable) { $sidecarComplete = false; }
    }
    if (is_resource($pairSidecarHandle) && !fclose($pairSidecarHandle)) $sidecarComplete = false;
    $pairSidecarHandle = null;
    if (!$sidecarComplete) {
        throw new RuntimeException('Не удалось завершить кэш метрик сочетаний.');
    }
    if (!rename($pairSidecarTempPath, $pairSidecarPath)) throw new RuntimeException('Не удалось сохранить кэш метрик сочетаний.');
    $pairSidecarTempPath = null;
    @unlink($quoteFilePath);
    @unlink($metadataFilePath);
    @unlink($workerLogPath);
    $quoteFilePath = $metadataFilePath = $workerLogPath = null;
    if ($orderedPairCount > 2000 && $bestPair !== null) $pairs[] = $bestPair;
    $sortPairs = 'hedge_ranking_compare_pairs';
    usort($pairs, $sortPairs);
    $bestLongPairs = array_values($bestLongPairs);
    $bestShortPairs = array_values($bestShortPairs);
    usort($bestLongPairs, $sortPairs);
    usort($bestShortPairs, $sortPairs);

    $result = [
        'status' => 'ready',
        'start_day' => $startValue,
        'end_day' => $endValue,
        'starting_balance' => (float) $balance,
        'fee_percent' => (float) $feePercent,
        'ordered_pair_count' => $orderedPairCount,
        'symbols' => $symbols,
        'pair_matrix' => $pairMatrix,
        'pair_ratio_matrix' => $ratioMatrix,
        'pair_maximum_ratio_matrix' => $maximumRatioMatrix,
        'sort_metric' => 'maximum_interval_ratio',
        'interval_mode' => $intervalMode,
        'excluded_interval_count' => $excludedIntervals,
        'ranking_end_day' => (new DateTimeImmutable($endUtc, new DateTimeZone('UTC')))->setTimezone($timezone)->format('Y-m-d'),
        'interval_windows' => $intervalWindows,
        'pairs' => $pairs,
        'best_long_pairs' => $bestLongPairs,
        'best_short_pairs' => $bestShortPairs,
        'cache_hit' => false,
    ];
    $writeLock = fopen($cachePath . '.lock', 'c');
    if ($writeLock !== false && flock($writeLock, LOCK_EX)) {
        try {
            hedge_ranking_write_cache($cachePath, $result);
        } catch (Throwable $cacheError) {
            error_log('Unable to cache hedge pair ranking: ' . $cacheError->getMessage());
        } finally {
            flock($writeLock, LOCK_UN);
            fclose($writeLock);
        }
    } elseif (is_resource($writeLock)) {
        fclose($writeLock);
    }
    if ($includeAllPairs) {
        $allPairs = json_decode((string) file_get_contents($pairSidecarPath), true);
        if (!is_array($allPairs) || count($allPairs) !== $orderedPairCount) {
            throw new RuntimeException('Не удалось прочитать полный кэш метрик сочетаний.');
        }
        $result['pairs'] = $allPairs;
    }
    hedge_ranking_result($result);
} catch (Throwable $error) {
    if (is_resource($workerProcess)) @proc_terminate($workerProcess);
    foreach ($workerPipes as $pipe) {
        if (is_resource($pipe)) @fclose($pipe);
    }
    if (is_resource($workerProcess)) @proc_close($workerProcess);
    if (is_resource($pairSidecarHandle)) @fclose($pairSidecarHandle);
    $diagnostic = is_string($workerLogPath) && is_file($workerLogPath)
        ? trim((string) file_get_contents($workerLogPath))
        : '';
    if ($diagnostic !== '') error_log('Hedge Numba worker: ' . $diagnostic);
    foreach ([$quoteFilePath, $metadataFilePath, $workerLogPath] as $temporaryPath) {
        if (is_string($temporaryPath)) @unlink($temporaryPath);
    }
    if (is_string($pairSidecarTempPath)) @unlink($pairSidecarTempPath);
    error_log('Hedge pair ranking failed: ' . $error->getMessage());
    hedge_ranking_respond(['status' => 'error', 'message' => 'Не удалось рассчитать доходность пар.'], 503);
}

function hedge_ranking_compare_pairs(array $left, array $right): int
{
    $a = $left['maximum_interval_ratio'] ?? $left['peak_drawdown_ratio']; $b = $right['maximum_interval_ratio'] ?? $right['peak_drawdown_ratio'];
    if ($a === null && $b !== null) return 1;
    if ($a !== null && $b === null) return -1;
    if ($a !== null && $b !== null && $a !== $b) return $b <=> $a;
    if ($left['net_pnl'] === null && $right['net_pnl'] !== null) return 1;
    if ($left['net_pnl'] !== null && $right['net_pnl'] === null) return -1;
    if ($left['net_pnl'] !== $right['net_pnl']) return $right['net_pnl'] <=> $left['net_pnl'];
    return [$left['long_symbol'], $left['short_symbol']] <=> [$right['long_symbol'], $right['short_symbol']];
}

function hedge_ranking_pair_is_better(array $candidate, array $current): bool
{
    return hedge_ranking_compare_pairs($candidate, $current) < 0;
}
