#!/usr/bin/env python3
"""Rank available symbols by the best reliable exit result for one entry profile."""
from __future__ import annotations

import argparse
import fcntl
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
from strategy_ranking import PriceTree, make_signals, resolve_entry_config, simulate, exit_values

KYIV = ZoneInfo("Europe/Kyiv")


def write_json(path: Path, payload: dict[str, Any]) -> None:
    temporary = path.with_suffix(path.suffix + f".{os.getpid()}.tmp")
    temporary.write_text(json.dumps(payload, separators=(",", ":")), encoding="utf-8")
    temporary.replace(path)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--cache-key", required=True)
    parser.add_argument("--start-day", required=True)
    parser.add_argument("--end-day", required=True)
    parser.add_argument("--balance", required=True, type=float)
    parser.add_argument("--fee-percent", required=True, type=float)
    parser.add_argument("--min-trades", required=True, type=int, choices=(1, 2))
    parser.add_argument("--entry-config", required=True)
    parser.add_argument("--max-ids", required=True)
    parser.add_argument("--cache-dir", required=True, type=Path)
    parser.add_argument("--ranking-params", required=True)
    parser.add_argument("--martingale-mode", choices=("none", "simple", "reverse"), default="none")
    parser.add_argument("--martingale-timing", choices=("immediate", "rules"), default="immediate")
    parser.add_argument("--martingale-attempts", type=int, choices=range(2, 11), default=3)
    args = parser.parse_args()
    ranking = json.loads(args.ranking_params)
    exit_profiles = ranking["exit_profiles"]

    status_path = args.cache_dir / f"symbols-{args.cache_key}.status.json"
    result_path = args.cache_dir / f"symbols-{args.cache_key}.json"
    worker_lock = (args.cache_dir / "ranking-worker.lock").open("a", encoding="utf-8")
    while True:
        try:
            fcntl.flock(worker_lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            break
        except BlockingIOError:
            write_json(status_path, {"status": "pending", "progress": 0, "queued": True})
            time.sleep(10)

    try:
        max_ids = {str(symbol): int(max_id) for symbol, max_id in json.loads(args.max_ids).items()}
        config = load_config(Path(__file__).with_name("config.json"))
        start_local = datetime.combine(date.fromisoformat(args.start_day), day_time.min, KYIV)
        end_local = datetime.combine(date.fromisoformat(args.end_day) + timedelta(days=1), day_time.min, KYIV)
        start_utc = start_local.astimezone(timezone.utc).replace(tzinfo=None)
        end_utc = end_local.astimezone(timezone.utc).replace(tzinfo=None)
        connection = connect_mysql(config["mysql"])
        try:
            with connection.cursor(pymysql.cursors.DictCursor) as cursor:
                cursor.execute(
                    """SELECT id, symbol, received_at_utc, bid_price, ask_price, last_price
                       FROM quote_snapshots
                       WHERE category = %s AND received_at_utc >= %s AND received_at_utc < %s
                       ORDER BY symbol, received_at_utc, id""",
                    (config["category"], start_utc, end_utc),
                )
                raw_rows = cursor.fetchall()
        finally:
            connection.close()

        rows_by_symbol: dict[str, list[dict[str, Any]]] = {symbol: [] for symbol in max_ids}
        for row in raw_rows:
            symbol = str(row["symbol"])
            if symbol in max_ids and int(row["id"]) <= max_ids[symbol]:
                row.pop("id", None)
                rows_by_symbol[symbol].append(row)

        entry_selection = resolve_entry_config(args.entry_config, ranking)
        if entry_selection is None:
            raise ValueError("Unknown entry profile")
        is_immediate = entry_selection["type"] == "immediate"
        lookback = None if is_immediate else entry_selection["window"]
        threshold = None if is_immediate else entry_selection["threshold_cents"] / 100
        entry_direction = "both" if is_immediate else entry_selection["family"]
        immediate_short = entry_selection["direction"] == "short" if is_immediate else None

        exit_counts = {
            "fixed": len(exit_values("fixed_tp", exit_profiles)) * len(exit_values("fixed_sl", exit_profiles)),
            "loss": len(exit_values("loss", exit_profiles)),
            "profit": len(exit_values("profit", exit_profiles)),
        }
        variants_per_symbol = sum(exit_counts.values())
        started = time.monotonic()
        results: list[dict[str, Any]] = []

        for symbol_index, symbol in enumerate(sorted(max_ids)):
            rows = rows_by_symbol[symbol]
            best_pnl = -math.inf
            best_label = ""
            symbol_completed = 0
            if len(rows) >= 2:
                if immediate_short is not None:
                    signals, shorts = [0], [immediate_short]
                else:
                    signals, shorts = make_signals(rows, float(threshold), int(lookback), entry_direction)
                if signals:
                    bid_tree = PriceTree([float(row["bid_price"]) for row in rows])
                    ask_tree = PriceTree([float(row["ask_price"]) for row in rows])
                    fee_rate = args.fee_percent / 100

                    def consider(target: float | None, loss: float | None, label: str) -> None:
                        nonlocal best_pnl, best_label, symbol_completed
                        pnl, trade_count = simulate(rows, signals, shorts, bid_tree, ask_tree, args.balance, fee_rate, target, loss, args.martingale_mode, args.martingale_timing, args.martingale_attempts)
                        if trade_count >= args.min_trades and pnl > best_pnl:
                            best_pnl, best_label = pnl, label
                        symbol_completed += 1

                    for take_cents in exit_values("fixed_tp", exit_profiles):
                        for loss_cents in exit_values("fixed_sl", exit_profiles):
                            consider(take_cents / 100, loss_cents / 100, f"TP {take_cents / 100:.2f} / SL {loss_cents / 100:.2f}")
                            if symbol_completed % 1000 == 0:
                                progress = int((symbol_index + symbol_completed / variants_per_symbol) * 100 / max(1, len(max_ids)))
                                write_json(status_path, {"status": "pending", "progress": min(99, progress), "elapsed_seconds": int(time.monotonic() - started), "symbol": symbol})
                    for loss_cents in exit_values("loss", exit_profiles):
                        consider(None, loss_cents / 100, f"SL {loss_cents / 100:.2f}")
                    for take_cents in exit_values("profit", exit_profiles):
                        consider(take_cents / 100, None, f"TP {take_cents / 100:.2f}")
            results.append({
                "symbol": symbol,
                "best_pnl": best_pnl if math.isfinite(best_pnl) else None,
                "best_exit": best_label if math.isfinite(best_pnl) else None,
            })
            write_json(status_path, {"status": "pending", "progress": min(99, int((symbol_index + 1) * 100 / max(1, len(max_ids)))), "elapsed_seconds": int(time.monotonic() - started), "symbol": symbol})

        results.sort(key=lambda item: (item["best_pnl"] is None, -(item["best_pnl"] or 0), item["symbol"]))
        write_json(result_path, {"status": "ready", "symbols": results})
        write_json(status_path, {"status": "ready", "progress": 100, "elapsed_seconds": int(time.monotonic() - started)})
        return 0
    except Exception as exc:
        write_json(status_path, {"status": "error", "progress": 0, "message": "Не удалось отсортировать торговые пары."})
        print(f"symbol ranking failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
