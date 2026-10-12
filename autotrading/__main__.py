"""Small local command-line tools for portable strategy releases."""

from __future__ import annotations

import argparse
import json
import logging
import os
import signal
import stat
import subprocess
import sys
import time
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path

from .contracts import Position, load_strategy_release, release_digest
from .bitget_demo import BitgetDemoAdapter, BitgetAPIError, ExchangeStateMismatch
from .bitget_production import BitgetProductionAdapter, LIVE_ORDER_ACKNOWLEDGEMENT, LIVE_SWITCH_ENV, LiveRiskSnapshot, LiveTradingDisabled
from .exchange_portfolio import ExchangePortfolioExecutor
from .market import BitgetPublicTickerPoller
from .paper import KYIV, PaperExecutor, PortfolioExecutor, SQLiteStateStore
from .pi_sync import pull_status as pi_pull_status, remote_stage_release, remote_status, stage_release as pi_stage_release
from .service import read_json, service_status, set_controls, start_portfolio_service, start_service, stop_service


def _load_private_environment(mode: str) -> dict[str, str]:
    prefix = "BITGET_DEMO" if mode == "demo" else "BITGET_LIVE"
    names = (f"{prefix}_API_KEY", f"{prefix}_API_SECRET", f"{prefix}_API_PASSPHRASE")
    values = {name: os.getenv(name, "") for name in names}
    env_file_name = "BITGET_DEMO_ENV_FILE" if mode == "demo" else "BITGET_LIVE_ENV_FILE"
    env_file = os.getenv(env_file_name, "")
    if not all(values.values()) and env_file:
        path = Path(env_file)
        metadata = path.lstat()
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid() or metadata.st_mode & 0o077:
            raise ValueError(f"{mode.title()} credentials file must be a regular file with owner-only permissions")
        file_values: dict[str, str] = {}
        for line in path.read_text(encoding="utf-8").splitlines():
            if not line or line.startswith("#"):
                continue
            name, separator, value = line.partition("=")
            if not separator or name in file_values or name not in names:
                raise ValueError(f"{mode.title()} credentials file has an invalid format")
            file_values[name] = value
        values = {name: values[name] or file_values.get(name, "") for name in names}
    if not all(values.values()):
        raise ValueError(f"{mode.title()} API credentials are not configured for this local process")
    return values


def _make_exchange_adapter(mode: str, store: SQLiteStateStore, enable_live: bool):
    if mode not in {"demo", "live"}:
        raise ValueError("Exchange adapter mode must be Demo or Real")
    rows = store.db.execute("SELECT payload FROM portfolio_strategies WHERE enabled=1 ORDER BY added_at_ms").fetchall()
    payloads = [json.loads(row["payload"]) for row in rows]
    if not payloads:
        raise ValueError("Add at least one strategy to the portfolio before starting exchange execution")
    symbols = sorted({symbol for payload in payloads for symbol in payload["market"]["symbols"]})
    credentials = _load_private_environment(mode)
    if mode == "demo":
        return BitgetDemoAdapter(
            symbols,
            api_key=credentials["BITGET_DEMO_API_KEY"],
            secret=credentials["BITGET_DEMO_API_SECRET"],
            passphrase=credentials["BITGET_DEMO_API_PASSPHRASE"],
        )
    if not enable_live:
        raise LiveTradingDisabled("Starting Real execution requires the exact dashboard acknowledgement")
    if os.getenv(LIVE_SWITCH_ENV) != "1":
        raise LiveTradingDisabled(f"Real execution requires {LIVE_SWITCH_ENV}=1 in the executor environment")

    risks = [payload["risk"] for payload in payloads]
    account = store.account()
    initial_balance = Decimal(account["initial_balance"] if account else "0")
    risk_limits = {
        "max_order_notional_usdt": str(max(Decimal(item["max_order_notional_usdt"]) for item in risks)),
        "max_total_exposure_usdt": str(min(initial_balance, sum((Decimal(item["max_total_exposure_usdt"]) for item in risks), Decimal(0)))),
        "max_daily_loss_usdt": str(sum((Decimal(item["max_daily_loss_usdt"]) for item in risks), Decimal(0))),
        "max_open_positions": sum(int(item["max_open_positions"]) for item in risks),
    }

    def daily_risk_snapshot() -> LiveRiskSnapshot:
        now_ms = int(time.time() * 1000)
        account_row = store.account()
        day = datetime.now(KYIV).date().isoformat()
        realized_today = Decimal(account_row["daily_pnl"]) if account_row and account_row["day"] == day else Decimal(0)
        unrealized = Decimal(0)
        for position in store.db.execute("SELECT * FROM positions").fetchall():
            quote_row = store.db.execute("SELECT bid,ask,ts FROM quotes WHERE symbol=? ORDER BY ts DESC LIMIT 1", (position["symbol"],)).fetchone()
            if quote_row is None or now_ms - int(quote_row["ts"]) > 60_000:
                raise RuntimeError("Fresh quote required for the live daily-risk snapshot")
            close_price = Decimal(quote_row["ask"] if position["side"] == "short" else quote_row["bid"])
            quantity, entry = Decimal(position["quantity"]), Decimal(position["entry_price"])
            gross = quantity * (entry - close_price if position["side"] == "short" else close_price - entry)
            unrealized += gross - Decimal(position["entry_fee"])
        return LiveRiskSnapshot(realized_today + unrealized, now_ms)

    max_slippage = max(int(Decimal(payload["execution"]["slippage_bps"])) for payload in payloads)
    return BitgetProductionAdapter(
        symbols,
        risk_limits,
        daily_risk_snapshot=daily_risk_snapshot,
        api_key=credentials["BITGET_LIVE_API_KEY"],
        secret=credentials["BITGET_LIVE_API_SECRET"],
        passphrase=credentials["BITGET_LIVE_API_PASSPHRASE"],
        allow_live_orders=True,
        live_acknowledgement=LIVE_ORDER_ACKNOWLEDGEMENT,
        max_slippage_bps=max_slippage,
    )


