#!/usr/bin/env python3
"""Backfill Bitget historical candles and fill missing quote-snapshot minutes."""

from __future__ import annotations

import argparse
import json
import logging
import math
import os
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen
from zoneinfo import ZoneInfo

from database import (
    connect_mysql,
    insert_historical_candles,
    insert_historical_candle_snapshots,
)

API_URL = "https://api.bitget.com/api/v3/market/history-candles"
INTERVAL = "1m"
INTERVAL_MS = 60_000
PAGE_CANDLES = 100
LOCAL_ZONE = ZoneInfo("Europe/Kyiv")
MIN_REQUEST_INTERVAL_SECONDS = 0.06
LAST_REQUEST_STARTED_AT = 0.0


def fetch_page(category: str, symbol: str, first_ms: int, last_ms: int, timeout: float) -> list[list[str]]:
    global LAST_REQUEST_STARTED_AT
    wait = MIN_REQUEST_INTERVAL_SECONDS - (time.monotonic() - LAST_REQUEST_STARTED_AT)
    if wait > 0:
        time.sleep(wait)
    LAST_REQUEST_STARTED_AT = time.monotonic()
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
    scope = parser.add_mutually_exclusive_group(required=True)
    scope.add_argument("--symbol", help="One enabled symbol from config.json")
    scope.add_argument("--all-enabled", action="store_true", help="Import every enabled symbol")
    parser.add_argument("--start-date", default="2026-10-01", help="Local calendar date in Europe/Kyiv")
    parser.add_argument("--end-date", required=True, help="Inclusive local calendar end date in Europe/Kyiv")
    parser.add_argument("--timeout", type=float, default=15.0, help="HTTP timeout in seconds")
    parser.add_argument("--status-file", type=Path, help="Write import progress to this JSON file")
    return parser.parse_args()


def load_config(path: Path) -> dict[str, Any]:
    try:
        config = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ValueError(f"Could not read config {path}: {exc}") from exc
    return config


