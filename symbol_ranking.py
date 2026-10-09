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
from types import SimpleNamespace
from typing import Any
from zoneinfo import ZoneInfo

import numpy as np
import pymysql

from app import load_config
from database import connect_mysql
import portfolio_ranking as portfolio_engine
from portfolio_ranking import exit_specs
from strategy_ranking import PriceTree, ProgressReporter, QuoteArrays, build_entry_specs, make_signals, martingale_exit_candidates, martingale_target_policies, resolve_entry_config, signal_profile_family, simulate, simulate_martingale_batch, exit_values

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
    parser.add_argument("--min-trades", required=True, type=int, choices=(1, 2, 3))
    parser.add_argument("--entry-config", required=True)
    parser.add_argument("--max-ids", required=True)
    parser.add_argument("--cache-dir", required=True, type=Path)
    parser.add_argument("--ranking-params", required=True)
    parser.add_argument("--martingale-mode", choices=("none", "simple", "reverse", "simple_profit", "reverse_profit"), default="none")
    parser.add_argument("--martingale-timing", choices=("immediate", "rules"), default="immediate")
    parser.add_argument("--martingale-attempts", type=int, choices=range(2, 11), default=3)
    parser.add_argument("--martingale-max-chain-loss", type=float, default=0.0)
    parser.add_argument("--martingale-max-day-loss", type=float, default=0.0)
    args = parser.parse_args()
    ranking = json.loads(args.ranking_params)
    exit_profiles = ranking["exit_profiles"]
    confirmation_snapshots = int(ranking.get("exit_confirmation_snapshots", 60))
    confirmation_mode = ranking.get("exit_confirmation_mode", "immediate")
    take_confirmation_snapshots = confirmation_snapshots if confirmation_mode in ("both_delayed", "tp_delayed") else 0
    stop_confirmation_snapshots = confirmation_snapshots if confirmation_mode in ("both_delayed", "sl_delayed") else 0
    martingale_candidates = (
        martingale_exit_candidates(exit_profiles, args.martingale_mode)
        if args.martingale_mode != "none" else []
    )

    status_path = args.cache_dir / f"symbols-{args.cache_key}.status.json"
    result_path = args.cache_dir / f"symbols-{args.cache_key}.json"
    job_lock = (args.cache_dir / f"symbols-worker-{args.cache_key}.lock").open("a", encoding="utf-8")
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
        max_ids = {str(symbol): int(max_id) for symbol, max_id in json.loads(args.max_ids).items()}
        config = load_config(Path(__file__).with_name("config.json"))
        end_local = datetime.combine(date.fromisoformat(args.end_day) + timedelta(days=1), day_time.min, KYIV)
        end_utc = end_local.astimezone(timezone.utc).replace(tzinfo=None)
        start_utc = datetime.combine(date.fromisoformat(args.start_day), day_time.min, KYIV).astimezone(timezone.utc).replace(tzinfo=None)
        snapshot_filter = " OR ".join("(symbol = %s AND id <= %s)" for _ in max_ids)
        quote_parameters: list[Any] = [config["category"], end_utc]
        if args.martingale_mode == "none":
            for snapshot_symbol, snapshot_max_id in sorted(max_ids.items()):
                quote_parameters.extend((snapshot_symbol, snapshot_max_id))
            quote_query = (
                "SELECT id, symbol, received_at_utc, bid_price, ask_price, last_price "
                "FROM quote_snapshots WHERE category = %s AND received_at_utc < %s AND ("
                + snapshot_filter + ") ORDER BY symbol, received_at_utc, id"
            )
        else:
            quote_query = (
                "SELECT id, symbol, received_at_utc, bid_price, ask_price, last_price "
                "FROM quote_snapshots WHERE category = %s AND received_at_utc >= %s AND received_at_utc < %s "
                "ORDER BY symbol, received_at_utc, id"
            )
            quote_parameters = [config["category"], start_utc, end_utc]
        connection = connect_mysql(config["mysql"])
        try:
            with connection.cursor(pymysql.cursors.DictCursor) as cursor:
                cursor.execute(quote_query, quote_parameters)
                raw_rows = cursor.fetchall()
        finally:
            connection.close()

        rows_by_symbol: dict[str, list[dict[str, Any]]] = {symbol: [] for symbol in max_ids}
        for row in raw_rows:
            symbol = str(row["symbol"])
            if symbol in max_ids and int(row["id"]) <= max_ids[symbol]:
                row.pop("id", None)
                rows_by_symbol[symbol].append(row)
        del raw_rows

        entry_selection = resolve_entry_config(args.entry_config, ranking)
        if entry_selection is None:
            raise ValueError("Unknown entry profile")
        is_immediate = entry_selection["type"] == "immediate"
        lookback = None if is_immediate else entry_selection["window"]
        threshold = None if is_immediate else entry_selection["threshold_tenths_percent"] / 10
        entry_direction = "both" if is_immediate else signal_profile_family(
            entry_selection["family"], entry_selection["signal_mode"], entry_selection["direction_mode"],
        )
        immediate_short = entry_selection["direction"] == "short" if is_immediate else None
        selected_entry_spec = next((spec for spec in build_entry_specs(ranking) if spec[0] == args.entry_config), None)
        if selected_entry_spec is None:
            raise ValueError("Unknown entry profile")

        exit_counts = {
            "fixed": len(exit_values("fixed_tp", exit_profiles)) * len(exit_values("fixed_sl", exit_profiles)),
            "loss": len(exit_values("loss", exit_profiles)),
            "profit": len(exit_values("profit", exit_profiles)),
        }
        variants_per_symbol = exit_counts["profit"] + sum(
            count * (2 if args.martingale_mode != "none" else 1)
            for count in (exit_counts["fixed"], exit_counts["loss"])
        )
        checkpoint_exit_options = [
            (
                f"{base_exit_id}:{policy}" if policy_label else base_exit_id,
                label + (f" · {policy_label}" if policy_label else "") + (f" · TP {'через ' + str(take_confirmation_snapshots) + ' снимков' if take_confirmation_snapshots else 'сразу'} / SL {'через ' + str(stop_confirmation_snapshots) + ' снимков' if stop_confirmation_snapshots else 'сразу'}" if take_confirmation_snapshots or stop_confirmation_snapshots else ""),
                target, loss, policy,
            )
            for base_exit_id, label, target, loss in exit_specs(exit_profiles)
            for policy, policy_label in martingale_target_policies("none", loss)
        ]
        quotes_by_symbol = {
            symbol: QuoteArrays.from_rows(rows)
            for symbol, rows in rows_by_symbol.items() if rows
        }
        checkpoint_args = SimpleNamespace(
            start_day=args.start_day, end_day=args.end_day, balance=args.balance,
            fee_percent=args.fee_percent, min_trades=args.min_trades,
            ranking_params=ranking, martingale_mode="none",
            martingale_timing=args.martingale_timing,
            martingale_attempts=args.martingale_attempts, entry_config=args.entry_config,
        )
        pair_cache_root = args.cache_dir / "symbol-pair-results" / args.cache_key
        signals_by_symbol: dict[str, dict[tuple[int, str, int], tuple[np.ndarray, np.ndarray]]] = {}
        if not is_immediate:
            signal_key = (int(lookback), entry_direction, int(entry_selection["threshold_tenths_percent"]))
            for symbol, quotes in quotes_by_symbol.items():
                signals_by_symbol[symbol] = {
                    signal_key: make_signals(quotes, float(threshold), int(lookback), entry_direction),
                }
        portfolio_engine._PORTFOLIO_WORKER_STATE = {
            "quotes_by_symbol": quotes_by_symbol,
            "take_confirmation_snapshots": take_confirmation_snapshots,
            "stop_confirmation_snapshots": stop_confirmation_snapshots,
            "signals_by_symbol": signals_by_symbol,
            "pair_cache_scopes": {symbol: pair_cache_root / symbol for symbol in quotes_by_symbol},
            "checkpoint_root": args.cache_dir.parent / "bitget-simulation-checkpoints" / "portfolio-state-v1",
            "category": config["category"],
            "exit_options": checkpoint_exit_options,
            "args": checkpoint_args,
            "fee_rate": args.fee_percent / 100,
        }
        started = time.monotonic()
        progress_reporter = ProgressReporter()
        results: list[dict[str, Any]] = []

        for symbol_index, symbol in enumerate(sorted(max_ids)):
            rows = rows_by_symbol[symbol]
            quotes = quotes_by_symbol.get(symbol, QuoteArrays([], [], [], []))
            del rows_by_symbol[symbol]
            best_pnl = -math.inf
            best_label = ""
            symbol_completed = 0
            if len(quotes) >= 2:
                if args.martingale_mode == "none":
                    simulation = portfolio_engine.simulate_symbol_entry(
                        (symbol, selected_entry_spec, True),
                    )
                    pair_results = simulation[1]
                    if pair_results is not None:
                        eligible = pair_results["trade_count"] >= args.min_trades
                        if np.any(eligible):
                            best_index = int(np.argmax(np.where(eligible, pair_results["pnl"], -np.inf)))
                            best_pnl = float(pair_results["pnl"][best_index])
                            best_label = checkpoint_exit_options[best_index][1]
                            symbol_completed = variants_per_symbol
                    progress = int((symbol_index + 1) * 100 / max(1, len(max_ids)))
                    progress_reporter.update(status_path, {
                        "status": "pending", "progress": min(99, progress),
                        "elapsed_seconds": int(time.monotonic() - started), "symbol": symbol,
                    })
                    results.append({
                        "symbol": symbol,
                        "best_pnl": best_pnl if math.isfinite(best_pnl) else None,
                        "best_exit": best_label if math.isfinite(best_pnl) else None,
                    })
                    continue
                if immediate_short is not None:
                    signals, shorts = np.array([0], dtype=np.int64), np.array([immediate_short], dtype=np.bool_)
                else:
                    signals, shorts = make_signals(quotes, float(threshold), int(lookback), entry_direction)
                if len(signals) > 0 and not (
                    args.martingale_mode == "none" and len(signals) < args.min_trades
                ):
                    bid_tree = PriceTree(quotes.bids)
                    ask_tree = PriceTree(quotes.asks)
                    fee_rate = args.fee_percent / 100
                    martingale_batch_results = {}
                    if args.martingale_mode != "none":
                        martingale_batch_results = simulate_martingale_batch(
                            quotes, signals, shorts, bid_tree, ask_tree,
                            args.balance, fee_rate, args.martingale_mode,
                            args.martingale_timing, args.martingale_attempts,
                            args.martingale_max_chain_loss, args.martingale_max_day_loss,
                            martingale_candidates,
                            take_confirmation_snapshots, stop_confirmation_snapshots,
                        )

                    def consider(target: float | None, loss: float | None, label: str) -> None:
                        nonlocal best_pnl, best_label, symbol_completed
                        for target_policy, policy_label in martingale_target_policies(args.martingale_mode, loss):
                            if args.martingale_mode != "none":
                                if target is not None and loss is None:
                                    identifier = f"profit:{int(round(target * 10))}"
                                elif target is None:
                                    identifier = f"loss:{int(round(loss))}"
                                else:
                                    identifier = f"fixed:{int(round(target * 10))}:{int(round(loss))}"
                                pnl, trade_count, chain_count = martingale_batch_results[(identifier, target_policy)]
                            else:
                                pnl, trade_count = simulate(
                                    quotes, signals, shorts, bid_tree, ask_tree, args.balance, fee_rate,
                                    target, loss, args.martingale_mode, args.martingale_timing,
                                    args.martingale_attempts, target_policy,
                                    args.martingale_max_chain_loss, args.martingale_max_day_loss,
                                    take_confirmation_snapshots, stop_confirmation_snapshots,
                                )
                                chain_count = trade_count
                            independent_count = chain_count if args.martingale_mode != "none" else trade_count
                            if independent_count >= args.min_trades and pnl > best_pnl:
                                best_pnl = pnl
                                best_label = label + (f" · {policy_label}" if policy_label else "")
                                if take_confirmation_snapshots or stop_confirmation_snapshots:
                                    best_label += f" · TP {'через ' + str(take_confirmation_snapshots) + ' снимков' if take_confirmation_snapshots else 'сразу'} / SL {'через ' + str(stop_confirmation_snapshots) + ' снимков' if stop_confirmation_snapshots else 'сразу'}"
                                if args.martingale_mode != "none":
                                    best_label += f" · {chain_count} независимых цепочек / {trade_count} ордеров"
                            symbol_completed += 1
                            if symbol_completed % 1000 == 0:
                                progress = int((symbol_index + symbol_completed / variants_per_symbol) * 100 / max(1, len(max_ids)))
                                progress_reporter.update(status_path, {"status": "pending", "progress": min(99, progress), "elapsed_seconds": int(time.monotonic() - started), "symbol": symbol})

                    for take_basis_points in exit_values("fixed_tp", exit_profiles):
                        for loss_basis_points in exit_values("fixed_sl", exit_profiles):
                            consider(take_basis_points / 10.0, loss_basis_points / 10.0, f"TP {take_basis_points / 100:.2f}% / SL {loss_basis_points / 100:.2f}% от стартового баланса")
                    for loss_tenths_percent in exit_values("loss", exit_profiles):
                        consider(None, float(loss_tenths_percent), f"SL {loss_tenths_percent / 10:.1f}% от стартового баланса")
                    for take_tenths_percent in exit_values("profit", exit_profiles):
                        consider(float(take_tenths_percent), None, f"TP {take_tenths_percent / 10:.1f}% от стартового баланса")
            results.append({
                "symbol": symbol,
                "best_pnl": best_pnl if math.isfinite(best_pnl) else None,
                "best_exit": best_label if math.isfinite(best_pnl) else None,
            })
            progress_reporter.update(status_path, {"status": "pending", "progress": min(99, int((symbol_index + 1) * 100 / max(1, len(max_ids)))), "elapsed_seconds": int(time.monotonic() - started), "symbol": symbol})

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
