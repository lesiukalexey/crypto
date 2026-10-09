"""Durable standard-ranking states shared by ticker and portfolio rankings."""

from __future__ import annotations

import errno
import hashlib
import json
import os
import shutil
import struct
import zlib
from datetime import date, datetime, time as day_time, timedelta, timezone
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

import numpy as np


STATE_RECORD = struct.Struct("<dIIiB")
STATE_MAGIC = b"BGCKPT1\0"
CHECKPOINT_MIN_FREE_BYTES = 1024**3
STATE_DTYPE = np.dtype([
    ("balance", "<f8"),
    ("signal_from", "<u4"),
    ("closed_trades", "<u4"),
    ("open_entry_index", "<i4"),
    ("open_is_short", "u1"),
])
KYIV = ZoneInfo("Europe/Kyiv")


def daily_quote_boundaries(timestamps: np.ndarray, start_day: str, end_day: str) -> list[tuple[str, int]]:
    """Return each Kyiv day and the number of quotes available through its end."""
    first = date.fromisoformat(start_day)
    last = date.fromisoformat(end_day)
    boundaries: list[tuple[str, int]] = []
    current = first
    while current <= last:
        next_midnight = datetime.combine(current + timedelta(days=1), day_time.min, KYIV)
        utc_boundary = next_midnight.astimezone(timezone.utc).timestamp()
        quote_count = int(np.searchsorted(timestamps, utc_boundary, side="left"))
        boundaries.append((current.isoformat(), quote_count))
        current += timedelta(days=1)
    return boundaries


def state_checkpoint_path(state: dict[str, Any], symbol: str, entry_id: str) -> Path:
    args = state["args"]
    key = hashlib.sha256(json.dumps([
        "portfolio-closed-state-v6-canonical-ledger",
        state["category"], symbol, args.balance, args.fee_percent,
        args.ranking_params, args.martingale_mode,
        args.martingale_timing, args.martingale_attempts, entry_id,
    ], separators=(",", ":")).encode("utf-8")).hexdigest()
    return state["checkpoint_root"] / key[:2] / f"{key}.bin.z"


def legacy_state_checkpoint_paths(state: dict[str, Any], symbol: str, entry_id: str) -> list[Path]:
    """Return pre-v6 checkpoint paths, whose identity unnecessarily included SAVE MODE."""
    args = state["args"]
    paths = []
    for min_trades in (1, 2):
        key = hashlib.sha256(json.dumps([
            "portfolio-closed-state-v5-canonical-ledger",
            state["category"], symbol, args.balance, args.fee_percent,
            min_trades, args.ranking_params, args.martingale_mode,
            args.martingale_timing, args.martingale_attempts, entry_id,
        ], separators=(",", ":")).encode("utf-8")).hexdigest()
        paths.append(state["checkpoint_root"] / key[:2] / f"{key}.bin.z")
    return paths


def dated_state_checkpoint_path(state: dict[str, Any], symbol: str, entry_id: str, end_day: str) -> Path:
    base = state_checkpoint_path(state, symbol, entry_id)
    day = date.fromisoformat(end_day).strftime("%Y%m%d")
    return base.with_name(f"{base.name.removesuffix('.bin.z')}-{day}.bin.z")


def dated_state_checkpoint_candidates(state: dict[str, Any], symbol: str, entry_id: str, end_day: str) -> list[Path]:
    day = date.fromisoformat(end_day).strftime("%Y%m%d")
    bases = [state_checkpoint_path(state, symbol, entry_id), *legacy_state_checkpoint_paths(state, symbol, entry_id)]
    return [base.with_name(f"{base.name.removesuffix('.bin.z')}-{day}.bin.z") for base in bases]


def has_dated_state_checkpoint(state: dict[str, Any], symbol: str, entry_id: str, end_day: str) -> bool:
    return any(path.is_file() for path in dated_state_checkpoint_candidates(state, symbol, entry_id, end_day))


def _checkpoint_end_day(path: Path) -> str | None:
    try:
        with path.open("rb") as checkpoint_file:
            if checkpoint_file.read(len(STATE_MAGIC)) != STATE_MAGIC:
                return None
            header_size = struct.unpack("<I", checkpoint_file.read(4))[0]
            if header_size > 1024 * 1024:
                return None
            header = json.loads(checkpoint_file.read(header_size))
        return str(header["end_day"])
    except (OSError, ValueError, KeyError, TypeError, struct.error, json.JSONDecodeError):
        return None


