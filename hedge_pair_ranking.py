#!/usr/bin/env python3
"""Calculate exact ordered hedge-pair returns over packed quote streams."""

from __future__ import annotations

import json
import math
import os
import sys
from pathlib import Path

if len(sys.argv) == 4:
    _numba_cache_dir = Path(sys.argv[3])
    _numba_cache_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.environ["NUMBA_CACHE_DIR"] = str(_numba_cache_dir)

import numpy as np
from numba import njit


@njit(cache=True)
def _calculate_metrics_chunk_kernel(timestamps, bids, asks, offsets, counts, long_index,
                                    short_start, short_end, starting_balance, fee_rate, max_age_ms,
                                    window_start_ms, window_end_ms):
    output = np.full((short_end-short_start, 4), np.nan)
    long_offset, long_count = offsets[long_index], counts[long_index]
    if long_count == 0:
        return output
    notional = (starting_balance/2)/(1+fee_rate)
    for output_index, short_index in enumerate(range(short_start, short_end)):
        if long_index == short_index or counts[short_index] == 0:
            continue
        short_offset, short_count = offsets[short_index], counts[short_index]
        full_stream = window_start_ms == np.uint64(0) and window_end_ms == np.uint64(18446744073709551615)
        if not full_stream:
            boundary_tolerance_ms = np.uint64(3 * 86_400_000)
            latest_first_ms = window_start_ms + boundary_tolerance_ms
            earliest_last_ms = window_end_ms - boundary_tolerance_ms
            if (timestamps[long_offset] > latest_first_ms
                    or timestamps[long_offset+long_count-1] < earliest_last_ms
                    or timestamps[short_offset] > latest_first_ms
                    or timestamps[short_offset+short_count-1] < earliest_last_ms):
                continue
        long_cursor = short_cursor = 0
        long_quote = short_quote = -1
        long_entry = short_entry = -1
        maximum = peak = -np.inf
        drawdown = 0.
        drawdown_peak = np.nan
        net = np.nan
        while long_cursor < long_count or short_cursor < short_count:
            long_time = timestamps[long_offset+long_cursor] if long_cursor < long_count else np.uint64(18446744073709551615)
            short_time = timestamps[short_offset+short_cursor] if short_cursor < short_count else np.uint64(18446744073709551615)
            timestamp = min(long_time, short_time)
            if timestamp >= window_end_ms:
                break
            while long_cursor < long_count and timestamps[long_offset+long_cursor] <= timestamp:
                long_quote = long_offset+long_cursor
                long_cursor += 1
            while short_cursor < short_count and timestamps[short_offset+short_cursor] <= timestamp:
                short_quote = short_offset+short_cursor
                short_cursor += 1
            if timestamp < window_start_ms:
                continue
            if long_quote < 0 or short_quote < 0:
                continue
            if timestamp-timestamps[long_quote] > max_age_ms or timestamp-timestamps[short_quote] > max_age_ms:
                continue
            if min(bids[long_quote],asks[long_quote],bids[short_quote],asks[short_quote]) <= 0:
                continue
            if long_entry < 0:
                long_entry, short_entry = long_quote, short_quote
                long_quantity = notional/asks[long_entry]
                short_quantity = notional/bids[short_entry]
            # Match PHP's separately netted legs, including both-side fees.
            long_pnl = (bids[long_quote]-asks[long_entry])*long_quantity-notional*fee_rate-bids[long_quote]*long_quantity*fee_rate
            short_pnl = (bids[short_entry]-asks[short_quote])*short_quantity-notional*fee_rate-asks[short_quote]*short_quantity*fee_rate
            net = long_pnl+short_pnl
            maximum = max(maximum, net)
            peak = max(peak, net)
            current_drawdown = peak-net
            if current_drawdown > drawdown:
                drawdown = current_drawdown
                drawdown_peak = peak
        if long_entry >= 0:
            output[output_index,0] = net
            output[output_index,1] = maximum
            output[output_index,2] = drawdown
            output[output_index,3] = drawdown_peak
    return output


