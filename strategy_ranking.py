#!/usr/bin/env python3
"""Rank fixed take-profit/stop-loss combinations for the quote replay."""

from __future__ import annotations

import argparse
import bisect
import fcntl
import heapq
import json
import math
import os
import re
import sys
import time
from dataclasses import dataclass
from datetime import date, datetime, time as day_time, timedelta, timezone
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

import numpy as np
import pymysql

from app import load_config
from database import connect_mysql
from martingale import martingale_base_notional
from ranking_checkpoints import STATE_DTYPE, daily_quote_boundaries, dated_state_checkpoint_path, load_best_state_checkpoint, write_state_checkpoint

TOP_PER_GROUP = 500
KYIV = ZoneInfo("Europe/Kyiv")
EXIT_PROFILE_CONFIG = json.loads(Path(__file__).with_name("exit_profiles.json").read_text(encoding="utf-8"))


def exit_values(family: str, profiles: dict[str, Any] | None = None) -> range:
    profile = (profiles or EXIT_PROFILE_CONFIG)[family]
    start = profile.get("from", profile.get("min_tenths_percent"))
    end = profile.get("to", profile.get("max_tenths_percent"))
    step = profile.get("step", profile.get("step_tenths_percent"))
    return range(int(start), int(end) + 1, int(step))


class PriceTree:
    """Segment tree that locates the first price crossing a threshold."""

    def __init__(self, values: list[float]) -> None:
        size = 1
        while size < len(values):
            size *= 2
        self.size = size
        self.length = len(values)
        maximum = [-math.inf] * (size * 2)
        minimum = [math.inf] * (size * 2)
        for index, value in enumerate(values):
            maximum[size + index] = value
            minimum[size + index] = value
        for node in range(size - 1, 0, -1):
            maximum[node] = max(maximum[node * 2], maximum[node * 2 + 1])
            minimum[node] = min(minimum[node * 2], minimum[node * 2 + 1])
        self.maximum = np.asarray(maximum, dtype=np.float64)
        self.minimum = np.asarray(minimum, dtype=np.float64)

    def first_ge(self, start: int, target: float) -> int | None:
        start = max(0, start)
        if start >= self.length:
            return None
        node = self.size + start
        if self.maximum[node] >= target:
            return start
        while node > 1:
            if node % 2 == 0:
                candidate = node + 1
                if self.maximum[candidate] >= target:
                    while candidate < self.size:
                        candidate *= 2
                        if self.maximum[candidate] < target:
                            candidate += 1
                    index = candidate - self.size
                    return index if index < self.length else None
            node //= 2
        return None

    def first_le(self, start: int, target: float) -> int | None:
        start = max(0, start)
        if start >= self.length:
            return None
        node = self.size + start
        if self.minimum[node] <= target:
            return start
        while node > 1:
            if node % 2 == 0:
                candidate = node + 1
                if self.minimum[candidate] <= target:
                    while candidate < self.size:
                        candidate *= 2
                        if self.minimum[candidate] > target:
                            candidate += 1
                    index = candidate - self.size
                    return index if index < self.length else None
            node //= 2
        return None


@dataclass(slots=True)
class QuoteArrays:
    """Numeric quote columns prepared once for a ranking simulation."""

    bids: np.ndarray
    asks: np.ndarray
    lasts: np.ndarray
    timestamps: np.ndarray

    def __post_init__(self) -> None:
        self.bids = np.asarray(self.bids, dtype=np.float64)
        self.asks = np.asarray(self.asks, dtype=np.float64)
        self.lasts = np.asarray(self.lasts, dtype=np.float64)
        self.timestamps = np.asarray(self.timestamps, dtype=np.float64)

    @classmethod
    def from_rows(cls, rows: list[dict[str, Any]]) -> "QuoteArrays":
        return cls(
            np.fromiter((float(row["bid_price"]) for row in rows), dtype=np.float64, count=len(rows)),
            np.fromiter((float(row["ask_price"]) for row in rows), dtype=np.float64, count=len(rows)),
            np.fromiter((float(row["last_price"]) for row in rows), dtype=np.float64, count=len(rows)),
            np.fromiter((row["received_at_utc"].replace(tzinfo=timezone.utc).timestamp() for row in rows), dtype=np.float64, count=len(rows)),
        )

    def __len__(self) -> int:
        return len(self.bids)


def write_json(path: Path, payload: dict[str, Any]) -> None:
    temporary = path.with_suffix(path.suffix + f".{os.getpid()}.tmp")
    temporary.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
    temporary.replace(path)


class ProgressReporter:
    """Throttle intermediate atomic progress writes while leaving final status immediate."""

    def __init__(self, interval_seconds: float = 1.0) -> None:
        self.interval_seconds = interval_seconds
        self.last_write = time.monotonic()

    def update(self, path: Path, payload: dict[str, Any]) -> bool:
        now = time.monotonic()
        if now - self.last_write < self.interval_seconds:
            return False
        write_json(path, payload)
        self.last_write = now
        return True


