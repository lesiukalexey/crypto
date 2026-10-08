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
from typing import Any
from zoneinfo import ZoneInfo

import pymysql

from app import load_config
from database import connect_mysql
from portfolio_ranking import exit_specs
from strategy_ranking import PriceTree, QuoteArrays, build_entry_specs, make_signals, martingale_target_policies, simulate, write_json

KYIV = ZoneInfo("Europe/Kyiv")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--cache-key", required=True)
    parser.add_argument("--start-day", required=True)
    parser.add_argument("--end-day", required=True)
    parser.add_argument("--balance", required=True, type=float)
    parser.add_argument("--fee-percent", required=True, type=float)
    parser.add_argument("--min-trades", required=True, type=int, choices=(1, 2))
    parser.add_argument("--entry-config", required=True)
    parser.add_argument("--exit-config", required=True)
    parser.add_argument("--max-ids", required=True)
    parser.add_argument("--cache-dir", required=True, type=Path)
    parser.add_argument("--ranking-params", required=True)
    parser.add_argument("--martingale-mode", choices=("none", "simple", "reverse"), default="none")
    parser.add_argument("--martingale-timing", choices=("immediate", "rules"), default="immediate")
    parser.add_argument("--martingale-attempts", type=int, choices=range(2, 11), default=3)
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
                    exit_label += " · TP/SL фиксированы в USDT"
                elif args.martingale_mode != "none" and stop_loss is not None:
                    exit_label += " · TP/SL растут вместе с позицией"
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
        del raw_rows

        entry_id, entry_label, lookback, threshold, entry_family, immediate_short = selected_entry
        _, exit_label, take_profit, stop_loss = selected_exit
        fee_rate = args.fee_percent / 100
        tickers = []
        for symbol in list(rows_by_symbol):
            rows = rows_by_symbol.pop(symbol)
            quotes = QuoteArrays.from_rows(rows) if rows else QuoteArrays([], [], [], [])
            pnl: float | None = None
            trade_count = 0
            if len(quotes) >= 2:
                if immediate_short is not None:
                    signals, shorts = [0], [immediate_short]
                else:
                    signals, shorts = make_signals(quotes, int(threshold) / 100, int(lookback), entry_family or "both")
                if signals:
                    pnl, trade_count = simulate(
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
                    )
                    if trade_count == 0:
                        pnl = None
            tickers.append({"symbol": symbol, "pnl": pnl, "trade_count": trade_count})

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
