"""MySQL persistence for collected Bitget quotes."""

from __future__ import annotations

import os
from datetime import datetime, timezone
from typing import Any


def connect_mysql(config: dict[str, Any]):
    try:
        import pymysql
    except ImportError as exc:
        raise RuntimeError("MySQL driver missing. Install dependencies with: python3 -m pip install -r requirements.txt") from exc

    password = os.environ.get(config["password_env"], "")
    if not password:
        raise RuntimeError(f"Set the MySQL password in environment variable {config['password_env']}.")
    try:
        connection = pymysql.connect(
            host=config["host"], port=config["port"], user=config["user"],
            password=password, database=config["database"], charset="utf8mb4",
            autocommit=False, connect_timeout=config["connect_timeout_seconds"],
        )
        initialize_schema(connection)
        return connection
    except pymysql.MySQLError as exc:
        if "connection" in locals():
            connection.close()
        raise RuntimeError(f"MySQL connection or schema setup failed: {exc}") from exc


def initialize_schema(connection: Any) -> None:
    with connection.cursor() as cursor:
        cursor.execute(
            """CREATE TABLE IF NOT EXISTS quote_snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(24) NOT NULL,
                symbol VARCHAR(40) NOT NULL,
                bid_price VARCHAR(80) NOT NULL,
                ask_price VARCHAR(80) NOT NULL,
                last_price VARCHAR(80) NOT NULL,
                bid_size VARCHAR(80) NULL,
                ask_size VARCHAR(80) NULL,
                exchange_timestamp_ms BIGINT NULL,
                received_at_utc DATETIME(3) NOT NULL,
                KEY idx_quote_symbol_time (category, symbol, received_at_utc)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"""
        )
    connection.commit()


def insert_snapshots(connection: Any, category: str, snapshots: list[dict[str, Any]], symbols: set[str]) -> int:
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    by_symbol = {str(row.get("symbol", "")).upper(): row for row in snapshots}
    rows = []
    for symbol in sorted(symbols):
        ticker = by_symbol.get(symbol)
        if ticker is None:
            print(f"WARNING: Bitget did not return configured symbol {symbol} ({category})")
            continue
        required = ("bid1Price", "ask1Price", "lastPrice")
        if any(ticker.get(field) in (None, "") for field in required):
            print(f"WARNING: Bitget returned incomplete bid/ask data for {symbol}; snapshot skipped")
            continue
        ts = ticker.get("ts")
        rows.append((
            category, symbol, str(ticker["bid1Price"]), str(ticker["ask1Price"]),
            str(ticker["lastPrice"]),
            str(ticker["bid1Size"]) if ticker.get("bid1Size") is not None else None,
            str(ticker["ask1Size"]) if ticker.get("ask1Size") is not None else None,
            int(ts) if ts not in (None, "") else None, now,
        ))
    if rows:
        with connection.cursor() as cursor:
            cursor.executemany(
                """INSERT INTO quote_snapshots (
                    category, symbol, bid_price, ask_price, last_price,
                    bid_size, ask_size, exchange_timestamp_ms, received_at_utc
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)""",
                rows,
            )
        connection.commit()
    return len(rows)
