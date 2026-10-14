#!/usr/bin/env python3
"""Compare entry/exit pairs across every symbol in the selected date range."""

from __future__ import annotations

import argparse
from concurrent.futures import ProcessPoolExecutor
import fcntl
import hashlib
import heapq
import json
import math
import multiprocessing
import os
import shutil
import sys
import time
import uuid
import zlib
from datetime import date, datetime, time as day_time, timedelta, timezone
from pathlib import Path
from typing import Any, Iterator
from zoneinfo import ZoneInfo

import numpy as np
import pymysql

from app import load_config
from contract_rules import load_contract_specs, numba_market_order_rules
from futures_costs import load_historical_funding_rates
from database import connect_mysql, ensure_strategy_return_profile, flush_strategy_return_points
from ranking_checkpoints import (
    CHECKPOINT_MIN_FREE_BYTES,
    STATE_DTYPE,
    daily_quote_boundaries,
    dated_state_checkpoint_path,
    has_dated_state_checkpoint,
    load_best_state_checkpoint,
    quote_prefix_fingerprint,
    write_state_checkpoint,
)
from strategy_ranking import (
    PriceTree,
    ProgressReporter,
    QuoteArrays,
    build_entry_specs,
    exit_values,
    finalize_standard_checkpoints,
    make_signal_profiles,
    martingale_target_policies,
    simulate,
    simulate_martingale_batch,
    simulate_standard_checkpoint,
    simulate_standard_checkpoint_days,
    standard_range_results,
    write_json,
)

KYIV = ZoneInfo("Europe/Kyiv")
_write_json_without_return_key = write_json
PORTFOLIO_RESULT_DTYPE = np.dtype([("pnl", "<f8"), ("trade_count", "<u4"), ("chain_count", "<u4")])


def durable_result_path(
    cache_dir: Path, args: argparse.Namespace, category: str,
    max_ids: dict[str, int], quotes_by_symbol: dict[str, QuoteArrays],
    order_rules_by_symbol: dict[str, tuple[float, float, int, float, float]] | None = None,
) -> Path:
    quote_versions = [
        (symbol, max_ids[symbol], quote_prefix_fingerprint(quotes_by_symbol[symbol], len(quotes_by_symbol[symbol]))
         if symbol in quotes_by_symbol else None)
        for symbol in sorted(max_ids)
    ]
    key = hashlib.sha256(json.dumps([
        "portfolio-durable-result-v11-no-selected-entry-range-reset-at-start" if args.martingale_mode == "none" else "portfolio-durable-result-v14-no-selected-entry-market-order-sizing", category, args.start_day, args.end_day,
        args.balance, args.fee_percent, args.min_trades,
        args.ranking_params, args.martingale_mode, args.martingale_timing,
        args.martingale_attempts, args.martingale_max_chain_loss,
        args.martingale_max_day_loss, getattr(args, "include_funding", "1"),
        getattr(args, "slippage_bps", 0.0),
        [(symbol, (order_rules_by_symbol or {}).get(symbol)) for symbol in sorted(max_ids)], quote_versions,
    ], separators=(",", ":")).encode("utf-8")).hexdigest()
    return cache_dir.parent / "bitget-simulation-checkpoints" / "portfolio-results-v1" / key[:2] / f"{key}.json"


def exit_specs(profiles: dict[str, Any]) -> Iterator[tuple[str, str, float | None, float | None]]:
    if profiles.get("no_exit"):
        yield "none", "Без выхода · удержание до конца периода", None, None
        return
    for target in exit_values("fixed_tp", profiles):
        for loss in exit_values("fixed_sl", profiles):
            yield (
                f"fixed:{target}:{loss}",
                f"TP {target / 100:.2f}% / SL {loss / 100:.2f}% от стартового баланса",
                target / 10.0,
                loss / 10.0,
            )
    for loss in exit_values("loss", profiles):
        yield f"loss:{loss}", f"Stop Loss {loss / 10:.1f}% от стартового баланса", None, float(loss)
    for target in exit_values("profit", profiles):
        yield f"profit:{target}", f"Take Profit {target / 10:.1f}% от стартового баланса", float(target), None


def exit_count(profiles: dict[str, Any]) -> int:
    if profiles.get("no_exit"):
        return 1
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
    martingale_max_chain_loss: float | None,
    martingale_max_day_loss: float | None,
    include_funding: str = "1",
    slippage_bps: float = 0.0,
    order_rules_by_symbol: dict[str, tuple[float, float, int, float, float]] | None = None,
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
            "portfolio-pair-simulation-v11-range-reset-at-start" if martingale_mode == "none" else "portfolio-pair-simulation-v14-market-order-sizing",
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
            martingale_max_chain_loss,
            martingale_max_day_loss,
            include_funding,
            slippage_bps,
            (order_rules_by_symbol or {}).get(symbol),
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


def load_pair_result_cache(path: Path) -> dict[str, tuple[float, int, int]]:
    try:
        payload = json.loads(zlib.decompress(path.read_bytes()))
    except (OSError, UnicodeDecodeError, zlib.error, json.JSONDecodeError):
        return {}
    if not isinstance(payload, dict):
        return {}
    results: dict[str, tuple[float, int, int]] = {}
    for exit_id, result in payload.items():
        if not isinstance(exit_id, str) or not isinstance(result, list) or len(result) not in (2, 3):
            continue
        try:
            pnl = float(result[0])
            trade_count = int(result[1])
            chain_count = int(result[2]) if len(result) == 3 else trade_count
        except (TypeError, ValueError, OverflowError):
            continue
        if math.isfinite(pnl) and trade_count >= 0 and chain_count >= 0:
            results[exit_id] = (pnl, trade_count, chain_count)
    return results


