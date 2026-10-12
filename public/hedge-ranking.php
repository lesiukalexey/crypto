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
try {
    // Large ticker sets can contain several million quote snapshots.
    set_time_limit(300);
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

    $balance = (string) ($_GET['balance'] ?? '100');
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
    $revisionStatement = $pdo->prepare(
        'SELECT symbol, MAX(id) AS max_id, COUNT(*) AS quote_count FROM quote_snapshots '
        . 'WHERE category = ? AND symbol IN (' . implode(',', array_fill(0, count($symbols), '?')) . ') '
        . 'AND received_at_utc >= ? AND received_at_utc < ? GROUP BY symbol ORDER BY symbol'
    );
    $revisionStatement->execute([$config['category'], ...$symbols, $startUtc, $endUtc]);
    $quoteRevision = [];
    $totalQuoteCount = 0;
    foreach ($revisionStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $quoteRevision[(string) $row['symbol']] = (int) $row['max_id'];
        $totalQuoteCount += (int) $row['quote_count'];
    }
    $cacheKey = hash('sha256', json_encode([
        'hedge-ranking-numba-v2', $config['category'], $symbols, $startValue, $endValue,
        (float) $balance, (float) $feePercent, $quoteRevision,
    ], JSON_THROW_ON_ERROR));
    $cachePath = $cacheDir . '/hedge-' . $cacheKey . '.json';
    $cacheLock = fopen($cachePath . '.lock', 'c');
    if ($cacheLock === false || !flock($cacheLock, LOCK_EX)) {
        if (is_resource($cacheLock)) fclose($cacheLock);
        hedge_ranking_respond(['status' => 'error', 'message' => 'Не удалось проверить кэш рейтинга пар.'], 503);
    }
    try {
        if (is_file($cachePath)) {
            $cached = json_decode((string) file_get_contents($cachePath), true);
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
        . number_format($totalQuoteCount, 0, ',', ' ') . ' снимков котировок; готовлю загрузку всех снимков для рейтинга.');
    $statement = $pdo->prepare(
        'SELECT symbol, received_at_utc, bid_price, ask_price FROM quote_snapshots '
        . 'WHERE category = ? AND symbol IN (' . implode(',', array_fill(0, count($symbols), '?')) . ') '
        . 'AND received_at_utc >= ? AND received_at_utc < ? '
        . 'ORDER BY symbol, received_at_utc, id'
    );
    $statement->execute([$config['category'], ...$symbols, $startUtc, $endUtc]);
    $quotesBySymbol = array_fill_keys($symbols, '');
    $quoteCounts = array_fill_keys($symbols, 0);
    $symbolCount = count($symbols);
    $currentSymbol = null;
    $packedChunk = [];
    $loadedQuotes = 0;
    while (($row = $statement->fetch()) !== false) {
        $symbol = (string) $row[0];
        if ($currentSymbol !== $symbol) {
            if ($currentSymbol !== null && $packedChunk !== []) {
                $quotesBySymbol[$currentSymbol] .= implode('', $packedChunk);
                $packedChunk = [];
            }
            $currentSymbol = $symbol;
        }
        if (++$quoteCounts[$symbol] > 100000) {
            hedge_ranking_respond([
                'status' => 'error',
                'message' => 'Для ранжирования пар выбрано слишком много снимков. Сузьте диапазон дат и повторите расчёт.',
            ], 400);
        }
        $date = new DateTimeImmutable((string) $row[1], new DateTimeZone('UTC'));
        $packedChunk[] = pack(
            'Pee',
            $date->getTimestamp() * 1000 + (int) $date->format('v'),
            (float) $row[2],
            (float) $row[3],
        );
        if (count($packedChunk) >= 2048) {
            $quotesBySymbol[$symbol] .= implode('', $packedChunk);
            $packedChunk = [];
        }
        $loadedQuotes++;
        if ($loadedQuotes % 10000 === 0) {
            $percent = $totalQuoteCount > 0 ? min(50, max(1, (int) ceil($loadedQuotes * 50 / $totalQuoteCount))) : 50;
            hedge_ranking_progress('Загружены снимки котировок: ' . number_format($loadedQuotes, 0, ',', ' ')
                . ' / ' . number_format($totalQuoteCount, 0, ',', ' ') . " ({$percent}%).");
            set_time_limit(300);
        }
    }
    if ($currentSymbol !== null && $packedChunk !== []) $quotesBySymbol[$currentSymbol] .= implode('', $packedChunk);
    $statement->closeCursor();
    $pdo = null;

    $quoteFilePath = tempnam($cacheDir, '.hedge-quotes-');
    if ($quoteFilePath === false) {
        throw new RuntimeException('Не удалось подготовить данные для Numba-расчёта.');
    }
    $quoteFile = fopen($quoteFilePath, 'wb');
    if ($quoteFile === false) {
        throw new RuntimeException('Не удалось открыть файл снимков для Numba-расчёта.');
    }
    try {
        foreach ($symbols as $symbol) {
            hedge_ranking_write_all($quoteFile, $quotesBySymbol[$symbol]);
            unset($quotesBySymbol[$symbol]);
        }
    } finally {
        fclose($quoteFile);
    }
    unset($quotesBySymbol, $packedChunk);
    gc_collect_cycles();

    $metadataFilePath = tempnam($cacheDir, '.hedge-meta-');
    if ($metadataFilePath === false) {
        throw new RuntimeException('Не удалось подготовить список котировок для Numba-расчёта.');
    }
    $metadata = [
        'symbols' => $symbols,
        'counts' => array_values(array_map(static fn(string $symbol): int => $quoteCounts[$symbol], $symbols)),
        'balance' => (float) $balance,
        'fee_rate' => (float) $feePercent / 100.0,
    ];
    if (file_put_contents($metadataFilePath, json_encode($metadata, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Не удалось записать параметры Numba-расчёта.');
    }
    $workerLogPath = tempnam($cacheDir, '.hedge-worker-');
    if ($workerLogPath === false) {
        throw new RuntimeException('Не удалось подготовить журнал Numba-расчёта.');
    }

    $pairs = [];
    $bestPair = null;
    $bestLongPairs = [];
    $bestShortPairs = [];
    $pairMatrix = array_fill(0, $symbolCount, array_fill(0, $symbolCount, null));
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
        if ($longIndex < 0 || $longIndex >= $symbolCount || $shortStart < 0
            || !is_array($values) || count($values) > $symbolCount - $shortStart) {
            throw new RuntimeException('Numba-расчёт вернул некорректный блок пар.');
        }
        $longSymbol = $symbols[$longIndex];
        foreach ($values as $offset => $value) {
            $shortIndex = $shortStart + $offset;
            if (!isset($symbols[$shortIndex]) || $longIndex === $shortIndex) continue;
            $shortSymbol = $symbols[$shortIndex];
            $netPnl = $value === null ? null : (float) $value;
            $pair = [
                'long_symbol' => $longSymbol,
                'short_symbol' => $shortSymbol,
                'net_pnl' => $netPnl,
                'return_percent' => $netPnl === null ? null : $netPnl / (float) $balance * 100.0,
            ];
            $pairMatrix[$longIndex][$shortIndex] = $netPnl;
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
    @unlink($quoteFilePath);
    @unlink($metadataFilePath);
    @unlink($workerLogPath);
    $quoteFilePath = $metadataFilePath = $workerLogPath = null;
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

    $result = [
        'status' => 'ready',
        'start_day' => $startValue,
        'end_day' => $endValue,
        'starting_balance' => (float) $balance,
        'fee_percent' => (float) $feePercent,
        'ordered_pair_count' => $orderedPairCount,
        'symbols' => $symbols,
        'pair_matrix' => $pairMatrix,
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
    hedge_ranking_result($result);
} catch (Throwable $error) {
    if (is_resource($workerProcess)) @proc_terminate($workerProcess);
    foreach ($workerPipes as $pipe) {
        if (is_resource($pipe)) @fclose($pipe);
    }
    if (is_resource($workerProcess)) @proc_close($workerProcess);
    $diagnostic = is_string($workerLogPath) && is_file($workerLogPath)
        ? trim((string) file_get_contents($workerLogPath))
        : '';
    if ($diagnostic !== '') error_log('Hedge Numba worker: ' . $diagnostic);
    foreach ([$quoteFilePath, $metadataFilePath, $workerLogPath] as $temporaryPath) {
        if (is_string($temporaryPath)) @unlink($temporaryPath);
    }
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
