#!/usr/bin/env python3
"""Run the Basic Checks 2 interval walk-forward grid for one symbol set."""

from __future__ import annotations

import argparse
import fcntl
import json
import math
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import pymysql

from app import load_config
from basic_checks_2_periods import build_full_periods
from basic_checks_2_store import BasicChecks2Store
from contract_rules import load_contract_specs, numba_market_order_rules
from database import connect_mysql
from futures_costs import load_historical_funding_rates
from strategy_ranking import (
    PriceTree, QuoteArrays, make_signals, simulate_standard_checkpoint_days,
)

EXIT_CONFIRMATIONS = (3, 10, 30, 80)
ENTRY_WINDOWS = (3, 10, 30, 80)
SIGNAL_MODES = ("exact", "within")
DIRECTION_MODES = ("follow", "reverse")
EXIT_MODES = ("immediate", "none", "tp_delayed", "sl_delayed", "both_delayed")


def atomic_json(path: Path, payload: dict) -> None:
    temporary = path.with_suffix(path.suffix + f".{os.getpid()}.tmp")
    temporary.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    temporary.replace(path)


def read_request(path: Path) -> dict:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict) or not isinstance(value.get("symbols"), list):
        raise ValueError("Некорректный запрос расчёта.")
    value["symbols"] = sorted({str(item).upper() for item in value["symbols"]})
    if not value["symbols"] or len(value["symbols"]) > 500:
        raise ValueError("В запросе должен быть список из 1–500 тикеров.")
    return value


def status_write(path: Path, started: float, *, state: str, done: int, total: int,
                 symbol: str = "", message: str = "", **extra: object) -> None:
    elapsed = max(0, int(time.monotonic() - started))
    percent = min(100, round(done * 100 / total, 2)) if total else 0
    remaining = int(elapsed * max(0, total - done) / done) if done else None
    atomic_json(path, {
        "status": state, "progress": percent, "done": done, "total": total,
        "elapsed_seconds": elapsed, "remaining_seconds": remaining,
        "current_symbol": symbol, "message": message, "updated_at": datetime.now(timezone.utc).isoformat(),
        **extra,
    })


def check_cancel(run_dir: Path) -> None:
    if (run_dir / "cancel").exists():
        raise InterruptedError("Расчёт остановлен пользователем.")


def load_coverage(connection, category: str, symbols: list[str]) -> tuple[datetime, datetime, dict[str, int]]:
    placeholders = ",".join(["%s"] * len(symbols))
    with connection.cursor(pymysql.cursors.DictCursor) as cursor:
        cursor.execute(
            f"""SELECT symbol, MIN(received_at_utc) AS first_at, MAX(received_at_utc) AS last_at,
                       MAX(id) AS max_id
                  FROM quote_snapshots
                 WHERE category=%s AND symbol IN ({placeholders})
                 GROUP BY symbol""",
            [category, *symbols],
        )
        rows = cursor.fetchall()
    by_symbol = {str(row["symbol"]): row for row in rows}
    missing = [symbol for symbol in symbols if symbol not in by_symbol]
    if missing:
        raise ValueError("Нет котировок для тикеров: " + ", ".join(missing[:20]))
    first = max(row["first_at"].replace(tzinfo=timezone.utc) for row in by_symbol.values())
    last = min(row["last_at"].replace(tzinfo=timezone.utc) for row in by_symbol.values())
    if first >= last:
        raise ValueError("У выбранного набора нет общего периода котировок.")
    max_ids = {symbol: int(by_symbol[symbol]["max_id"]) for symbol in symbols}
    return first, last, max_ids


def entry_specs(entry_config: dict) -> list[tuple[str, str, int, int, bool | None]]:
    thresholds = sorted({int(value) for value in entry_config.get("thresholds_tenths_percent", [])})
    output: list[tuple[str, str, int, int, bool | None]] = []
    for window in ENTRY_WINDOWS:
        for threshold in thresholds:
            output.append((f"entry:both:{window}:{threshold}", f"Окно {window} · порог {threshold / 10:.1f}%", window, threshold, None))
    for direction in ("long", "short"):
        output.append((f"immediate:{direction}", "Сразу · " + ("лонг" if direction == "long" else "шорт"), 0, 0, direction == "short"))
    return output