@njit(cache=True)
def _calculate_chunk_kernel(timestamps,bids,asks,offsets,counts,long_index,short_start,short_end,
                            starting_balance,fee_rate,max_age_ms):
    return _calculate_metrics_chunk_kernel(timestamps,bids,asks,offsets,counts,long_index,short_start,
                                            short_end,starting_balance,fee_rate,max_age_ms,
                                            np.uint64(0),np.uint64(18446744073709551615))[:,0]


def main() -> int:
    if len(sys.argv) != 4:
        raise SystemExit("usage: hedge_pair_ranking.py QUOTES_FILE METADATA_FILE NUMBA_CACHE_DIR")
    quotes_path, metadata_path, _ = map(Path, sys.argv[1:])
    print(json.dumps({"type": "progress", "message": "Запускаю Numba-расчёт пар…"}), flush=True)

    metadata = json.loads(metadata_path.read_text(encoding="utf-8"))
    symbols = metadata["symbols"]
    counts = np.asarray(metadata["counts"], dtype=np.int64)
    if len(symbols) != len(counts) or np.any(counts < 0):
        raise ValueError("Invalid quote metadata")
    offsets = np.zeros(len(counts), dtype=np.int64)
    if len(counts) > 1:
        offsets[1:] = np.cumsum(counts[:-1])
    record_count = int(counts.sum())
    if quotes_path.stat().st_size != record_count * 24:
        raise ValueError("Packed quote file size does not match metadata")
    quote_dtype = np.dtype([("timestamp", "<u8"), ("bid", "<f8"), ("ask", "<f8")])
    if record_count:
        quotes = np.memmap(quotes_path, dtype=quote_dtype, mode="r", shape=(record_count,))
        timestamps, bids, asks = quotes["timestamp"], quotes["bid"], quotes["ask"]
    else:
        quotes = np.empty(0, dtype=quote_dtype)
        timestamps, bids, asks = quotes["timestamp"], quotes["bid"], quotes["ask"]

    symbol_count = len(symbols)
    ordered_pair_count = symbol_count * max(0, symbol_count - 1)
    chunk_size = 64
    windows = metadata.get("windows", [])
    for long_index in range(symbol_count):
        for short_start in range(0, symbol_count, chunk_size):
            short_end = min(symbol_count, short_start + chunk_size)
            metrics = _calculate_metrics_chunk_kernel(
                timestamps,
                bids,
                asks,
                offsets,
                counts,
                long_index,
                short_start,
                short_end,
                float(metadata["balance"]),
                float(metadata["fee_rate"]),
                9_223_372_036_854_775_807,
                np.uint64(0),
                np.uint64(18446744073709551615),
            )
            worst_interval_values = np.full(short_end-short_start, np.nan)
            worst_interval_maximums = np.full(short_end-short_start, np.nan)
            worst_interval_drawdowns = np.full(short_end-short_start, np.nan)
            worst_interval_indices = np.full(short_end-short_start, -1, dtype=np.int64)
            minimum_interval_ratios = np.full(short_end-short_start, np.nan)
            minimum_interval_ratio_indices = np.full(short_end-short_start, -1, dtype=np.int64)
            maximum_interval_ratios = np.full(short_end-short_start, np.nan)
            maximum_interval_ratio_indices = np.full(short_end-short_start, -1, dtype=np.int64)
            maximum_interval_drawdowns = np.full(short_end-short_start, np.nan)
            maximum_interval_drawdown_indices = np.full(short_end-short_start, -1, dtype=np.int64)
            maximum_interval_drawdown_peaks = np.full(short_end-short_start, np.nan)
            for window_index, window in enumerate(windows):
                part = _calculate_metrics_chunk_kernel(
                    timestamps, bids, asks, offsets, counts, long_index,
                    short_start, short_end, float(metadata["balance"]),
                    float(metadata["fee_rate"]), 9_223_372_036_854_775_807,
                    np.uint64(window["start_ms"]), np.uint64(window["end_ms"]),
                )
                for offset, interval_net in enumerate(part[:, 0]):
                    if math.isnan(float(interval_net)):
                        continue
                    if math.isnan(float(worst_interval_values[offset])) or interval_net < worst_interval_values[offset]:
                        worst_interval_values[offset] = interval_net
                        worst_interval_maximums[offset] = part[offset, 1]
                        worst_interval_drawdowns[offset] = part[offset, 2]
                        worst_interval_indices[offset] = window_index
                    interval_drawdown = float(part[offset, 2])
                    interval_maximum = float(part[offset, 1])
                    if not math.isnan(interval_drawdown) and (math.isnan(float(maximum_interval_drawdowns[offset])) or interval_drawdown > maximum_interval_drawdowns[offset]):
                        maximum_interval_drawdowns[offset] = interval_drawdown
                        maximum_interval_drawdown_indices[offset] = window_index
                        maximum_interval_drawdown_peaks[offset] = part[offset, 3]
                    if math.isnan(interval_drawdown) or math.isnan(interval_maximum) or interval_drawdown <= 0:
                        continue
                    interval_ratio = interval_maximum / interval_drawdown
                    current_minimum_ratio = minimum_interval_ratios[offset]
                    if math.isnan(float(current_minimum_ratio)) or interval_ratio < current_minimum_ratio:
                        minimum_interval_ratios[offset] = interval_ratio
                        minimum_interval_ratio_indices[offset] = window_index
                    current_maximum_ratio = maximum_interval_ratios[offset]
                    if math.isnan(float(current_maximum_ratio)) or interval_ratio > current_maximum_ratio:
                        maximum_interval_ratios[offset] = interval_ratio
                        maximum_interval_ratio_indices[offset] = window_index
            print(
                json.dumps(
                    {
                        "type": "chunk",
                        "long_index": long_index,
                        "short_start": short_start,
                        "values": [None if math.isnan(float(value)) else float(value) for value in metrics[:,0]],
                        "maximums": [None if math.isnan(float(value)) else float(value) for value in metrics[:,1]],
                        "drawdowns": [None if math.isnan(float(value)) else float(value) for value in metrics[:,2]],
                        "drawdown_peaks": [None if math.isnan(float(value)) else float(value) for value in metrics[:,3]],
                        "worst_interval_values": [None if math.isnan(float(value)) else float(value) for value in worst_interval_values],
                        "worst_interval_maximums": [None if math.isnan(float(value)) else float(value) for value in worst_interval_maximums],
                        "worst_interval_drawdowns": [None if math.isnan(float(value)) else float(value) for value in worst_interval_drawdowns],
                        "worst_interval_indices": [int(value) for value in worst_interval_indices],
                        "minimum_interval_ratios": [None if math.isnan(float(value)) else float(value) for value in minimum_interval_ratios],
                        "minimum_interval_ratio_indices": [int(value) for value in minimum_interval_ratio_indices],
                        "maximum_interval_ratios": [None if math.isnan(float(value)) else float(value) for value in maximum_interval_ratios],
                        "maximum_interval_ratio_indices": [int(value) for value in maximum_interval_ratio_indices],
                        "maximum_interval_drawdowns": [None if math.isnan(float(value)) else float(value) for value in maximum_interval_drawdowns],
                        "maximum_interval_drawdown_indices": [int(value) for value in maximum_interval_drawdown_indices],
                        "maximum_interval_drawdown_peaks": [None if math.isnan(float(value)) else float(value) for value in maximum_interval_drawdown_peaks],
                    },
                    separators=(",", ":"),
                    allow_nan=False,
                ),
                flush=True,
            )
    print(json.dumps({"type": "done", "ordered_pair_count": ordered_pair_count}), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
