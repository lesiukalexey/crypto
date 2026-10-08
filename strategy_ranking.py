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
import sys
import time
from datetime import date, datetime, time as day_time, timedelta, timezone
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

import pymysql

from app import load_config
from database import connect_mysql

TOP_PER_GROUP = 500
KYIV = ZoneInfo("Europe/Kyiv")
ENTRY_PROFILE_CONFIG = json.loads(Path(__file__).with_name("entry_profiles.json").read_text(encoding="utf-8"))
ENTRY_WINDOWS = tuple(int(value) for value in ENTRY_PROFILE_CONFIG["snapshot_windows"])
ENTRY_THRESHOLDS_CENTS = tuple(int(value) for value in ENTRY_PROFILE_CONFIG["thresholds_cents"])
IMMEDIATE_DIRECTIONS = tuple(str(value) for value in ENTRY_PROFILE_CONFIG.get("immediate_directions", ["short", "long"]))
EXIT_PROFILE_CONFIG = json.loads(Path(__file__).with_name("exit_profiles.json").read_text(encoding="utf-8"))


def exit_values(family: str, profiles: dict[str, Any] | None = None) -> range:
    profile = (profiles or EXIT_PROFILE_CONFIG)[family]
    start = profile.get("from", profile.get("min_cents"))
    end = profile.get("to", profile.get("max_cents"))
    step = profile.get("step", profile.get("step_cents"))
    return range(int(start), int(end) + 1, int(step))


class PriceTree:
    """Segment tree that locates the first price crossing a threshold."""

    def __init__(self, values: list[float]) -> None:
        size = 1
        while size < len(values):
            size *= 2
        self.size = size
        self.length = len(values)
        self.maximum = [-math.inf] * (size * 2)
        self.minimum = [math.inf] * (size * 2)
        for index, value in enumerate(values):
            self.maximum[size + index] = value
            self.minimum[size + index] = value
        for node in range(size - 1, 0, -1):
            self.maximum[node] = max(self.maximum[node * 2], self.maximum[node * 2 + 1])
            self.minimum[node] = min(self.minimum[node * 2], self.minimum[node * 2 + 1])

    def first_ge(self, start: int, target: float) -> int | None:
        def visit(node: int, left: int, right: int) -> int | None:
            if right <= start or left >= self.length or self.maximum[node] < target:
                return None
            if right - left == 1:
                return left
            middle = (left + right) // 2
            found = visit(node * 2, left, middle)
            return found if found is not None else visit(node * 2 + 1, middle, right)

        return visit(1, 0, self.size)

    def first_le(self, start: int, target: float) -> int | None:
        def visit(node: int, left: int, right: int) -> int | None:
            if right <= start or left >= self.length or self.minimum[node] > target:
                return None
            if right - left == 1:
                return left
            middle = (left + right) // 2
            found = visit(node * 2, left, middle)
            return found if found is not None else visit(node * 2 + 1, middle, right)

        return visit(1, 0, self.size)


def write_json(path: Path, payload: dict[str, Any]) -> None:
    temporary = path.with_suffix(path.suffix + f".{os.getpid()}.tmp")
    temporary.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
    temporary.replace(path)


def make_signals(rows: list[dict[str, Any]], threshold: float, lookback: int) -> tuple[list[int], list[bool]]:
    count = len(rows)
    timestamps = [row["received_at_utc"].replace(tzinfo=timezone.utc).timestamp() for row in rows]
    gaps = [0.0] + [timestamps[index] - timestamps[index - 1] for index in range(1, count)]
    continuous = [False] * count
    for index in range(lookback, count):
        continuous[index] = all(gaps[j] <= 120 for j in range(index - lookback + 1, index + 1))

    indexes: list[int] = []
    directions: list[bool] = []
    for index in range(lookback + 1, count - 1):
        if not continuous[index] or not continuous[index - 1]:
            continue
        current_base = float(rows[index - lookback]["last_price"])
        previous_base = float(rows[index - lookback - 1]["last_price"])
        movement = float(rows[index]["last_price"]) - current_base
        previous_movement = float(rows[index - 1]["last_price"]) - previous_base
        if movement >= threshold and previous_movement < threshold:
            indexes.append(index)
            directions.append(False)
        elif movement <= -threshold and previous_movement > -threshold:
            indexes.append(index)
            directions.append(True)
    return indexes, directions


