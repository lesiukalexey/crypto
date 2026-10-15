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
    bool $longEnabled = true,
    bool $shortEnabled = true,
): array {
    if (!$longEnabled && !$shortEnabled) throw new InvalidArgumentException("Выберите хотя бы один тикер.");
    $longIndex = -1;
    $shortIndex = -1;
    $longCursor = 0;
    $shortCursor = 0;
    $entry = null;
    $points = [];
    $firstPoint = null;
    $lastPoint = null;
    $maximumPoint = null;
    $minimumAfterMaximumPoint = null;
    $runningPeakPoint = null;
    $maximumDrawdownPeakPoint = null;
    $maximumDrawdownTroughPoint = null;
    $maximumDrawdown = 0.0;
    $alignedPoints = 0;
    $chartPointLimit = 25000;
    $chartRangeStarts = [];
    $chartRangeEnds = [];
    foreach ([$longRows, $shortRows] as $rows) {
        if ($rows === []) continue;
        $chartRangeStarts[] = hedge_timestamp_ms($rows[0]);
        $chartRangeEnds[] = hedge_timestamp_ms($rows[count($rows) - 1]);
    }
    $chartStartMs = $chartRangeStarts === [] ? 0 : min($chartRangeStarts);
    $chartEndMs = $chartRangeEnds === [] ? $chartStartMs : max($chartRangeEnds);
    $allocation = $startingBalance / ((int) $longEnabled + (int) $shortEnabled);
    $longNotional = $longEnabled ? $allocation / (1.0 + $feeRate) : 0.0;
    $shortNotional = $shortEnabled ? $allocation / (1.0 + $feeRate) : 0.0;

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
        if (($longEnabled && $longIndex < 0) || ($shortEnabled && $shortIndex < 0)) continue;
        $inactiveQuote = ['_timestamp_ms'=>$timestampMs, 'bid_price'=>1, 'ask_price'=>1, 'last_price'=>1];
        $longQuote = $longEnabled ? $longRows[$longIndex] : $inactiveQuote;
        $shortQuote = $shortEnabled ? $shortRows[$shortIndex] : $inactiveQuote;
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
        $firstPoint ??= $point;
        $lastPoint = $point;
        if ($maximumPoint === null || $point['net_pnl'] > $maximumPoint['net_pnl']) {
            $maximumPoint = $point;
            $minimumAfterMaximumPoint = $point;
        } elseif ($point['net_pnl'] < $minimumAfterMaximumPoint['net_pnl']) {
            $minimumAfterMaximumPoint = $point;
        }
        if ($runningPeakPoint === null || $point['net_pnl'] > $runningPeakPoint['net_pnl']) {
            $runningPeakPoint = $point;
            if ($maximumDrawdown === 0.0) {
                $maximumDrawdownPeakPoint = $point;
                $maximumDrawdownTroughPoint = $point;
            }
        } else {
            $drawdown = $runningPeakPoint['net_pnl'] - $point['net_pnl'];
            if ($drawdown > $maximumDrawdown) {
                $maximumDrawdown = $drawdown;
                $maximumDrawdownPeakPoint = $runningPeakPoint;
                $maximumDrawdownTroughPoint = $point;
            }
        }
        $alignedPoints++;
        $bucket = $chartEndMs > $chartStartMs
            ? (int) floor(($timestampMs - $chartStartMs) * ($chartPointLimit - 1) / ($chartEndMs - $chartStartMs))
            : 0;
        $bucket = max(0, min($chartPointLimit - 1, $bucket));
        // Keep the latest eligible point in each uniform time bucket.
        $points[$bucket] = $point;
    }

    if ($entry === null || $points === []) {
        return ['entry' => null, 'points' => [], 'summary' => null];
    }

    $last = $lastPoint;
    $points = array_values($points);
    $points[0] = $firstPoint;
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
            'maximum_net_pnl' => $maximumPoint['net_pnl'],
            'maximum_time_ms' => $maximumPoint['t'],
            'minimum_after_maximum_net_pnl' => $minimumAfterMaximumPoint['net_pnl'],
            'minimum_after_maximum_time_ms' => $minimumAfterMaximumPoint['t'],
            'decline_after_maximum' => $maximumPoint['net_pnl'] - $minimumAfterMaximumPoint['net_pnl'],
            'decline_after_maximum_percent' => ($startingBalance + $maximumPoint['net_pnl']) > 0.0
                ? ($maximumPoint['net_pnl'] - $minimumAfterMaximumPoint['net_pnl'])
                    / ($startingBalance + $maximumPoint['net_pnl']) * 100.0
                : null,
            'maximum_drawdown' => $maximumDrawdown,
            'maximum_drawdown_percent' => ($startingBalance + $maximumDrawdownPeakPoint['net_pnl']) > 0.0
                ? $maximumDrawdown / ($startingBalance + $maximumDrawdownPeakPoint['net_pnl']) * 100.0
                : null,
            'maximum_drawdown_peak_net_pnl' => $maximumDrawdownPeakPoint['net_pnl'],
            'maximum_drawdown_peak_time_ms' => $maximumDrawdownPeakPoint['t'],
            'maximum_drawdown_trough_net_pnl' => $maximumDrawdownTroughPoint['net_pnl'],
            'maximum_drawdown_trough_time_ms' => $maximumDrawdownTroughPoint['t'],
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

