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
from contract_rules import load_contract_specs, numba_market_order_rules
from futures_costs import load_historical_funding_rates
from database import connect_mysql
from martingale import martingale_base_notional
from ranking_checkpoints import STATE_DTYPE, daily_quote_boundaries, dated_state_checkpoint_path, has_dated_state_checkpoint, load_best_state_checkpoint, load_exact_state_checkpoint, write_state_checkpoint

TOP_PER_GROUP = 500
KYIV = ZoneInfo("Europe/Kyiv")
EXIT_PROFILE_CONFIG = json.loads(Path(__file__).with_name("exit_profiles.json").read_text(encoding="utf-8"))


def exit_values(family: str, profiles: dict[str, Any] | None = None) -> range | list[int]:
    profile = (profiles or EXIT_PROFILE_CONFIG)[family]
    if family in ("fixed_tp", "fixed_sl") and isinstance(profile.get("values_basis_points"), list):
        return sorted({int(value) for value in profile["values_basis_points"]})
    if isinstance(profile.get("segments"), list):
        values: set[int] = set()
        for segment in profile["segments"]:
            start = int(segment["from"])
            end = int(segment["to"])
            step = int(segment["step"])
            values.update(range(start, end + 1, step))
        return sorted(values)
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
    local_days: np.ndarray | None = None
    funding_quote_indexes: np.ndarray | None = None
    funding_mark_prices: np.ndarray | None = None
    funding_rates: np.ndarray | None = None

    def __post_init__(self) -> None:
        self.bids = np.asarray(self.bids, dtype=np.float64)
        self.asks = np.asarray(self.asks, dtype=np.float64)
        self.lasts = np.asarray(self.lasts, dtype=np.float64)
        self.timestamps = np.asarray(self.timestamps, dtype=np.float64)
        if self.local_days is None:
            self.local_days = np.fromiter(
                (datetime.fromtimestamp(float(value), KYIV).date().toordinal() for value in self.timestamps),
                dtype=np.int32,
                count=len(self.timestamps),
            )
        else:
            self.local_days = np.asarray(self.local_days, dtype=np.int32)
        self.funding_quote_indexes = np.asarray(self.funding_quote_indexes if self.funding_quote_indexes is not None else [], dtype=np.int64)
        self.funding_mark_prices = np.asarray(self.funding_mark_prices if self.funding_mark_prices is not None else [], dtype=np.float64)
        self.funding_rates = np.asarray(self.funding_rates if self.funding_rates is not None else [], dtype=np.float64)

    def set_funding_rates(self, rates: list[tuple[float, float]], mark_prices: np.ndarray) -> None:
        if len(rates) == 0:
            self.funding_quote_indexes = np.empty(0, dtype=np.int64)
            self.funding_mark_prices = np.empty(0, dtype=np.float64)
            self.funding_rates = np.empty(0, dtype=np.float64)
            return
        event_times = np.fromiter((timestamp for timestamp, _ in rates), dtype=np.float64, count=len(rates))
        quote_indexes = np.searchsorted(self.timestamps, event_times, side="left")
        valid = quote_indexes < len(self.timestamps)
        self.funding_quote_indexes = quote_indexes[valid].astype(np.int64)
        self.funding_mark_prices = np.asarray(mark_prices, dtype=np.float64)[self.funding_quote_indexes]
        self.funding_rates = np.fromiter((rate for _, rate in rates), dtype=np.float64, count=len(rates))[valid]

    @classmethod
    def from_rows(cls, rows: list[dict[str, Any]], slippage_bps: float = 0.0) -> "QuoteArrays":
        if not math.isfinite(slippage_bps) or slippage_bps < 0 or slippage_bps >= 10_000:
            raise ValueError("Slippage must be from 0 to 9,999.9999 basis points")
        adverse = slippage_bps / 10_000
        return cls(
            np.fromiter((float(row["bid_price"]) * (1 - adverse) for row in rows), dtype=np.float64, count=len(rows)),
            np.fromiter((float(row["ask_price"]) * (1 + adverse) for row in rows), dtype=np.float64, count=len(rows)),
            np.fromiter((float(row["last_price"]) for row in rows), dtype=np.float64, count=len(rows)),
            np.fromiter((row["received_at_utc"].replace(tzinfo=timezone.utc).timestamp() for row in rows), dtype=np.float64, count=len(rows)),
        )

    def __len__(self) -> int:
        return len(self.bids)


def write_json(path: Path, payload: dict[str, Any]) -> None:
    temporary = path.with_suffix(path.suffix + f".{os.getpid()}.tmp")
    temporary.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
    temporary.replace(path)


def signal_profile_family(family: str, signal_mode: str, direction_mode: str) -> str:
    suffix = []
    if signal_mode == "within":
        suffix.append("within")
    if direction_mode == "reverse":
        suffix.append("reverse")
    return family + ((":" + ":".join(suffix)) if suffix else "")


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


