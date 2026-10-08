#!/usr/bin/env python3
"""Periodically collect public Bitget bid/ask quotes into MySQL."""

from __future__ import annotations

import argparse
import json
import logging
import signal
import sys
import threading
import time
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from database import connect_mysql, insert_snapshots

API_URL = "https://api.bitget.com/api/v3/market/tickers"
VALID_CATEGORIES = {"SPOT", "USDT-FUTURES", "COIN-FUTURES", "USDC-FUTURES"}
STOP_EVENT = threading.Event()


def stop_handler(_signum: int, _frame: Any) -> None:
    STOP_EVENT.set()


def load_config(path: Path) -> dict[str, Any]:
    try:
        config = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise ValueError(f"Config file not found: {path}") from exc
    except json.JSONDecodeError as exc:
        raise ValueError(f"Invalid JSON in {path}: {exc}") from exc

    category = str(config.get("category", "SPOT")).upper()
    if category not in VALID_CATEGORIES:
        raise ValueError(f"Unsupported category {category!r}; choose one of {', '.join(sorted(VALID_CATEGORIES))}")
    raw_symbols = config.get("symbols", [])
    if not isinstance(raw_symbols, list):
        raise ValueError('Config field "symbols" must be a JSON array, e.g. ["BTCUSDT"].')
    symbols = sorted({str(symbol).strip().upper() for symbol in raw_symbols if str(symbol).strip()})
    if not symbols:
        raise ValueError('No symbols configured. Add ticker names to the "symbols" array in config.json.')

    interval = float(config.get("poll_interval_seconds", 5))
    request_timeout = float(config.get("request_timeout_seconds", 10))
    if interval <= 0 or request_timeout <= 0:
        raise ValueError("Poll interval and request timeout must be greater than zero.")
    mysql = config.get("mysql", {})
    if not isinstance(mysql, dict):
        raise ValueError('Config field "mysql" must be a JSON object.')
    mysql_settings = {
        "host": str(mysql.get("host", "127.0.0.1")),
        "port": int(mysql.get("port", 3306)),
        "user": str(mysql.get("user", "bitget_tracker")),
        "password_env": str(mysql.get("password_env", "BITGET_DB_PASSWORD")),
        "database": str(mysql.get("database", "bitget_quotes")),
        "connect_timeout_seconds": int(mysql.get("connect_timeout_seconds", 5)),
    }
    return {
        "category": category,
        "symbols": symbols,
        "poll_interval_seconds": interval,
        "request_timeout_seconds": request_timeout,
        "mysql": mysql_settings,
    }


def fetch_tickers(category: str, timeout: float) -> list[dict[str, Any]]:
    url = f"{API_URL}?{urlencode({'category': category})}"
    request = Request(url, headers={"Accept": "application/json", "User-Agent": "bitget-price-tracker/1.0"})
    try:
        with urlopen(request, timeout=timeout) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Bitget returned HTTP {exc.code}: {detail[:300]}") from exc
    except (URLError, TimeoutError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Could not read Bitget ticker response: {exc}") from exc
    if payload.get("code") != "00000":
        raise RuntimeError(f"Bitget API error {payload.get('code')}: {payload.get('msg', 'unknown error')}")
    data = payload.get("data")
    if not isinstance(data, list):
        raise RuntimeError("Unexpected Bitget response: data is not an array")
    return data


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, default=Path("config.json"), help="JSON config file")
    parser.add_argument("--once", action="store_true", help="Collect one snapshot and exit")
    return parser.parse_args()


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    args = parse_args()
    try:
        config = load_config(args.config)
        connection = connect_mysql(config["mysql"])
    except (ValueError, RuntimeError) as exc:
        logging.error("%s", exc)
        return 2

    logging.info(
        "Collecting %s symbols for %s every %s seconds into MySQL %s",
        len(config["symbols"]), config["category"], config["poll_interval_seconds"],
        config["mysql"]["database"],
    )
    signal.signal(signal.SIGINT, stop_handler)
    signal.signal(signal.SIGTERM, stop_handler)
    symbols = set(config["symbols"])
    try:
        while not STOP_EVENT.is_set():
            started = time.monotonic()
            try:
                tickers = fetch_tickers(config["category"], config["request_timeout_seconds"])
                saved = insert_snapshots(connection, config["category"], tickers, symbols)
                logging.info("Saved %s of %s configured quote snapshots", saved, len(symbols))
            except RuntimeError as exc:
                logging.error("Collection failed; will retry after the configured interval: %s", exc)
                if args.once:
                    return 1
            if args.once or STOP_EVENT.is_set():
                break
            delay = max(0.0, config["poll_interval_seconds"] - (time.monotonic() - started))
            STOP_EVENT.wait(delay)
    finally:
        connection.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