def write_pair_result_cache(path: Path, results: dict[str, tuple[float, int, int]]) -> None:
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    payload = json.dumps(
        {exit_id: [pnl, trade_count, chain_count] for exit_id, (pnl, trade_count, chain_count) in results.items()},
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")
    compressed = zlib.compress(payload, level=1)
    temporary_path = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    temporary_path.write_bytes(compressed)
    temporary_path.replace(path)


_PORTFOLIO_WORKER_STATE: dict[str, Any] | None = None
_PORTFOLIO_WORKER_PRICE_TREES: dict[str, tuple[PriceTree, PriceTree]] = {}
_PORTFOLIO_WORKER_TRADE_CONNECTION = None
_PORTFOLIO_WORKER_SOURCE_BUILDS: dict[str, str | None] = {}


def return_unit_compatibility_key(state: dict[str, Any], symbol: str) -> str:
    """Identify a ticker simulation independently of the list that requested it."""
    args = state["args"]
    return hashlib.sha256(json.dumps([
        "strategy-return-trade-unit-v2-boundary-checked",
        state["category"], args.balance, args.fee_percent,
        args.ranking_params, args.martingale_mode, args.martingale_timing,
        args.martingale_attempts, args.martingale_max_chain_loss,
        args.martingale_max_day_loss, args.include_funding, args.slippage_bps,
        state["order_rules_by_symbol"][symbol], args.cache_generation,
    ], separators=(",", ":")).encode("utf-8")).hexdigest()


def simulate_symbol_entry(task: tuple[str, tuple[str, str, int | None, int | None, str | None, bool | None], bool]) -> tuple[str, np.ndarray | None, int, int, int, bool, str, tuple[str, ...]]:
    symbol, entry_spec, include_results = task
    state = _PORTFOLIO_WORKER_STATE
    if state is None:
        raise RuntimeError("Portfolio ranking worker state is not initialized")
    entry_id, _, lookback, threshold, entry_family, immediate_short = entry_spec
    quotes_by_symbol = state["quotes_by_symbol"]
    quotes = quotes_by_symbol.get(symbol)
    if quotes is None or len(quotes) < 2:
        return symbol, None, 0, 0, 0, False, "unused", ()

    args = state["args"]
    global _PORTFOLIO_WORKER_TRADE_CONNECTION
    if args.persist_returns and _PORTFOLIO_WORKER_TRADE_CONNECTION is None:
        _PORTFOLIO_WORKER_TRADE_CONNECTION = connect_mysql(state["mysql_config"])
    trade_connection = _PORTFOLIO_WORKER_TRADE_CONNECTION if args.persist_returns else None
    if trade_connection is not None:
        trade_connection.ping(reconnect=True)
    source_return_run_key = ""
    source_return_start_day = args.start_day
    source_return_end_day = ""
    trade_rows: list[tuple[Any, ...]] = []
    profile_ids: dict[str, int] = {}
    trade_insert_sql = (
        "INSERT IGNORE INTO strategy_return_points "
        "(symbol,strategy_id,point_minute,return_percent,trade_count) VALUES (%s,%s,%s,%s,%s)"
    )
    range_start_timestamp = datetime.combine(date.fromisoformat(args.start_day), day_time.min, KYIV).timestamp()
    range_start_quote_count = int(np.searchsorted(quotes.timestamps, range_start_timestamp, side="left"))
    if immediate_short is not None:
        if range_start_quote_count < len(quotes):
            signals, shorts = np.array([range_start_quote_count], dtype=np.int64), np.array([immediate_short], dtype=np.bool_)
        else:
            signals, shorts = np.empty(0, dtype=np.int64), np.empty(0, dtype=np.bool_)
    else:
        signals, shorts = state["signals_by_symbol"][symbol][
            (int(lookback), entry_family or "both", int(threshold))
        ]
    symbol_state = dict(state)
    symbol_state["order_rules"] = state["order_rules_by_symbol"][symbol]
    use_checkpoint = args.martingale_mode == "none"
    if not use_checkpoint and (len(signals) == 0 or args.min_trades > len(signals)):
        return symbol, None, 0, 0, 0, False, "unused", ()

    cache_filename = hashlib.sha256(entry_id.encode("utf-8")).hexdigest() + ".json.z"
    cache_path = state["pair_cache_scopes"][symbol] / cache_filename
    checkpoint_path = dated_state_checkpoint_path(symbol_state, symbol, entry_id, args.end_day) if use_checkpoint else None
    source_day = (
        datetime.fromtimestamp(float(quotes.timestamps[0]), timezone.utc)
        .astimezone(KYIV).date().isoformat()
    )
    all_day_boundaries = [
        (day, count) for day, count in daily_quote_boundaries(
            quotes.timestamps, source_day, args.end_day,
        ) if count > 0
    ] if use_checkpoint else []
    range_boundaries = [(day, count) for day, count in all_day_boundaries if day >= args.start_day]
    price_trees = _PORTFOLIO_WORKER_PRICE_TREES.get(symbol)
    if price_trees is None:
        price_trees = (PriceTree(quotes.bids), PriceTree(quotes.asks))
        _PORTFOLIO_WORKER_PRICE_TREES[symbol] = price_trees

    def validated_source_checkpoint(candidate_start_day: str, candidate_checkpoint_day: str):
        candidate_state = dict(symbol_state)
        candidate_args = argparse.Namespace(**vars(args))
        candidate_args.start_day = candidate_start_day
        candidate_state["args"] = candidate_args
        loaded = load_best_state_checkpoint(
            candidate_state, symbol, entry_id, candidate_checkpoint_day,
            quotes, len(state["exit_options"]),
        )
        if loaded is None or loaded[2] != candidate_checkpoint_day:
            return None
        if candidate_start_day == args.start_day:
            return loaded
        boundary_timestamp = datetime.combine(
            date.fromisoformat(candidate_start_day), day_time.min, KYIV,
        ).timestamp()
        boundary_quote_count = int(np.searchsorted(quotes.timestamps, boundary_timestamp, side="left"))
        if boundary_quote_count <= range_start_quote_count:
            return loaded
        initial_state = (args.balance, range_start_quote_count, 0, -1, False)
        boundary_counts = np.asarray([boundary_quote_count], dtype=np.int64)
        for _, _, take_profit, stop_loss, _ in state["exit_options"]:
            _, _, prefix_state, _ = simulate_standard_checkpoint_days(
                quotes, signals, shorts, price_trees[0], price_trees[1],
                args.balance, state["fee_rate"], take_profit, stop_loss,
                boundary_counts, initial_state,
                state["take_confirmation_snapshots"], state["stop_confirmation_snapshots"],
                state["order_rules_by_symbol"][symbol], False,
            )
            if (not math.isclose(prefix_state[0], args.balance, rel_tol=0.0, abs_tol=1e-9)
                or prefix_state[2] != 0 or prefix_state[3] >= 0):
                return None
        return loaded

    previous_checkpoint = None
    first_missing_day = next((index for index, (day, _) in enumerate(range_boundaries)
        if not has_dated_state_checkpoint(symbol_state, symbol, entry_id, day)), None) if use_checkpoint else None
    resume_until_day = (
        source_return_end_day
        if args.persist_returns and source_return_run_key and source_return_end_day
        else (None if args.persist_returns else (
        range_boundaries[first_missing_day - 1][0]
        if first_missing_day is not None and first_missing_day > 0
        else (None if first_missing_day == 0 else args.end_day)
        ))
    ) if use_checkpoint else None
    if previous_checkpoint is None and checkpoint_path is not None and resume_until_day is not None:
        previous_checkpoint = load_best_state_checkpoint(
            symbol_state, symbol, entry_id, resume_until_day, quotes, len(state["exit_options"]),
        )
    previous_states = previous_checkpoint[0] if previous_checkpoint is not None else None
    previous_quote_count = previous_checkpoint[1] if previous_checkpoint is not None else 0
    previous_checkpoint_day = previous_checkpoint[2] if previous_checkpoint is not None else None
    bridged_checkpoint_day = None
    if (previous_checkpoint is not None and source_return_start_day != args.start_day
        and previous_checkpoint_day is not None):
        bridge_path = dated_state_checkpoint_path(symbol_state, symbol, entry_id, previous_checkpoint_day)
        if write_state_checkpoint(
            bridge_path, previous_checkpoint_day, quotes, previous_states, previous_quote_count,
        ):
            bridged_checkpoint_day = previous_checkpoint_day
    prior_events_by_exit: dict[str, tuple[int, float]] = {}
    if (not args.persist_returns and use_checkpoint and first_missing_day is None and previous_states is not None
        and previous_quote_count == len(quotes)):
        result_pnls, result_trades = standard_range_results(
            quotes, args.balance, state["fee_rate"], None, previous_states,
            state["order_rules_by_symbol"][symbol],
        )
        results = np.empty(len(result_pnls), dtype=PORTFOLIO_RESULT_DTYPE)
        results["pnl"] = result_pnls
        results["trade_count"] = result_trades
        results["chain_count"] = result_trades
        # The durable checkpoint already contains the full simulation state;
        # no per-exit kernel call or broom-reset pair-result file is needed.
        reused_days = tuple(day for day, loaded in (
            (previous_checkpoint_day, previous_checkpoint is not None),
        ) if day is not None and loaded)
        return symbol, results, 0, 0, len(result_pnls), True, "unused", reused_days

    boundaries_to_capture = [
        (day, count) for day, count in range_boundaries
        if use_checkpoint and count > 0 and (
            previous_checkpoint_day is None
            or day > previous_checkpoint_day
            or (day == previous_checkpoint_day and count > previous_quote_count)
        )
    ]
    day_quote_counts_to_capture = np.fromiter((count for _, count in boundaries_to_capture), dtype=np.int64)

    cached_results = load_pair_result_cache(cache_path)
    batch_results: dict[tuple[str, str], tuple[float, int, int]] = {}
    if args.martingale_mode != "none":
        uncached = [
            (exit_id, take_profit, stop_loss, target_policy)
            for exit_id, _, take_profit, stop_loss, target_policy in state["exit_options"]
            if exit_id not in cached_results
        ]
        if uncached:
            batch_results = simulate_martingale_batch(
                quotes, signals, shorts, price_trees[0], price_trees[1],
                args.balance, state["fee_rate"], args.martingale_mode,
                args.martingale_timing, args.martingale_attempts,
                args.martingale_max_chain_loss, args.martingale_max_day_loss,
                uncached,
                state["take_confirmation_snapshots"], state["stop_confirmation_snapshots"],
                state["order_rules_by_symbol"][symbol],
            )
    cache_updated = False
    checkpoint_updated = bool(boundaries_to_capture)
    hits = 0
    misses = 0
    results = np.empty(len(state["exit_options"]), dtype=PORTFOLIO_RESULT_DTYPE) if include_results else None
    next_states: list[tuple[float, int, int, int, bool]] = []
    states_by_day: dict[str, np.ndarray] = {
        day: np.empty(len(state["exit_options"]), dtype=STATE_DTYPE) for day, _ in boundaries_to_capture
    }
    state_counts_by_day = {day: 0 for day, _ in boundaries_to_capture}
    for exit_index, (exit_id, _, take_profit, stop_loss, target_policy) in enumerate(state["exit_options"]):
        cached_result = cached_results.get(exit_id)
        if use_checkpoint and (previous_states is None or previous_quote_count != len(quotes)):
            # Cached endpoint PnL cannot stand in for a state checkpoint at this
            # endpoint. Recompute this option once so the next extension is safe.
            cached_result = None
        if cached_result is None:
            if use_checkpoint:
                previous_state = previous_states[exit_index] if previous_states is not None else (args.balance, range_start_quote_count, 0, -1, False)
                if boundaries_to_capture:
                    state_index = len(next_states)
                    simulation_result = simulate_standard_checkpoint_days(
                        quotes, signals, shorts, price_trees[0], price_trees[1],
                        args.balance, state["fee_rate"], take_profit, stop_loss,
                        day_quote_counts_to_capture, previous_state,
                        state["take_confirmation_snapshots"], state["stop_confirmation_snapshots"],
                        state["order_rules_by_symbol"][symbol],
                        bool(args.persist_returns),
                    )
                    pnl, trade_count, next_state, day_states = simulation_result[:4]
                    if args.persist_returns:
                        trade_events = simulation_result[4]
                        prior_trade_count, prior_cumulative_percent = prior_events_by_exit.get(exit_id, (0, 0.0))
                        cumulative_factor = 1.0 + prior_cumulative_percent / 100.0
                        for trade_index, (entry_quote_index, exit_quote_index, event_pnl, balance_before, provisional) in enumerate(trade_events):
                            if balance_before <= 0 or entry_quote_index < 0 or exit_quote_index < 0:
                                continue
                            trade_return = event_pnl / balance_before
                            cumulative_factor *= 1.0 + trade_return
                            if not provisional:
                                strategy_id = profile_ids.get(exit_id)
                                if strategy_id is None:
                                    strategy_id = ensure_strategy_return_profile(
                                        trade_connection, entry_id, entry_spec[1], exit_id,
                                        state["exit_options"][exit_index][1],
                                        state["exit_confirmation_mode"],
                                        state["exit_confirmation_snapshots"] if state["exit_confirmation_mode"] != "immediate" else 0,
                                    )
                                    profile_ids[exit_id] = strategy_id
                                point_time = datetime.fromtimestamp(
                                    float(quotes.timestamps[exit_quote_index]), timezone.utc
                                ).replace(second=0, microsecond=0, tzinfo=None)
                                trade_rows.append((symbol, strategy_id, point_time, f"{trade_return * 100:.10f}", 1))
                        if len(trade_rows) >= 5000:
                            flush_strategy_return_points(trade_connection, trade_rows)
                            trade_rows.clear()
                    for index, (day, _) in enumerate(boundaries_to_capture):
                        states_by_day[day][state_index] = day_states[index]
                        state_counts_by_day[day] += 1
                else:
                    pnl, trade_count, next_state = simulate_standard_checkpoint(
                        quotes, signals, shorts, price_trees[0], price_trees[1],
                        args.balance, state["fee_rate"], take_profit, stop_loss, previous_state,
                        state["take_confirmation_snapshots"], state["stop_confirmation_snapshots"],
                        state["order_rules_by_symbol"][symbol],
                    )
                next_states.append(next_state)
                chain_count = trade_count
                checkpoint_updated = True
            else:
                if args.martingale_mode != "none":
                    pnl, trade_count, chain_count = batch_results[(exit_id, target_policy)]
                else:
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
                        args.martingale_max_chain_loss,
                        args.martingale_max_day_loss,
                        state["take_confirmation_snapshots"], state["stop_confirmation_snapshots"],
                        state["order_rules_by_symbol"][symbol],
                    )
                    chain_count = trade_count
                if use_checkpoint:
                    chain_count = trade_count
            cached_results[exit_id] = (pnl, trade_count, chain_count)
            cache_updated = True
            misses += 1
        else:
            pnl, trade_count, chain_count = cached_result
            if use_checkpoint and previous_states is not None:
                previous_state = previous_states[exit_index]
                next_states.append(previous_state)
                for day, count in boundaries_to_capture:
                    if count <= previous_quote_count:
                        state_index = len(next_states) - 1
                        states_by_day[day][state_index] = previous_state
                        state_counts_by_day[day] += 1
            hits += 1
        if results is not None:
            results[exit_index] = (pnl, trade_count, chain_count)

    if trade_connection is not None:
        if trade_rows:
            flush_strategy_return_points(trade_connection, trade_rows)
    if cache_updated:
        write_pair_result_cache(cache_path, cached_results)
    checkpoint_status = "unused"
    if checkpoint_updated and len(next_states) == len(state["exit_options"]):
        checkpoint_status = "saved"
        for day, day_states in states_by_day.items():
            day_quote_count = next(count for current_day, count in boundaries_to_capture if current_day == day)
            day_path = dated_state_checkpoint_path(symbol_state, symbol, entry_id, day)
            if state_counts_by_day[day] != len(state["exit_options"]) or not write_state_checkpoint(day_path, day, quotes, day_states, day_quote_count):
                checkpoint_status = "skipped_low_disk"
    completed_checkpoint_day = None
    if checkpoint_status == "saved" and boundaries_to_capture:
        completed_checkpoint_day = boundaries_to_capture[-1][0]
    elif bridged_checkpoint_day == args.end_day and previous_quote_count == len(quotes):
        completed_checkpoint_day = bridged_checkpoint_day
    elif (source_return_start_day == args.start_day and previous_checkpoint_day == args.end_day
          and previous_quote_count == len(quotes)):
        completed_checkpoint_day = previous_checkpoint_day
    reused_days = tuple(day for day, loaded in (
        (previous_checkpoint_day, previous_checkpoint is not None),
    ) if day is not None and loaded)
    return symbol, results, hits, misses, 0, True, checkpoint_status, reused_days