def _minimum_equity_percent(path: str | None) -> Decimal:
    if not path:
        return Decimal("75")
    try:
        payload = json.loads(Path(path).read_text(encoding="utf-8"))
        value = Decimal(str(payload.get("minimum_equity_percent", "75")))
        if not value.is_finite() or value < 1 or value > 100:
            raise ValueError
        return value
    except (OSError, json.JSONDecodeError, AttributeError, InvalidOperation, ValueError):
        return Decimal("75")


def main() -> int:
    parser = argparse.ArgumentParser(prog="python -m autotrading")
    commands = parser.add_subparsers(dest="command", required=True)
    validate = commands.add_parser("validate", help="validate a strategy release")
    validate.add_argument("release", help="path to a strategy release JSON file")
    paper_check = commands.add_parser("paper-check", help="validate a release against paper v1 support")
    paper_check.add_argument("release", help="path to a strategy release JSON file")
    run = commands.add_parser("paper-run", help="run a public-quote paper executor")
    run.add_argument("release", help="strategy release JSON")
    run.add_argument("--state", required=True, help="SQLite state path outside the repository")
    run.add_argument("--interval", type=float, default=5, help="public ticker polling interval in seconds")
    run.add_argument("--stale-seconds", type=int, default=30, help="maximum accepted quote age")
    run.add_argument("--control", help="local JSON control file")
    portfolio_run = commands.add_parser("portfolio-run", help="run all local paper strategies against one shared account")
    portfolio_run.add_argument("--state", required=True)
    portfolio_run.add_argument("--interval", type=float, default=5)
    portfolio_run.add_argument("--stale-seconds", type=int, default=30)
    portfolio_run.add_argument("--control")
    portfolio_run.add_argument("--risk-settings")
    portfolio_run.add_argument("--mode", choices=("paper", "demo", "live"), default="paper")
    portfolio_run.add_argument("--enable-live", action="store_true", help="explicitly arm live mode; process switch is also required")
    status = commands.add_parser("status", help="show local paper executor state")
    status.add_argument("--state", required=True, help="SQLite state path")
    status.add_argument("--pid-file", help="managed service PID file")
    status.add_argument("--control", help="local JSON control file")
    start = commands.add_parser("service-start", help="start a detached local paper service")
    for option in ("release", "state", "control", "pid-file", "log"):
        start.add_argument("--" + option, required=True)
    start.add_argument("--interval", type=float, default=5)
    stop = commands.add_parser("service-stop", help="stop a managed local paper service")
    stop.add_argument("--state", required=True)
    stop.add_argument("--pid-file", required=True)
    control = commands.add_parser("control", help="pause entries or close paper positions")
    control.add_argument("--control", required=True)
    control.add_argument("action", choices=("pause_entries", "resume_entries", "close_positions"))
    portfolio_add = commands.add_parser("portfolio-add", help="add a validated paper strategy to the shared account")
    portfolio_add.add_argument("--state", required=True)
    portfolio_add.add_argument("--release", required=True)
    portfolio_add.add_argument("--kind", choices=("strategy", "hedge"), default="strategy")
    portfolio_add.add_argument("--name", default="")
    portfolio_remove = commands.add_parser("portfolio-remove", help="remove a flat strategy and release its portfolio allocation")
    portfolio_remove.add_argument("--state", required=True)
    portfolio_remove.add_argument("--strategy-id", required=True)
    portfolio_copy = commands.add_parser("portfolio-copy", help="copy missing strategy releases from another mode's portfolio")
    portfolio_copy.add_argument("--state", required=True, help="target mode SQLite state")
    portfolio_copy.add_argument("--source-state", required=True, help="source portfolio SQLite state")
    strategy_stop = commands.add_parser("strategy-stop", help="stop one strategy and close its positions")
    strategy_stop.add_argument("--state", required=True)
    strategy_stop.add_argument("--strategy-id", required=True)
    strategy_resume = commands.add_parser("strategy-resume", help="resume a stopped strategy with a new risk session")
    strategy_resume.add_argument("--state", required=True)
    strategy_resume.add_argument("--strategy-id", required=True)
    portfolio_start = commands.add_parser("portfolio-start", help="start the shared portfolio service in Paper, Demo, or Real mode")
    for option in ("state", "control", "pid-file", "log"):
        portfolio_start.add_argument("--" + option, required=True)
    portfolio_start.add_argument("--interval", type=float, default=5)
    portfolio_start.add_argument("--mode", choices=("paper", "demo", "live"), default="paper")
    portfolio_start.add_argument("--risk-settings")
    portfolio_start.add_argument("--enable-live", action="store_true", help="explicitly arm live mode")
    demo_reconcile = commands.add_parser("demo-reconcile", help="read-only reconciliation of a Bitget Demo account")
    demo_reconcile.add_argument("--symbols", required=True, help="comma-separated uppercase USDT-futures symbols")
    demo_reconcile.add_argument("--state", help="Demo portfolio state used as the local reconciliation ledger")
    pi_status = commands.add_parser("pi-status", help="pull paper executor status over verified SSH")
    pi_status.add_argument("--target", required=True, help="SSH host alias from the local SSH config")
    pi_publish = commands.add_parser("pi-stage-release", help="send a validated paper strategy to Raspberry Pi over SSH")
    pi_publish.add_argument("--target", required=True, help="SSH host alias from the local SSH config")
    pi_publish.add_argument("--release", required=True, help="validated local strategy release JSON")
    remote_status_parser = commands.add_parser("pi-remote-status", help="internal command used by the Pi SSH transport")
    remote_status_parser.add_argument("--state", required=True)
    remote_status_parser.add_argument("--release", required=True)
    remote_stage_parser = commands.add_parser("pi-remote-stage-release", help="internal command used by the Pi SSH transport")
    remote_stage_parser.add_argument("--state", required=True)
    remote_stage_parser.add_argument("--release", required=True)
    args = parser.parse_args()
    if args.command == "demo-reconcile":
        store = None
        try:
            symbols = [symbol.strip() for symbol in args.symbols.split(",") if symbol.strip()]
            if args.state:
                store = SQLiteStateStore.read_only(args.state)
                symbols = sorted(set(symbols) | {row[0] for row in store.db.execute("SELECT DISTINCT symbol FROM positions")})
                expected = [Position(row["symbol"], row["side"], Decimal(row["quantity"]), Decimal(row["entry_price"])) for row in store.db.execute("SELECT symbol,side,quantity,entry_price FROM positions")]
                pending = [(row["symbol"], row["client_order_id"]) for row in store.db.execute("SELECT symbol,client_order_id FROM exchange_orders WHERE status IN ('submitting','uncertain','submitted','partial')")]
            else:
                expected, pending = [], []
            credentials = _load_private_environment("demo")
            adapter = BitgetDemoAdapter(symbols, api_key=credentials["BITGET_DEMO_API_KEY"], secret=credentials["BITGET_DEMO_API_SECRET"], passphrase=credentials["BITGET_DEMO_API_PASSPHRASE"])
            result = adapter.reconcile(expected_positions=expected, pending_orders=pending)
            report = {
                "mode": "demo",
                "checked_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
                "symbols": symbols,
                "matched": result.matched,
                "discrepancies": list(result.discrepancies),
                "positions": [
                    {"symbol": item.symbol, "side": item.side, "quantity": str(item.quantity), "average_entry_price": str(item.average_entry_price)}
                    for item in result.positions
                ],
                "pending_orders": len(result.orders),
                "fills": len(result.fills),
                "basis": "local_demo_portfolio_ledger" if args.state else "empty_local_demo_ledger",
            }
            print(json.dumps(report, ensure_ascii=False))
            return 0 if result.matched else 1
        except (BitgetAPIError, OSError, ValueError, RuntimeError) as exc:
            print(json.dumps({
                "mode": "demo",
                "checked_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
                "matched": False,
                "error": str(exc)[:500],
            }, ensure_ascii=False))
            return 1
        finally:
            if store is not None:
                store.close()
    if args.command == "pi-status":
        try:
            print(json.dumps(pi_pull_status(args.target), ensure_ascii=False))
            return 0
        except (OSError, ValueError, RuntimeError) as exc:
            print(f"Raspberry Pi status unavailable: {exc}", file=sys.stderr)
            return 2
    if args.command == "pi-stage-release":
        try:
            print(json.dumps(pi_stage_release(args.target, args.release), ensure_ascii=False))
            return 0
        except (OSError, ValueError, RuntimeError) as exc:
            print(f"Could not stage Raspberry Pi strategy: {exc}", file=sys.stderr)
            return 2
    if args.command == "pi-remote-status":
        try:
            print(json.dumps(remote_status(args.state, args.release), ensure_ascii=False))
            return 0
        except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
            print(f"Could not read Pi executor status: {type(exc).__name__}", file=sys.stderr)
            return 2
    if args.command == "pi-remote-stage-release":
        try:
            print(json.dumps(remote_stage_release(args.state, args.release), ensure_ascii=False))
            return 0
        except (OSError, ValueError, RuntimeError) as exc:
            print(f"Could not stage Pi strategy: {exc}", file=sys.stderr)
            return 2
    if args.command in {"validate", "paper-check"}:
        try:
            release = load_strategy_release(args.release) if args.command == "validate" else PaperExecutor.validate_release(load_strategy_release(args.release))
            label = "Valid strategy release" if args.command == "validate" else "Supported paper v1 release"
            print(f"{label}: {release['release_id']}")
            print(f"SHA-256: {release_digest(release)}")
            return 0
        except ValueError as exc:
            print(f"Invalid strategy release: {exc}", file=sys.stderr)
            return 2
    if args.command == "status":
        store = SQLiteStateStore(args.state)
        try:
            result = store.health()
            if args.pid_file:
                result["service"] = service_status(args.pid_file, args.state)
            if args.control:
                result["controls"] = read_json(args.control, {"entries_paused": False, "close_positions": False})
            print(json.dumps(result, ensure_ascii=False, indent=2))
        finally:
            store.close()
        return 0
    if args.command == "portfolio-add":
        store = SQLiteStateStore(args.state)
        try:
            digest = store.add_portfolio_strategy(load_strategy_release(args.release), kind=args.kind, name=args.name)
            print(json.dumps({"added": True, "strategy_id": digest, "kind": args.kind}))
        finally:
            store.close()
        return 0
    if args.command == "portfolio-remove":
        store = SQLiteStateStore(args.state)
        try:
            store.remove_portfolio_strategy(args.strategy_id)
            print(json.dumps({"removed": True, "strategy_id": args.strategy_id}))
            return 0
        except ValueError as exc:
            print(str(exc), file=sys.stderr)
            return 2
        finally:
            store.close()
    if args.command == "portfolio-copy":
        source = target = None
        try:
            source = SQLiteStateStore(args.source_state)
            target = SQLiteStateStore(args.state)
            source_rows = source.db.execute("SELECT strategy_id,kind,payload,name FROM portfolio_strategies WHERE enabled=1 ORDER BY added_at_ms").fetchall()
            existing = {row[0] for row in target.db.execute("SELECT strategy_id FROM portfolio_strategies WHERE enabled=1")}
            removed = {row[0] for row in target.db.execute("SELECT strategy_id FROM portfolio_removed")}
            missing = [row for row in source_rows if row["strategy_id"] not in existing and row["strategy_id"] not in removed]
            if missing and target.health()["open_positions"]:
                raise ValueError("Close the selected mode's open positions before syncing newly added portfolio strategies")
            for row in missing:
                target.add_portfolio_strategy(json.loads(row["payload"]), kind=row["kind"], name=row["name"])
            source_account, target_account = source.account(), target.account()
            if target_account and source_account and not target.db.execute("SELECT 1 FROM positions LIMIT 1").fetchone():
                target.db.execute("UPDATE account SET balance=?,initial_balance=? WHERE id=1", (source_account["initial_balance"], source_account["initial_balance"]))
                target.db.commit()
            print(json.dumps({"copied": len(missing), "strategies": len(source_rows)}))
            return 0
        except (OSError, ValueError, RuntimeError) as exc:
            print(f"Could not copy portfolio strategies: {exc}", file=sys.stderr)
            return 2
        finally:
            if source is not None: source.close()
            if target is not None: target.close()
    if args.command == "portfolio-start":
        store = None
        try:
            if args.mode in {"demo", "live"}:
                store = SQLiteStateStore(args.state)
                adapter = _make_exchange_adapter(args.mode, store, args.enable_live)
                expected = [
                    Position(row["symbol"], row["side"], Decimal(row["quantity"]), Decimal(row["entry_price"]))
                    for row in store.db.execute("SELECT symbol,side,quantity,entry_price FROM positions")
                ]
                pending = [
                    (row["symbol"], row["client_order_id"])
                    for row in store.db.execute("SELECT symbol,client_order_id FROM exchange_orders WHERE status IN ('submitting','uncertain','submitted','partial')")
                ]
                reconciliation = adapter.reconcile(expected, pending)
                if not reconciliation.matched:
                    raise ExchangeStateMismatch("Exchange account does not match the local portfolio ledger: " + "; ".join(reconciliation.discrepancies))
            print(json.dumps(start_portfolio_service(args.state, args.control, args.pid_file, args.log, args.interval, mode=args.mode, enable_live=args.enable_live, risk_settings=args.risk_settings)))
            return 0
        except (ValueError, OSError, RuntimeError) as exc:
            print(f"Could not start portfolio: {exc}", file=sys.stderr)
            return 2
        finally:
            if store is not None:
                store.close()
    if args.command in {"strategy-stop", "strategy-resume"}:
        store = SQLiteStateStore(args.state)
        try:
            if args.command == "strategy-stop":
                store.request_strategy_stop(args.strategy_id)
            else:
                store.resume_strategy(args.strategy_id)
            print(json.dumps({"ok": True, "strategy_id": args.strategy_id}))
            return 0
        except ValueError as exc:
            print(str(exc), file=sys.stderr)
            return 2
        finally:
            store.close()
    if args.command == "service-start":
        try:
            print(json.dumps(start_service(args.release, args.state, args.control, args.pid_file, args.log, args.interval)))
            return 0
        except (ValueError, OSError, RuntimeError) as exc:
            print(f"Could not start paper service: {exc}", file=sys.stderr)
            return 2
    if args.command == "service-stop":
        try:
            result = stop_service(args.pid_file, args.state)
            print(json.dumps(result))
            return 0 if result.get("stopped") or result.get("already_stopped") else 2
        except (ValueError, OSError) as exc:
            print(f"Could not stop paper service: {exc}", file=sys.stderr)
            return 2
    if args.command == "control":
        if args.action == "pause_entries":
            controls = set_controls(args.control, entries_paused=True)
        elif args.action == "resume_entries":
            controls = set_controls(args.control, entries_paused=False)
        else:
            controls = set_controls(args.control, entries_paused=True, close_positions=True)
        print(json.dumps(controls))
        return 0
    store = None
    try:
        store = SQLiteStateStore(args.state)
        is_portfolio = args.command == "portfolio-run"
        release = None if is_portfolio else load_strategy_release(args.release)
        mode = args.mode if is_portfolio else "paper"
        risk_settings_path = args.risk_settings if is_portfolio else None
        minimum_equity_percent = _minimum_equity_percent(risk_settings_path)
        if is_portfolio and mode == "paper":
            executor = PortfolioExecutor(store, stale_after_seconds=args.stale_seconds, minimum_equity_percent=minimum_equity_percent)
        elif is_portfolio:
            executor = ExchangePortfolioExecutor(store, _make_exchange_adapter(mode, store, args.enable_live), mode=mode, stale_after_seconds=args.stale_seconds, minimum_equity_percent=minimum_equity_percent)
        else:
            executor = PaperExecutor(release, store, stale_after_seconds=args.stale_seconds)
        store.db.execute("INSERT INTO runtime_meta(key,value) VALUES('execution_mode',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (mode,)); store.db.commit()
        symbols = executor.symbols() if is_portfolio else release["market"]["symbols"]
        poller = BitgetPublicTickerPoller("USDT-FUTURES" if is_portfolio else release["market"]["category"], interval_seconds=args.interval)
        control_path = args.control or str(Path(args.state).with_suffix(".control.json"))
        stopped = False

        def stop(_signum: int, _frame: object) -> None:
            nonlocal stopped
            stopped = True

        signal.signal(signal.SIGINT, stop)
        signal.signal(signal.SIGTERM, stop)
        logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
        store.event("service_started", {"mode": mode, "portfolio": is_portfolio})
        print(f"{mode.title()} portfolio running; Ctrl+C to stop" if is_portfolio else f"Paper executor running for {release['release_id']} ({release_digest(release)}); Ctrl+C to stop")
        while not stopped:
            began = time.monotonic()
            try:
                controls = read_json(control_path, {"entries_paused": False, "close_positions": False})
                close_requested = bool(controls.get("close_positions"))
                if is_portfolio:
                    executor.minimum_equity_percent = _minimum_equity_percent(risk_settings_path)
                    executor.refresh()
                executor.set_controls(entries_paused=bool(controls.get("entries_paused")), close_positions=close_requested)
                symbols = executor.symbols() if is_portfolio else release["market"]["symbols"]
                if not symbols:
                    time.sleep(max(0, args.interval - (time.monotonic() - began)))
                    continue
                quotes = poller.fetch(symbols)
                received = {quote.symbol for quote in quotes}
                for missing in set(symbols) - received:
                    store.event("missing_quote", {"reason": "ticker_missing_from_response"}, missing)
                if is_portfolio:
                    executor.on_quotes(quotes)
                else:
                    for quote in quotes:
                        executor.on_quote(quote)
                if close_requested and store.health()["open_positions"] == 0:
                    controls["close_positions"] = False
                    from .service import write_json_atomic
                    write_json_atomic(control_path, controls)
            except Exception as exc:
                logging.exception("Public quote cycle failed")
                event_kind = "exchange_runtime_error" if is_portfolio and mode in {"demo", "live"} else "market_data_error"
                error_payload = {
                    "mode": mode, "phase": "portfolio_cycle" if is_portfolio else "market_data_cycle",
                    "category": "exchange_runtime" if is_portfolio and mode in {"demo", "live"} else "market_data",
                    "exception_type": type(exc).__name__, "message": str(exc)[:500],
                    "retryable": isinstance(exc, (OSError, TimeoutError)),
                }
                if isinstance(exc, BitgetAPIError):
                    error_payload.update({
                        "category": exc.category, "error_code": exc.code,
                        "api_message": exc.api_message, "endpoint": exc.endpoint,
                        "method": exc.method, "mode": exc.mode or mode,
                        "retryable": exc.retryable, "outcome": exc.outcome,
                        **exc.details,
                    })
                store.event_error(event_kind, error_payload)
            time.sleep(max(0, args.interval - (time.monotonic() - began)))
        store.event("service_stopped", {"mode": mode})
        return 0
    except (ValueError, OSError, RuntimeError) as exc:
        print(f"Autotrading executor error: {exc}", file=sys.stderr)
        return 2
    finally:
        if store is not None:
            store.close()


if __name__ == "__main__":
    raise SystemExit(main())