/** Return the same final PnL as cross_hedge_net_pnl for 24-byte packed quote streams. */
function cross_hedge_net_pnl_packed(
    string $longRows,
    string $shortRows,
    float $startingBalance,
    float $feeRate,
    int $maxQuoteAgeSeconds = 120,
): ?float {
    $longCount = intdiv(strlen($longRows), 24);
    $shortCount = intdiv(strlen($shortRows), 24);
    if ($longCount === 0 || $shortCount === 0) return null;

    $maxAgeMs = $maxQuoteAgeSeconds * 1000;
    $longCursor = 0;
    $shortCursor = 0;
    $longEntry = null;
    $shortEntry = null;
    $longQuote = null;
    $shortQuote = null;
    while ($longCursor < $longCount || $shortCursor < $shortCount) {
        $nextLong = $longCursor < $longCount ? hedge_unpack_packed_quote($longRows, $longCursor) : null;
        $nextShort = $shortCursor < $shortCount ? hedge_unpack_packed_quote($shortRows, $shortCursor) : null;
        $timestampMs = min($nextLong['timestamp'] ?? PHP_INT_MAX, $nextShort['timestamp'] ?? PHP_INT_MAX);
        while ($nextLong !== null && $nextLong['timestamp'] <= $timestampMs) {
            $longQuote = $nextLong;
            $longCursor++;
            $nextLong = $longCursor < $longCount ? hedge_unpack_packed_quote($longRows, $longCursor) : null;
        }
        while ($nextShort !== null && $nextShort['timestamp'] <= $timestampMs) {
            $shortQuote = $nextShort;
            $shortCursor++;
            $nextShort = $shortCursor < $shortCount ? hedge_unpack_packed_quote($shortRows, $shortCursor) : null;
        }
        if ($longQuote === null || $shortQuote === null) continue;
        if ($timestampMs - $longQuote['timestamp'] <= $maxAgeMs
            && $timestampMs - $shortQuote['timestamp'] <= $maxAgeMs
            && min($longQuote['bid'], $longQuote['ask'], $shortQuote['bid'], $shortQuote['ask']) > 0.0) {
            $longEntry = $longQuote;
            $shortEntry = $shortQuote;
            break;
        }
    }
    if ($longEntry === null || $shortEntry === null) return null;

    $longIndex = $longCount - 1;
    $shortIndex = $shortCount - 1;
    $lastLong = hedge_unpack_packed_quote($longRows, $longIndex);
    $lastShort = hedge_unpack_packed_quote($shortRows, $shortIndex);
    while ($longIndex >= 0 || $shortIndex >= 0) {
        $timestampMs = max($lastLong['timestamp'] ?? PHP_INT_MIN, $lastShort['timestamp'] ?? PHP_INT_MIN);
        if ($lastLong !== null && $lastShort !== null
            && $timestampMs - $lastLong['timestamp'] <= $maxAgeMs
            && $timestampMs - $lastShort['timestamp'] <= $maxAgeMs
            && min($lastLong['bid'], $lastLong['ask'], $lastShort['bid'], $lastShort['ask']) > 0.0) break;
        while ($longIndex >= 0 && $lastLong['timestamp'] === $timestampMs) {
            $lastLong = --$longIndex >= 0 ? hedge_unpack_packed_quote($longRows, $longIndex) : null;
        }
        while ($shortIndex >= 0 && $lastShort['timestamp'] === $timestampMs) {
            $lastShort = --$shortIndex >= 0 ? hedge_unpack_packed_quote($shortRows, $shortIndex) : null;
        }
    }
    if ($lastLong === null || $lastShort === null) return null;

    $allocation = $startingBalance / 2.0;
    $longNotional = $allocation / (1.0 + $feeRate);
    $shortNotional = $allocation / (1.0 + $feeRate);
    $longQuantity = $longNotional / $longEntry['ask'];
    $shortQuantity = $shortNotional / $shortEntry['bid'];
    $entryFees = ($longNotional + $shortNotional) * $feeRate;
    $grossPnl = ($lastLong['bid'] - $longEntry['ask']) * $longQuantity
        + ($shortEntry['bid'] - $lastShort['ask']) * $shortQuantity;
    $exitFees = ($lastLong['bid'] * $longQuantity + $lastShort['ask'] * $shortQuantity) * $feeRate;
    return $grossPnl - $entryFees - $exitFees;
}

function hedge_unpack_packed_quote(string $rows, int $index): array
{
    $values = unpack('Ptimestamp/ebid/eask', $rows, $index * 24);
    return ['timestamp' => (int) $values['timestamp'], 'bid' => (float) $values['bid'], 'ask' => (float) $values['ask']];
}

function hedge_timestamp_ms(array $row): int
{
    if (isset($row['_timestamp_ms'])) return (int) $row['_timestamp_ms'];
    $timestamp = (string) $row['received_at_utc'];
    $date = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
    $fraction = $date->format('v');
    return $date->getTimestamp() * 1000 + (int) $fraction;
}