def load_best_state_checkpoint(
    state: dict[str, Any], symbol: str, entry_id: str,
    end_day: str, quotes: Any, expected_records: int,
) -> tuple[np.ndarray, int, str] | None:
    bases = [state_checkpoint_path(state, symbol, entry_id), *legacy_state_checkpoint_paths(state, symbol, entry_id)]
    candidates = []
    for base in bases:
        stem = base.name.removesuffix(".bin.z")
        candidates.extend([base, *base.parent.glob(f"{stem}-*.bin.z")])
    dated = []
    for path in candidates:
        checkpoint_day = _checkpoint_end_day(path)
        if checkpoint_day is not None and checkpoint_day <= end_day:
            try:
                dated.append((checkpoint_day, path.stat().st_mtime_ns, path))
            except OSError:
                continue
    for _, _, path in sorted(dated, key=lambda item: (item[0], item[1]), reverse=True):
        loaded = load_state_checkpoint(path, end_day, quotes, expected_records)
        if loaded is not None:
            return loaded[0], loaded[1], _checkpoint_end_day(path) or end_day
    return None


def quote_prefix_fingerprint(quotes: Any, count: int) -> str:
    digest = hashlib.sha256()
    for values in (quotes.bids, quotes.asks, quotes.lasts, quotes.timestamps):
        digest.update(np.ascontiguousarray(values[:count], dtype=np.float64).view(np.uint8))
    return digest.hexdigest()


def load_state_checkpoint(
    path: Path, end_day: str, quotes: Any, expected_records: int,
) -> tuple[np.ndarray, int] | None:
    try:
        payload = path.read_bytes()
        if not payload.startswith(STATE_MAGIC):
            return None
        header_size = struct.unpack_from("<I", payload, len(STATE_MAGIC))[0]
        header_start = len(STATE_MAGIC) + 4
        header = json.loads(payload[header_start:header_start + header_size])
        checkpoint_quote_count = int(header.get("quote_count", -1))
        if (header.get("version") != 1
            or header.get("end_day", "") > end_day
            or checkpoint_quote_count < 1
            or checkpoint_quote_count > len(quotes)
            or header.get("quote_prefix_sha256") != quote_prefix_fingerprint(quotes, checkpoint_quote_count)
            or int(header.get("record_count", -1)) != expected_records):
            return None
        raw = zlib.decompress(payload[header_start + header_size:])
        if len(raw) != STATE_RECORD.size * expected_records:
            return None
        return np.frombuffer(raw, dtype=STATE_DTYPE), checkpoint_quote_count
    except (OSError, ValueError, TypeError, KeyError, struct.error, zlib.error, json.JSONDecodeError):
        return None


def load_exact_state_checkpoint(
    state: dict[str, Any], symbol: str, entry_id: str, day: str,
    quotes: Any, expected_records: int, expected_quote_count: int | None = None,
) -> np.ndarray | None:
    """Load the state at a canonical day boundary, if its quote prefix still matches."""
    for path in dated_state_checkpoint_candidates(state, symbol, entry_id, day):
        loaded = load_state_checkpoint(path, day, quotes, expected_records)
        if loaded is None or _checkpoint_end_day(path) != day:
            continue
        if expected_quote_count is not None and loaded[1] != expected_quote_count:
            continue
        return loaded[0]
    return None


def write_state_checkpoint(
    path: Path, end_day: str, quotes: Any,
    states: list[tuple[float, int, int, int, bool]] | np.ndarray,
    quote_count: int | None = None,
) -> bool:
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    if shutil.disk_usage(path.parent).free < CHECKPOINT_MIN_FREE_BYTES:
        return False
    saved_quote_count = len(quotes) if quote_count is None else int(quote_count)
    if saved_quote_count < 1 or saved_quote_count > len(quotes):
        return False
    record_count = len(states)
    header = json.dumps({
        "version": 1, "end_day": end_day, "quote_count": saved_quote_count,
        "quote_prefix_sha256": quote_prefix_fingerprint(quotes, saved_quote_count),
        "record_count": record_count,
    }, separators=(",", ":")).encode("utf-8")
    if isinstance(states, np.ndarray):
        if states.dtype != STATE_DTYPE:
            return False
        raw = states.tobytes(order="C")
    else:
        raw_buffer = bytearray(STATE_RECORD.size * record_count)
        for index, state in enumerate(states):
            STATE_RECORD.pack_into(raw_buffer, index * STATE_RECORD.size, *state)
        raw = bytes(raw_buffer)
    payload = STATE_MAGIC + struct.pack("<I", len(header)) + header + zlib.compress(raw, level=6)
    temporary_path = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    try:
        temporary_path.write_bytes(payload)
        temporary_path.replace(path)
    except OSError as exc:
        if exc.errno == errno.ENOSPC:
            return False
        raise
    finally:
        temporary_path.unlink(missing_ok=True)
    return True