def simulate(
    rows: list[dict[str, Any]],
    signal_indexes: list[int],
    signal_shorts: list[bool],
    bid_tree: PriceTree,
    ask_tree: PriceTree,
    start_balance: float,
    fee_rate: float,
    take_profit: float | None,
    stop_loss: float | None,
) -> tuple[float, int]:
    balance = start_balance
    signal_cursor = 0
    count = len(rows)
    trades_closed = 0
    fee_factor = (1 + fee_rate) / (1 - fee_rate)
    inverse_fee_factor = (1 - fee_rate) / (1 + fee_rate)

    while balance > 0:
        if signal_cursor >= len(signal_indexes):
            break
        entry_index = signal_indexes[signal_cursor]
        is_short = signal_shorts[signal_cursor]
        signal_cursor += 1
        entry_price = float(rows[entry_index]["bid_price"] if is_short else rows[entry_index]["ask_price"])
        if entry_price <= 0:
            continue

        first_take: int | None = None
        first_stop: int | None = None
        if is_short:
            if take_profit is not None and take_profit < balance:
                take_price = entry_price * inverse_fee_factor * (1 - take_profit / balance)
                first_take = ask_tree.first_le(entry_index + 1, take_price)
            if stop_loss is not None:
                stop_price = entry_price * inverse_fee_factor * (1 + stop_loss / balance)
                first_stop = ask_tree.first_ge(entry_index + 1, stop_price)
        else:
            if take_profit is not None:
                take_price = entry_price * fee_factor * (1 + take_profit / balance)
                first_take = bid_tree.first_ge(entry_index + 1, take_price)
            if stop_loss is not None and stop_loss < balance:
                stop_price = entry_price * fee_factor * (1 - stop_loss / balance)
                first_stop = bid_tree.first_le(entry_index + 1, stop_price)

        crossings = [index for index in (first_take, first_stop) if index is not None]
        exit_index = min(crossings) if crossings else count - 1
        exit_price = float(rows[exit_index]["ask_price"] if is_short else rows[exit_index]["bid_price"])
        if is_short:
            net_ratio = (entry_price * (1 - fee_rate) - exit_price * (1 + fee_rate)) / (entry_price * (1 + fee_rate))
        else:
            net_ratio = (exit_price * (1 - fee_rate) - entry_price * (1 + fee_rate)) / (entry_price * (1 + fee_rate))
        balance *= 1 + net_ratio
        trades_closed += 1

        if not crossings:
            break
        signal_cursor = bisect.bisect_left(signal_indexes, exit_index + 1, lo=signal_cursor)
    return balance - start_balance, trades_closed


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
    args = parser.parse_args()
    ranking = json.loads(args.ranking_params)
    entry_windows = ranking["entry_windows"]
    entry_thresholds = ranking["entry_thresholds_cents"]
    immediate_directions = ranking["immediate_directions"]
    exit_profiles = ranking["exit_profiles"]

    status_path = args.cache_dir / f"{args.cache_key}.status.json"
    result_path = args.cache_dir / f"{args.cache_key}.json"
    worker_lock = (args.cache_dir / "ranking-worker.lock").open("a", encoding="utf-8")
    while True:
        try:
            fcntl.flock(worker_lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            break
        except BlockingIOError:
            write_json(status_path, {"status": "pending", "progress": 0, "queued": True})
            time.sleep(10)
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

        groups_specs = [
            ("fixed", "Автоматические · фиксированные Take Profit и Stop Loss", len(exit_values("fixed_tp", exit_profiles)) * len(exit_values("fixed_sl", exit_profiles))),
            ("loss", "Автоматические · фиксированный Stop Loss", len(exit_values("loss", exit_profiles))),
            ("profit", "Автоматические · фиксированный Take Profit", len(exit_values("profit", exit_profiles))),
        ]
        total_per_entry = sum(spec[2] for spec in groups_specs)
        entry_specs: list[tuple[str, str, int | None, int | None, bool | None]] = []
        for lookback in entry_windows:
            for threshold_cents in entry_thresholds:
                entry_specs.append((f"entry:{lookback}:{threshold_cents}", f"±{threshold_cents / 100:.2f} USDT за {lookback} снимков", lookback, threshold_cents, None))
        for direction in immediate_directions:
            is_short = direction == "short"
            entry_specs.append((f"immediate:{direction}", f"Сразу · 1 заявка на {'продажу' if is_short else 'покупку'}", None, None, is_short))
        total = total_per_entry * len(entry_specs)
        completed = 0
        started = time.monotonic()
        entries: list[dict[str, Any]] = []

        if len(rows) >= 2:
            bid_tree = PriceTree([float(row["bid_price"]) for row in rows])
            ask_tree = PriceTree([float(row["ask_price"]) for row in rows])
        else:
            bid_tree = PriceTree([])
            ask_tree = PriceTree([])
        fee_rate = args.fee_percent / 100

        for entry_id, entry_label, lookback, threshold_cents, immediate_short in entry_specs:
                if len(rows) < 2:
                    signals, shorts = [], []
                elif immediate_short is not None:
                    signals, shorts = [0], [immediate_short]
                else:
                    signals, shorts = make_signals(rows, int(threshold_cents) / 100, int(lookback))
                if not signals:
                    continue

                profile_best = [-math.inf]
                groups: list[dict[str, Any]] = []

                for family, label, candidate_count in groups_specs:
                    best: list[tuple[float, str, str]] = []

                    def consider(identifier: str, display: str, target: float | None, loss: float | None) -> None:
                        nonlocal completed
                        pnl, trade_count = simulate(rows, signals, shorts, bid_tree, ask_tree, args.balance, fee_rate, target, loss)
                        if trade_count < args.min_trades:
                            completed += 1
                            if completed % 1000 == 0:
                                write_json(status_path, {"status": "pending", "progress": int(completed * 100 / total), "elapsed_seconds": int(time.monotonic() - started)})
                            return
                        profile_best[0] = max(profile_best[0], pnl)
                        item = (pnl, identifier, f"{display} · {trade_count} сделок · итог {pnl:+.4f} USDT")
                        if len(best) < TOP_PER_GROUP:
                            heapq.heappush(best, item)
                        elif (pnl, identifier) > (best[0][0], best[0][1]):
                            heapq.heapreplace(best, item)
                        completed += 1
                        if completed % 1000 == 0:
                            write_json(status_path, {"status": "pending", "progress": int(completed * 100 / total), "elapsed_seconds": int(time.monotonic() - started)})

                    if family == "fixed":
                        for target_cents in exit_values("fixed_tp", exit_profiles):
                            for loss_cents in exit_values("fixed_sl", exit_profiles):
                                consider(f"fixed:{target_cents}:{loss_cents}", f"TP {target_cents / 100:.2f} / SL {loss_cents / 100:.2f} USDT", target_cents / 100, loss_cents / 100)
                    elif family == "loss":
                        for loss_cents in exit_values("loss", exit_profiles):
                            consider(f"loss:{loss_cents}", f"Stop Loss {loss_cents / 100:.2f} USDT · без фиксации прибыли", None, loss_cents / 100)
                    else:
                        for target_cents in exit_values("profit", exit_profiles):
                            consider(f"profit:{target_cents}", f"Take Profit {target_cents / 100:.2f} USDT · без фиксации убытка", target_cents / 100, None)

                    best.sort(key=lambda current: (-current[0], current[1]))
                    groups.append({
                        "label": label + f" · топ {min(TOP_PER_GROUP, len(best))} из {candidate_count}",
                        "items": [{"id": item[1], "label": item[2]} for item in best],
                    })

                write_json(args.cache_dir / f"{args.cache_key}.{entry_id}.json", {"status": "ready", "groups": groups})
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
        write_json(result_path, {"status": "ready", "entries": entries})
        write_json(status_path, {"status": "ready", "progress": 100, "elapsed_seconds": int(time.monotonic() - started)})
        return 0
    except Exception as exc:
        write_json(status_path, {"status": "error", "progress": 0, "message": "Не удалось рассчитать рейтинг стратегий."})
        print(f"strategy ranking failed: {exc}", file=sys.stderr)
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
