#!/usr/bin/env python3
"""Backfill Bitget historical candles through the latest stored quote snapshot."""

from __future__ import annotations

import argparse
import json
import logging
import time
from datetime import datetime
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen
from zoneinfo import ZoneInfo

from database import (
    connect_mysql,
    insert_historical_candles,
    latest_historical_candle_timestamp_ms,
    latest_snapshot_timestamp_ms,
)

API_URL = "https://api.bitget.com/api/v3/market/history-candles"
INTERVAL = "1m"
INTERVAL_MS = 60_000
PAGE_CANDLES = 100
LOCAL_ZONE = ZoneInfo("Europe/Kyiv")


def fetch_page(category: str, symbol: str, first_ms: int, last_ms: int, timeout: float) -> list[list[str]]:
    params = {
        "category": category,
        "symbol": symbol,
        "interval": INTERVAL,
        "startTime": str(first_ms - 1),
        "endTime": str(last_ms + INTERVAL_MS),
        "limit": str(PAGE_CANDLES),
    }
    url = f"{API_URL}?{urlencode(params)}"
    request = Request(url, headers={"Accept": "application/json", "User-Agent": "bitget-price-tracker/1.0"})
    try:
        with urlopen(request, timeout=timeout) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Bitget returned HTTP {exc.code}: {detail[:300]}") from exc
    except (URLError, TimeoutError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Could not read Bitget historical candles: {exc}") from exc
    if payload.get("code") != "00000":
        raise RuntimeError(f"Bitget API error {payload.get('code')}: {payload.get('msg', 'unknown error')}")
    data = payload.get("data")
    if not isinstance(data, list):
        raise RuntimeError("Unexpected Bitget response: data is not an array")
    rows = [row for row in data if isinstance(row, list) and len(row) >= 7]
    return sorted((list(map(str, row[:7])) for row in rows), key=lambda row: int(row[0]))


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, default=Path("config.json"), help="JSON config file")
    parser.add_argument("--symbol", required=True, help="One symbol from config.json")
    parser.add_argument("--start-date", default="2026-10-01", help="Local calendar date in Europe/Kyiv")
    parser.add_argument("--timeout", type=float, default=15.0, help="HTTP timeout in seconds")
    return parser.parse_args()


def load_config(path: Path) -> dict[str, Any]:
    try:
        config = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ValueError(f"Could not read config {path}: {exc}") from exc
    return config


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    args = parse_args()
    try:
        config = load_config(args.config)
        category = str(config.get("category", "SPOT")).upper()
        symbol = args.symbol.upper()
        if symbol not in {str(item).upper() for item in config.get("symbols", [])}:
            raise ValueError(f"Symbol {symbol} is not configured in {args.config}")
        start_dt = datetime.strptime(args.start_date, "%Y-%m-%d").replace(tzinfo=LOCAL_ZONE)
        start_ms = int(start_dt.timestamp() * 1000)
        mysql_config = config["mysql"]
        mysql_config.setdefault("password_env", "BITGET_DB_PASSWORD")
        connection = connect_mysql(mysql_config)
    except (KeyError, TypeError, ValueError, RuntimeError) as exc:
        logging.error("%s", exc)
        return 2

    try:
        latest_quote_ms = latest_snapshot_timestamp_ms(connection, category, symbol)
        if latest_quote_ms is None:
            logging.error("No timestamped quote snapshots found for %s (%s)", symbol, category)
            return 2
        last_candle_ms = latest_quote_ms // INTERVAL_MS * INTERVAL_MS - INTERVAL_MS
        if last_candle_ms < start_ms:
            logging.error("Latest saved quote for %s is earlier than the requested start date", symbol)
            return 2

        candle_rows: dict[int, list[str]] = {}
        latest_saved_candle_ms = latest_historical_candle_timestamp_ms(connection, category, symbol, INTERVAL)
        import_start_ms = max(
            start_ms // INTERVAL_MS * INTERVAL_MS,
            latest_saved_candle_ms + INTERVAL_MS if latest_saved_candle_ms is not None else start_ms // INTERVAL_MS * INTERVAL_MS,
        )
        if import_start_ms > last_candle_ms:
            logging.info("No completed 1m candles are missing through the latest saved quote")
            return 0
        page_start = import_start_ms
        while page_start <= last_candle_ms:
            page_last = min(page_start + (PAGE_CANDLES - 1) * INTERVAL_MS, last_candle_ms)
            rows = fetch_page(category, symbol, page_start, page_last, args.timeout)
            for row in rows:
                timestamp = int(row[0])
                if page_start <= timestamp <= page_last and timestamp <= last_candle_ms:
                    candle_rows[timestamp] = row
            page_start = page_last + INTERVAL_MS
            time.sleep(0.06)

        candles = [candle_rows[key] for key in sorted(candle_rows)]
        saved = insert_historical_candles(connection, category, symbol, INTERVAL, candles)
        first_time = datetime.fromtimestamp(int(candles[0][0]) / 1000, LOCAL_ZONE) if candles else None
        last_time = datetime.fromtimestamp(int(candles[-1][0]) / 1000, LOCAL_ZONE) if candles else None
        quote_time = datetime.fromtimestamp(latest_quote_ms / 1000, LOCAL_ZONE)
        logging.info("Symbol: %s (%s)", symbol, category)
        logging.info("Existing quote boundary: %s Europe/Kyiv", quote_time.isoformat(sep=" "))
        logging.info("New candles inserted: %s", saved)
        logging.info("Candle range: %s — %s Europe/Kyiv", first_time, last_time)
        return 0
    except RuntimeError as exc:
        connection.rollback()
        logging.error("%s", exc)
        return 1
    finally:
        connection.close()


if __name__ == "__main__":
    raise SystemExit(main())
