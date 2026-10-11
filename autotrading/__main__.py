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
from pathlib import Path

from .contracts import load_strategy_release, release_digest
from .bitget_demo import BitgetDemoAdapter, BitgetAPIError
from .market import BitgetPublicTickerPoller
from .paper import PaperExecutor, PortfolioExecutor, SQLiteStateStore
from .pi_sync import pull_status as pi_pull_status, remote_stage_release, remote_status, stage_release as pi_stage_release
from .service import read_json, service_status, set_controls, start_portfolio_service, start_service, stop_service


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
    portfolio_start = commands.add_parser("portfolio-start", help="start the shared paper portfolio service")
    for option in ("state", "control", "pid-file", "log"):
        portfolio_start.add_argument("--" + option, required=True)
    portfolio_start.add_argument("--interval", type=float, default=5)
    demo_reconcile = commands.add_parser("demo-reconcile", help="read-only reconciliation of a Bitget Demo account")
    demo_reconcile.add_argument("--symbols", required=True, help="comma-separated uppercase USDT-futures symbols")
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
        try:
            symbols = [symbol.strip() for symbol in args.symbols.split(",") if symbol.strip()]
            api_key = os.getenv("BITGET_DEMO_API_KEY", "")
            secret = os.getenv("BITGET_DEMO_API_SECRET", "")
            passphrase = os.getenv("BITGET_DEMO_API_PASSPHRASE", "")
            env_file = os.getenv("BITGET_DEMO_ENV_FILE", "")
            if not all((api_key, secret, passphrase)) and env_file:
                path = Path(env_file)
                metadata = path.lstat()
                if not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != os.geteuid() or metadata.st_mode & 0o077:
                    raise ValueError("Demo credentials file must be a regular file with owner-only permissions")
                values: dict[str, str] = {}
                for line in path.read_text(encoding="utf-8").splitlines():
                    if not line or line.startswith("#"):
                        continue
                    name, separator, value = line.partition("=")
                    if not separator or name in values:
                        raise ValueError("Demo credentials file has an invalid format")
                    values[name] = value
                api_key = api_key or values.get("BITGET_DEMO_API_KEY", "")
                secret = secret or values.get("BITGET_DEMO_API_SECRET", "")
                passphrase = passphrase or values.get("BITGET_DEMO_API_PASSPHRASE", "")
            if not all((api_key, secret, passphrase)):
                raise ValueError("Demo API credentials are not configured for this local process")
            adapter = BitgetDemoAdapter(symbols, api_key=api_key, secret=secret, passphrase=passphrase)
            result = adapter.reconcile(expected_positions=[], pending_orders=[])
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
                "basis": "empty_local_demo_ledger",
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
    if args.command == "portfolio-start":
        try:
            print(json.dumps(start_portfolio_service(args.state, args.control, args.pid_file, args.log, args.interval)))
            return 0
        except (ValueError, OSError, RuntimeError) as exc:
            print(f"Could not start paper portfolio: {exc}", file=sys.stderr)
            return 2
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
        executor = PortfolioExecutor(store, stale_after_seconds=args.stale_seconds) if is_portfolio else PaperExecutor(release, store, stale_after_seconds=args.stale_seconds)
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
        store.event("service_started", {"mode": "paper", "portfolio": is_portfolio})
        print("Paper portfolio running; Ctrl+C to stop" if is_portfolio else f"Paper executor running for {release['release_id']} ({release_digest(release)}); Ctrl+C to stop")
        while not stopped:
            began = time.monotonic()
            try:
                controls = read_json(control_path, {"entries_paused": False, "close_positions": False})
                close_requested = bool(controls.get("close_positions"))
                if is_portfolio:
                    executor.refresh()
                executor.set_controls(entries_paused=bool(controls.get("entries_paused")), close_positions=close_requested)
                symbols = executor.symbols() if is_portfolio else release["market"]["symbols"]
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
                store.event("market_data_error", {"message": str(exc)[:500]})
            time.sleep(max(0, args.interval - (time.monotonic() - began)))
        store.event("service_stopped", {"mode": "paper"})
        return 0
    except (ValueError, OSError) as exc:
        print(f"Paper executor error: {exc}", file=sys.stderr)
        return 2
    finally:
        if store is not None:
            store.close()


if __name__ == "__main__":
    raise SystemExit(main())
