<?php
declare(strict_types=1);

/**
 * Replay one long and one short position on causally aligned quote streams.
 * Rows must be ordered and include received_at_utc, bid_price, ask_price, and last_price.
 */
function simulate_cross_hedge(
    array $longRows,
    array $shortRows,
    float $startingBalance,
    float $feeRate,
    int $maxQuoteAgeSeconds = 120,
): array {
    $quoteTimes = [];
    foreach ([$longRows, $shortRows] as $rows) {
        foreach ($rows as $row) {
            $quoteTimes[] = hedge_timestamp_ms($row);
        }
    }
    sort($quoteTimes, SORT_NUMERIC);
    $quoteTimes = array_values(array_unique($quoteTimes));
    $longIndex = -1;
    $shortIndex = -1;
    $longCursor = 0;
    $shortCursor = 0;
    $entry = null;
    $points = [];
    $allocation = $startingBalance / 2.0;
    $longNotional = $allocation / (1.0 + $feeRate);
    $shortNotional = $allocation / (1.0 + $feeRate);

    foreach ($quoteTimes as $timestampMs) {
        while (isset($longRows[$longCursor]) && hedge_timestamp_ms($longRows[$longCursor]) <= $timestampMs) {
            $longIndex = $longCursor++;
        }
        while (isset($shortRows[$shortCursor]) && hedge_timestamp_ms($shortRows[$shortCursor]) <= $timestampMs) {
            $shortIndex = $shortCursor++;
        }
        if ($longIndex < 0 || $shortIndex < 0) continue;
        $longQuote = $longRows[$longIndex];
        $shortQuote = $shortRows[$shortIndex];
        $longAge = ($timestampMs - hedge_timestamp_ms($longQuote)) / 1000;
        $shortAge = ($timestampMs - hedge_timestamp_ms($shortQuote)) / 1000;
        if ($longAge > $maxQuoteAgeSeconds || $shortAge > $maxQuoteAgeSeconds) continue;

        $longAsk = (float) $longQuote['ask_price'];
        $shortBid = (float) $shortQuote['bid_price'];
        $longBid = (float) $longQuote['bid_price'];
        $shortAsk = (float) $shortQuote['ask_price'];
        if (min($longAsk, $shortBid, $longBid, $shortAsk) <= 0.0) continue;

        if ($entry === null) {
            $entry = [
                'timestamp_ms' => $timestampMs,
                'long_ask' => $longAsk,
                'short_bid' => $shortBid,
                'long_quantity' => $longNotional / $longAsk,
                'short_quantity' => $shortNotional / $shortBid,
                'long_entry_fee' => $longNotional * $feeRate,
                'short_entry_fee' => $shortNotional * $feeRate,
            ];
        }

        $longGross = ($longBid - $entry['long_ask']) * $entry['long_quantity'];
        $shortGross = ($entry['short_bid'] - $shortAsk) * $entry['short_quantity'];
        $longExitFee = $longBid * $entry['long_quantity'] * $feeRate;
        $shortExitFee = $shortAsk * $entry['short_quantity'] * $feeRate;
        $longPnl = $longGross - $entry['long_entry_fee'] - $longExitFee;
        $shortPnl = $shortGross - $entry['short_entry_fee'] - $shortExitFee;
        $netPnl = $longPnl + $shortPnl;
        $points[] = [
            't' => $timestampMs,
            'long_index' => 100.0 * $longBid / $entry['long_ask'],
            'short_index' => 100.0 * (1.0 + ($entry['short_bid'] - $shortAsk) / $entry['short_bid']),
            'equity_index' => 100.0 * ($startingBalance + $netPnl) / $startingBalance,
            'long_pnl' => $longPnl,
            'short_pnl' => $shortPnl,
            'net_pnl' => $netPnl,
            'long_last' => (float) $longQuote['last_price'],
            'short_last' => (float) $shortQuote['last_price'],
            'long_close' => $longBid,
            'short_close' => $shortAsk,
        ];
    }

    if ($entry === null || $points === []) {
        return ['entry' => null, 'points' => [], 'summary' => null];
    }

    $last = $points[count($points) - 1];
    return [
        'entry' => $entry,
        'points' => $points,
        'summary' => [
            'long_notional' => $longNotional,
            'short_notional' => $shortNotional,
            'long_quantity' => $entry['long_quantity'],
            'short_quantity' => $entry['short_quantity'],
            'long_pnl' => $last['long_pnl'],
            'short_pnl' => $last['short_pnl'],
            'net_pnl' => $last['net_pnl'],
            'ending_balance' => $startingBalance + $last['net_pnl'],
            'entry_fees' => $entry['long_entry_fee'] + $entry['short_entry_fee'],
            'estimated_exit_fees' => (
                (float) $last['long_close'] * $entry['long_quantity']
                + (float) $last['short_close'] * $entry['short_quantity']
            ) * $feeRate,
            'entry_time_ms' => $entry['timestamp_ms'],
            'last_aligned_time_ms' => $last['t'],
            'aligned_points' => count($points),
        ],
    ];
}

function hedge_timestamp_ms(array $row): int
{
    if (isset($row['_timestamp_ms'])) return (int) $row['_timestamp_ms'];
    $timestamp = (string) $row['received_at_utc'];
    $date = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    $fraction = $date->format('v');
    return $date->getTimestamp() * 1000 + (int) $fraction;
}