def make_signals(quotes: QuoteArrays, threshold: float, lookback: int, direction: str = "both", signal_mode: str = "exact", direction_mode: str = "follow") -> tuple[list[int], list[bool]]:
    if ":" in direction:
        parts = direction.split(":")
        direction = parts[0]
        for part in parts[1:]:
            if part in ("exact", "within"):
                signal_mode = part
            elif part in ("follow", "reverse"):
                direction_mode = part
    if direction not in ("both", "long", "short"):
        raise ValueError("Invalid entry direction")
    if signal_mode not in ("exact", "within"):
        raise ValueError("Invalid entry signal mode")
    if direction_mode not in ("follow", "reverse"):
        raise ValueError("Invalid entry direction mode")
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
        if signal_mode == "within":
            current_bases = quotes.lasts[max(0, index - lookback):index]
            previous_bases = quotes.lasts[max(0, index - lookback - 1):index - 1]
            if np.any(current_bases <= 0) or np.any(previous_bases <= 0):
                continue
            current_moves = (quotes.lasts[index] / current_bases - 1.0) * 100.0
            previous_moves = (quotes.lasts[index - 1] / previous_bases - 1.0) * 100.0
            movement = float(np.max(current_moves))
            previous_movement = float(np.max(previous_moves))
            negative_movement = float(np.min(current_moves))
            previous_negative_movement = float(np.min(previous_moves))
        else:
            current_base = quotes.lasts[index - lookback]
            previous_base = quotes.lasts[index - lookback - 1]
            if current_base <= 0 or previous_base <= 0:
                continue
            movement = (quotes.lasts[index] / current_base - 1.0) * 100.0
            previous_movement = (quotes.lasts[index - 1] / previous_base - 1.0) * 100.0
            negative_movement = movement
            previous_negative_movement = previous_movement
        if direction != "short" and movement >= threshold and previous_movement < threshold:
            indexes.append(index)
            directions.append(direction_mode == "reverse")
        elif direction != "long" and negative_movement <= -threshold and previous_negative_movement > -threshold:
            indexes.append(index)
            directions.append(direction_mode != "reverse")
    return np.asarray(indexes, dtype=np.int64), np.asarray(directions, dtype=np.bool_)