def make_signals(quotes: QuoteArrays, threshold: float, lookback: int, direction: str = "both") -> tuple[list[int], list[bool]]:
    if direction not in ("both", "long", "short"):
        raise ValueError("Invalid entry direction")
    count = len(quotes)
    gaps = [0.0] + [quotes.timestamps[index] - quotes.timestamps[index - 1] for index in range(1, count)]
    continuous = [False] * count
    for index in range(lookback, count):
        continuous[index] = all(gaps[j] <= 120 for j in range(index - lookback + 1, index + 1))

    indexes: list[int] = []
    directions: list[bool] = []
    for index in range(lookback + 1, count - 1):
        if not continuous[index] or not continuous[index - 1]:
            continue
        current_base = quotes.lasts[index - lookback]
        previous_base = quotes.lasts[index - lookback - 1]
        if current_base <= 0 or previous_base <= 0:
            continue
        movement = (quotes.lasts[index] / current_base - 1.0) * 100.0
        previous_movement = (quotes.lasts[index - 1] / previous_base - 1.0) * 100.0
        if direction != "short" and movement >= threshold and previous_movement < threshold:
            indexes.append(index)
            directions.append(False)
        elif direction != "long" and movement <= -threshold and previous_movement > -threshold:
            indexes.append(index)
            directions.append(True)
    return np.asarray(indexes, dtype=np.int64), np.asarray(directions, dtype=np.bool_)


def make_signal_profiles(
    quotes: QuoteArrays,
    ranking: dict[str, Any],
) -> dict[tuple[int, str, int], tuple[list[int], list[bool]]]:
    """Build every configured momentum signal from one quote scan per window."""
    count = len(quotes)
    profiles = ranking["entry_profiles"]
    thresholds = {
        family: sorted({int(value) for value in profile["thresholds_tenths_percent"]})
        for family, profile in profiles.items()
    }
    threshold_values = {
        family: [value / 10 for value in values]
        for family, values in thresholds.items()
    }
    signals = {
        (lookback, family, cents): ([], [])
        for family, values in thresholds.items()
        for lookback in {int(window) for window in profiles[family]["windows"]}
        for cents in values
    }
    windows = sorted({int(window) for profile in profiles.values() for window in profile["windows"]})
    if count < 2:
        return signals

    gaps = [0.0] + [quotes.timestamps[index] - quotes.timestamps[index - 1] for index in range(1, count)]
    for lookback in windows:
        continuous = [False] * count
        for index in range(lookback, count):
            continuous[index] = all(gaps[j] <= 120 for j in range(index - lookback + 1, index + 1))

        for index in range(lookback + 1, count - 1):
            if not continuous[index] or not continuous[index - 1]:
                continue
            current_base = quotes.lasts[index - lookback]
            previous_base = quotes.lasts[index - lookback - 1]
            if current_base <= 0 or previous_base <= 0:
                continue
            movement = (quotes.lasts[index] / current_base - 1.0) * 100.0
            previous_movement = (quotes.lasts[index - 1] / previous_base - 1.0) * 100.0
            for family, values in thresholds.items():
                candidates = threshold_values[family]
                if family != "short":
                    first = bisect.bisect_right(candidates, previous_movement)
                    after_last = bisect.bisect_right(candidates, movement)
                    for candidate_index in range(first, after_last):
                        threshold_value = thresholds[family][candidate_index]
                        signals[(lookback, family, threshold_value)][0].append(index)
                        signals[(lookback, family, threshold_value)][1].append(False)
                if family != "long":
                    first = bisect.bisect_right(candidates, -previous_movement)
                    after_last = bisect.bisect_right(candidates, -movement)
                    for candidate_index in range(first, after_last):
                        threshold_value = thresholds[family][candidate_index]
                        signals[(lookback, family, threshold_value)][0].append(index)
                        signals[(lookback, family, threshold_value)][1].append(True)
    return {
        key: (np.asarray(indexes, dtype=np.int64), np.asarray(directions, dtype=np.bool_))
        for key, (indexes, directions) in signals.items()
    }


def build_entry_specs(ranking: dict[str, Any]) -> list[tuple[str, str, int | None, int | None, str | None, bool | None]]:
    specs: list[tuple[str, str, int | None, int | None, str | None, bool | None]] = []
    labels = {
        "both": lambda amount: f"±{amount:.1f}% от стартового баланса",
        "long": lambda amount: f"+{amount:.1f}% от стартового баланса при росте",
        "short": lambda amount: f"−{amount:.1f}% от стартового баланса при падении",
    }
    for family in ("both", "long", "short"):
        profile = ranking["entry_profiles"][family]
        prefix = "entry" if family == "both" else f"entry:{family}"
        for window in profile["windows"]:
            for threshold in profile["thresholds_tenths_percent"]:
                suffix = f"{window}:{threshold}"
                specs.append((
                    f"{prefix}:{suffix}",
                    f"{labels[family](threshold / 10)} за {window} снимков",
                    int(window),
                    int(threshold),
                    family,
                    None,
                ))
    for direction in ranking["immediate_directions"]:
        is_short = direction == "short"
        specs.append((
            f"immediate:{direction}",
            f"Сразу · 1 заявка на {'продажу' if is_short else 'покупку'}",
            None,
            None,
            None,
            is_short,
        ))
    return specs


