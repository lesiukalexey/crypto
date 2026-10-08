#!/usr/bin/env python3
"""Compare entry/exit pairs across every symbol in the selected date range."""

from __future__ import annotations

import argparse
from array import array
from concurrent.futures import ProcessPoolExecutor
import fcntl
import hashlib
import heapq
import json
import math
import multiprocessing
import os
import shutil
import struct
import sys
import tempfile
import time
import zlib
from datetime import date, datetime, time as day_time, timedelta, timezone
from pathlib import Path
from typing import Any, Iterator
from zoneinfo import ZoneInfo

import numpy as np
import pymysql

from app import load_config
from database import connect_mysql
from strategy_ranking import (
    PriceTree,
    ProgressReporter,
    QuoteArrays,
    build_entry_specs,
    exit_values,
    make_signal_profiles,
    martingale_target_policies,
    simulate,
    write_json,
)

KYIV = ZoneInfo("Europe/Kyiv")
CANDIDATE_RECORD = struct.Struct("<dII")


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


def pair_result_cache_paths(
    cache_dir: Path,
    cache_generation: str,
    category: str,
    max_ids: dict[str, int],
    start_day: str,
    end_day: str,
    balance: float,
    fee_percent: float,
    min_trades: int,
    martingale_mode: str,
    martingale_timing: str,
    martingale_attempts: int,
) -> tuple[Path, dict[str, Path]]:
    cache_root = cache_dir / "portfolio-pair-results"
    cache_root.mkdir(mode=0o700, parents=True, exist_ok=True)
    generation_key = hashlib.sha256(cache_generation.encode("utf-8")).hexdigest()
    generation_dir = cache_root / generation_key
    generation_dir.mkdir(mode=0o700, exist_ok=True)
    for old_generation in cache_root.iterdir():
        if old_generation != generation_dir and old_generation.is_dir():
            shutil.rmtree(old_generation, ignore_errors=True)

    scope_keys = {
        symbol: hashlib.sha256(json.dumps([
            "portfolio-pair-simulation-v1",
            category,
            symbol,
            max_ids[symbol],
            start_day,
            end_day,
            balance,
            fee_percent,
            min_trades,
            martingale_mode,
            martingale_timing,
            martingale_attempts,
        ], separators=(",", ":")).encode("utf-8")).hexdigest()
        for symbol in max_ids
    }
    active_scopes = set(scope_keys.values())
    for old_scope in generation_dir.iterdir():
        if old_scope.is_dir() and old_scope.name not in active_scopes:
            shutil.rmtree(old_scope, ignore_errors=True)
    return generation_dir, {
        symbol: generation_dir / scope_key
        for symbol, scope_key in scope_keys.items()
    }


def load_pair_result_cache(path: Path) -> dict[str, tuple[float, int]]:
    try:
        payload = json.loads(zlib.decompress(path.read_bytes()))
    except (OSError, UnicodeDecodeError, zlib.error, json.JSONDecodeError):
        return {}
    if not isinstance(payload, dict):
        return {}
    results: dict[str, tuple[float, int]] = {}
    for exit_id, result in payload.items():
        if not isinstance(exit_id, str) or not isinstance(result, list) or len(result) != 2:
            continue
        try:
            pnl = float(result[0])
            trade_count = int(result[1])
        except (TypeError, ValueError, OverflowError):
            continue
        if math.isfinite(pnl) and trade_count >= 0:
            results[exit_id] = (pnl, trade_count)
    return results