def exit_specs(mode: str, exits: dict) -> list[tuple[str, str, float | None, float | None, int, int]]:
    fixed: list[tuple[str, str, float | None, float | None]] = []
    fixed_values: set[int] = set()
    for segment in exits["fixed"]["segments_basis_points"]:
        fixed_values.update(range(int(segment["from"]), int(segment["to"]) + 1, int(segment["step"])))
        fixed_values.add(int(segment["to"]))
    fixed_values = sorted(fixed_values)
    for tp in fixed_values:
        for sl in fixed_values:
            fixed.append((f"fixed:{tp}:{sl}", f"TP {tp / 100:.2f}% / SL {sl / 100:.2f}%", tp / 10, sl / 10))
    for value in range(int(exits["profit"]["min_tenths_percent"]), int(exits["profit"]["max_tenths_percent"]) + 1, int(exits["profit"]["step_tenths_percent"])):
        fixed.append((f"profit:{value}", f"TP {value / 10:.1f}% · без SL", value, None))
    for value in range(int(exits["loss"]["min_tenths_percent"]), int(exits["loss"]["max_tenths_percent"]) + 1, int(exits["loss"]["step_tenths_percent"])):
        fixed.append((f"loss:{value}", f"SL {value / 10:.1f}% · без TP", None, value))
    if mode == "none":
        return [("none", "Без выхода", None, None, 0, 0)]
    candidates = []
    for identifier, label, tp, sl in fixed:
        if mode == "immediate":
            candidates.append((identifier, label, tp, sl, 0, 0))
        elif mode == "tp_delayed" and tp is not None:
            candidates.extend((identifier + f":tp{count}", label + f" · проверка TP {count}", tp, sl, count, 0) for count in EXIT_CONFIRMATIONS)
        elif mode == "sl_delayed" and sl is not None:
            candidates.extend((identifier + f":sl{count}", label + f" · проверка SL {count}", tp, sl, 0, count) for count in EXIT_CONFIRMATIONS)
        elif mode == "both_delayed" and tp is not None and sl is not None:
            candidates.extend((identifier + f":both{count}", label + f" · проверка {count}", tp, sl, count, count) for count in EXIT_CONFIRMATIONS)
    return candidates


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--request", required=True, type=Path)
    args = parser.parse_args()
    request = read_request(args.request)
    run_dir = args.request.parent
    status_path = run_dir / "status.json"
    lock_path = run_dir / "worker.lock"
    with lock_path.open("a", encoding="utf-8") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        started = time.monotonic()
        done = 0
        total = 0
        store = None
        try:
            config = load_config(Path(__file__).with_name("config.json"))
            connection = connect_mysql(config["mysql"])
            try:
                coverage_start, coverage_end, max_ids = load_coverage(connection, config["category"], request["symbols"])
                periods = build_full_periods(coverage_start, coverage_end, request["interval"])
                if not periods:
                    raise ValueError("Для выбранного интервала нет ни одного полного периода общего покрытия.")
                request["periods"] = periods
                request["coverage"] = {"start": coverage_start.isoformat(), "end": coverage_end.isoformat()}
                request["max_ids"] = max_ids
                atomic_json(run_dir / "request.json", request)
                symbols = request["symbols"] if request["scope"] == "list" else [request["symbol"]]
                spec_count = len(entry_specs(json.loads(Path(__file__).with_name("entry_profiles.json").read_text(encoding="utf-8"))))
                selected_groups = [(m, d, e) for m in SIGNAL_MODES for d in DIRECTION_MODES for e in EXIT_MODES]
                if request["scope"] == "single":
                    selected_groups = [(request["signal_mode"], request["direction_mode"], request["exit_mode"])]
                exits_cfg = json.loads(Path(__file__).with_name("exit_profiles.json").read_text(encoding="utf-8"))
                group_candidate_count = sum(spec_count * len(exit_specs(exit_mode, exits_cfg)) for _, _, exit_mode in selected_groups)
                total = max(1, len(symbols) * group_candidate_count)
                store = BasicChecks2Store(run_dir / "results.sqlite")
                store.set_meta("request", request)
                store.set_meta("periods", periods)
                store.set_meta("status", "running")
                contracts = load_contract_specs(config["category"])
                done = 0
                last_status = 0.0
                status_write(status_path, started, state="running", done=done, total=total, message="Подготавливаю расчёт", periods=periods)

                for symbol in symbols:
                    check_cancel(run_dir)
                    start_utc = datetime.fromisoformat(periods[0]["start_utc"]).replace(tzinfo=timezone.utc).replace(tzinfo=None)
                    end_utc = datetime.fromisoformat(periods[-1]["end_utc"]).replace(tzinfo=timezone.utc).replace(tzinfo=None)
                    with connection.cursor(pymysql.cursors.DictCursor) as cursor:
                        cursor.execute(
                            """SELECT received_at_utc,bid_price,ask_price,last_price FROM quote_snapshots
                               WHERE category=%s AND symbol=%s AND id<=%s AND received_at_utc>=%s AND received_at_utc<=%s
                               ORDER BY received_at_utc,id""",
                            (config["category"], symbol, max_ids[symbol], start_utc, end_utc),
                        )
                        rows = cursor.fetchall()
                    if len(rows) < 2:
                        done += group_candidate_count
                        status_write(status_path, started, state="running", done=done, total=total, symbol=symbol,
                                     message=f"Недостаточно котировок: {symbol}", periods=periods)
                        continue
                    quotes = QuoteArrays.from_rows(rows, float(request.get("slippage_bps", 0)))
                    contract = contracts.get(symbol)
                    if contract is None:
                        raise RuntimeError(f"Нет правил контракта Bitget для {symbol}")
                    order_rules = numba_market_order_rules(contract, leverage=1.0)
                    if request.get("include_funding", True):
                        rates = load_historical_funding_rates(config["category"], symbol, float(quotes.timestamps[0]), float(quotes.timestamps[-1]))
                        mids = np.fromiter(((float(row["bid_price"]) + float(row["ask_price"])) / 2 for row in rows), dtype=np.float64, count=len(rows))
                        quotes.set_funding_rates(rates, mids)
                    del rows
                    # Include the quote at each shared boundary. Replays are cumulative, so
                    # that quote is consumed once and its marked state carries into the next interval.
                    boundaries = np.asarray([np.searchsorted(quotes.timestamps, datetime.fromisoformat(item["end_utc"]).replace(tzinfo=timezone.utc).timestamp(), side="right") for item in periods], dtype=np.int64)
                    if np.any(boundaries < 2) or np.any(np.diff(boundaries) <= 0):
                        raise ValueError(f"В {symbol} недостаточно котировок на границах полных интервалов.")
                    bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
                    balance = float(request["balance"])
                    fee_rate = float(request["fee"]) / 100
                    entry_cfg = json.loads(Path(__file__).with_name("entry_profiles.json").read_text(encoding="utf-8"))
                    entries = entry_specs(entry_cfg)
                    for signal_mode, direction_mode, exit_mode in selected_groups:
                        exits = exit_specs(exit_mode, exits_cfg)
                        best_min: dict[str, dict] = {}
                        best_sum: dict[str, dict] = {}
                        for entry_id, entry_label, window, threshold, immediate_short in entries:
                            check_cancel(run_dir)
                            if immediate_short is None:
                                signals, short_flags = make_signals(quotes, threshold / 10, window, "both", signal_mode, direction_mode)
                            else:
                                signals = np.asarray([0], dtype=np.int64)
                                short_flags = np.asarray([immediate_short], dtype=np.bool_)
                            if len(signals) == 0:
                                done += len(exits)
                                continue
                            for exit_id, exit_label, tp, sl, tp_confirm, sl_confirm in exits:
                                if done % 64 == 0:
                                    check_cancel(run_dir)
                                replay = simulate_standard_checkpoint_days(
                                    quotes, signals, short_flags, bid_tree, ask_tree, balance, fee_rate,
                                    tp, sl, boundaries, (balance, 0, 0, -1, False),
                                    tp_confirm, sl_confirm, order_rules, capture_boundary_results=True,
                                )
                                cumulative_pnl = replay[4]
                                cumulative_trades = [int(state[2]) for state in replay[3]]
                                interval_rows = []
                                previous_pnl = 0.0
                                previous_trades = 0
                                returns = []
                                for index, period in enumerate(periods):
                                    start_equity = balance + previous_pnl
                                    end_equity = balance + float(cumulative_pnl[index])
                                    pnl = end_equity - start_equity
                                    pct = pnl / start_equity * 100 if start_equity > 0 else float("nan")
                                    trades = max(0, int(cumulative_trades[index]) - previous_trades)
                                    returns.append(pct)
                                    interval_rows.append({"label": period["label"], "profit_usdt": pnl,
                                                          "return_percent": pct, "equity_start": start_equity,
                                                          "equity_end": end_equity, "trades": trades})
                                    previous_pnl = float(cumulative_pnl[index])
                                    previous_trades = int(cumulative_trades[index])
                                finite = [value for value in returns if math.isfinite(value)]
                                final_state = replay[2]
                                has_activity = int(cumulative_trades[-1]) > 0 or int(final_state[3]) >= 0
                                worst = min(finite) if finite else 0.0
                                sum_pct = sum(finite)
                                total_pct = float(cumulative_pnl[-1]) / balance * 100
                                accepted = has_activity and len(finite) == len(periods) and worst >= -25
                                params = {"window": window, "threshold_tenths_percent": threshold,
                                          "take_profit_tenths_percent": tp, "stop_loss_tenths_percent": sl,
                                          "take_confirmation_snapshots": tp_confirm,
                                          "stop_confirmation_snapshots": sl_confirm,
                                          "signal_mode": signal_mode, "direction_mode": direction_mode,
                                          "exit_mode": exit_mode}
                                row = {"symbol": symbol, "signal_mode": signal_mode, "direction_mode": direction_mode,
                                       "exit_mode": exit_mode, "entry_id": entry_id, "exit_id": exit_id,
                                       "entry_label": entry_label, "exit_label": exit_label, "worst_percent": worst,
                                       "sum_percent": sum_pct, "total_percent": total_pct,
                                       "closed_trades": int(cumulative_trades[-1]), "periods": interval_rows,
                                       "parameters": params, "accepted": accepted}
                                if accepted:
                                    store.add_candidate(row)
                                    group = f"{signal_mode}:{direction_mode}:{exit_mode}"
                                    if group not in best_min or worst > best_min[group]["worst_percent"]:
                                        best_min[group] = row
                                    if group not in best_sum or sum_pct > best_sum[group]["sum_percent"]:
                                        best_sum[group] = row
                                done += 1
                                now = time.monotonic()
                                if now - last_status >= 1:
                                    store.flush()
                                    status_write(status_path, started, state="running", done=done, total=total,
                                                 symbol=symbol, message=f"{symbol}: {entry_label} · {exit_mode}",
                                                 periods=periods, current_group={"signal_mode": signal_mode,
                                                 "direction_mode": direction_mode, "exit_mode": exit_mode})
                                    last_status = now
                        store.flush()
                        for objective, winners in (("minimum_worst", best_min), ("maximum_sum", best_sum)):
                            for winner in winners.values():
                                winner = {**winner, "objective": objective}
                                store.set_meta(f"winner:{symbol}:{objective}:{winner['signal_mode']}:{winner['direction_mode']}:{winner['exit_mode']}", winner)
                    status_write(status_path, started, state="running", done=done, total=total, symbol=symbol,
                                 message=f"Завершён тикер {symbol}", periods=periods)
                store.flush()
                store.set_meta("status", "ready")
                status_write(status_path, started, state="ready", done=total, total=total,
                             message="Расчёт завершён", periods=periods)
            finally:
                connection.close()
        except InterruptedError as error:
            if store:
                store.set_meta("status", "cancelled")
            status_write(status_path, started, state="cancelled", done=done, total=total, message=str(error))
            return 0
        except Exception as error:
            if store:
                store.set_meta("status", "error")
            status_write(status_path, started, state="error", done=done, total=total, message=str(error))
            raise
        finally:
            if store:
                store.close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"Basic Checks 2 worker failed: {exc}", file=sys.stderr)
        raise
