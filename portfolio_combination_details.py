#!/usr/bin/env python3
"""Calculate one selected entry/exit pair for every ticker in a frozen snapshot."""

from __future__ import annotations

import argparse
import fcntl
import json
import sys
import time
from datetime import date, datetime, time as day_time, timedelta, timezone
from pathlib import Path
from types import SimpleNamespace
from typing import Any
from zoneinfo import ZoneInfo

import pymysql

from app import load_config
from database import connect_mysql
from contract_rules import load_contract_specs, numba_market_order_rules
import portfolio_ranking as portfolio_engine
from portfolio_ranking import exit_specs
from ranking_checkpoints import daily_quote_boundaries
from strategy_ranking import PriceTree, QuoteArrays, build_entry_specs, make_signals, martingale_target_policies, simulate, simulate_standard_checkpoint_days, standard_range_results, write_json

KYIV = ZoneInfo("Europe/Kyiv")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--cache-key", required=True)
    parser.add_argument("--start-day", required=True)
    parser.add_argument("--end-day", required=True)
    parser.add_argument("--balance", required=True, type=float)
    parser.add_argument("--fee-percent", required=True, type=float)
    parser.add_argument("--min-trades", required=True, type=int, choices=(1, 2, 3))
    parser.add_argument("--entry-config", required=True)
    parser.add_argument("--exit-config", required=True)
    parser.add_argument("--max-ids", required=True)
    parser.add_argument("--cache-dir", required=True, type=Path)
    parser.add_argument("--ranking-params", required=True)
    parser.add_argument("--martingale-mode", choices=("none", "simple", "reverse", "simple_profit", "reverse_profit"), default="none")
    parser.add_argument("--martingale-timing", choices=("immediate", "rules"), default="immediate")
    parser.add_argument("--martingale-attempts", type=int, choices=range(2, 11), default=3)
    parser.add_argument("--martingale-max-chain-loss", type=float, default=0.0)
    parser.add_argument("--martingale-max-day-loss", type=float, default=0.0)
    args = parser.parse_args()

    result_path = args.cache_dir / f"combination-{args.cache_key}.json"
    status_path = args.cache_dir / f"combination-{args.cache_key}.status.json"
    lock_path = args.cache_dir / f"combination-{args.cache_key}.lock"
    args.cache_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    job_lock = lock_path.open("a", encoding="utf-8")
    try:
        fcntl.flock(job_lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        return 0

    started = time.monotonic()
    write_json(status_path, {"status": "pending", "progress": 0})
    try:
        ranking = json.loads(args.ranking_params)
        confirmation_snapshots = int(ranking.get("exit_confirmation_snapshots", 60))
        confirmation_mode = ranking.get("exit_confirmation_mode", "immediate")
        take_confirmation_snapshots = confirmation_snapshots if confirmation_mode in ("both_delayed", "tp_delayed") else 0
        stop_confirmation_snapshots = confirmation_snapshots if confirmation_mode in ("both_delayed", "sl_delayed") else 0
        max_ids = {str(symbol): int(max_id) for symbol, max_id in json.loads(args.max_ids).items()}
        selected_entry = next((spec for spec in build_entry_specs(ranking) if spec[0] == args.entry_config), None)
        if selected_entry is None:
            raise ValueError("Unknown entry configuration")

        selected_exit = None
        target_policy = "scaled"
        base_exit_id = args.exit_config
        if args.martingale_mode != "none":
            for policy in ("scaled", "fixed"):
                suffix = f":{policy}"
                if base_exit_id.endswith(suffix):
                    base_exit_id = base_exit_id[:-len(suffix)]
                    target_policy = policy
                    break
        for exit_id, exit_label, take_profit, stop_loss in exit_specs(ranking["exit_profiles"]):
            if exit_id != base_exit_id:
                continue
            allowed = {policy for policy, _ in martingale_target_policies(args.martingale_mode, stop_loss)}
            if target_policy in allowed:
                if args.martingale_mode != "none" and target_policy != "scaled":
                    exit_label += " · цель рассчитана от стартового баланса"
                elif args.martingale_mode != "none" and stop_loss is not None:
                    exit_label += " · TP/SL растут вместе с позицией"
                if take_confirmation_snapshots or stop_confirmation_snapshots:
                    exit_label += f" · TP {'через ' + str(take_confirmation_snapshots) + ' интервалов' if take_confirmation_snapshots else 'сразу'} / SL {'через ' + str(stop_confirmation_snapshots) + ' интервалов' if stop_confirmation_snapshots else 'сразу'}"
                selected_exit = (exit_id, exit_label, take_profit, stop_loss)
                break
        if selected_exit is None:
            raise ValueError("Unknown exit configuration")

        if not max_ids:
            write_json(result_path, {
                "status": "ready",
                "entry_id": args.entry_config,
                "entry_label": selected_entry[1],
                "exit_id": args.exit_config,
                "exit_label": selected_exit[1],
                "tickers": [],
                "universe_size": 0,
                "min_trades": args.min_trades,
            })
            write_json(status_path, {"status": "ready", "progress": 100, "elapsed_seconds": 0})
            return 0

        config = load_config(Path(__file__).with_name("config.json"))
        contract_rules_by_symbol = {}
        if args.martingale_mode != "none":
            contract_specs = load_contract_specs(config["category"])
            contract_rules_by_symbol = {
                symbol: numba_market_order_rules(spec, leverage=1.0)
                for symbol, spec in contract_specs.items()
            }
        end_local = datetime.combine(date.fromisoformat(args.end_day) + timedelta(days=1), day_time.min, KYIV)
        end_utc = end_local.astimezone(timezone.utc).replace(tzinfo=None)
        start_utc = datetime.combine(date.fromisoformat(args.start_day), day_time.min, KYIV).astimezone(timezone.utc).replace(tzinfo=None)
        snapshot_filter = " OR ".join("(symbol = %s AND (id <= %s OR quote_source = 'historical_candle_close'))" for _ in max_ids)
        quote_parameters: list[Any] = [config["category"], end_utc]
        if args.martingale_mode == "none":
            for snapshot_symbol, snapshot_max_id in sorted(max_ids.items()):
                quote_parameters.extend((snapshot_symbol, snapshot_max_id))
            quote_query = (
                "SELECT id, symbol, received_at_utc, bid_price, ask_price, last_price, quote_source "
                "FROM quote_snapshots WHERE category = %s AND received_at_utc < %s AND ("
                + snapshot_filter + ") ORDER BY symbol, received_at_utc, id"
            )
        else:
            quote_query = (
                "SELECT id, symbol, received_at_utc, bid_price, ask_price, last_price, quote_source "
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
            if symbol in max_ids and (int(row["id"]) <= max_ids[symbol] or row["quote_source"] == "historical_candle_close"):
                row.pop("id", None)
                rows_by_symbol[symbol].append(row)
        del raw_rows

        entry_id, entry_label, lookback, threshold, entry_family, immediate_short = selected_entry
        _, exit_label, take_profit, stop_loss = selected_exit
        fee_rate = args.fee_percent / 100
        quotes_by_symbol = {
            symbol: QuoteArrays.from_rows(rows)
            for symbol, rows in rows_by_symbol.items() if rows
        }
        checkpoint_exit_options = [
            (
                f"{base_exit_id}:{policy}" if policy_label else base_exit_id,
                label + (f" · {policy_label}" if policy_label else "") + (f" · TP {'через ' + str(take_confirmation_snapshots) + ' интервалов' if take_confirmation_snapshots else 'сразу'} / SL {'через ' + str(stop_confirmation_snapshots) + ' интервалов' if stop_confirmation_snapshots else 'сразу'}" if take_confirmation_snapshots or stop_confirmation_snapshots else ""),
                target, loss, policy,
            )
            for base_exit_id, label, target, loss in exit_specs(ranking["exit_profiles"])
            for policy, policy_label in martingale_target_policies("none", loss)
        ]
        checkpoint_exit_index = next((index for index, option in enumerate(checkpoint_exit_options)
            if option[0] == args.exit_config), None)
        checkpoint_args = SimpleNamespace(
            start_day=args.start_day, end_day=args.end_day, balance=args.balance,
            fee_percent=args.fee_percent, min_trades=args.min_trades,
            ranking_params=ranking, martingale_mode="none",
            martingale_timing=args.martingale_timing,
            martingale_attempts=args.martingale_attempts, entry_config=args.entry_config,
        )
        checkpoint_cache_root = args.cache_dir / "combination-pair-results" / args.cache_key
        checkpoint_state = {
            "quotes_by_symbol": quotes_by_symbol,
            "take_confirmation_snapshots": take_confirmation_snapshots,
            "stop_confirmation_snapshots": stop_confirmation_snapshots,
            "signals_by_symbol": {},
            "pair_cache_scopes": {symbol: checkpoint_cache_root / symbol for symbol in quotes_by_symbol},
            "checkpoint_root": args.cache_dir.parent / "bitget-simulation-checkpoints" / "portfolio-state-v1",
            "category": config["category"],
            "exit_options": checkpoint_exit_options,
            "args": checkpoint_args,
            "fee_rate": fee_rate,
        }
        if immediate_short is None:
            signal_key = (int(lookback), entry_family or "both", int(threshold))
            checkpoint_state["signals_by_symbol"] = {
                symbol: {signal_key: make_signals(
                    quotes, int(threshold) / 10, int(lookback), entry_family or "both",
                )}
                for symbol, quotes in quotes_by_symbol.items()
            }
        portfolio_engine._PORTFOLIO_WORKER_STATE = checkpoint_state
        tickers = []
        for symbol in list(rows_by_symbol):
            pnl: float | None = None
            trade_count = 0
            chain_count = 0
            if args.martingale_mode == "none":
                simulation = portfolio_engine.simulate_symbol_entry(
                    (symbol, selected_entry, True),
                )
                pair_results = simulation[1]
                if pair_results is not None and checkpoint_exit_index is not None:
                    pnl = float(pair_results[checkpoint_exit_index]["pnl"])
                    trade_count = int(pair_results[checkpoint_exit_index]["trade_count"])
                    chain_count = int(pair_results[checkpoint_exit_index]["chain_count"])
                    if trade_count == 0:
                        pnl = None
            else:
                rows = rows_by_symbol.pop(symbol)
                quotes = QuoteArrays.from_rows(rows) if rows else QuoteArrays([], [], [], [])
                if len(quotes) >= 2:
                    if immediate_short is not None:
                        signals, shorts = [0], [immediate_short]
                    else:
                        signals, shorts = make_signals(quotes, int(threshold) / 10, int(lookback), entry_family or "both")
                    if len(signals) > 0:
                        simulation_result = simulate(
                            quotes,
                            signals,
                            shorts,
                            PriceTree(quotes.bids),
                            PriceTree(quotes.asks),
                            args.balance,
                            fee_rate,
                            take_profit,
                            stop_loss,
                            args.martingale_mode,
                            args.martingale_timing,
                            args.martingale_attempts,
                            target_policy,
                            args.martingale_max_chain_loss,
                            args.martingale_max_day_loss,
                            order_rules=contract_rules_by_symbol.get(symbol),
                        )
                        pnl, trade_count = simulation_result[:2]
                        chain_count = int(simulation_result[2]) if len(simulation_result) > 2 else trade_count
                        if trade_count == 0:
                            pnl = None
            tickers.append({"symbol": symbol, "pnl": pnl, "trade_count": trade_count, "chain_count": chain_count})

        write_json(result_path, {
            "status": "ready",
            "entry_id": entry_id,
            "entry_label": entry_label,
            "exit_id": args.exit_config,
            "exit_label": exit_label,
            "tickers": tickers,
            "universe_size": len(max_ids),
            "min_trades": args.min_trades,
        })
        write_json(status_path, {"status": "ready", "progress": 100, "elapsed_seconds": int(time.monotonic() - started)})
        return 0
    except Exception as exc:
        write_json(status_path, {"status": "error", "progress": 0, "message": "Не удалось рассчитать выбранную комбинацию по тикерам."})
        print(f"portfolio combination details failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