def main() -> int:
    global _PORTFOLIO_WORKER_STATE
    parser = argparse.ArgumentParser()
    parser.add_argument("--cache-key", required=True)
    parser.add_argument("--cache-generation", default="benchmark")
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
    parser.add_argument("--include-funding", choices=("0", "1"), default="1")
    parser.add_argument("--slippage-bps", type=float, default=0.0)
    parser.add_argument("--workers", type=int, choices=range(1, 5), default=4)
    parser.add_argument("--persist-returns", action="store_true")
    parser.add_argument("--return-run-key", default="")
    parser.add_argument("--return-scope-id", default="")
    parser.add_argument("--return-scope-name", default="")
    parser.add_argument("--return-scope-symbol", default="")
    parser.add_argument("--source-return-runs", default="{}")
    parser.add_argument("--return-history-only", action="store_true")
    args = parser.parse_args()
    source_return_runs = json.loads(args.source_return_runs)
    if not isinstance(source_return_runs, dict):
        raise RuntimeError("Неверная карта ранее рассчитанной доходности по тикерам.")
    if args.persist_returns:
        source_return_runs = {}
    ranking = json.loads(args.ranking_params)
    exit_profiles = ranking["exit_profiles"]
    confirmation_snapshots = int(ranking.get("exit_confirmation_snapshots", 60))
    confirmation_mode = ranking.get("exit_confirmation_mode", "immediate")
    take_confirmation_snapshots = confirmation_snapshots if confirmation_mode in ("both_delayed", "tp_delayed") else 0
    stop_confirmation_snapshots = confirmation_snapshots if confirmation_mode in ("both_delayed", "sl_delayed") else 0

    status_path = args.cache_dir / f"portfolio-{args.cache_key}.status.json"
    result_path = args.cache_dir / f"portfolio-{args.cache_key}.json"
    write_json_base = _write_json_without_return_key
    def write_json(path: Path, payload: dict[str, Any]) -> None:
        if args.return_run_key and path == status_path:
            payload = {
                **payload,
                "return_run_key": args.return_run_key,
                "exit_confirmation_mode": confirmation_mode,
                "exit_confirmation_snapshots": confirmation_snapshots if confirmation_mode != "immediate" else 0,
            }
        write_json_base(path, payload)
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
        if not args.persist_returns and isinstance(cached, dict) and cached.get("status") == "ready":
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
        order_rules_by_symbol = {}
        contract_specs = load_contract_specs(config["category"])
        for contract_symbol in max_ids:
            contract = contract_specs.get(contract_symbol)
            if contract is None:
                raise RuntimeError(f"Bitget contract rules are unavailable for {contract_symbol}")
            order_rules_by_symbol[contract_symbol] = numba_market_order_rules(contract, leverage=1.0)
        end_local = datetime.combine(date.fromisoformat(args.end_day) + timedelta(days=1), day_time.min, KYIV)
        end_utc = end_local.astimezone(timezone.utc).replace(tzinfo=None)
        start_local = datetime.combine(date.fromisoformat(args.start_day), day_time.min, KYIV)
        start_utc = start_local.astimezone(timezone.utc).replace(tzinfo=None)
        quotes_by_symbol: dict[str, QuoteArrays] = {}
        reused_return_symbols: set[str] = set()
        ordered_symbols = sorted(max_ids.items())
        write_json(status_path, {
            "status": "pending", "progress": 0, "phase": "loading_quotes",
            "elapsed_seconds": int(time.monotonic() - started),
            "loaded_quote_symbols": 0, "total_quote_symbols": len(ordered_symbols),
            "current_quote_symbol": ordered_symbols[0][0] if ordered_symbols else None,
            "start_day": args.start_day, "end_day": args.end_day,
        })
        connection = connect_mysql(config["mysql"])
        try:
            if args.persist_returns:
                if not args.return_run_key:
                    raise RuntimeError("Не задан ключ сохраняемого расчёта доходности.")
                connection.commit()
            ordered_symbols = [item for item in ordered_symbols if item[0] not in reused_return_symbols]
            for symbol_index, (symbol, snapshot_max_id) in enumerate(ordered_symbols, start=1):
                funding_contract = contract_specs[symbol]
                write_json(status_path, {
                    "status": "pending", "progress": 0, "phase": "loading_quotes",
                    "elapsed_seconds": int(time.monotonic() - started),
                    "loaded_quote_symbols": symbol_index - 1,
                    "total_quote_symbols": len(ordered_symbols),
                    "current_quote_symbol": symbol,
                    "reused_return_symbols": len(reused_return_symbols),
                    "start_day": args.start_day, "end_day": args.end_day,
                })
                with connection.cursor(pymysql.cursors.DictCursor) as cursor:
                    quote_query = (
                        "SELECT received_at_utc, bid_price, ask_price, last_price "
                        "FROM quote_snapshots WHERE category = %s AND symbol = %s "
                        "AND received_at_utc < %s "
                        + ("AND received_at_utc >= %s " if args.martingale_mode != "none" else "")
                        + "AND (id <= %s OR quote_source = 'historical_candle_close') "
                        "ORDER BY received_at_utc, id"
                    )
                    quote_parameters: list[Any] = [config["category"], symbol, end_utc]
                    if args.martingale_mode != "none":
                        quote_parameters.append(start_utc)
                    quote_parameters.append(snapshot_max_id)
                    cursor.execute(quote_query, quote_parameters)
                    rows = cursor.fetchall()

                if rows:
                    quotes = QuoteArrays.from_rows(rows, args.slippage_bps)
                    if args.include_funding == "1" and len(quotes):
                        funding_rows = load_historical_funding_rates(
                            config["category"], symbol,
                            float(quotes.timestamps[0]), float(quotes.timestamps[-1]),
                            contract_open_timestamp=(
                                int(funding_contract.get("openTime") or 0) / 1000
                                if str(funding_contract.get("openTime") or "").isdigit() and int(funding_contract.get("openTime") or 0) > 0
                                else None
                            ),
                            funding_interval_hours=(
                                float(funding_contract.get("fundInterval"))
                                if str(funding_contract.get("fundInterval") or "").replace(".", "", 1).isdigit()
                                else None
                            ),
                        )
                        marks = np.fromiter(
                            ((float(row["bid_price"]) + float(row["ask_price"])) / 2 for row in rows),
                            dtype=np.float64, count=len(rows),
                        )
                        quotes.set_funding_rates(funding_rows, marks)
                    quotes_by_symbol[symbol] = quotes
                del rows
                write_json(status_path, {
                    "status": "pending", "progress": 0, "phase": "loading_quotes",
                    "elapsed_seconds": int(time.monotonic() - started),
                    "loaded_quote_symbols": symbol_index,
                    "total_quote_symbols": len(ordered_symbols),
                    "current_quote_symbol": ordered_symbols[symbol_index][0]
                        if symbol_index < len(ordered_symbols) else None,
                    "reused_return_symbols": len(reused_return_symbols),
                    "start_day": args.start_day, "end_day": args.end_day,
                })
        finally:
            connection.close()
        write_json(status_path, {
            "status": "pending", "progress": 0, "phase": "building_signals",
            "elapsed_seconds": int(time.monotonic() - started),
            "loaded_quote_symbols": len(ordered_symbols),
            "total_quote_symbols": len(ordered_symbols),
            "reused_return_symbols": len(reused_return_symbols),
            "start_day": args.start_day, "end_day": args.end_day,
        })
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
            args.martingale_max_chain_loss,
            args.martingale_max_day_loss,
            args.include_funding,
            args.slippage_bps,
            order_rules_by_symbol,
        )
        saved_result_path = durable_result_path(
            args.cache_dir, args, config["category"], max_ids, quotes_by_symbol, order_rules_by_symbol,
        )
        try:
            saved_result = json.loads(saved_result_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            saved_result = None
        if not args.persist_returns and isinstance(saved_result, dict) and saved_result.get("status") == "ready":
            write_json(result_path, saved_result)
            write_json(status_path, {
                "status": "ready", "progress": 100,
                "elapsed_seconds": int(time.monotonic() - started),
                "durable_result_reuse": True,
            })
            return 0
        signal_symbols = list(quotes_by_symbol.items())
        signal_phase_started = time.monotonic()
        signals_by_symbol: dict[str, Any] = {}
        for signal_index, (symbol, quotes) in enumerate(signal_symbols, start=1):
            signals_by_symbol[symbol] = make_signal_profiles(quotes, ranking)
            if signal_index % 5 == 0 or signal_index == len(signal_symbols):
                write_json(status_path, {
                    "status": "pending", "progress": 0, "phase": "building_signals",
                    "elapsed_seconds": int(time.monotonic() - started),
                    "signals_elapsed_seconds": int(time.monotonic() - signal_phase_started),
                    "loaded_quote_symbols": len(ordered_symbols),
                    "total_quote_symbols": len(ordered_symbols),
                    "signals_built_symbols": signal_index,
                    "total_signal_symbols": len(signal_symbols),
                    "current_signal_symbol": signal_symbols[signal_index][0]
                        if signal_index < len(signal_symbols) else None,
                    "start_day": args.start_day, "end_day": args.end_day,
                })
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
        write_json(status_path, {
            "status": "pending", "progress": 0, "phase": "simulating_candidates",
            "elapsed_seconds": int(time.monotonic() - started),
            "signals_elapsed_seconds": int(time.monotonic() - signal_phase_started),
            "signals_built_symbols": len(signal_symbols),
            "total_signal_symbols": len(signal_symbols),
            "total_entry_profiles": len(specs),
            "total_candidates": total,
            "completed_candidates": 0,
            "total_ticker_evaluations": total * universe_size,
            "completed_ticker_evaluations": 0,
            "universe_size": universe_size,
            "reused_return_symbols": len(reused_return_symbols),
            "start_day": args.start_day, "end_day": args.end_day,
        })
        fee_rate = args.fee_percent / 100
        pair_cache_hits = 0
        pair_cache_misses = 0
        checkpoint_result_reuses = 0
        checkpoint_writes = 0
        checkpoint_skips = 0
        checkpoint_days_reused: dict[str, int] = {}
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
        exit_lexical_ranks = np.empty(len(exit_options), dtype=np.int32)
        for lexical_rank, exit_index in enumerate(sorted(range(len(exit_options)), key=lambda index: exit_options[index][0])):
            exit_lexical_ranks[exit_index] = lexical_rank
        exit_index_by_id = {option[0]: index for index, option in enumerate(exit_options)}
        _PORTFOLIO_WORKER_STATE = {
            "quotes_by_symbol": quotes_by_symbol,
            "take_confirmation_snapshots": take_confirmation_snapshots,
            "stop_confirmation_snapshots": stop_confirmation_snapshots,
            "exit_confirmation_mode": confirmation_mode,
            "exit_confirmation_snapshots": confirmation_snapshots,
            "signals_by_symbol": signals_by_symbol,
            "pair_cache_scopes": pair_cache_scopes,
            "checkpoint_root": args.cache_dir.parent / "bitget-simulation-checkpoints" / "portfolio-state-v1",
            "category": config["category"],
            "exit_options": exit_options,
            "args": args,
            "fee_rate": fee_rate,
            "order_rules_by_symbol": order_rules_by_symbol,
            "mysql_config": config["mysql"],
            "source_return_runs": source_return_runs,
        }
        worker_count = min(args.workers, len(quotes_by_symbol), os.cpu_count() or 1)
        if worker_count > 1:
            process_pool = ProcessPoolExecutor(
                max_workers=worker_count,
                mp_context=multiprocessing.get_context("fork"),
            )
        best_by_symbol: dict[str, dict[str, Any]] = {}

        for entry_batch_index, (entry_id, entry_label, lookback, threshold, entry_family, immediate_short) in enumerate(specs):
            batch_aggregate_pnls = np.zeros(len(exit_options), dtype=np.float64)
            batch_eligible_symbols = np.zeros(len(exit_options), dtype=np.uint32)
            batch_profitable_symbols = np.zeros(len(exit_options), dtype=np.uint32)
            ticker_tasks = [
                (symbol, (entry_id, entry_label, lookback, threshold, entry_family, immediate_short), not args.return_history_only)
                for symbol, quotes in quotes_by_symbol.items()
                if len(quotes) >= 2
            ]
            simulation_results = (
                process_pool.map(simulate_symbol_entry, ticker_tasks)
                if process_pool is not None
                else (simulate_symbol_entry(task) for task in ticker_tasks)
            )
            active_symbols = 0
            ticker_results: dict[str, np.ndarray] = {}
            for symbol, pair_results, hits, misses, checkpoint_reuses, active, checkpoint_status, reused_days in simulation_results:
                pair_cache_hits += hits
                pair_cache_misses += misses
                checkpoint_result_reuses += checkpoint_reuses
                checkpoint_writes += checkpoint_status == "saved"
                checkpoint_skips += checkpoint_status == "skipped_low_disk"
                for reused_day in reused_days:
                    checkpoint_days_reused[reused_day] = checkpoint_days_reused.get(reused_day, 0) + 1
                if not active:
                    continue
                active_symbols += 1
                if pair_results is None and not args.return_history_only:
                    cache_filename = hashlib.sha256(entry_id.encode("utf-8")).hexdigest() + ".json.z"
                    cached_results = load_pair_result_cache(pair_cache_scopes[symbol] / cache_filename)
                    pair_results = np.empty(len(exit_options), dtype=PORTFOLIO_RESULT_DTYPE)
                    pair_results["pnl"] = np.fromiter(
                        (cached_results[option[0]][0] for option in exit_options),
                        dtype=np.float64, count=len(exit_options),
                    )
                    pair_results["trade_count"] = np.fromiter(
                        (cached_results[option[0]][1] for option in exit_options),
                        dtype=np.uint32, count=len(exit_options),
                    )
                    pair_results["chain_count"] = np.fromiter(
                        (cached_results[option[0]][2] for option in exit_options),
                        dtype=np.uint32, count=len(exit_options),
                    )
                if not args.return_history_only:
                    ticker_results[symbol] = pair_results

            if args.return_history_only:
                completed += candidate_count
                progress_reporter.update(status_path, {
                    "status": "pending", "phase": "simulating_candidates",
                    "progress": min(99, int(completed * 100 / total)),
                    "elapsed_seconds": int(time.monotonic() - started),
                    "entry_profile": entry_label,
                    "completed_entry_profiles": entry_batch_index + 1,
                    "total_entry_profiles": len(specs),
                    "checkpoint_result_reuses": checkpoint_result_reuses,
                    "pair_cache_hits": pair_cache_hits,
                    "pair_cache_misses": pair_cache_misses,
                    "checkpoint_days_reused": checkpoint_days_reused,
                    "total_candidates": total,
                    "completed_candidates": completed,
                    "total_ticker_evaluations": total * universe_size,
                    "completed_ticker_evaluations": completed * universe_size,
                    "universe_size": universe_size,
                    "start_day": args.start_day,
                    "end_day": args.end_day,
                })
                continue

            for symbol, pair_results in ticker_results.items():
                pnls = pair_results["pnl"]
                trade_counts = pair_results["trade_count"]
                chain_counts = pair_results["chain_count"]
                independent_counts = chain_counts if args.martingale_mode != "none" else trade_counts
                eligible = independent_counts >= args.min_trades
                batch_aggregate_pnls += np.where(eligible, pnls, 0.0)
                batch_eligible_symbols += eligible
                batch_profitable_symbols += eligible & (pnls > 0.0)
                if not np.any(eligible):
                    continue
                best_pnl = float(np.max(pnls[eligible]))
                tied_indexes = np.flatnonzero(eligible & (pnls == best_pnl))
                best_exit_index = int(tied_indexes[np.argmax(exit_lexical_ranks[tied_indexes])])
                exit_id, exit_label = exit_options[best_exit_index][:2]
                trade_count = int(trade_counts[best_exit_index])
                chain_count = int(chain_counts[best_exit_index])
                ticker_candidate = {
                    "symbol": symbol,
                    "entry_id": entry_id,
                    "entry_label": entry_label,
                    "exit_id": exit_id,
                    "exit_label": exit_label,
                    "pnl": best_pnl,
                    "trade_count": trade_count,
                    "chain_count": chain_count,
                }
                previous = best_by_symbol.get(symbol)
                if previous is None or (best_pnl, entry_id, exit_id) > (
                    previous["pnl"], previous["entry_id"], previous["exit_id"]
                ):
                    best_by_symbol[symbol] = ticker_candidate

            if not active_symbols:
                # No ticker can meet the trade minimum for this entry. Count
                # its candidates as completed without simulating each exit.
                completed += candidate_count
                progress_reporter.update(status_path, {
                    "status": "pending", "phase": "simulating_candidates",
                    "progress": min(99, int(completed * 100 / total)),
                    "elapsed_seconds": int(time.monotonic() - started),
                    "entry_profile": entry_label,
                    "completed_entry_profiles": entry_batch_index + 1,
                    "total_entry_profiles": len(specs),
                    "checkpoint_result_reuses": checkpoint_result_reuses,
                    "pair_cache_hits": pair_cache_hits,
                    "pair_cache_misses": pair_cache_misses,
                    "checkpoint_days_reused": checkpoint_days_reused,
                    "total_candidates": total,
                    "completed_candidates": completed,
                    "total_ticker_evaluations": total * universe_size,
                    "completed_ticker_evaluations": completed * universe_size,
                    "universe_size": universe_size,
                    "start_day": args.start_day,
                    "end_day": args.end_day,
                })
                continue

            average_all_symbols = batch_aggregate_pnls / universe_size
            for winner in best_by_symbol.values():
                if winner["entry_id"] == entry_id:
                    exit_index = exit_index_by_id[winner["exit_id"]]
                    winner["market_average_pnl"] = float(average_all_symbols[exit_index])
                    winner["market_eligible_symbols"] = int(batch_eligible_symbols[exit_index])
                    winner["market_profitable_symbols"] = int(batch_profitable_symbols[exit_index])

            eligible_indexes = np.flatnonzero(batch_eligible_symbols)
            if eligible_indexes.size:
                batch_order = np.lexsort((
                    exit_lexical_ranks[eligible_indexes],
                    batch_eligible_symbols[eligible_indexes],
                    average_all_symbols[eligible_indexes],
                ))
                for ranked_index in eligible_indexes[batch_order[-5:][::-1]]:
                    exit_index = int(ranked_index)
                    exit_id, exit_label = exit_options[exit_index][:2]
                    eligible_symbols = int(batch_eligible_symbols[exit_index])
                    aggregate_pnl = float(batch_aggregate_pnls[exit_index])
                    average_pnl = float(average_all_symbols[exit_index])
                    candidate = {
                        "entry_id": entry_id,
                        "entry_label": entry_label,
                        "exit_id": exit_id,
                        "exit_label": exit_label,
                        "average_pnl": average_pnl,
                        "total_pnl": aggregate_pnl,
                        "eligible_symbols": eligible_symbols,
                        "profitable_symbols": int(batch_profitable_symbols[exit_index]),
                    }
                    combination_key = f"{entry_id}|{exit_id}"
                    heap_item = (average_pnl, eligible_symbols, combination_key, candidate)
                    if len(global_top) < 5:
                        heapq.heappush(global_top, heap_item)
                    elif heap_item[:3] > global_top[0][:3]:
                        heapq.heapreplace(global_top, heap_item)

            completed += candidate_count

            progress_reporter.update(status_path, {
                "status": "pending", "phase": "simulating_candidates",
                "progress": min(99, int(completed * 100 / total)),
                "elapsed_seconds": int(time.monotonic() - started),
                "entry_profile": entry_label,
                "completed_entry_profiles": entry_batch_index + 1,
                "total_entry_profiles": len(specs),
                "checkpoint_result_reuses": checkpoint_result_reuses,
                "checkpoint_writes": checkpoint_writes,
                "checkpoint_skips_low_disk": checkpoint_skips,
                "checkpoint_days_reused": checkpoint_days_reused,
                "total_candidates": total,
                "completed_candidates": completed,
                "total_ticker_evaluations": total * universe_size,
                "completed_ticker_evaluations": completed * universe_size,
                "universe_size": universe_size,
                "start_day": args.start_day,
                "end_day": args.end_day,
            })

        if process_pool is not None:
            process_pool.shutdown(wait=True)
            process_pool = None
        sorted_global = [item[3] for item in sorted(global_top, key=lambda item: (-item[0], -item[1], item[2]))]
        return_point_count = None
        if args.persist_returns:
            if args.martingale_mode != "none":
                raise RuntimeError("История доходности пока поддерживает обычный режим без мартингейла.")
            if not args.return_run_key:
                raise RuntimeError("Не задан ключ сохраняемого расчёта доходности.")
            write_json(status_path, {
                "status": "pending", "progress": 99, "phase": "finalizing_return_history",
                "elapsed_seconds": int(time.monotonic() - started),
                "start_day": args.start_day, "end_day": args.end_day,
                "return_run_key": args.return_run_key,
            })
            history_connection = connect_mysql(config["mysql"])
            try:
                with history_connection.cursor() as cursor:
                    cursor.execute("SELECT COUNT(*) FROM strategy_return_points WHERE point_minute >= %s AND point_minute < %s", (start_utc, end_utc))
                    count_row = cursor.fetchone()
                    return_point_count = int(count_row[0]) if count_row else 0
            finally:
                history_connection.close()
        if args.return_history_only:
            write_json(status_path, {
                "status": "ready", "progress": 100,
                "elapsed_seconds": int(time.monotonic() - started),
                "universe_size": universe_size,
                "start_day": args.start_day, "end_day": args.end_day,
                "return_run_key": args.return_run_key,
                "return_point_count": return_point_count,
            })
            return 0
        ranked_ticker_leaders = sorted(best_by_symbol.values(), key=lambda item: (-item["pnl"], item["symbol"]))
        ticker_leaders = ranked_ticker_leaders + [
            {
                "symbol": symbol,
                "entry_id": None,
                "entry_label": None,
                "exit_id": None,
                "exit_label": None,
                "pnl": None,
                "trade_count": 0,
                "chain_count": 0,
            }
            for symbol in sorted(max_ids)
            if symbol not in best_by_symbol
        ]
        symbols = []
        for symbol in sorted(max_ids):
            result = best_by_symbol.get(symbol)
            symbols.append({
                "symbol": symbol,
                "best_pnl": result["pnl"] if result else None,
                "best_exit": result["exit_label"] if result else None,
            })
        symbols.sort(key=lambda item: (item["best_pnl"] is None, -(item["best_pnl"] or 0), item["symbol"]))
        result_payload = {
            "status": "ready",
            "global_top": sorted_global,
            "ticker_leaders": ticker_leaders,
            "symbols": symbols,
            "universe_size": universe_size,
            "min_trades": args.min_trades,
            "return_run_key": args.return_run_key if args.persist_returns else None,
            "return_point_count": return_point_count,
        }
        durable_result_saved = False
        try:
            saved_result_path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            if shutil.disk_usage(saved_result_path.parent).free >= CHECKPOINT_MIN_FREE_BYTES:
                write_json(saved_result_path, result_payload)
                durable_result_saved = True
        except OSError:
            pass
        write_json(result_path, result_payload)
        write_json(status_path, {
            "status": "ready",
            "progress": 100,
            "elapsed_seconds": int(time.monotonic() - started),
            "pair_cache_hits": pair_cache_hits,
            "pair_cache_misses": pair_cache_misses,
            "checkpoint_result_reuses": checkpoint_result_reuses,
            "checkpoint_writes": checkpoint_writes,
            "checkpoint_skips_low_disk": checkpoint_skips,
            "durable_result_saved": durable_result_saved,
            "workers": worker_count,
            "checkpoint_days_reused": checkpoint_days_reused,
            "total_candidates": total,
            "completed_candidates": completed,
            "total_ticker_evaluations": total * universe_size,
            "completed_ticker_evaluations": completed * universe_size,
            "universe_size": universe_size,
            "start_day": args.start_day,
            "end_day": args.end_day,
            "return_run_key": args.return_run_key if args.persist_returns else None,
            "return_point_count": return_point_count,
        })
        return 0
    except Exception as exc:
        if process_pool is not None:
            process_pool.shutdown(wait=True, cancel_futures=True)
        write_json(status_path, {"status": "error", "progress": 0, "message": "Не удалось рассчитать общий рейтинг комбинаций."})
        print(f"portfolio ranking failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