def make_signal_profiles(
    quotes: QuoteArrays,
    ranking: dict[str, Any],
) -> dict[tuple[int, str, int], tuple[list[int], list[bool]]]:
    """Build every configured momentum signal from one quote scan per window."""
    count = len(quotes)
    profiles = ranking["entry_profiles"]
    signal_mode = ranking.get("entry_signal_mode", "exact")
    direction_mode = ranking.get("entry_direction_mode", "follow")
    if signal_mode == "within":
        thresholds_by_family = {
            family: sorted({int(value) for value in profile["thresholds_tenths_percent"]})
            for family, profile in profiles.items()
        }
        values_by_family = {
            family: [value / 10 for value in values]
            for family, values in thresholds_by_family.items()
        }
        signals: dict[tuple[int, str, int], tuple[list[int], list[bool]]] = {
            (int(window), signal_profile_family(family, signal_mode, direction_mode), threshold): ([], [])
            for family, profile in profiles.items()
            for window in profile["windows"]
            for threshold in thresholds_by_family[family]
        }
        gaps = [0.0] + [quotes.timestamps[index] - quotes.timestamps[index - 1] for index in range(1, count)]
        for lookback in sorted({int(window) for profile in profiles.values() for window in profile["windows"]}):
            for index in range(lookback + 1, count - 1):
                if any(gaps[j] > 120 for j in range(index - lookback + 1, index + 1)):
                    continue
                if any(gaps[j] > 120 for j in range(index - lookback, index)):
                    continue
                current_bases = quotes.lasts[index - lookback:index]
                previous_bases = quotes.lasts[index - lookback - 1:index - 1]
                if np.any(current_bases <= 0) or np.any(previous_bases <= 0):
                    continue
                current_moves = (quotes.lasts[index] / current_bases - 1.0) * 100.0
                previous_moves = (quotes.lasts[index - 1] / previous_bases - 1.0) * 100.0
                up_move, previous_up_move = float(np.max(current_moves)), float(np.max(previous_moves))
                down_move, previous_down_move = float(np.min(current_moves)), float(np.min(previous_moves))
                for family, cents_values in thresholds_by_family.items():
                    candidates = values_by_family[family]
                    if family != "short":
                        first = bisect.bisect_right(candidates, previous_up_move)
                        last = bisect.bisect_right(candidates, up_move)
                        for candidate_index in range(first, last):
                            threshold = cents_values[candidate_index]
                            signals[(lookback, signal_profile_family(family, signal_mode, direction_mode), threshold)][0].append(index)
                            signals[(lookback, signal_profile_family(family, signal_mode, direction_mode), threshold)][1].append(direction_mode == "reverse")
                    if family != "long":
                        first = bisect.bisect_right(candidates, -previous_down_move)
                        last = bisect.bisect_right(candidates, -down_move)
                        for candidate_index in range(first, last):
                            threshold = cents_values[candidate_index]
                            signals[(lookback, signal_profile_family(family, signal_mode, direction_mode), threshold)][0].append(index)
                            signals[(lookback, signal_profile_family(family, signal_mode, direction_mode), threshold)][1].append(direction_mode != "reverse")
        return {
            key: (np.asarray(indexes, dtype=np.int64), np.asarray(directions, dtype=np.bool_))
            for key, (indexes, directions) in signals.items()
        }
    thresholds = {
        family: sorted({int(value) for value in profile["thresholds_tenths_percent"]})
        for family, profile in profiles.items()
    }
    threshold_values = {
        family: [value / 10 for value in values]
        for family, values in thresholds.items()
    }
    signals = {
        (lookback, signal_profile_family(family, signal_mode, direction_mode), cents): ([], [])
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
                        signals[(lookback, signal_profile_family(family, signal_mode, direction_mode), threshold_value)][0].append(index)
                        signals[(lookback, signal_profile_family(family, signal_mode, direction_mode), threshold_value)][1].append(direction_mode == "reverse")
                if family != "long":
                    first = bisect.bisect_right(candidates, -previous_movement)
                    after_last = bisect.bisect_right(candidates, -movement)
                    for candidate_index in range(first, after_last):
                        threshold_value = thresholds[family][candidate_index]
                        signals[(lookback, signal_profile_family(family, signal_mode, direction_mode), threshold_value)][0].append(index)
                        signals[(lookback, signal_profile_family(family, signal_mode, direction_mode), threshold_value)][1].append(direction_mode != "reverse")
    return {
        key: (np.asarray(indexes, dtype=np.int64), np.asarray(directions, dtype=np.bool_))
        for key, (indexes, directions) in signals.items()
    }


def build_entry_specs(ranking: dict[str, Any]) -> list[tuple[str, str, int | None, int | None, str | None, bool | None]]:
    specs: list[tuple[str, str, int | None, int | None, str | None, bool | None]] = []
    labels = {
        "both": lambda amount: f"±{amount:.1f}% от баланса",
        "long": lambda amount: f"+{amount:.1f}% от баланса при росте",
        "short": lambda amount: f"−{amount:.1f}% от баланса при падении",
    }
    for family in ("both", "long", "short"):
        profile = ranking["entry_profiles"][family]
        prefix = "entry" if family == "both" else f"entry:{family}"
        for window in profile["windows"]:
            for threshold in profile["thresholds_tenths_percent"]:
                suffix = f"{window}:{threshold}"
                if ranking.get("entry_signal_mode", "exact") == "within":
                    suffix += ":within"
                if ranking.get("entry_direction_mode", "follow") == "reverse":
                    suffix += ":reverse"
                signal_family = signal_profile_family(family, ranking.get("entry_signal_mode", "exact"), ranking.get("entry_direction_mode", "follow"))
                specs.append((
                    f"{prefix}:{suffix}",
                    f"{labels[family](threshold / 10)} за {window} снимков" + (" · внутри" if ranking.get("entry_signal_mode", "exact") == "within" else "") + (" · против движения" if ranking.get("entry_direction_mode", "follow") == "reverse" else ""),
                    int(window),
                    int(threshold),
                    signal_family,
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
    match = re.fullmatch(r"entry(?::(long|short))?:(\d+):(\d+)(?::(within))?(?::(reverse))?", entry_config)
    if match is None:
        return None
    family = match.group(1) or "both"
    window = int(match.group(2))
    threshold = int(match.group(3))
    profile = ranking["entry_profiles"].get(family)
    if not isinstance(profile, dict) or window not in profile["windows"] or threshold not in profile["thresholds_tenths_percent"]:
        return None
    mode = "within" if match.group(4) else "exact"
    direction_mode = "reverse" if match.group(5) else "follow"
    if mode != ranking.get("entry_signal_mode", "exact") or direction_mode != ranking.get("entry_direction_mode", "follow"):
        return None
    return {"type": "momentum", "family": family, "window": window, "threshold_tenths_percent": threshold, "signal_mode": mode, "direction_mode": direction_mode}


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
    martingale_max_chain_loss: float | None = None,
    martingale_max_day_loss: float | None = None,
    take_confirmation_snapshots: int = 0,
    stop_confirmation_snapshots: int = 0,
    order_rules: tuple[float, float, int, float, float] | None = None,
    include_risk_metrics: bool = False,
) -> tuple[float, int] | tuple[float, int, int, float, float]:
    indexes = signal_indexes if isinstance(signal_indexes, np.ndarray) else np.asarray(signal_indexes, dtype=np.int64)
    shorts = signal_shorts if isinstance(signal_shorts, np.ndarray) else np.asarray(signal_shorts, dtype=np.bool_)
    # TP/SL candidates are tenths of one percent of the starting balance.
    # Convert once to a fixed USDT amount; accumulated balance never changes it.
    take_profit_amount = None if take_profit is None else start_balance * take_profit / 1000.0
    stop_loss_amount = None if stop_loss is None else start_balance * stop_loss / 1000.0

    if martingale_mode != "none":
        from numba_simulation import simulate_martingale_numba

        if martingale_mode not in ("simple", "reverse", "simple_profit", "reverse_profit") or martingale_timing not in ("immediate", "rules") or not 2 <= martingale_attempts <= 10:
            raise ValueError("Invalid martingale options")
        if martingale_target_policy not in ("scaled", "fixed"):
            raise ValueError("Invalid martingale exit target policy")
        market_rules = order_rules or (0.0, 0.0, 12, 0.0, 0.0)
        pnl, order_count, chain_count, max_drawdown, worst_chain = simulate_martingale_numba(
            quotes.bids, quotes.asks,
            bid_tree.maximum, bid_tree.minimum, ask_tree.maximum, ask_tree.minimum,
            bid_tree.size, bid_tree.length, indexes, shorts,
            start_balance, fee_rate,
            quotes.funding_quote_indexes, quotes.funding_mark_prices, quotes.funding_rates,
            *market_rules,
            np.nan if take_profit_amount is None else take_profit_amount,
            np.nan if stop_loss_amount is None else -stop_loss_amount,
            martingale_base_notional(
                start_balance, fee_rate, stop_loss_amount, martingale_mode, martingale_attempts, martingale_target_policy,
            ),
            {"simple": 0, "reverse": 1, "simple_profit": 2, "reverse_profit": 3}[martingale_mode],
            0 if martingale_timing == "immediate" else 1,
            martingale_attempts,
            0 if martingale_target_policy == "scaled" else 1,
            np.nan if martingale_max_chain_loss is None else martingale_max_chain_loss,
            np.nan if martingale_max_day_loss is None else martingale_max_day_loss,
            quotes.local_days,
            take_confirmation_snapshots, stop_confirmation_snapshots,
        )
        if include_risk_metrics:
            return pnl, order_count, chain_count, max_drawdown, worst_chain
        return pnl, order_count

    from numba_simulation import simulate_numba

    market_rules = order_rules or (0.0, 0.0, 12, 0.0, 0.0)
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
        *market_rules,
        quotes.funding_quote_indexes,
        quotes.funding_mark_prices,
        quotes.funding_rates,
        np.nan if take_profit_amount is None else take_profit_amount,
        np.nan if stop_loss_amount is None else stop_loss_amount,
        take_confirmation_snapshots, stop_confirmation_snapshots,
    )


def simulate_martingale_batch(
    quotes: QuoteArrays,
    signal_indexes: np.ndarray,
    signal_shorts: np.ndarray,
    bid_tree: PriceTree,
    ask_tree: PriceTree,
    start_balance: float,
    fee_rate: float,
    mode: str,
    timing: str,
    attempts: int,
    max_chain_loss: float | None,
    max_day_loss: float | None,
    candidates: list[tuple[str, float | None, float | None, str]],
    take_confirmation_snapshots: int = 0,
    stop_confirmation_snapshots: int = 0,
    order_rules: tuple[float, float, int, float, float] | None = None,
    include_risk_metrics: bool = False,
) -> dict[tuple[str, str], tuple[float, ...]]:
    """Run a complete martingale exit grid in one Python-to-Numba call."""
    from martingale import martingale_base_notional
    from numba_simulation import simulate_martingale_batch_numba

    takes = np.fromiter(
        (np.nan if target is None else start_balance * target / 1000.0 for _, target, _, _ in candidates),
        dtype=np.float64, count=len(candidates),
    )
    stops = np.fromiter(
        (np.nan if loss is None else -start_balance * abs(loss) / 1000.0 for _, _, loss, _ in candidates),
        dtype=np.float64, count=len(candidates),
    )
    base_notionals = np.fromiter(
        (martingale_base_notional(
            start_balance, fee_rate,
            None if loss is None else start_balance * abs(loss) / 1000.0,
            mode, attempts, policy,
        ) for _, _, loss, policy in candidates),
        dtype=np.float64, count=len(candidates),
    )
    policies = np.fromiter((0 if policy == "scaled" else 1 for _, _, _, policy in candidates), dtype=np.int64, count=len(candidates))
    mode_id = {"simple": 0, "reverse": 1, "simple_profit": 2, "reverse_profit": 3}[mode]
    timing_id = 0 if timing == "immediate" else 1
    market_rules = order_rules or (0.0, 0.0, 12, 0.0, 0.0)
    pnls, order_counts, chain_counts, max_drawdowns, worst_chains = simulate_martingale_batch_numba(
        quotes.bids, quotes.asks, bid_tree.maximum, bid_tree.minimum,
        ask_tree.maximum, ask_tree.minimum, bid_tree.size, bid_tree.length,
        signal_indexes, signal_shorts, start_balance, fee_rate,
        quotes.funding_quote_indexes, quotes.funding_mark_prices, quotes.funding_rates,
        *market_rules, takes, stops,
        base_notionals, mode_id, timing_id, attempts, policies,
        np.nan if max_chain_loss is None else max_chain_loss,
        np.nan if max_day_loss is None else max_day_loss,
        quotes.local_days,
        take_confirmation_snapshots, stop_confirmation_snapshots,
    )
    return {
        (identifier, policy): (
            float(pnl), int(order_count), int(chain_count),
            float(max_drawdown), float(worst_chain),
        ) if include_risk_metrics else (float(pnl), int(order_count), int(chain_count))
        for (identifier, _, _, policy), pnl, order_count, chain_count, max_drawdown, worst_chain
        in zip(candidates, pnls, order_counts, chain_counts, max_drawdowns, worst_chains)
    }


def martingale_exit_candidates(
    exit_profiles: dict[str, Any], mode: str,
) -> list[tuple[str, float | None, float | None, str]]:
    """Build the complete exit grid in the same order as the ranking loops."""
    candidates: list[tuple[str, float | None, float | None, str]] = []
    for target_tenths in exit_values("fixed_tp", exit_profiles):
        for loss_tenths in exit_values("fixed_sl", exit_profiles):
            identifier = f"fixed:{target_tenths}:{loss_tenths}"
            for policy, _ in martingale_target_policies(mode, float(loss_tenths)):
                candidates.append((identifier, target_tenths / 10.0, loss_tenths / 10.0, policy))
    for loss_tenths in exit_values("loss", exit_profiles):
        identifier = f"loss:{loss_tenths}"
        for policy, _ in martingale_target_policies(mode, float(loss_tenths)):
            candidates.append((identifier, None, float(loss_tenths), policy))
    for target_tenths in exit_values("profit", exit_profiles):
        identifier = f"profit:{target_tenths}"
        for policy, _ in martingale_target_policies(mode, None):
            candidates.append((identifier, float(target_tenths), None, policy))
    return candidates


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
    take_confirmation_snapshots: int = 0,
    stop_confirmation_snapshots: int = 0,
    order_rules: tuple[float, float, int, float, float] | None = None,
) -> tuple[float, int, tuple[float, int, int, int, bool]]:
    """Resume a non-martingale simulation from its last closed-trade boundary."""
    from numba_simulation import simulate_standard_stateful_numba

    indexes = signal_indexes if isinstance(signal_indexes, np.ndarray) else np.asarray(signal_indexes, dtype=np.int64)
    shorts = signal_shorts if isinstance(signal_shorts, np.ndarray) else np.asarray(signal_shorts, dtype=np.bool_)
    take_profit_amount = None if take_profit is None else start_balance * take_profit / 1000.0
    stop_loss_amount = None if stop_loss is None else start_balance * stop_loss / 1000.0
    market_rules = order_rules or (0.0, 0.0, 12, 0.0, 0.0)
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
        *market_rules,
        quotes.funding_quote_indexes,
        quotes.funding_mark_prices,
        quotes.funding_rates,
        np.nan if take_profit_amount is None else take_profit_amount,
        np.nan if stop_loss_amount is None else stop_loss_amount,
        float(checkpoint[0]),
        int(checkpoint[1]),
        int(checkpoint[2]),
        int(checkpoint[3]),
        bool(checkpoint[4]),
        take_confirmation_snapshots, stop_confirmation_snapshots,
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
    take_confirmation_snapshots: int = 0,
    stop_confirmation_snapshots: int = 0,
    order_rules: tuple[float, float, int, float, float] | None = None,
) -> tuple[float, int, tuple[float, int, int, int, bool], list[tuple[float, int, int, int, bool]]]:
    """Resume once through the range and capture each supplied day boundary."""
    from numba_simulation import simulate_standard_stateful_days_numba

    indexes = signal_indexes if isinstance(signal_indexes, np.ndarray) else np.asarray(signal_indexes, dtype=np.int64)
    shorts = signal_shorts if isinstance(signal_shorts, np.ndarray) else np.asarray(signal_shorts, dtype=np.bool_)
    take_profit_amount = None if take_profit is None else start_balance * take_profit / 1000.0
    stop_loss_amount = None if stop_loss is None else start_balance * stop_loss / 1000.0
    market_rules = order_rules or (0.0, 0.0, 12, 0.0, 0.0)
    values = simulate_standard_stateful_days_numba(
        quotes.bids, quotes.asks,
        bid_tree.maximum, bid_tree.minimum, ask_tree.maximum, ask_tree.minimum,
        bid_tree.size, indexes, shorts, start_balance, fee_rate, *market_rules,
        quotes.funding_quote_indexes, quotes.funding_mark_prices, quotes.funding_rates,
        np.nan if take_profit_amount is None else take_profit_amount,
        np.nan if stop_loss_amount is None else stop_loss_amount,
        np.asarray(day_quote_counts, dtype=np.int64),
        float(checkpoint[0]), int(checkpoint[1]), int(checkpoint[2]),
        int(checkpoint[3]), bool(checkpoint[4]),
        take_confirmation_snapshots, stop_confirmation_snapshots,
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
    order_rules: tuple[float, float, int, float, float] | None = None,
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
        min_qty, step, places, min_notional, max_qty = order_rules or (0.0, 0.0, 12, 0.0, 0.0)
        indexes = open_indexes[open_mask]
        is_short = open_shorts[open_mask]
        entry_prices = np.where(is_short, quotes.bids[indexes], quotes.asks[indexes])
        exit_prices = np.where(is_short, quotes.asks[-1], quotes.bids[-1])
        available_notionals = np.minimum(start_balance, closed_balance[open_mask] / (1 + fee_rate))
        quantities = np.floor((available_notionals / entry_prices) / step + 1e-10) * step if step > 0 else available_notionals / entry_prices
        if step > 0:
            quantities = np.floor(quantities * (10 ** places) + 1e-8) / (10 ** places)
        notionals = quantities * entry_prices
        feasible = (quantities >= min_qty) & (notionals >= min_notional) & ((max_qty <= 0) | (quantities <= max_qty))
        net = np.where(is_short, quantities * (entry_prices * (1 - fee_rate) - exit_prices * (1 + fee_rate)), quantities * (exit_prices * (1 - fee_rate) - entry_prices * (1 + fee_rate)))
        net[~feasible] = np.nan
        pnls[open_mask] = closed_balance[open_mask] + net - start_balance
        if len(quotes.funding_quote_indexes):
            open_rows = np.flatnonzero(open_mask)
            for local_index, (row, entry_index, short, entry_price) in enumerate(zip(open_rows, indexes, is_short, entry_prices)):
                quantity = quantities[local_index]
                accrued = (quotes.funding_quote_indexes > entry_index) & (quotes.funding_quote_indexes < len(quotes))
                sign = 1.0 if short else -1.0
                pnls[row] += sign * quantity * float(np.sum(quotes.funding_mark_prices[accrued] * quotes.funding_rates[accrued]))
        trade_counts[open_mask] += 1
    return pnls, trade_counts


def standard_range_results(
    quotes: QuoteArrays,
    start_balance: float,
    fee_rate: float,
    start_states: np.ndarray | None,
    end_states: np.ndarray,
    order_rules: tuple[float, float, int, float, float] | None = None,
) -> tuple[np.ndarray, np.ndarray]:
    """Aggregate canonical closed trades by close day and mark one end-open trade."""
    end_pnls, end_trade_counts = finalize_standard_checkpoints(
        quotes, start_balance, fee_rate, end_states, order_rules,
    )
    if start_states is None:
        return end_pnls, end_trade_counts
    pnl_before_range = start_states["balance"] - start_balance
    pnls = end_pnls - pnl_before_range
    trade_counts = end_trade_counts - start_states["closed_trades"].astype(np.int64)
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


def deduplicate_ranked_groups(groups: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Keep the highest-ranked exit for each displayed trade-count/PnL pair."""
    flattened = [
        (float(item["pnl"]), str(item["id"]), group_index, item_index, int(item["trade_count"]))
        for group_index, group in enumerate(groups)
        for item_index, item in enumerate(group["items"])
        if "pnl" in item and "trade_count" in item
    ]
    flattened.sort(key=lambda row: (-row[0], row[1]))
    seen: set[tuple[int, str]] = set()
    keep: set[tuple[int, int]] = set()
    for pnl, _, group_index, item_index, trade_count in flattened:
        identity = (trade_count, f"{pnl:+.4f}")
        if identity in seen:
            continue
        seen.add(identity)
        keep.add((group_index, item_index))
    result = []
    for group_index, group in enumerate(groups):
        items = [item for item_index, item in enumerate(group["items"])
                 if (group_index, item_index) in keep]
        label = re.sub(r"топ \d+ из (\d+)$", f"топ {len(items)} из \\1", group["label"])
        result.append({**group, "label": label, "items": items})
    return result


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
                    display = f"TP {target / 100:.2f}% / SL {value / 100:.2f}% от стартового баланса"
                elif family == "loss":
                    display = f"Stop Loss {value / 10:.1f}% от стартового баланса · без фиксации прибыли"
                else:
                    display = f"Take Profit {value / 10:.1f}% от стартового баланса · без фиксации убытка"
                pnl = float(group_pnls[index])
                items.append({
                    "id": exit_id,
                    "label": f"{display} · {int(group_trades[index])} сделок · итог {pnl:+.4f} USDT",
                    "pnl": pnl,
                    "trade_count": int(group_trades[index]),
                })
        groups.append({
            "label": label + f" · топ {min(TOP_PER_GROUP, len(items))} из {candidate_count}",
            "items": items,
        })
        offset += candidate_count
    return deduplicate_ranked_groups(groups), profile_best


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--cache-key", required=True)
    parser.add_argument("--symbol", required=True)
    parser.add_argument("--start-day", required=True)
    parser.add_argument("--end-day", required=True)
    parser.add_argument("--balance", required=True, type=float)
    parser.add_argument("--fee-percent", required=True, type=float)
    parser.add_argument("--min-trades", required=True, type=int, choices=(1, 2, 3))
    parser.add_argument("--max-id", required=True, type=int)
    parser.add_argument("--cache-dir", required=True, type=Path)
    parser.add_argument("--ranking-params", required=True)
    parser.add_argument("--martingale-mode", choices=("none", "simple", "reverse", "simple_profit", "reverse_profit"), default="none")
    parser.add_argument("--martingale-timing", choices=("immediate", "rules"), default="immediate")
    parser.add_argument("--martingale-attempts", type=int, choices=range(2, 11), default=3)
    parser.add_argument("--martingale-max-chain-loss", type=float, default=0.0)
    parser.add_argument("--martingale-max-day-loss", type=float, default=0.0)
    parser.add_argument("--include-funding", choices=("0", "1"), default="1")
    parser.add_argument("--slippage-bps", type=float, default=0.0)
    args = parser.parse_args()
    ranking = json.loads(args.ranking_params)
    exit_profiles = ranking["exit_profiles"]
    confirmation_snapshots = int(ranking.get("exit_confirmation_snapshots", 60))
    confirmation_mode = ranking.get("exit_confirmation_mode", "immediate")
    take_confirmation_snapshots = confirmation_snapshots if confirmation_mode in ("both_delayed", "tp_delayed") else 0
    stop_confirmation_snapshots = confirmation_snapshots if confirmation_mode in ("both_delayed", "sl_delayed") else 0

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
        contract_specs = load_contract_specs(config["category"])
        contract = contract_specs.get(args.symbol)
        if contract is None:
            raise RuntimeError(f"Bitget contract rules are unavailable for {args.symbol}")
        order_rules = numba_market_order_rules(contract, leverage=1.0)
        connection = connect_mysql(config["mysql"])
        try:
            end_local = datetime.combine(date.fromisoformat(args.end_day) + timedelta(days=1), day_time.min, KYIV)
            end_utc = end_local.astimezone(timezone.utc).replace(tzinfo=None)
            start_local = datetime.combine(date.fromisoformat(args.start_day), day_time.min, KYIV)
            start_utc = start_local.astimezone(timezone.utc).replace(tzinfo=None)
            with connection.cursor(pymysql.cursors.DictCursor) as cursor:
                if args.martingale_mode == "none":
                    cursor.execute(
                        """SELECT received_at_utc, bid_price, ask_price, last_price
                           FROM quote_snapshots
                           WHERE category = %s AND symbol = %s AND (id <= %s OR quote_source = 'historical_candle_close')
                             AND received_at_utc < %s
                           ORDER BY received_at_utc, id""",
                        (config["category"], args.symbol, args.max_id, end_utc),
                    )
                else:
                    cursor.execute(
                        """SELECT received_at_utc, bid_price, ask_price, last_price
                           FROM quote_snapshots
                           WHERE category = %s AND symbol = %s AND (id <= %s OR quote_source = 'historical_candle_close')
                             AND received_at_utc >= %s AND received_at_utc < %s
                           ORDER BY received_at_utc, id""",
                        (config["category"], args.symbol, args.max_id, start_utc, end_utc),
                    )
                rows = cursor.fetchall()
        finally:
            connection.close()
        quotes = QuoteArrays.from_rows(rows, args.slippage_bps)
        if args.include_funding == "1" and len(quotes):
            funding_rows = load_historical_funding_rates(
                config["category"], args.symbol, float(quotes.timestamps[0]), float(quotes.timestamps[-1]),
            )
            mark_prices = np.fromiter(
                ((float(row["bid_price"]) + float(row["ask_price"])) / 2 for row in rows),
                dtype=np.float64, count=len(rows),
            )
            quotes.set_funding_rates(funding_rows, mark_prices)
        del rows
        source_day = (
            datetime.fromtimestamp(float(quotes.timestamps[0]), timezone.utc)
            .astimezone(KYIV).date().isoformat()
            if len(quotes) else args.end_day
        )
        all_day_boundaries = [
            (day, count) for day, count in daily_quote_boundaries(
                quotes.timestamps, source_day, args.end_day,
            ) if count > 0
        ]
        previous_range_day = (date.fromisoformat(args.start_day) - timedelta(days=1)).isoformat()
        range_start_day = next((day for day, count in reversed(all_day_boundaries)
            if day <= previous_range_day and count > 0), None)
        range_start_quote_count = next((count for day, count in all_day_boundaries
            if day == range_start_day), 0) if range_start_day is not None else 0

        groups_specs = [
            ("fixed", "Автоматические · фиксированные Take Profit и Stop Loss", len(exit_values("fixed_tp", exit_profiles)) * len(exit_values("fixed_sl", exit_profiles))),
            ("loss", "Автоматические · фиксированный Stop Loss", len(exit_values("loss", exit_profiles))),
            ("profit", "Автоматические · фиксированный Take Profit", len(exit_values("profit", exit_profiles))),
        ]
        total_per_entry = sum(
            spec[2] * (2 if args.martingale_mode != "none" and spec[0] in ("fixed", "loss") else 1)
            for spec in groups_specs
        )
        martingale_batch_candidates = (
            martingale_exit_candidates(exit_profiles, args.martingale_mode)
            if args.martingale_mode != "none" else []
        )
        entry_specs_for_worker = build_entry_specs(ranking)
        checkpoint_state = {
            "args": args,
            "category": config["category"],
            "order_rules": order_rules,
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
        equivalent_signal_results: dict[tuple[bytes, bytes], tuple[str, float]] = {}

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

                signal_signature = (signals.tobytes(), shorts.tobytes())
                if args.martingale_mode != "none" and signal_signature in equivalent_signal_results:
                    source_entry_id, cached_best_pnl = equivalent_signal_results[signal_signature]
                    cached_payload = json.loads((args.cache_dir / f"{args.cache_key}.{source_entry_id}.json").read_text(encoding="utf-8"))
                    write_json(args.cache_dir / f"{args.cache_key}.{entry_id}.json", cached_payload)
                    if math.isfinite(cached_best_pnl):
                        entries.append({
                            "id": entry_id,
                            "best_pnl": cached_best_pnl,
                            "label": f"{entry_label} · лучший результат {cached_best_pnl:+.4f} USDT",
                        })
                    completed += total_per_entry
                    progress_reporter.update(status_path, {
                        "status": "pending", "progress": min(99, int(completed * 100 / total)),
                        "elapsed_seconds": int(time.monotonic() - started),
                    })
                    continue

                martingale_batch_results: dict[tuple[str, str], tuple[float, ...]] = {}
                if args.martingale_mode != "none":
                    martingale_batch_results = simulate_martingale_batch(
                        quotes, signals, shorts, bid_tree, ask_tree,
                        args.balance, fee_rate, args.martingale_mode,
                        args.martingale_timing, args.martingale_attempts,
                        args.martingale_max_chain_loss, args.martingale_max_day_loss,
                        martingale_batch_candidates,
                        take_confirmation_snapshots, stop_confirmation_snapshots,
                        order_rules, include_risk_metrics=True,
                    )

                checkpoint_path = None
                previous_states = None
                previous_quote_count = 0
                previous_checkpoint_day = None
                boundaries_to_capture: list[tuple[str, int]] = []
                range_start_states = None
                range_start_in_capture = False
                if args.martingale_mode == "none":
                    checkpoint_path = dated_state_checkpoint_path(checkpoint_state, args.symbol, entry_id, args.end_day)
                    first_missing_day = next((index for index, (day, _) in enumerate(all_day_boundaries)
                        if not has_dated_state_checkpoint(checkpoint_state, args.symbol, entry_id, day)), None)
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
                    if range_start_day is not None:
                        range_start_states = load_exact_state_checkpoint(
                            checkpoint_state, args.symbol, entry_id, range_start_day,
                            quotes, total_per_entry, range_start_quote_count,
                        )
                    if first_missing_day is None and checkpoint is not None and previous_quote_count == len(quotes):
                        if range_start_day is not None and range_start_states is None:
                            range_start_states = load_exact_state_checkpoint(
                                checkpoint_state, args.symbol, entry_id, range_start_day,
                                quotes, total_per_entry, range_start_quote_count,
                            )
                            if range_start_states is None:
                                raise RuntimeError(f"Missing canonical range-start checkpoint for {range_start_day}")
                        saved_pnls, saved_trades = standard_range_results(
                            quotes, args.balance, fee_rate, range_start_states, previous_states, order_rules,
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
                    range_start_in_capture = any(
                        day == range_start_day for day, _ in boundaries_to_capture
                    )

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
                    best_by_policy: dict[str, list[tuple[float, str, str, int, int, float, float]]] = {
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
                                candidate_index = len(next_states)
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
                                        take_confirmation_snapshots, stop_confirmation_snapshots,
                                        order_rules,
                                    )
                                    for index, (day, _) in enumerate(boundaries_to_capture):
                                        states_by_day[day][state_index] = day_states[index]
                                        state_counts_by_day[day] += 1
                                else:
                                    pnl, trade_count, next_state = simulate_standard_checkpoint(
                                        quotes, signals, shorts, bid_tree, ask_tree,
                                        args.balance, fee_rate, target, loss, previous_state,
                                        take_confirmation_snapshots, stop_confirmation_snapshots,
                                        order_rules,
                                    )
                                next_states.append(next_state)
                                if range_start_day is not None:
                                    if range_start_states is not None:
                                        start_state = range_start_states[candidate_index]
                                    elif range_start_in_capture:
                                        start_boundary_index = next(
                                            index for index, (day, _) in enumerate(boundaries_to_capture)
                                            if day == range_start_day
                                        )
                                        start_state = day_states[start_boundary_index]
                                    else:
                                        raise RuntimeError(
                                            f"Missing canonical checkpoint for range start {range_start_day}"
                                        )
                                    pnl -= float(start_state[0]) - args.balance
                                    trade_count -= int(start_state[2])
                                chain_count = trade_count
                            else:
                                result = martingale_batch_results[(identifier, target_policy)]
                                pnl, trade_count, chain_count = result[:3]
                                max_drawdown, worst_chain = result[3:5]
                            if args.martingale_mode == "none":
                                max_drawdown = worst_chain = 0.0
                            independent_count = chain_count if args.martingale_mode != "none" else trade_count
                            if math.isfinite(pnl) and independent_count >= args.min_trades:
                                profile_best[0] = max(profile_best[0], pnl)
                                ranked_id = f"{identifier}:{target_policy}" if policy_label else identifier
                                best = best_by_policy[target_policy]
                                enters_top = len(best) < TOP_PER_GROUP or (pnl, ranked_id) > (best[0][0], best[0][1])
                                if enters_top:
                                    if display_kind == "fixed":
                                        display = f"TP {target_tenths_percent / 100:.2f}% / SL {loss_tenths_percent / 100:.2f}% от стартового баланса"
                                    elif display_kind == "loss":
                                        display = f"Stop Loss {loss_tenths_percent / 10:.1f}% от стартового баланса · без фиксации прибыли"
                                    else:
                                        display = f"Take Profit {target_tenths_percent / 10:.1f}% от стартового баланса · без фиксации убытка"
                                    item = (pnl, ranked_id, display, trade_count, chain_count, max_drawdown, worst_chain)
                                    if len(best) < TOP_PER_GROUP:
                                        heapq.heappush(best, item)
                                    else:
                                        heapq.heapreplace(best, item)
                            completed += 1
                            if completed % 1000 == 0:
                                progress_reporter.update(status_path, {"status": "pending", "progress": int(completed * 100 / total), "elapsed_seconds": int(time.monotonic() - started)})

                    if family == "fixed":
                        for target_basis_points in exit_values("fixed_tp", exit_profiles):
                            for loss_basis_points in exit_values("fixed_sl", exit_profiles):
                                consider(f"fixed:{target_basis_points}:{loss_basis_points}", "fixed", target_basis_points, loss_basis_points, target_basis_points / 10.0, loss_basis_points / 10.0)
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
                        for pnl, ranked_id, display, trade_count, chain_count, max_drawdown, worst_chain in best:
                            ranked_label = display + (f" · {policy_label}" if policy_label else "")
                            if take_confirmation_snapshots or stop_confirmation_snapshots:
                                ranked_label += f" · TP {'через ' + str(take_confirmation_snapshots) + ' интервалов' if take_confirmation_snapshots else 'сразу'} / SL {'через ' + str(stop_confirmation_snapshots) + ' интервалов' if stop_confirmation_snapshots else 'сразу'}"
                            sample_label = (
                                f"{trade_count} ордеров · {chain_count} независимых цепочек"
                                if args.martingale_mode != "none" else f"{trade_count} сделок"
                            )
                            item_result = {
                                "id": ranked_id,
                                "label": f"{ranked_label} · {sample_label} · итог {pnl:+.4f} USDT",
                                "pnl": float(pnl),
                                "trade_count": int(trade_count),
                            }
                            if args.martingale_mode != "none":
                                item_result["chain_count"] = int(chain_count)
                                item_result["max_drawdown"] = float(max_drawdown)
                                item_result["worst_chain"] = float(worst_chain)
                                item_result["label"] += (
                                    f" · худшая цепочка {worst_chain:+.4f} USDT"
                                    f" · просадка после цепочки {max_drawdown:.4f} USDT"
                                )
                            items.append(item_result)
                        groups.append({
                            "label": group_label + f" · топ {min(TOP_PER_GROUP, len(best))} из {candidate_count}",
                            "items": items,
                        })

                if args.martingale_mode == "none":
                    groups = deduplicate_ranked_groups(groups)

                profile_payload = {"status": "ready", "groups": groups}
                write_json(args.cache_dir / f"{args.cache_key}.{entry_id}.json", profile_payload)
                if args.martingale_mode != "none":
                    equivalent_signal_results[signal_signature] = (entry_id, profile_best[0])
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