def resolve_entry_config(entry_config: str, ranking: dict[str, Any]) -> dict[str, Any] | None:
    if entry_config.startswith("immediate:"):
        direction = entry_config.removeprefix("immediate:")
        if direction in ranking["immediate_directions"] and direction in ("long", "short"):
            return {"type": "immediate", "direction": direction}
        return None
    match = re.fullmatch(r"entry(?::(long|short))?:(\d+):(\d+)", entry_config)
    if match is None:
        return None
    family = match.group(1) or "both"
    window = int(match.group(2))
    threshold = int(match.group(3))
    profile = ranking["entry_profiles"].get(family)
    if not isinstance(profile, dict) or window not in profile["windows"] or threshold not in profile["thresholds_tenths_percent"]:
        return None
    return {"type": "momentum", "family": family, "window": window, "threshold_tenths_percent": threshold}


def simulate(
    quotes: QuoteArrays,
    signal_indexes: list[int],
    signal_shorts: list[bool],
    bid_tree: PriceTree,
    ask_tree: PriceTree,
    start_balance: float,
    fee_rate: float,
    take_profit: float | None,
    stop_loss: float | None,
    martingale_mode: str = "none",
    martingale_timing: str = "immediate",
    martingale_attempts: int = 3,
    martingale_target_policy: str = "scaled",
) -> tuple[float, int]:
    indexes = signal_indexes if isinstance(signal_indexes, np.ndarray) else np.asarray(signal_indexes, dtype=np.int64)
    shorts = signal_shorts if isinstance(signal_shorts, np.ndarray) else np.asarray(signal_shorts, dtype=np.bool_)
    # TP/SL candidates are tenths of one percent of the starting balance.
    # Convert once to a fixed USDT amount; accumulated balance never changes it.
    take_profit_amount = None if take_profit is None else start_balance * take_profit / 1000.0
    stop_loss_amount = None if stop_loss is None else start_balance * stop_loss / 1000.0

    if martingale_mode != "none":
        from numba_simulation import simulate_martingale_numba

        if martingale_mode not in ("simple", "reverse") or martingale_timing not in ("immediate", "rules") or not 2 <= martingale_attempts <= 10:
            raise ValueError("Invalid martingale options")
        if martingale_target_policy not in ("scaled", "fixed"):
            raise ValueError("Invalid martingale exit target policy")
        return simulate_martingale_numba(
            quotes.bids, quotes.asks,
            bid_tree.maximum, bid_tree.minimum, ask_tree.maximum, ask_tree.minimum,
            bid_tree.size, bid_tree.length, indexes, shorts,
            start_balance, fee_rate,
            np.nan if take_profit_amount is None else take_profit_amount,
            np.nan if stop_loss_amount is None else stop_loss_amount,
            martingale_base_notional(
                start_balance, fee_rate, stop_loss_amount, martingale_mode, martingale_attempts, martingale_target_policy,
            ),
            0 if martingale_mode == "simple" else 1,
            0 if martingale_timing == "immediate" else 1,
            martingale_attempts,
            0 if martingale_target_policy == "scaled" else 1,
        )

    from numba_simulation import simulate_numba

    return simulate_numba(
        quotes.bids,
        quotes.asks,
        bid_tree.maximum,
        bid_tree.minimum,
        ask_tree.maximum,
        ask_tree.minimum,
        bid_tree.size,
        bid_tree.length,
        indexes,
        shorts,
        start_balance,
        fee_rate,
        np.nan if take_profit_amount is None else take_profit_amount,
        np.nan if stop_loss_amount is None else stop_loss_amount,
    )


