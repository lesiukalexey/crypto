#!/usr/bin/env python3
"""Compare entry/exit pairs across every symbol in the selected date range."""

from __future__ import annotations

import argparse
import fcntl
import heapq
import json
import math
import os
import sys
import time
from datetime import date, datetime, time as day_time, timedelta, timezone
from pathlib import Path
from typing import Any, Iterator
from zoneinfo import ZoneInfo

import pymysql

from app import load_config
from database import connect_mysql
from strategy_ranking import (
    PriceTree,
    exit_values,
    make_signals,
    simulate,
    write_json,
)

KYIV = ZoneInfo("Europe/Kyiv")


def entry_specs(ranking: dict[str, Any]) -> list[tuple[str, str, int | None, int | None, bool | None]]:
    specs = [
        (
            f"entry:{window}:{threshold}",
            f"±{threshold / 100:.2f} USDT за {window} снимков",
            window,
            threshold,
            None,
        )
        for window in ranking["entry_windows"]
        for threshold in ranking["entry_thresholds_cents"]
    ]
    for direction in ranking["immediate_directions"]:
        is_short = direction == "short"
        specs.append((
            f"immediate:{direction}",
            f"Сразу · 1 заявка на {'продажу' if is_short else 'покупку'}",
            None,
            None,
            is_short,
        ))
    return specs


def exit_specs(profiles: dict[str, Any]) -> Iterator[tuple[str, str, float | None, float | None]]:
    for target in exit_values("fixed_tp", profiles):
        for loss in exit_values("fixed_sl", profiles):
            yield (
                f"fixed:{target}:{loss}",
                f"TP {target / 100:.2f} / SL {loss / 100:.2f} USDT",
                target / 100,
                loss / 100,
            )
    for loss in exit_values("loss", profiles):
        yield f"loss:{loss}", f"Stop Loss {loss / 100:.2f} USDT", None, loss / 100
    for target in exit_values("profit", profiles):
        yield f"profit:{target}", f"Take Profit {target / 100:.2f} USDT", target / 100, None


