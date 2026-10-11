"""Small local command-line tools for portable strategy releases."""

from __future__ import annotations

import argparse
import json
import logging
import signal
import sys
import time

from .contracts import load_strategy_release, release_digest
from .market import BitgetPublicTickerPoller
from .paper import PaperExecutor, SQLiteStateStore


def main() -> int:
    parser = argparse.ArgumentParser(prog="python -m autotrading")
    commands = parser.add_subparsers(dest="command", required=True)
    validate = commands.add_parser("validate", help="validate a strategy release")
    validate.add_argument("release", help="path to a strategy release JSON file")
    run = commands.add_parser("paper-run", help="run a public-quote paper executor")
    run.add_argument("release", help="strategy release JSON")
    run.add_argument("--state", required=True, help="SQLite state path outside the repository")
    run.add_argument("--interval", type=float, default=5, help="public ticker polling interval in seconds")
    run.add_argument("--stale-seconds", type=int, default=30, help="maximum accepted quote age")
    status = commands.add_parser("status", help="show local paper executor state")
    status.add_argument("--state", required=True, help="SQLite state path")
    args = parser.parse_args()
    if args.command == "validate":
        try:
            release = load_strategy_release(args.release)
            print(f"Valid strategy release: {release['release_id']}")
            print(f"SHA-256: {release_digest(release)}")
            return 0
        except ValueError as exc:
            print(f"Invalid strategy release: {exc}", file=sys.stderr)
            return 2
    if args.command == "status":
        store = SQLiteStateStore(args.state)
        print(json.dumps(store.health(), default=dict, ensure_ascii=False, indent=2))
        store.close()
        return 0
    store = None
    try:
        release = load_strategy_release(args.release)
        store = SQLiteStateStore(args.state)
        executor = PaperExecutor(release, store, stale_after_seconds=args.stale_seconds)
        poller = BitgetPublicTickerPoller(release["market"]["category"], interval_seconds=args.interval)
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
                quotes = poller.fetch(release["market"]["symbols"])
                received = {quote.symbol for quote in quotes}
                for missing in set(release["market"]["symbols"]) - received:
                    store.event("missing_quote", {"reason": "ticker_missing_from_response"}, missing)
                for quote in quotes:
                    executor.on_quote(quote)
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
