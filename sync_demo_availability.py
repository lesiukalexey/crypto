#!/usr/bin/env python3
"""Persist Bitget Demo contract availability for every configured ticker."""

from __future__ import annotations

import argparse
import json
import os
from datetime import datetime, timezone
from pathlib import Path

from app import load_config
from autotrading.__main__ import _load_private_environment
from autotrading.bitget_demo import BitgetDemoAdapter, PRODUCT_TYPE
from database import connect_mysql


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, default=Path("config.json"))
    parser.add_argument("--demo-env-file", type=Path)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if args.demo_env_file is not None:
        os.environ["BITGET_DEMO_ENV_FILE"] = str(args.demo_env_file)
    config = load_config(args.config)
    credentials = _load_private_environment("demo")
    adapter = BitgetDemoAdapter(
        [config["symbols"][0]],
        api_key=credentials["BITGET_DEMO_API_KEY"],
        secret=credentials["BITGET_DEMO_API_SECRET"],
        passphrase=credentials["BITGET_DEMO_API_PASSPHRASE"],
        timeout_seconds=config["request_timeout_seconds"],
    )
    response = adapter._request("GET", "/api/v2/mix/market/contracts", params={"productType": PRODUCT_TYPE})
    rows = response.get("data")
    if not isinstance(rows, list):
        raise RuntimeError("Bitget Demo returned no contract list")
    demo_symbols = {
        str(row.get("symbol"))
        for row in rows
        if isinstance(row, dict) and row.get("symbolStatus") == "normal" and row.get("symbol")
    }
    checked_at = datetime.now(timezone.utc).replace(tzinfo=None)
    values = [
        (
            config["category"], symbol, 1 if symbol in demo_symbols else 0,
            "available" if symbol in demo_symbols else "not_listed_in_demo", checked_at,
        )
        for symbol in config["symbols"]
    ]
    connection = connect_mysql(config["mysql"])
    try:
        with connection.cursor() as cursor:
            cursor.executemany(
                """INSERT INTO ticker_capability_checks
                   (category, symbol, demo_available, demo_reason, demo_checked_at_utc)
                   VALUES (%s, %s, %s, %s, %s)""",
                values,
            )
        connection.commit()
    finally:
        connection.close()
    available = [symbol for symbol in config["symbols"] if symbol in demo_symbols]
    print(json.dumps({
        "configured": len(config["symbols"]),
        "available": len(available),
        "unavailable": len(config["symbols"]) - len(available),
        "available_symbols": available,
    }, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
