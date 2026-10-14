<?php
declare(strict_types=1);

function writeReturnSummaryJson(string $path, array $payload): void
{
    $temporary = tempnam(dirname($path), '.return-summary-');
    if ($temporary === false) throw new RuntimeException('Unable to prepare return summary.');
    try {
        if (file_put_contents($temporary, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
            || !rename($temporary, $path)) throw new RuntimeException('Unable to save return summary.');
        chmod($path, 0600);
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function returnSummaryPath(array $job, string $symbol): string
{
    $cacheDir = (getenv('HOME') ?: '/home/alex') . '/.ai/home/.local/bitget-backtest-cache';
    $directory = $cacheDir . '/strategy-totals';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare strategy totals directory.');
    }
    $config = json_decode((string) file_get_contents(dirname(__DIR__) . '/config.json'), true, 512, JSON_THROW_ON_ERROR);
    $generationPath = $cacheDir . '/strategy-ranking-generation.txt';
    $generation = is_file($generationPath) ? trim((string) file_get_contents($generationPath)) : '0';
    $identity = [
        'strategy-totals-v3-contained-trades', $config['category'], $generation,
        (string) ($job['run_key'] ?? ''), $symbol, $job['start_day'], $job['end_day'],
        $job['exit_confirmation_mode'], (int) $job['exit_confirmation_snapshots'],
    ];
    return $directory . '/' . hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) . '.sqlite';
}

function returnSummaryDatabase(PDO $source, array $job, string $symbol): PDO
{
    if (!preg_match('/^[A-Z0-9]{2,40}$/', $symbol)) throw new InvalidArgumentException('Invalid summary symbol.');
    $path = returnSummaryPath($job, $symbol);
    if (!is_file($path)) {
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock strategy totals.');
        $temporary = null;
        $query = null;
        try {
            if (!is_file($path)) {
                $zone = new DateTimeZone('Europe/Kyiv');
                $start = new DateTimeImmutable($job['start_day'] . ' 00:00:00', $zone);
                $end = (new DateTimeImmutable($job['end_day'] . ' 00:00:00', $zone))->modify('+1 day');
                $temporary = tempnam(dirname($path), '.strategy-totals-');
                if ($temporary === false) throw new RuntimeException('Unable to prepare strategy totals.');
                $store = new PDO('sqlite:' . $temporary, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $store->exec('PRAGMA journal_mode=OFF');
                $store->exec('CREATE TABLE totals (strategy_id INTEGER PRIMARY KEY, entry_id TEXT NOT NULL, entry_label TEXT NOT NULL, exit_id TEXT NOT NULL, exit_label TEXT NOT NULL, cumulative_pnl REAL, closed_trade_count INTEGER NOT NULL)');
                $insert = $store->prepare('INSERT INTO totals VALUES (?,?,?,?,?,?,?)');
                // Read daily totals by numeric ID, joining labels after aggregation.
                $source->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $query = $source->prepare(
                    'SELECT r.strategy_id,s.entry_id,s.entry_label,s.exit_id,s.exit_label,r.cumulative_pnl,r.closed_trade_count '
                    . 'FROM (SELECT d.strategy_id,CASE WHEN SUM(d.valid_factor_count)>0 THEN (EXP(SUM(d.log_factor_sum))-1)*100 ELSE NULL END AS cumulative_pnl,'
                    . 'SUM(d.trade_count) AS closed_trade_count FROM strategy_return_daily_totals d FORCE INDEX (PRIMARY) '
                    . 'WHERE d.symbol=? AND d.entry_day>=? AND d.exit_day<=? GROUP BY d.strategy_id) r '
                    . 'JOIN strategy_return_profiles s ON s.strategy_id=r.strategy_id '
                    . 'WHERE s.exit_confirmation_mode=? AND s.exit_confirmation_snapshots=?'
                );
                $query->execute([
                    $symbol, $job['start_day'], $job['end_day'],
                    $job['exit_confirmation_mode'], (int) $job['exit_confirmation_snapshots'],
                ]);
                $store->beginTransaction();
                while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $insert->execute([
                        $row['strategy_id'], $row['entry_id'], $row['entry_label'], $row['exit_id'], $row['exit_label'],
                        $row['cumulative_pnl'], $row['closed_trade_count'],
                    ]);
                }
                $query->closeCursor();
                $source->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                $store->commit();
                $store->exec('CREATE INDEX totals_entry ON totals(entry_id)');
                $insert = null;
                $store = null;
                chmod($temporary, 0600);
                if (!rename($temporary, $path)) throw new RuntimeException('Unable to publish strategy totals.');
                $temporary = null;
            }
        } finally {
            if ($query !== null) $query->closeCursor();
            $source->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    $store = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $store->exec('PRAGMA query_only=ON');
    return $store;
}

function returnSummaryRows(PDO $source, array $job, string $symbol): Generator
{
    $store = returnSummaryDatabase($source, $job, $symbol);
    $query = $store->query('SELECT entry_id,entry_label,exit_id,exit_label,cumulative_pnl,closed_trade_count FROM totals');
    while (($row = $query->fetch()) !== false) yield ['symbol' => $symbol] + $row;
}