def simulate_standard_checkpoint(
    quotes: QuoteArrays,
    signal_indexes: list[int] | np.ndarray,
    signal_shorts: list[bool] | np.ndarray,
    bid_tree: PriceTree,
    ask_tree: PriceTree,
    start_balance: float,
    fee_rate: float,
    take_profit: float | None,
    stop_loss: float | None,
    checkpoint: tuple[float, int, int, int, bool],
) -> tuple[float, int, tuple[float, int, int, int, bool]]:
    """Resume a non-martingale simulation from its last closed-trade boundary."""
    from numba_simulation import simulate_standard_stateful_numba

    indexes = signal_indexes if isinstance(signal_indexes, np.ndarray) else np.asarray(signal_indexes, dtype=np.int64)
    shorts = signal_shorts if isinstance(signal_shorts, np.ndarray) else np.asarray(signal_shorts, dtype=np.bool_)
    take_profit_amount = None if take_profit is None else start_balance * take_profit / 1000.0
    stop_loss_amount = None if stop_loss is None else start_balance * stop_loss / 1000.0
    pnl, trade_count, balance, signal_from, open_entry_index, open_is_short = simulate_standard_stateful_numba(
        quotes.bids,
        quotes.asks,
        bid_tree.maximum,
        bid_tree.minimum,
        ask_tree.maximum,
        ask_tree.minimum,
        bid_tree.size,
        bid_tree.length,
        indexes,
        shorts,
        start_balance,
        fee_rate,
        np.nan if take_profit_amount is None else take_profit_amount,
        np.nan if stop_loss_amount is None else stop_loss_amount,
        float(checkpoint[0]),
        int(checkpoint[1]),
        int(checkpoint[2]),
        int(checkpoint[3]),
        bool(checkpoint[4]),
    )
    state = (float(balance), int(signal_from), int(trade_count if open_entry_index < 0 else trade_count - 1), int(open_entry_index), bool(open_is_short))
    return float(pnl), int(trade_count), state


def simulate_standard_checkpoint_days(
    quotes: QuoteArrays,
    signal_indexes: list[int] | np.ndarray,
    signal_shorts: list[bool] | np.ndarray,
    bid_tree: PriceTree,
    ask_tree: PriceTree,
    start_balance: float,
    fee_rate: float,
    take_profit: float | None,
    stop_loss: float | None,
    day_quote_counts: np.ndarray,
    checkpoint: tuple[float, int, int, int, bool],
) -> tuple[float, int, tuple[float, int, int, int, bool], list[tuple[float, int, int, int, bool]]]:
    """Resume once through the range and capture each supplied day boundary."""
    from numba_simulation import simulate_standard_stateful_days_numba

    indexes = signal_indexes if isinstance(signal_indexes, np.ndarray) else np.asarray(signal_indexes, dtype=np.int64)
    shorts = signal_shorts if isinstance(signal_shorts, np.ndarray) else np.asarray(signal_shorts, dtype=np.bool_)
    take_profit_amount = None if take_profit is None else start_balance * take_profit / 1000.0
    stop_loss_amount = None if stop_loss is None else start_balance * stop_loss / 1000.0
    values = simulate_standard_stateful_days_numba(
        quotes.bids, quotes.asks,
        bid_tree.maximum, bid_tree.minimum, ask_tree.maximum, ask_tree.minimum,
        bid_tree.size, indexes, shorts, start_balance, fee_rate,
        np.nan if take_profit_amount is None else take_profit_amount,
        np.nan if stop_loss_amount is None else stop_loss_amount,
        np.asarray(day_quote_counts, dtype=np.int64),
        float(checkpoint[0]), int(checkpoint[1]), int(checkpoint[2]),
        int(checkpoint[3]), bool(checkpoint[4]),
    )
    pnls, trade_counts, balances, signal_positions, closed_counts, open_indexes, open_shorts = values
    states = [
        (
            float(balances[index]), int(signal_positions[index]), int(closed_counts[index]),
            int(open_indexes[index]), bool(open_shorts[index]),
        )
        for index in range(len(day_quote_counts))
    ]
    final_state = states[-1]
    return float(pnls[-1]), int(trade_counts[-1]), final_state, states


def finalize_standard_checkpoints(
    quotes: QuoteArrays,
    start_balance: float,
    fee_rate: float,
    checkpoints: list[tuple[float, int, int, int, bool]] | np.ndarray,
) -> tuple[np.ndarray, np.ndarray]:
    """Mark saved states at the same quote endpoint without replaying the tree."""
    if len(checkpoints) == 0:
        return np.empty(0, dtype=np.float64), np.empty(0, dtype=np.int64)
    if isinstance(checkpoints, np.ndarray):
        closed_balance = checkpoints["balance"].copy()
        trade_counts = checkpoints["closed_trades"].astype(np.int64)
        open_indexes = checkpoints["open_entry_index"]
        open_shorts = checkpoints["open_is_short"].astype(np.bool_)
    else:
        closed_balance = np.fromiter((state[0] for state in checkpoints), dtype=np.float64, count=len(checkpoints))
        trade_counts = np.fromiter((state[2] for state in checkpoints), dtype=np.int64, count=len(checkpoints))
        open_indexes = np.fromiter((state[3] for state in checkpoints), dtype=np.int64, count=len(checkpoints))
        open_shorts = np.fromiter((state[4] for state in checkpoints), dtype=np.bool_, count=len(checkpoints))
    pnls = closed_balance - start_balance
    open_mask = open_indexes >= 0
    if np.any(open_mask):
        indexes = open_indexes[open_mask]
        is_short = open_shorts[open_mask]
        entry_prices = np.where(is_short, quotes.bids[indexes], quotes.asks[indexes])
        exit_prices = np.where(is_short, quotes.asks[-1], quotes.bids[-1])
        net_ratios = np.where(
            is_short,
            (entry_prices * (1.0 - fee_rate) - exit_prices * (1.0 + fee_rate)) / (entry_prices * (1.0 + fee_rate)),
            (exit_prices * (1.0 - fee_rate) - entry_prices * (1.0 + fee_rate)) / (entry_prices * (1.0 + fee_rate)),
        )
        pnls[open_mask] = closed_balance[open_mask] + start_balance * net_ratios - start_balance
        trade_counts[open_mask] += 1
    return pnls, trade_counts



