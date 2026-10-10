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
    $longIndex = -1;
    $shortIndex = -1;
    $longCursor = 0;
    $shortCursor = 0;
    $entry = null;
    $points = [];
    $lastPoint = null;
    $alignedPoints = 0;
    $chartPointLimit = 3600;
    $allocation = $startingBalance / 2.0;
    $longNotional = $allocation / (1.0 + $feeRate);
    $shortNotional = $allocation / (1.0 + $feeRate);

    while (isset($longRows[$longCursor]) || isset($shortRows[$shortCursor])) {
        $timestampMs = min(
            isset($longRows[$longCursor]) ? hedge_timestamp_ms($longRows[$longCursor]) : PHP_INT_MAX,
            isset($shortRows[$shortCursor]) ? hedge_timestamp_ms($shortRows[$shortCursor]) : PHP_INT_MAX,
        );
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
        $point = [
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
        $lastPoint = $point;
        $alignedPoints++;
        $points[] = $point;
        // Keep enough points for the chart while allowing long date ranges.
        if (count($points) > $chartPointLimit) {
            $points = array_values(array_filter(
                $points,
                static fn (array $_point, int $index): bool => $index % 2 === 0,
                ARRAY_FILTER_USE_BOTH,
            ));
        }
    }

    if ($entry === null || $points === []) {
        return ['entry' => null, 'points' => [], 'summary' => null];
    }

    $last = $lastPoint;
    $points[count($points) - 1] = $last;
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
            'aligned_points' => $alignedPoints,
        ],
    ];
}

/**
 * Return the final net PnL for one ordered pair without allocating chart points.
 * Ranking uses compact [timestamp_ms, bid, ask] rows fetched once per symbol.
 */
function cross_hedge_net_pnl(
    array $longRows,
    array $shortRows,
    float $startingBalance,
    float $feeRate,
    int $maxQuoteAgeSeconds = 120,
): ?float {
    $longCursor = 0;
    $shortCursor = 0;
    $longEntry = null;
    $shortEntry = null;
    $maxAgeMs = $maxQuoteAgeSeconds * 1000;
    $allocation = $startingBalance / 2.0;
    $longNotional = $allocation / (1.0 + $feeRate);
    $shortNotional = $allocation / (1.0 + $feeRate);

    while ($longCursor < count($longRows) || $shortCursor < count($shortRows)) {
        $longTime = $longRows[$longCursor][0] ?? PHP_INT_MAX;
        $shortTime = $shortRows[$shortCursor][0] ?? PHP_INT_MAX;
        $timestampMs = min($longTime, $shortTime);
        $longQuote = null;
        $shortQuote = null;

        while (isset($longRows[$longCursor]) && $longRows[$longCursor][0] <= $timestampMs) {
            $longQuote = $longRows[$longCursor++];
        }
        while (isset($shortRows[$shortCursor]) && $shortRows[$shortCursor][0] <= $timestampMs) {
            $shortQuote = $shortRows[$shortCursor++];
        }
        $longQuote ??= $longRows[$longCursor - 1] ?? null;
        $shortQuote ??= $shortRows[$shortCursor - 1] ?? null;
        if ($longQuote === null || $shortQuote === null) continue;
        // A side without an event at this timestamp keeps its latest quote,
        // matching the chart replay's causal as-of alignment.
        if ($timestampMs - $longQuote[0] > $maxAgeMs
            || $timestampMs - $shortQuote[0] > $maxAgeMs) continue;

        $longBid = $longQuote[1];
        $longAsk = $longQuote[2];
        $shortBid = $shortQuote[1];
        $shortAsk = $shortQuote[2];
        if (min($longBid, $longAsk, $shortBid, $shortAsk) <= 0.0) continue;
        $longEntry = $longQuote;
        $shortEntry = $shortQuote;
        break;
    }

    if ($longEntry === null || $shortEntry === null) return null;

    // The displayed final PnL depends only on the first and last valid aligned
    // quotes. Walk backward to the last one instead of materializing chart points.
    $longIndex = count($longRows) - 1;
    $shortIndex = count($shortRows) - 1;
    $lastLong = $longRows[$longIndex] ?? null;
    $lastShort = $shortRows[$shortIndex] ?? null;
    while ($longIndex >= 0 || $shortIndex >= 0) {
        $longTime = $lastLong[0] ?? PHP_INT_MIN;
        $shortTime = $lastShort[0] ?? PHP_INT_MIN;
        $timestampMs = max($longTime, $shortTime);
        if ($lastLong !== null && $lastShort !== null
            && $timestampMs - $lastLong[0] <= $maxAgeMs
            && $timestampMs - $lastShort[0] <= $maxAgeMs
            && min($lastLong[1], $lastLong[2], $lastShort[1], $lastShort[2]) > 0.0) {
            break;
        }
        while ($longIndex >= 0 && $longRows[$longIndex][0] === $timestampMs) $longIndex--;
        while ($shortIndex >= 0 && $shortRows[$shortIndex][0] === $timestampMs) $shortIndex--;
        $lastLong = $longIndex >= 0 ? $longRows[$longIndex] : null;
        $lastShort = $shortIndex >= 0 ? $shortRows[$shortIndex] : null;
    }
    if ($lastLong === null || $lastShort === null) return null;

    $entryLongAsk = $longEntry[2];
    $entryShortBid = $shortEntry[1];
    $lastLongBid = $lastLong[1];
    $lastShortAsk = $lastShort[2];
    $longQuantity = $longNotional / $entryLongAsk;
    $shortQuantity = $shortNotional / $entryShortBid;
    $entryFees = ($longNotional + $shortNotional) * $feeRate;
    $grossPnl = ($lastLongBid - $entryLongAsk) * $longQuantity
        + ($entryShortBid - $lastShortAsk) * $shortQuantity;
    $exitFees = ($lastLongBid * $longQuantity + $lastShortAsk * $shortQuantity) * $feeRate;
    return $grossPnl - $entryFees - $exitFees;
}

function hedge_timestamp_ms(array $row): int
{
    if (isset($row['_timestamp_ms'])) return (int) $row['_timestamp_ms'];
    $timestamp = (string) $row['received_at_utc'];
    $date = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    $fraction = $date->format('v');
    return $date->getTimestamp() * 1000 + (int) $fraction;
}
