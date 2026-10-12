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
def _calculate_chunk_kernel(
    timestamps,
    bids,
    asks,
    offsets,
    counts,
    long_index,
    short_start,
    short_end,
    starting_balance,
    fee_rate,
    max_age_ms,
):
    output = np.empty(short_end - short_start, dtype=np.float64)
    output[:] = np.nan
    long_offset = offsets[long_index]
    long_count = counts[long_index]
    if long_count == 0:
        return output

    for output_index, short_index in enumerate(range(short_start, short_end)):
        if long_index == short_index:
            continue
        short_offset = offsets[short_index]
        short_count = counts[short_index]
        if short_count == 0:
            continue

        long_cursor = 0
        short_cursor = 0
        long_quote_index = -1
        short_quote_index = -1
        entry_long_index = -1
        entry_short_index = -1

        while long_cursor < long_count or short_cursor < short_count:
            next_long_time = (
                timestamps[long_offset + long_cursor]
                if long_cursor < long_count
                else np.uint64(18446744073709551615)
            )
            next_short_time = (
                timestamps[short_offset + short_cursor]
                if short_cursor < short_count
                else np.uint64(18446744073709551615)
            )
            timestamp = min(next_long_time, next_short_time)
            while long_cursor < long_count and timestamps[long_offset + long_cursor] <= timestamp:
                long_quote_index = long_offset + long_cursor
                long_cursor += 1
            while short_cursor < short_count and timestamps[short_offset + short_cursor] <= timestamp:
                short_quote_index = short_offset + short_cursor
                short_cursor += 1
            if long_quote_index < 0 or short_quote_index < 0:
                continue
            if (
                timestamp - timestamps[long_quote_index] <= max_age_ms
                and timestamp - timestamps[short_quote_index] <= max_age_ms
                and min(
                    bids[long_quote_index],
                    asks[long_quote_index],
                    bids[short_quote_index],
                    asks[short_quote_index],
                ) > 0.0
            ):
                entry_long_index = long_quote_index
                entry_short_index = short_quote_index
                break

        if entry_long_index < 0 or entry_short_index < 0:
            continue

        long_local_index = long_count - 1
        short_local_index = short_count - 1
        last_long_index = long_offset + long_local_index
        last_short_index = short_offset + short_local_index
        while long_local_index >= 0 or short_local_index >= 0:
            long_time = timestamps[last_long_index] if long_local_index >= 0 else np.uint64(0)
            short_time = timestamps[last_short_index] if short_local_index >= 0 else np.uint64(0)
            timestamp = max(long_time, short_time)
            if (
                long_local_index >= 0
                and short_local_index >= 0
                and timestamp - long_time <= max_age_ms
                and timestamp - short_time <= max_age_ms
                and min(
                    bids[last_long_index],
                    asks[last_long_index],
                    bids[last_short_index],
                    asks[last_short_index],
                ) > 0.0
            ):
                break
            while long_local_index >= 0 and timestamps[last_long_index] == timestamp:
                long_local_index -= 1
                last_long_index = long_offset + long_local_index if long_local_index >= 0 else -1
            while short_local_index >= 0 and timestamps[last_short_index] == timestamp:
                short_local_index -= 1
                last_short_index = short_offset + short_local_index if short_local_index >= 0 else -1

        if long_local_index < 0 or short_local_index < 0:
            continue

        long_notional = (starting_balance / 2.0) / (1.0 + fee_rate)
        short_notional = (starting_balance / 2.0) / (1.0 + fee_rate)
        long_quantity = long_notional / asks[entry_long_index]
        short_quantity = short_notional / bids[entry_short_index]
        entry_fees = (long_notional + short_notional) * fee_rate
        gross_pnl = (
            (bids[last_long_index] - asks[entry_long_index]) * long_quantity
            + (bids[entry_short_index] - asks[last_short_index]) * short_quantity
        )
        exit_fees = (
            bids[last_long_index] * long_quantity + asks[last_short_index] * short_quantity
        ) * fee_rate
        output[output_index] = gross_pnl - entry_fees - exit_fees
    return output


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
    for long_index in range(symbol_count):
        for short_start in range(0, symbol_count, chunk_size):
            short_end = min(symbol_count, short_start + chunk_size)
            values = _calculate_chunk_kernel(
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
                120_000,
            )
            print(
                json.dumps(
                    {
                        "type": "chunk",
                        "long_index": long_index,
                        "short_start": short_start,
                        "values": [None if math.isnan(float(value)) else float(value) for value in values],
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
