<?php
declare(strict_types=1);

function rankStrategyReturnRows(iterable $rows, array $symbols, int $minTrades): array
{
    $universeSize = count($symbols);
    $allowedSymbols = array_fill_keys($symbols, true);
    $bestBySymbol = [];
    $temporary = tempnam(sys_get_temp_dir(), '.return-ranking-');
    if ($temporary === false) throw new RuntimeException('Unable to prepare ranking accumulator.');
    try {
        $store = new PDO('sqlite:' . $temporary, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $store->exec('PRAGMA journal_mode=OFF');
        $store->exec('CREATE TABLE combinations (entry_id TEXT NOT NULL,exit_id TEXT NOT NULL,entry_label TEXT NOT NULL,exit_label TEXT NOT NULL,total_pnl REAL NOT NULL,eligible_symbols INTEGER NOT NULL,profitable_symbols INTEGER NOT NULL,PRIMARY KEY(entry_id,exit_id)) WITHOUT ROWID');
        $insert = $store->prepare('INSERT INTO combinations VALUES (?,?,?,?,?,1,?) ON CONFLICT(entry_id,exit_id) DO UPDATE SET total_pnl=total_pnl+excluded.total_pnl,eligible_symbols=eligible_symbols+1,profitable_symbols=profitable_symbols+excluded.profitable_symbols');
        $store->beginTransaction();
        foreach ($rows as $row) {
            $symbol = (string) $row['symbol'];
            $tradeCount = (int) $row['closed_trade_count'];
            if (!isset($allowedSymbols[$symbol]) || $tradeCount < $minTrades) continue;
            $pnl = (float) $row['cumulative_pnl'];
            $entryId = (string) $row['entry_id'];
            $exitId = (string) $row['exit_id'];
            $insert->execute([$entryId,$exitId,$row['entry_label'],$row['exit_label'],$pnl,$pnl>0?1:0]);
            $candidate = [
                'symbol'=>$symbol,'entry_id'=>$entryId,'entry_label'=>(string)$row['entry_label'],
                'exit_id'=>$exitId,'exit_label'=>(string)$row['exit_label'],
                'pnl'=>$pnl,'trade_count'=>$tradeCount,'chain_count'=>$tradeCount,
            ];
            $previous = $bestBySymbol[$symbol] ?? null;
            $comparison = $previous === null ? 1 : (($pnl <=> $previous['pnl'])
                ?: strcmp($entryId,$previous['entry_id']) ?: strcmp($exitId,$previous['exit_id']));
            if ($comparison > 0) $bestBySymbol[$symbol] = $candidate;
        }
        $store->commit();
        $globalTop = $store->query("SELECT * FROM combinations ORDER BY total_pnl DESC,eligible_symbols DESC,entry_id || '|' || exit_id ASC LIMIT 5")->fetchAll();
        foreach ($globalTop as &$combination) {
            $combination['average_pnl'] = $universeSize ? $combination['total_pnl'] / $universeSize : 0.0;
        }
        unset($combination);
        $lookup = $store->prepare('SELECT total_pnl,eligible_symbols,profitable_symbols FROM combinations WHERE entry_id=? AND exit_id=?');
        $tickerLeaders = array_values($bestBySymbol);
        foreach ($tickerLeaders as &$leader) {
            $lookup->execute([$leader['entry_id'],$leader['exit_id']]);
            $combination = $lookup->fetch();
            $leader['market_average_pnl'] = $combination['total_pnl'] / $universeSize;
            $leader['market_eligible_symbols'] = $combination['eligible_symbols'];
            $leader['market_profitable_symbols'] = $combination['profitable_symbols'];
        }
        unset($leader);
        usort($tickerLeaders, static fn(array $left,array $right): int => ($right['pnl']<=>$left['pnl']) ?: strcmp($left['symbol'],$right['symbol']));
        $symbolResults = [];
        foreach ($symbols as $symbol) {
            $best = $bestBySymbol[$symbol] ?? null;
            if ($best === null) $tickerLeaders[] = ['symbol'=>$symbol,'entry_id'=>null,'entry_label'=>null,'exit_id'=>null,'exit_label'=>null,'pnl'=>null,'trade_count'=>0,'chain_count'=>0];
            $symbolResults[] = ['symbol'=>$symbol,'best_pnl'=>$best['pnl']??null,'best_exit'=>$best['exit_label']??null];
        }
        usort($symbolResults, static fn(array $left,array $right): int => (($left['best_pnl']===null)<=>($right['best_pnl']===null)) ?: (($right['best_pnl']??0)<=>($left['best_pnl']??0)) ?: strcmp($left['symbol'],$right['symbol']));
        return ['global_top'=>$globalTop,'ticker_leaders'=>$tickerLeaders,'symbols'=>$symbolResults,'universe_size'=>$universeSize,'min_trades'=>$minTrades];
    } finally {
        $insert = $lookup = $store = null;
        if (is_file($temporary)) unlink($temporary);
    }
}