def write_pair_result_cache(path: Path, results: dict[str, tuple[float, int]]) -> None:
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    payload = json.dumps(
        {exit_id: [pnl, trade_count] for exit_id, (pnl, trade_count) in results.items()},
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    compressed = zlib.compress(payload, level=1)
    temporary_path = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    temporary_path.write_bytes(compressed)
    temporary_path.replace(path)


_PORTFOLIO_WORKER_STATE: dict[str, Any] | None = None
_PORTFOLIO_WORKER_PRICE_TREES: dict[str, tuple[PriceTree, PriceTree]] = {}


def simulate_symbol_entry(task: tuple[str, tuple[str, str, int | None, int | None, str | None, bool | None], bool]) -> tuple[str, list[tuple[float, int]] | None, int, int, bool]:
    symbol, entry_spec, include_results = task
    state = _PORTFOLIO_WORKER_STATE
    if state is None:
        raise RuntimeError("Portfolio ranking worker state is not initialized")
    entry_id, _, lookback, threshold, entry_family, immediate_short = entry_spec
    quotes_by_symbol = state["quotes_by_symbol"]
    quotes = quotes_by_symbol.get(symbol)
    if quotes is None or len(quotes) < 2:
        return symbol, None, 0, 0, False

    if immediate_short is not None:
        signals, shorts = np.array([0], dtype=np.int64), np.array([immediate_short], dtype=np.bool_)
    else:
        signals, shorts = state["signals_by_symbol"][symbol][
            (int(lookback), entry_family or "both", int(threshold))
        ]
    args = state["args"]
    if len(signals) == 0 or (args.min_trades > len(signals) and args.martingale_mode == "none"):
        return symbol, None, 0, 0, False

    price_trees = _PORTFOLIO_WORKER_PRICE_TREES.get(symbol)
    if price_trees is None:
        price_trees = (PriceTree(quotes.bids), PriceTree(quotes.asks))
        _PORTFOLIO_WORKER_PRICE_TREES[symbol] = price_trees

    cache_filename = hashlib.sha256(entry_id.encode("utf-8")).hexdigest() + ".json.z"
    cache_path = state["pair_cache_scopes"][symbol] / cache_filename
    cached_results = load_pair_result_cache(cache_path)
    cache_updated = False
    hits = 0
    misses = 0
    results: list[tuple[float, int]] | None = [] if include_results else None
    for exit_id, _, take_profit, stop_loss, target_policy in state["exit_options"]:
        cached_result = cached_results.get(exit_id)
        if cached_result is None:
            pnl, trade_count = simulate(
                quotes,
                signals,
                shorts,
                price_trees[0],
                price_trees[1],
                args.balance,
                state["fee_rate"],
                take_profit,
                stop_loss,
                args.martingale_mode,
                args.martingale_timing,
                args.martingale_attempts,
                target_policy,
            )
            cached_results[exit_id] = (pnl, trade_count)
            cache_updated = True
            misses += 1
        else:
            pnl, trade_count = cached_result
            hits += 1
        if results is not None:
            results.append((pnl, trade_count))

    if cache_updated:
        write_pair_result_cache(cache_path, cached_results)
    return symbol, results, hits, misses, True


def main() -> int:
    global _PORTFOLIO_WORKER_STATE
    parser = argparse.ArgumentParser()
    parser.add_argument("--cache-key", required=True)
    parser.add_argument("--cache-generation", default="benchmark")
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
    parser.add_argument("--workers", type=int, choices=range(1, 5), default=4)
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
            return 0

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
    progress_reporter = ProgressReporter()
    batch_temp = None
    process_pool: ProcessPoolExecutor | None = None
    write_json(status_path, {"status": "pending", "progress": 0, "queued": False})
    try:
        max_ids = {str(symbol): int(max_id) for symbol, max_id in json.loads(args.max_ids).items()}
        if not max_ids:
            write_json(result_path, {
                "status": "ready", "global_top": [], "ticker_leaders": [], "symbols": [],
                "universe_size": 0, "min_trades": args.min_trades,
            })
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

        quotes_by_symbol = {symbol: QuoteArrays.from_rows(rows) for symbol, rows in rows_by_symbol.items() if rows}
        del raw_rows, rows_by_symbol
        _, pair_cache_scopes = pair_result_cache_paths(
            args.cache_dir,
            args.cache_generation,
            config["category"],
            max_ids,
            args.start_day,
            args.end_day,
            args.balance,
            args.fee_percent,
            args.min_trades,
            args.martingale_mode,
            args.martingale_timing,
            args.martingale_attempts,
        )
        signals_by_symbol = {
            symbol: make_signal_profiles(quotes, ranking)
            for symbol, quotes in quotes_by_symbol.items()
        }
        specs = build_entry_specs(ranking)
        base_candidate_count = exit_count(exit_profiles)
        duplicate_exit_policies = 2 if args.martingale_mode != "none" else 1
        candidate_count = base_candidate_count + (duplicate_exit_policies - 1) * (
            len(exit_values("fixed_tp", exit_profiles)) * len(exit_values("fixed_sl", exit_profiles))
            + len(exit_values("loss", exit_profiles))
        )
        total = len(specs) * candidate_count
        completed = 0
        universe_size = len(max_ids)
        fee_rate = args.fee_percent / 100
        pair_cache_hits = 0
        pair_cache_misses = 0
        global_top: list[tuple[float, int, str, dict[str, Any]]] = []
        exit_options = [
            (
                f"{base_exit_id}:{target_policy}" if policy_label else base_exit_id,
                base_exit_label + (f" · {policy_label}" if policy_label else ""),
                take_profit,
                stop_loss,
                target_policy,
            )
            for base_exit_id, base_exit_label, take_profit, stop_loss in exit_specs(exit_profiles)
            for target_policy, policy_label in martingale_target_policies(args.martingale_mode, stop_loss)
        ]
        _PORTFOLIO_WORKER_STATE = {
            "quotes_by_symbol": quotes_by_symbol,
            "signals_by_symbol": signals_by_symbol,
            "pair_cache_scopes": pair_cache_scopes,
            "exit_options": exit_options,
            "args": args,
            "fee_rate": fee_rate,
        }
        worker_count = min(args.workers, len(quotes_by_symbol), os.cpu_count() or 1)
        if worker_count > 1:
            process_pool = ProcessPoolExecutor(
                max_workers=worker_count,
                mp_context=multiprocessing.get_context("fork"),
            )
        batch_temp = tempfile.TemporaryDirectory(prefix=f"portfolio-ranking-{args.cache_key[:12]}-", dir=args.cache_dir)
        batch_directory = Path(batch_temp.name)
        candidate_batches: list[tuple[str, Path]] = []
        best_by_symbol: dict[str, dict[str, Any]] = {}
        selected_entry_best: dict[str, dict[str, Any]] = {}

        for entry_batch_index, (entry_id, entry_label, lookback, threshold, entry_family, immediate_short) in enumerate(specs):
            batch_average_pnls = array("d")
            batch_eligible_counts = array("I")
            batch_exit_indexes = array("I")
            batch_aggregate_pnls = array("d", [0.0]) * len(exit_options)
            batch_eligible_symbols = array("I", [0]) * len(exit_options)
            batch_profitable_symbols = array("I", [0]) * len(exit_options)
            ticker_tasks = [
                (symbol, (entry_id, entry_label, lookback, threshold, entry_family, immediate_short), process_pool is None)
                for symbol, quotes in quotes_by_symbol.items()
                if len(quotes) >= 2
            ]
            simulation_results = (
                process_pool.map(simulate_symbol_entry, ticker_tasks)
                if process_pool is not None
                else (simulate_symbol_entry(task) for task in ticker_tasks)
            )
            active_symbols = 0
            for symbol, pair_results, hits, misses, active in simulation_results:
                pair_cache_hits += hits
                pair_cache_misses += misses
                if not active:
                    continue
                active_symbols += 1
                cached_results = None
                if pair_results is None:
                    cache_filename = hashlib.sha256(entry_id.encode("utf-8")).hexdigest() + ".json.z"
                    cached_results = load_pair_result_cache(pair_cache_scopes[symbol] / cache_filename)
                for exit_index, (exit_id, exit_label, _, _, _) in enumerate(exit_options):
                    pnl, trade_count = pair_results[exit_index] if pair_results is not None else cached_results[exit_id]
                    if trade_count < args.min_trades:
                        continue
                    batch_aggregate_pnls[exit_index] += pnl
                    batch_eligible_symbols[exit_index] += 1
                    if pnl > 0:
                        batch_profitable_symbols[exit_index] += 1
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

            if not active_symbols:
                # No ticker can meet the trade minimum for this entry. Count
                # its candidates as completed without simulating each exit.
                completed += candidate_count
                progress_reporter.update(status_path, {
                    "status": "pending",
                    "progress": min(99, int(completed * 100 / total)),
                    "elapsed_seconds": int(time.monotonic() - started),
                    "entry_profile": entry_label,
                    "completed_entry_profiles": entry_batch_index + 1,
                    "total_entry_profiles": len(specs),
                })
                continue

            entry_winners_by_exit: dict[str, list[dict[str, Any]]] = {}
            for winner in best_by_symbol.values():
                if winner["entry_id"] == entry_id:
                    entry_winners_by_exit.setdefault(winner["exit_id"], []).append(winner)

            for exit_index, (exit_id, exit_label, _, _, _) in enumerate(exit_options):
                eligible_symbols = batch_eligible_symbols[exit_index]
                if eligible_symbols:
                    aggregate_pnl = batch_aggregate_pnls[exit_index]
                    profitable_symbols = batch_profitable_symbols[exit_index]
                    average_all_symbols = aggregate_pnl / universe_size
                    for winner in entry_winners_by_exit.get(exit_id, []):
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
                    batch_average_pnls.append(average_all_symbols)
                    batch_eligible_counts.append(eligible_symbols)
                    batch_exit_indexes.append(exit_index)
                    combination_key = f"{entry_id}|{exit_id}"
                    heap_item = (average_all_symbols, eligible_symbols, combination_key, candidate)
                    if len(global_top) < 5:
                        heapq.heappush(global_top, heap_item)
                    elif heap_item[:3] > global_top[0][:3]:
                        heapq.heapreplace(global_top, heap_item)

                completed += 1
                if completed % 5000 == 0:
                    progress_reporter.update(status_path, {
                        "status": "pending",
                        "progress": min(99, int(completed * 100 / total)),
                        "elapsed_seconds": int(time.monotonic() - started),
                        "entry_profile": entry_label,
                        "completed_entry_profiles": entry_batch_index,
                        "total_entry_profiles": len(specs),
                    })

            if batch_average_pnls:
                batch_order = list(range(len(batch_average_pnls)))
                batch_order.sort(key=lambda index: exit_options[batch_exit_indexes[index]][0])
                batch_order.sort(key=batch_eligible_counts.__getitem__, reverse=True)
                batch_order.sort(key=batch_average_pnls.__getitem__, reverse=True)
                batch_path = batch_directory / f"entry-{entry_batch_index:04d}.bin"
                with batch_path.open("wb") as batch_file:
                    for index in batch_order:
                        batch_file.write(CANDIDATE_RECORD.pack(
                            batch_average_pnls[index],
                            batch_eligible_counts[index],
                            batch_exit_indexes[index],
                        ))
                candidate_batches.append((entry_id, batch_path))
            progress_reporter.update(status_path, {
                "status": "pending",
                "progress": min(99, int(completed * 100 / total)),
                "elapsed_seconds": int(time.monotonic() - started),
                "entry_profile": entry_label,
                "completed_entry_profiles": entry_batch_index + 1,
                "total_entry_profiles": len(specs),
            })

        if process_pool is not None:
            process_pool.shutdown(wait=True)
            process_pool = None
        ranked_ticker_leaders = sorted(best_by_symbol.values(), key=lambda item: (-item["pnl"], item["symbol"]))
        for rank, leader in enumerate(ranked_ticker_leaders, start=1):
            leader["rank"] = rank
        winning_combinations = {
            f"{leader['entry_id']}|{leader['exit_id']}"
            for leader in ranked_ticker_leaders
        }
        global_rank_by_combination: dict[str, int] = {}
        batch_streams = []
        merge_heap = []
        try:
            for batch_index, (entry_id, batch_path) in enumerate(candidate_batches):
                batch_stream = batch_path.open("rb")
                batch_streams.append(batch_stream)
                record = batch_stream.read(CANDIDATE_RECORD.size)
                if record:
                    average_pnl, eligible_count, exit_index = CANDIDATE_RECORD.unpack(record)
                    combination_key = f"{entry_id}|{exit_options[exit_index][0]}"
                    heapq.heappush(merge_heap, (-average_pnl, -eligible_count, combination_key, batch_index, exit_index))

            global_rank = 0
            while merge_heap:
                _, _, combination_key, batch_index, _ = heapq.heappop(merge_heap)
                global_rank += 1
                if combination_key in winning_combinations:
                    global_rank_by_combination[combination_key] = global_rank
                record = batch_streams[batch_index].read(CANDIDATE_RECORD.size)
                if record:
                    if len(record) != CANDIDATE_RECORD.size:
                        raise OSError("Incomplete overall-ranking score batch")
                    average_pnl, eligible_count, exit_index = CANDIDATE_RECORD.unpack(record)
                    entry_id = candidate_batches[batch_index][0]
                    next_key = f"{entry_id}|{exit_options[exit_index][0]}"
                    heapq.heappush(merge_heap, (-average_pnl, -eligible_count, next_key, batch_index, exit_index))
        finally:
            for batch_stream in batch_streams:
                batch_stream.close()
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
        batch_temp.cleanup()
        batch_temp = None
        write_json(result_path, {
            "status": "ready",
            "global_top": sorted_global,
            "ticker_leaders": ticker_leaders,
            "symbols": symbols,
            "universe_size": universe_size,
            "min_trades": args.min_trades,
        })
        write_json(status_path, {
            "status": "ready",
            "progress": 100,
            "elapsed_seconds": int(time.monotonic() - started),
            "pair_cache_hits": pair_cache_hits,
            "pair_cache_misses": pair_cache_misses,
            "workers": worker_count,
        })
        return 0
    except Exception as exc:
        if process_pool is not None:
            process_pool.shutdown(wait=True, cancel_futures=True)
        if batch_temp is not None:
            batch_temp.cleanup()
        write_json(status_path, {"status": "error", "progress": 0, "message": "Не удалось рассчитать общий рейтинг комбинаций."})
        print(f"portfolio ranking failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
