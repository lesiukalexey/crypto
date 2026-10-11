"""Small local command-line tools for portable strategy releases."""

from __future__ import annotations

import argparse
import json
import logging
import signal
import sys
import time
from pathlib import Path

from .contracts import load_strategy_release, release_digest
from .market import BitgetPublicTickerPoller
from .paper import PaperExecutor, SQLiteStateStore
from .service import read_json, service_status, set_controls, start_service, stop_service


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
    args = parser.parse_args()
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
        release = load_strategy_release(args.release)
        store = SQLiteStateStore(args.state)
        executor = PaperExecutor(release, store, stale_after_seconds=args.stale_seconds)
        poller = BitgetPublicTickerPoller(release["market"]["category"], interval_seconds=args.interval)
        control_path = args.control or str(Path(args.state).with_suffix(".control.json"))
        stopped = False

        def stop(_signum: int, _frame: object) -> None:
            nonlocal stopped
            stopped = True

        signal.signal(signal.SIGINT, stop)
        signal.signal(signal.SIGTERM, stop)
        logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
        store.event("service_started", {"mode": "paper", "release_id": release["release_id"], "digest": release_digest(release)})
        print(f"Paper executor running for {release['release_id']} ({release_digest(release)}); Ctrl+C to stop")
        while not stopped:
            began = time.monotonic()
            try:
                controls = read_json(control_path, {"entries_paused": False, "close_positions": False})
                close_requested = bool(controls.get("close_positions"))
                executor.set_controls(entries_paused=bool(controls.get("entries_paused")), close_positions=close_requested)
                quotes = poller.fetch(release["market"]["symbols"])
                received = {quote.symbol for quote in quotes}
                for missing in set(release["market"]["symbols"]) - received:
                    store.event("missing_quote", {"reason": "ticker_missing_from_response"}, missing)
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
