<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function writeTickerSummaryFile(string $path, array $payload): void
{
    $temporaryPath = tempnam(dirname($path), '.ticker-summary-');
    if ($temporaryPath === false) throw new RuntimeException('Unable to prepare ticker summary file.');
    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || !rename($temporaryPath, $path)) {
            throw new RuntimeException('Unable to write ticker summary file.');
        }
        @chmod($path, 0600);
    } finally {
        if (is_file($temporaryPath)) @unlink($temporaryPath);
    }
}

$jobPath = $argv[1] ?? '';
$root = dirname(__DIR__);
$summaryDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache/ticker-return-summaries';
if (!is_string($jobPath) || !str_starts_with($jobPath, $summaryDir . '/') || !str_ends_with($jobPath, '.job.json')) exit(2);
$key = basename($jobPath, '.job.json');
if (!preg_match('/^[a-f0-9]{64}$/', $key)) exit(2);
$statusPath = $summaryDir . '/' . $key . '.status.json';
$resultPath = $summaryDir . '/' . $key . '.json';

try {
    $job = json_decode((string) file_get_contents($jobPath), true, 512, JSON_THROW_ON_ERROR);
    $symbols = $job['symbols'] ?? [];
    if (!is_array($symbols) || $symbols === []) throw new RuntimeException('Ticker list is empty.');
    writeTickerSummaryFile($statusPath, [
        'status' => 'pending', 'message' => 'Сортирую пары по рассчитанной доходности…',
        'progress' => 0, 'updated_at' => time(), 'pid' => getmypid(),
    ]);

    $startDay = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $job['start_day'], new DateTimeZone('Europe/Kyiv'));
    $endDay = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $job['end_day'], new DateTimeZone('Europe/Kyiv'));
    if ($startDay === false || $endDay === false || $endDay < $startDay) throw new RuntimeException('Invalid date range.');
    $startUtc = $startDay->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $endUtc = $endDay->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    $config = json_decode((string) file_get_contents($root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $db = $config['mysql'];
    $password = getenv($db['password_env']) ?: '';
    if ($password === '') throw new RuntimeException('Database password is unavailable to the summary worker.');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
        $db['user'], $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $statement = $pdo->prepare(
        'SELECT p.strategy_id,MAX(s.entry_id) AS entry_id,MAX(s.entry_label) AS entry_label,'
        . 'MAX(s.exit_id) AS exit_id,MAX(s.exit_label) AS exit_label,'
        '(EXP(SUM(LN(1 + p.return_percent / 100))) - 1) * 100 AS cumulative_return_percent,'
        'SUM(p.trade_count) AS closed_trade_count '
        . 'FROM strategy_return_points p JOIN strategy_return_profiles s ON s.strategy_id=p.strategy_id '
        . 'WHERE p.symbol=? AND p.point_minute>=? AND p.point_minute<? '
        . 'AND s.exit_confirmation_mode=? AND s.exit_confirmation_snapshots=? '
        . 'GROUP BY p.strategy_id'
    );
    $minimumTrades = (int) $job['min_trades'];
    $bestBySymbol = [];
    foreach ($symbols as $index => $symbol) {
        $statement->execute([
            $symbol, $startUtc, $endUtc,
            (string) $job['exit_confirmation_mode'], (int) $job['exit_confirmation_snapshots'],
        ]);
        while (($row = $statement->fetch()) !== false) {
            if ((int) $row['closed_trade_count'] < $minimumTrades) continue;
            $pnl = (float) $row['cumulative_return_percent'];
            if (!isset($bestBySymbol[$symbol]) || $pnl > $bestBySymbol[$symbol]['pnl']) {
                $bestBySymbol[$symbol] = [
                    'symbol' => $symbol, 'pnl' => $pnl,
                    'entry_id' => (string) $row['entry_id'], 'entry_label' => (string) $row['entry_label'],
                    'exit_id' => (string) $row['exit_id'], 'exit_label' => (string) $row['exit_label'],
                    'trade_count' => (int) $row['closed_trade_count'],
                ];
            }
        }
        $statement->closeCursor();
        $completed = $index + 1;
        $progress = (int) floor($completed * 100 / count($symbols));
        writeTickerSummaryFile($statusPath, [
            'status' => 'pending',
            'message' => "Сортирую пары: {$completed} из " . count($symbols) . " · {$progress}%",
            'progress' => $progress, 'completed_symbols' => $completed,
            'total_symbols' => count($symbols), 'updated_at' => time(), 'pid' => getmypid(),
        ]);
    }
    $pdo = null;
    $leaders = array_values($bestBySymbol);
    usort($leaders, static fn(array $left, array $right): int =>
        ($right['pnl'] <=> $left['pnl']) ?: strcmp($left['symbol'], $right['symbol'])
    );
    $result = [
        'status' => 'ready', 'run_key' => (string) $job['run_key'],
        'start_day' => (string) $job['start_day'], 'end_day' => (string) $job['end_day'],
        'min_trades' => $minimumTrades, 'universe_size' => count($symbols),
        'ranked_count' => count($leaders), 'symbols' => $leaders,
    ];
    writeTickerSummaryFile($resultPath, $result);
    writeTickerSummaryFile($statusPath, array_merge($result, ['updated_at' => time()]));
} catch (Throwable $error) {
    error_log('Ticker return summary worker failed: ' . $error->getMessage());
    writeTickerSummaryFile($statusPath, [
        'status' => 'error', 'message' => 'Не удалось подготовить доходность по торговым парам.', 'updated_at' => time(),
    ]);
    exit(1);
}