def enabled_symbols(config: dict[str, Any]) -> list[str]:
    configured = {str(item).upper() for item in config.get("symbols", [])}
    settings_path = Path(os.environ.get("HOME", "/home/alex")) / ".ai/home/.local/bitget-symbol-settings.json"
    try:
        saved = json.loads(settings_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        saved = None
    enabled = saved.get("enabled_symbols") if isinstance(saved, dict) else None
    if not isinstance(enabled, list):
        return sorted(configured)
    return sorted(configured.intersection(str(item).upper() for item in enabled))


def write_status(path: Path | None, payload: dict[str, Any]) -> None:
    if path is None:
        return
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    os.chmod(temporary, 0o600)
    temporary.replace(path)


def import_symbol(
    connection: Any,
    category: str,
    symbol: str,
    start_ms: int,
    end_exclusive_ms: int,
    timeout: float,
    on_page: Any,
) -> tuple[int, int, int]:
    last_ms = end_exclusive_ms - INTERVAL_MS
    if last_ms < start_ms:
        return 0, 0, 0

    candle_rows: dict[int, list[str]] = {}
    page_start = start_ms
    page_count = math.ceil((last_ms - start_ms + INTERVAL_MS) / (PAGE_CANDLES * INTERVAL_MS))
    page_number = 0
    while page_start <= last_ms:
        page_last = min(page_start + (PAGE_CANDLES - 1) * INTERVAL_MS, last_ms)
        rows = fetch_page(category, symbol, page_start, page_last, timeout)
        for row in rows:
            timestamp = int(row[0])
            if page_start <= timestamp <= page_last:
                candle_rows[timestamp] = row
        page_number += 1
        on_page(page_number, page_count)
        page_start = page_last + INTERVAL_MS

    candles = [candle_rows[key] for key in sorted(candle_rows)]
    try:
        raw_inserted = insert_historical_candles(connection, category, symbol, INTERVAL, candles)
        snapshots_inserted = insert_historical_candle_snapshots(
            connection, category, symbol, INTERVAL, start_ms, end_exclusive_ms,
        )
        return len(candles), raw_inserted, snapshots_inserted
    except Exception:
        connection.rollback()
        raise


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    args = parse_args()
    try:
        config = load_config(args.config)
        category = str(config.get("category", "SPOT")).upper()
        symbols = enabled_symbols(config) if args.all_enabled else [args.symbol.upper()]
        if not symbols:
            raise ValueError("There are no enabled symbols to import")
        configured = {str(item).upper() for item in config.get("symbols", [])}
        unknown = [symbol for symbol in symbols if symbol not in configured]
        disabled = [symbol for symbol in symbols if symbol not in enabled_symbols(config)]
        if unknown:
            raise ValueError(f"Symbols are not configured: {', '.join(unknown)}")
        if disabled:
            raise ValueError(f"Symbols are disabled in Settings: {', '.join(disabled)}")
        start_dt = datetime.strptime(args.start_date, "%Y-%m-%d").replace(tzinfo=LOCAL_ZONE)
        end_dt = datetime.strptime(args.end_date, "%Y-%m-%d").replace(tzinfo=LOCAL_ZONE)
        if end_dt < start_dt:
            raise ValueError("End date must be on or after the start date")
        start_ms = int(start_dt.timestamp() * 1000) // INTERVAL_MS * INTERVAL_MS
        requested_end_exclusive_ms = int((end_dt + timedelta(days=1)).timestamp() * 1000)
        latest_completed_ms = int(datetime.now(timezone.utc).timestamp() * 1000) // INTERVAL_MS * INTERVAL_MS
        end_exclusive_ms = min(requested_end_exclusive_ms, latest_completed_ms)
        total_minutes = max(0, (end_exclusive_ms - start_ms) // INTERVAL_MS)
        total_pages = math.ceil(total_minutes / PAGE_CANDLES) * len(symbols)
        write_status(args.status_file, {
            "status": "running", "progress": 0, "start_day": args.start_date,
            "end_day": args.end_date, "symbol_count": len(symbols), "completed_symbols": 0,
            "current_symbol": symbols[0], "candles_received": 0, "candles_inserted": 0,
            "snapshots_inserted": 0, "message": "Подключаюсь к Bitget и MySQL…",
        })
        mysql_config = config["mysql"]
        mysql_config.setdefault("password_env", "BITGET_DB_PASSWORD")
        connection = connect_mysql(mysql_config)
    except (KeyError, TypeError, ValueError, RuntimeError) as exc:
        logging.error("%s", exc)
        write_status(args.status_file, {"status": "error", "progress": 100, "message": str(exc)[:500]})
        return 2

    try:
        page_offset = 0
        totals = {"candles_received": 0, "candles_inserted": 0, "snapshots_inserted": 0}
        failures: list[dict[str, str]] = []
        for symbol_index, symbol in enumerate(symbols, start=1):
            write_status(args.status_file, {
                "status": "running", "progress": int(page_offset * 100 / max(1, total_pages)),
                "start_day": args.start_date, "end_day": args.end_date,
                "symbol_count": len(symbols), "completed_symbols": symbol_index - 1,
                "current_symbol": symbol, **totals,
                "message": f"Загружаю {symbol} ({symbol_index}/{len(symbols)})…",
            })

            def on_page(page_number: int, page_count: int) -> None:
                current_pages = page_offset + page_number
                write_status(args.status_file, {
                    "status": "running", "progress": int(current_pages * 100 / max(1, total_pages)),
                    "start_day": args.start_date, "end_day": args.end_date,
                    "symbol_count": len(symbols), "completed_symbols": symbol_index - 1,
                    "current_symbol": symbol, "current_page": page_number,
                    "symbol_pages": page_count, **totals,
                    "message": f"{symbol}: страница {page_number} из {page_count}",
                })

            try:
                received, inserted, snapshots = import_symbol(
                    connection, category, symbol, start_ms, end_exclusive_ms, args.timeout, on_page,
                )
                totals["candles_received"] += received
                totals["candles_inserted"] += inserted
                totals["snapshots_inserted"] += snapshots
                logging.info("%s: fetched=%s archived=%s snapshots_added=%s", symbol, received, inserted, snapshots)
            except Exception as exc:
                connection.rollback()
                failures.append({"symbol": symbol, "message": str(exc)[:500]})
                logging.exception("Historical import failed for %s", symbol)
            page_offset += math.ceil(max(0, end_exclusive_ms - start_ms) / (PAGE_CANDLES * INTERVAL_MS))
            write_status(args.status_file, {
                "status": "running", "progress": int(page_offset * 100 / max(1, total_pages)),
                "start_day": args.start_date, "end_day": args.end_date,
                "symbol_count": len(symbols), "completed_symbols": symbol_index,
                "current_symbol": symbols[symbol_index] if symbol_index < len(symbols) else None,
                **totals, "failures": failures,
                "message": f"Обработано пар: {symbol_index} из {len(symbols)}",
            })

        final_status = "error" if len(failures) == len(symbols) else "partial" if failures else "ready"
        message = "Загрузка завершена" if not failures else f"Загрузка завершена с ошибками у {len(failures)} пар"
        if total_minutes == 0:
            message = "В выбранном диапазоне пока нет завершённых минутных свечей"
        write_status(args.status_file, {
            "status": final_status, "progress": 100, "start_day": args.start_date,
            "end_day": args.end_date, "symbol_count": len(symbols),
            "completed_symbols": len(symbols), "current_symbol": None,
            **totals, "failures": failures, "message": message,
        })
        return 1 if final_status == "error" else 0
    except Exception as exc:
        if "connection" in locals():
            connection.rollback()
        logging.exception("Historical import job failed")
        write_status(args.status_file, {"status": "error", "progress": 100, "message": str(exc)[:500]})
        return 1
    finally:
        if "connection" in locals():
            connection.close()


if __name__ == "__main__":
    raise SystemExit(main())
