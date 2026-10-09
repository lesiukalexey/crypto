<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/hedge_simulation.php';

function quote(string $time, string $bid, string $ask, string $last): array
{
    return ['received_at_utc' => $time, 'bid_price' => $bid, 'ask_price' => $ask, 'last_price' => $last];
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$long = [
    quote('2026-10-06 12:00:00.000', '100', '101', '100.5'),
    quote('2026-10-06 12:01:00.000', '110', '111', '110.5'),
];
$short = [
    quote('2026-10-06 12:00:30.000', '50', '51', '50.5'),
    quote('2026-10-06 12:01:30.000', '40', '41', '40.5'),
];
$result = simulate_cross_hedge($long, $short, 100.0, 0.0);
check($result['summary'] !== null, 'Expected a synchronized entry and result.');
check($result['summary']['entry_time_ms'] === 1791288030000, 'Entry must use the first instant both quotes are known.');
check($result['summary']['aligned_points'] === 3, 'Expected entry and two subsequent as-of aligned points.');
check(abs($result['points'][0]['long_last'] - 100.5) < 1e-9, 'Long leg must use the prior known long quote, not a future one.');
check(abs($result['summary']['net_pnl'] - (50.0 * 9.0 / 101.0 + 9.0)) < 1e-8, 'Expected half-balance long and short PnL.');
check(abs($result['summary']['long_notional'] - 50.0) < 1e-9, 'Long allocation must be half the start balance when fees are zero.');
check(abs($result['summary']['short_notional'] - 50.0) < 1e-9, 'Short allocation must be half the start balance when fees are zero.');

$stale = simulate_cross_hedge(
    [quote('2026-10-06 12:00:00.000', '100', '101', '100.5')],
    [quote('2026-10-06 12:05:00.000', '50', '51', '50.5')],
    100.0,
    0.0006,
    120,
);
check($stale['summary'] === null, 'Quotes separated by more than the staleness limit must not open a hedge.');

echo "hedge simulation checks passed\n";