def martingale_target_policies(mode: str, stop_loss: float | None) -> list[tuple[str, str]]:
    if mode == "none" or stop_loss is None:
        return [("scaled", "")]
    return [
        ("scaled", "TP/SL растут вместе с позицией"),
        ("fixed", "TP/SL рассчитаны от стартового баланса"),
    ]


def saved_standard_group_ids(exit_profiles: dict[str, Any]) -> list[list[str]]:
    return [
        [f"fixed:{target}:{loss}" for target in exit_values("fixed_tp", exit_profiles) for loss in exit_values("fixed_sl", exit_profiles)],
        [f"loss:{loss}" for loss in exit_values("loss", exit_profiles)],
        [f"profit:{target}" for target in exit_values("profit", exit_profiles)],
    ]


def rank_saved_standard_results(
    pnls: np.ndarray,
    trade_counts: np.ndarray,
    min_trades: int,
    groups_specs: list[tuple[str, str, int]],
    group_ids: list[list[str]],
    lexical_ranks: list[np.ndarray],
) -> tuple[list[dict[str, Any]], float]:
    groups = []
    profile_best = -math.inf
    offset = 0
    for (family, label, candidate_count), ids, ranks in zip(groups_specs, group_ids, lexical_ranks):
        group_pnls = pnls[offset:offset + candidate_count]
        group_trades = trade_counts[offset:offset + candidate_count]
        eligible = np.flatnonzero(group_trades >= min_trades)
        items = []
        if eligible.size:
            profile_best = max(profile_best, float(np.max(group_pnls[eligible])))
            order = np.lexsort((ranks[eligible], group_pnls[eligible]))
            selected = eligible[order[-TOP_PER_GROUP:]]
            selected = sorted(selected, key=lambda index: (-float(group_pnls[index]), ids[index]))
            for index in selected:
                exit_id = ids[index]
                value = int(exit_id.rsplit(":", 1)[1])
                if family == "fixed":
                    target = int(exit_id.split(":", 2)[1])
                    display = f"TP {target / 10:.1f}% / SL {value / 10:.1f}% от стартового баланса"
                elif family == "loss":
                    display = f"Stop Loss {value / 10:.1f}% от стартового баланса · без фиксации прибыли"
                else:
                    display = f"Take Profit {value / 10:.1f}% от стартового баланса · без фиксации убытка"
                pnl = float(group_pnls[index])
                items.append({
                    "id": exit_id,
                    "label": f"{display} · {int(group_trades[index])} сделок · итог {pnl:+.4f} USDT",
                })
        groups.append({
            "label": label + f" · топ {min(TOP_PER_GROUP, len(items))} из {candidate_count}",
            "items": items,
        })
        offset += candidate_count
    return groups, profile_best


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--cache-key", required=True)
    parser.add_argument("--symbol", required=True)
    parser.add_argument("--start-day", required=True)
    parser.add_argument("--end-day", required=True)
    parser.add_argument("--balance", required=True, type=float)
    parser.add_argument("--fee-percent", required=True, type=float)
    parser.add_argument("--min-trades", required=True, type=int, choices=(1, 2))
    parser.add_argument("--max-id", required=True, type=int)
    parser.add_argument("--cache-dir", required=True, type=Path)
    parser.add_argument("--ranking-params", required=True)
    parser.add_argument("--martingale-mode", choices=("none", "simple", "reverse"), default="none")
    parser.add_argument("--martingale-timing", choices=("immediate", "rules"), default="immediate")
    parser.add_argument("--martingale-attempts", type=int, choices=range(2, 11), default=3)
    args = parser.parse_args()
    ranking = json.loads(args.ranking_params)
    exit_profiles = ranking["exit_profiles"]

    status_path = args.cache_dir / f"{args.cache_key}.status.json"
    result_path = args.cache_dir / f"{args.cache_key}.json"
    job_lock = (args.cache_dir / f"strategy-worker-{args.cache_key}.lock").open("a", encoding="utf-8")
    while True:
        try:
            fcntl.flock(job_lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            break
        except BlockingIOError:
            return 0
    if result_path.is_file():
        try:
            cached_result = json.loads(result_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            cached_result = None
        if isinstance(cached_result, dict) and cached_result.get("status") == "ready":
            write_json(status_path, {"status": "ready", "progress": 100})
            return 0
    worker_lock = (args.cache_dir / "ranking-worker.lock").open("a", encoding="utf-8")
    while True:
        try:
            fcntl.flock(worker_lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            break
        except BlockingIOError:
            write_json(status_path, {"status": "pending", "progress": 0, "queued": True})
            time.sleep(10)
    if result_path.is_file():
        try:
            cached_result = json.loads(result_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            cached_result = None
        if isinstance(cached_result, dict) and cached_result.get("status") == "ready":
            write_json(status_path, {"status": "ready", "progress": 100})
            return 0
    try:
        config = load_config(Path(__file__).with_name("config.json"))
        connection = connect_mysql(config["mysql"])
        try:
            start_local = datetime.combine(date.fromisoformat(args.start_day), day_time.min, KYIV)
            end_local = datetime.combine(date.fromisoformat(args.end_day) + timedelta(days=1), day_time.min, KYIV)
            start_utc = start_local.astimezone(timezone.utc).replace(tzinfo=None)
            end_utc = end_local.astimezone(timezone.utc).replace(tzinfo=None)
            with connection.cursor(pymysql.cursors.DictCursor) as cursor:
                cursor.execute(
                    """SELECT received_at_utc, bid_price, ask_price, last_price
                       FROM quote_snapshots
                       WHERE category = %s AND symbol = %s AND id <= %s
                         AND received_at_utc >= %s AND received_at_utc < %s
                       ORDER BY received_at_utc, id""",
                    (config["category"], args.symbol, args.max_id, start_utc, end_utc),
                )
                rows = cursor.fetchall()
        finally:
            connection.close()
        quotes = QuoteArrays.from_rows(rows)
        del rows
        all_day_boundaries = daily_quote_boundaries(quotes.timestamps, args.start_day, args.end_day)

        groups_specs = [
            ("fixed", "Автоматические · фиксированные Take Profit и Stop Loss", len(exit_values("fixed_tp", exit_profiles)) * len(exit_values("fixed_sl", exit_profiles))),
            ("loss", "Автоматические · фиксированный Stop Loss", len(exit_values("loss", exit_profiles))),
            ("profit", "Автоматические · фиксированный Take Profit", len(exit_values("profit", exit_profiles))),
        ]
        total_per_entry = sum(
            spec[2] * (2 if args.martingale_mode != "none" and spec[0] in ("fixed", "loss") else 1)
            for spec in groups_specs
        )
        entry_specs_for_worker = build_entry_specs(ranking)
        checkpoint_state = {
            "args": args,
            "category": config["category"],
            "checkpoint_root": args.cache_dir.parent / "bitget-simulation-checkpoints" / "portfolio-state-v1",
        }
        checkpoint_group_ids = saved_standard_group_ids(exit_profiles) if args.martingale_mode == "none" else []
        checkpoint_lexical_ranks = []
        for ids in checkpoint_group_ids:
            ranks = np.empty(len(ids), dtype=np.int32)
            for rank, index in enumerate(sorted(range(len(ids)), key=lambda current: ids[current])):
                ranks[index] = rank
            checkpoint_lexical_ranks.append(ranks)
        total = total_per_entry * len(entry_specs_for_worker)
        completed = 0
        checkpoint_result_reuses = 0
        checkpoint_writes = 0
        checkpoint_skips = 0
        started = time.monotonic()
        progress_reporter = ProgressReporter()
        entries: list[dict[str, Any]] = []

        if len(quotes) >= 2:
            bid_tree = PriceTree(quotes.bids)
            ask_tree = PriceTree(quotes.asks)
        else:
            quotes = QuoteArrays([], [], [], [])
            bid_tree = PriceTree([])
            ask_tree = PriceTree([])
        fee_rate = args.fee_percent / 100

        for entry_id, entry_label, lookback, threshold_tenths_percent, entry_family, immediate_short in entry_specs_for_worker:
                if len(quotes) < 2:
                    signals, shorts = np.empty(0, dtype=np.int64), np.empty(0, dtype=np.bool_)
                elif immediate_short is not None:
                    signals, shorts = np.array([0], dtype=np.int64), np.array([immediate_short], dtype=np.bool_)
                else:
                    signals, shorts = make_signals(quotes, int(threshold_tenths_percent) / 10, int(lookback), entry_family or "both")
                if len(signals) == 0 or (
                    args.martingale_mode == "none" and len(signals) < args.min_trades
                ):
                    # Without martingale, each signal can open at most one
                    # trade, so this entry cannot meet the ranking minimum.
                    completed += total_per_entry
                    progress_reporter.update(status_path, {
                        "status": "pending",
                        "progress": min(99, int(completed * 100 / total)),
                        "elapsed_seconds": int(time.monotonic() - started),
                    })
                    continue

                checkpoint_path = None
                previous_states = None
                previous_quote_count = 0
                previous_checkpoint_day = None
                boundaries_to_capture: list[tuple[str, int]] = []
                if args.martingale_mode == "none":
                    checkpoint_path = dated_state_checkpoint_path(checkpoint_state, args.symbol, entry_id, args.end_day)
                    first_missing_day = next((index for index, (day, _) in enumerate(all_day_boundaries)
                        if not dated_state_checkpoint_path(checkpoint_state, args.symbol, entry_id, day).is_file()), None)
                    resume_until_day = (
                        all_day_boundaries[first_missing_day - 1][0]
                        if first_missing_day is not None and first_missing_day > 0
                        else (None if first_missing_day == 0 else args.end_day)
                    )
                    checkpoint = load_best_state_checkpoint(
                        checkpoint_state, args.symbol, entry_id,
                        resume_until_day, quotes, total_per_entry,
                    ) if resume_until_day is not None else None
                    if checkpoint is not None:
                        previous_states, previous_quote_count, previous_checkpoint_day = checkpoint
                    if first_missing_day is None and checkpoint is not None and previous_quote_count == len(quotes):
                        saved_pnls, saved_trades = finalize_standard_checkpoints(
                            quotes, args.balance, fee_rate, previous_states,
                        )
                        groups, best_pnl = rank_saved_standard_results(
                            saved_pnls, saved_trades, args.min_trades,
                            groups_specs, checkpoint_group_ids, checkpoint_lexical_ranks,
                        )
                        write_json(args.cache_dir / f"{args.cache_key}.{entry_id}.json", {"status": "ready", "groups": groups})
                        if math.isfinite(best_pnl):
                            entries.append({
                                "id": entry_id,
                                "best_pnl": best_pnl,
                                "label": f"{entry_label} · лучший результат {best_pnl:+.4f} USDT",
                            })
                        else:
                            (args.cache_dir / f"{args.cache_key}.{entry_id}.json").unlink(missing_ok=True)
                        checkpoint_result_reuses += total_per_entry
                        completed += total_per_entry
                        progress_reporter.update(status_path, {
                            "status": "pending", "progress": min(99, int(completed * 100 / total)),
                            "elapsed_seconds": int(time.monotonic() - started),
                            "checkpoint_result_reuses": checkpoint_result_reuses,
                        })
                        continue
                    boundaries_to_capture = [
                        (day, count) for day, count in all_day_boundaries
                        if count > 0 and (
                            previous_checkpoint_day is None
                            or day > previous_checkpoint_day
                            or (day == previous_checkpoint_day and count > previous_quote_count)
                        )
                    ]

                profile_best = [-math.inf]
                groups: list[dict[str, Any]] = []
                next_states: list[tuple[float, int, int, int, bool]] = []
                states_by_day: dict[str, np.ndarray] = {
                    day: np.empty(total_per_entry, dtype=STATE_DTYPE) for day, _ in boundaries_to_capture
                }
                state_counts_by_day = {day: 0 for day, _ in boundaries_to_capture}
                day_quote_counts_to_capture = np.fromiter((count for _, count in boundaries_to_capture), dtype=np.int64)

                for family, label, candidate_count in groups_specs:
                    family_policies = martingale_target_policies(
                        args.martingale_mode,
                        1.0 if family in ("fixed", "loss") else None,
                    )
                    best_by_policy: dict[str, list[tuple[float, str, str, int]]] = {
                        policy: [] for policy, _ in family_policies
                    }

                    def consider(
                        identifier: str,
                        display_kind: str,
                        target_tenths_percent: int | None,
                        loss_tenths_percent: int | None,
                        target: float | None,
                        loss: float | None,
                    ) -> None:
                        nonlocal completed
                        for target_policy, policy_label in martingale_target_policies(args.martingale_mode, loss):
                            if args.martingale_mode == "none":
                                previous_state = (
                                    previous_states[len(next_states)]
                                    if previous_states is not None else (args.balance, 0, 0, -1, False)
                                )
                                if boundaries_to_capture:
                                    state_index = len(next_states)
                                    pnl, trade_count, next_state, day_states = simulate_standard_checkpoint_days(
                                        quotes, signals, shorts, bid_tree, ask_tree,
                                        args.balance, fee_rate, target, loss,
                                        day_quote_counts_to_capture,
                                        previous_state,
                                    )
                                    for index, (day, _) in enumerate(boundaries_to_capture):
                                        states_by_day[day][state_index] = day_states[index]
                                        state_counts_by_day[day] += 1
                                else:
                                    pnl, trade_count, next_state = simulate_standard_checkpoint(
                                        quotes, signals, shorts, bid_tree, ask_tree,
                                        args.balance, fee_rate, target, loss, previous_state,
                                    )
                                next_states.append(next_state)
                            else:
                                pnl, trade_count = simulate(
                                    quotes, signals, shorts, bid_tree, ask_tree, args.balance, fee_rate,
                                    target, loss, args.martingale_mode, args.martingale_timing,
                                    args.martingale_attempts, target_policy,
                                )
                            if trade_count >= args.min_trades:
                                profile_best[0] = max(profile_best[0], pnl)
                                ranked_id = f"{identifier}:{target_policy}" if policy_label else identifier
                                best = best_by_policy[target_policy]
                                enters_top = len(best) < TOP_PER_GROUP or (pnl, ranked_id) > (best[0][0], best[0][1])
                                if enters_top:
                                    if display_kind == "fixed":
                                        display = f"TP {target_tenths_percent / 10:.1f}% / SL {loss_tenths_percent / 10:.1f}% от стартового баланса"
                                    elif display_kind == "loss":
                                        display = f"Stop Loss {loss_tenths_percent / 10:.1f}% от стартового баланса · без фиксации прибыли"
                                    else:
                                        display = f"Take Profit {target_tenths_percent / 10:.1f}% от стартового баланса · без фиксации убытка"
                                    item = (pnl, ranked_id, display, trade_count)
                                    if len(best) < TOP_PER_GROUP:
                                        heapq.heappush(best, item)
                                    else:
                                        heapq.heapreplace(best, item)
                            completed += 1
                            if completed % 1000 == 0:
                                progress_reporter.update(status_path, {"status": "pending", "progress": int(completed * 100 / total), "elapsed_seconds": int(time.monotonic() - started)})

                    if family == "fixed":
                        for target_tenths_percent in exit_values("fixed_tp", exit_profiles):
                            for loss_tenths_percent in exit_values("fixed_sl", exit_profiles):
                                consider(f"fixed:{target_tenths_percent}:{loss_tenths_percent}", "fixed", target_tenths_percent, loss_tenths_percent, float(target_tenths_percent), float(loss_tenths_percent))
                    elif family == "loss":
                        for loss_tenths_percent in exit_values("loss", exit_profiles):
                            consider(f"loss:{loss_tenths_percent}", "loss", None, loss_tenths_percent, None, float(loss_tenths_percent))
                    else:
                        for target_tenths_percent in exit_values("profit", exit_profiles):
                            consider(f"profit:{target_tenths_percent}", "profit", target_tenths_percent, None, float(target_tenths_percent), None)

                    for target_policy, policy_label in family_policies:
                        best = best_by_policy[target_policy]
                        best.sort(key=lambda current: (-current[0], current[1]))
                        group_label = label + (f" · {policy_label}" if policy_label else "")
                        items = []
                        for pnl, ranked_id, display, trade_count in best:
                            ranked_label = display + (f" · {policy_label}" if policy_label else "")
                            items.append({
                                "id": ranked_id,
                                "label": f"{ranked_label} · {trade_count} сделок · итог {pnl:+.4f} USDT",
                            })
                        groups.append({
                            "label": group_label + f" · топ {min(TOP_PER_GROUP, len(best))} из {candidate_count}",
                            "items": items,
                        })

                write_json(args.cache_dir / f"{args.cache_key}.{entry_id}.json", {"status": "ready", "groups": groups})
                if checkpoint_path is not None and len(next_states) == total_per_entry:
                    for day, day_states in states_by_day.items():
                        day_quote_count = next(count for current_day, count in boundaries_to_capture if current_day == day)
                        day_path = dated_state_checkpoint_path(checkpoint_state, args.symbol, entry_id, day)
                        if state_counts_by_day[day] == total_per_entry and write_state_checkpoint(day_path, day, quotes, day_states, day_quote_count):
                            checkpoint_writes += 1
                        else:
                            checkpoint_skips += 1
                if math.isfinite(profile_best[0]):
                    entries.append({
                    "id": entry_id,
                    "best_pnl": profile_best[0],
                    "label": f"{entry_label} · лучший результат {profile_best[0]:+.4f} USDT",
                    })
                else:
                    # No exit candidate met the selected sample-size threshold.
                    try:
                        (args.cache_dir / f"{args.cache_key}.{entry_id}.json").unlink()
                    except FileNotFoundError:
                        pass

        entries.sort(key=lambda item: (-item["best_pnl"], item["label"]))
        elapsed_seconds = int(time.monotonic() - started)
        write_json(result_path, {"status": "ready", "entries": entries, "elapsed_seconds": elapsed_seconds})
        write_json(status_path, {
            "status": "ready", "progress": 100, "elapsed_seconds": elapsed_seconds,
            "checkpoint_result_reuses": checkpoint_result_reuses,
            "checkpoint_writes": checkpoint_writes,
            "checkpoint_skips_low_disk": checkpoint_skips,
        })
        return 0
    except Exception as exc:
        write_json(status_path, {"status": "error", "progress": 0, "message": "Не удалось рассчитать рейтинг стратегий."})
        print(f"strategy ranking failed: {exc}", file=sys.stderr)
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