def exit_count(profiles: dict[str, Any]) -> int:
    return len(exit_values("fixed_tp", profiles)) * len(exit_values("fixed_sl", profiles)) + len(exit_values("loss", profiles)) + len(exit_values("profit", profiles))


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
    args = parser.parse_args()
    ranking = json.loads(args.ranking_params)
    exit_profiles = ranking["exit_profiles"]

    status_path = args.cache_dir / f"portfolio-{args.cache_key}.status.json"
    result_path = args.cache_dir / f"portfolio-{args.cache_key}.json"
    job_lock = (args.cache_dir / f"portfolio-{args.cache_key}.lock").open("a", encoding="utf-8")
    while True:
        try:
            fcntl.flock(job_lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            break
        except BlockingIOError:
            time.sleep(1)

    if result_path.is_file():
        try:
            cached = json.loads(result_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            cached = None
        if isinstance(cached, dict) and cached.get("status") == "ready":
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

    started = time.monotonic()
    write_json(status_path, {"status": "pending", "progress": 0, "queued": False})
    try:
        max_ids = {str(symbol): int(max_id) for symbol, max_id in json.loads(args.max_ids).items()}
        if not max_ids:
            write_json(result_path, {"status": "ready", "global_top": [], "ticker_leaders": [], "symbols": []})
            write_json(status_path, {"status": "ready", "progress": 100, "elapsed_seconds": 0})
            return 0

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

        specs = entry_specs(ranking)
        candidate_count = exit_count(exit_profiles)
        total = len(specs) * candidate_count
        completed = 0
        universe_size = len(max_ids)
        fee_rate = args.fee_percent / 100
        global_top: list[tuple[float, int, str, dict[str, Any]]] = []
        global_candidates: list[tuple[float, int, str]] = []
        best_by_symbol: dict[str, dict[str, Any]] = {}
        selected_entry_best: dict[str, dict[str, Any]] = {}

        for entry_id, entry_label, lookback, threshold, immediate_short in specs:
            contexts: dict[str, tuple[list[dict[str, Any]], list[int], list[bool], PriceTree, PriceTree]] = {}
            for symbol, rows in rows_by_symbol.items():
                if len(rows) < 2:
                    continue
                if immediate_short is not None:
                    signals, shorts = [0], [immediate_short]
                else:
                    signals, shorts = make_signals(rows, int(threshold) / 100, int(lookback))
                if not signals:
                    continue
                contexts[symbol] = (
                    rows,
                    signals,
                    shorts,
                    PriceTree([float(row["bid_price"]) for row in rows]),
                    PriceTree([float(row["ask_price"]) for row in rows]),
                )

            for exit_id, exit_label, take_profit, stop_loss in exit_specs(exit_profiles):
                aggregate_pnl = 0.0
                eligible_symbols = 0
                profitable_symbols = 0
                local_winners_for_candidate: list[tuple[str, dict[str, Any]]] = []
                for symbol, (rows, signals, shorts, bid_tree, ask_tree) in contexts.items():
                    pnl, trade_count = simulate(
                        rows,
                        signals,
                        shorts,
                        bid_tree,
                        ask_tree,
                        args.balance,
                        fee_rate,
                        take_profit,
                        stop_loss,
                    )
                    if trade_count < args.min_trades:
                        continue
                    eligible_symbols += 1
                    aggregate_pnl += pnl
                    if pnl > 0:
                        profitable_symbols += 1
                    ticker_candidate = {
                        "symbol": symbol,
                        "entry_id": entry_id,
                        "entry_label": entry_label,
                        "exit_id": exit_id,
                        "exit_label": exit_label,
                        "pnl": pnl,
                        "trade_count": trade_count,
                    }
                    previous = best_by_symbol.get(symbol)
                    if previous is None or (pnl, entry_id, exit_id) > (
                        previous["pnl"], previous["entry_id"], previous["exit_id"]
                    ):
                        best_by_symbol[symbol] = ticker_candidate
                        local_winners_for_candidate.append((symbol, ticker_candidate))
                    if entry_id == args.entry_config:
                        previous_selected = selected_entry_best.get(symbol)
                        if previous_selected is None or (pnl, exit_id) > (
                            previous_selected["best_pnl"], previous_selected["best_exit_id"]
                        ):
                            selected_entry_best[symbol] = {
                                "symbol": symbol,
                                "best_pnl": pnl,
                                "best_exit": exit_label,
                                "best_exit_id": exit_id,
                            }

                if eligible_symbols:
                    average_all_symbols = aggregate_pnl / universe_size
                    for symbol, winner in local_winners_for_candidate:
                        if best_by_symbol.get(symbol) is winner:
                            winner["market_average_pnl"] = average_all_symbols
                            winner["market_eligible_symbols"] = eligible_symbols
                            winner["market_profitable_symbols"] = profitable_symbols
                    candidate = {
                        "entry_id": entry_id,
                        "entry_label": entry_label,
                        "exit_id": exit_id,
                        "exit_label": exit_label,
                        "average_pnl": average_all_symbols,
                        "total_pnl": aggregate_pnl,
                        "eligible_symbols": eligible_symbols,
                        "profitable_symbols": profitable_symbols,
                    }
                    combination_key = f"{entry_id}|{exit_id}"
                    global_candidates.append((average_all_symbols, eligible_symbols, combination_key))
                    heap_item = (average_all_symbols, eligible_symbols, combination_key, candidate)
                    if len(global_top) < 5:
                        heapq.heappush(global_top, heap_item)
                    elif heap_item[:3] > global_top[0][:3]:
                        heapq.heapreplace(global_top, heap_item)

                completed += 1
                if completed % 5000 == 0:
                    write_json(status_path, {
                        "status": "pending",
                        "progress": min(99, int(completed * 100 / total)),
                        "elapsed_seconds": int(time.monotonic() - started),
                    })

        ranked_ticker_leaders = sorted(best_by_symbol.values(), key=lambda item: (-item["pnl"], item["symbol"]))
        for rank, leader in enumerate(ranked_ticker_leaders, start=1):
            leader["rank"] = rank
        global_rank_by_combination = {
            combination_key: rank
            for rank, (_, _, combination_key) in enumerate(
                sorted(global_candidates, key=lambda item: (-item[0], -item[1], item[2])),
                start=1,
            )
        }
        for leader in ranked_ticker_leaders:
            combination_key = f"{leader['entry_id']}|{leader['exit_id']}"
            leader["global_rank"] = global_rank_by_combination.get(combination_key)
        ticker_leaders = ranked_ticker_leaders + [
            {
                "symbol": symbol,
                "entry_id": None,
                "entry_label": None,
                "exit_id": None,
                "exit_label": None,
                "pnl": None,
                "trade_count": 0,
                "rank": None,
                "global_rank": None,
            }
            for symbol in sorted(max_ids)
            if symbol not in best_by_symbol
        ]
        symbols = []
        for symbol in sorted(max_ids):
            result = selected_entry_best.get(symbol)
            symbols.append({
                "symbol": symbol,
                "best_pnl": result["best_pnl"] if result else None,
                "best_exit": result["best_exit"] if result else None,
            })
        symbols.sort(key=lambda item: (item["best_pnl"] is None, -(item["best_pnl"] or 0), item["symbol"]))
        sorted_global = [item[3] for item in sorted(global_top, key=lambda item: (-item[0], -item[1], item[2]))]
        write_json(result_path, {
            "status": "ready",
            "global_top": sorted_global,
            "ticker_leaders": ticker_leaders,
            "symbols": symbols,
            "universe_size": universe_size,
            "min_trades": args.min_trades,
        })
        write_json(status_path, {"status": "ready", "progress": 100, "elapsed_seconds": int(time.monotonic() - started)})
        return 0
    except Exception as exc:
        write_json(status_path, {"status": "error", "progress": 0, "message": "Не удалось рассчитать общий рейтинг комбинаций."})
        print(f"portfolio ranking failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
