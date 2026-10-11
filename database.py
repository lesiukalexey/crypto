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
                quote_source VARCHAR(32) NOT NULL DEFAULT 'ticker',
                candle_open_price VARCHAR(80) NULL,
                candle_high_price VARCHAR(80) NULL,
                candle_low_price VARCHAR(80) NULL,
                candle_close_price VARCHAR(80) NULL,
                base_volume VARCHAR(80) NULL,
                quote_volume VARCHAR(80) NULL,
                KEY idx_quote_symbol_time (category, symbol, received_at_utc)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"""
        )
        cursor.execute(
            """CREATE TABLE IF NOT EXISTS historical_candles (
                category VARCHAR(24) NOT NULL,
                symbol VARCHAR(40) NOT NULL,
                interval_name VARCHAR(8) NOT NULL,
                candle_timestamp_ms BIGINT NOT NULL,
                open_price VARCHAR(80) NOT NULL,
                high_price VARCHAR(80) NOT NULL,
                low_price VARCHAR(80) NOT NULL,
                close_price VARCHAR(80) NOT NULL,
                base_volume VARCHAR(80) NOT NULL,
                quote_volume VARCHAR(80) NOT NULL,
                retrieved_at_utc DATETIME(3) NOT NULL,
                PRIMARY KEY (category, symbol, interval_name, candle_timestamp_ms),
                KEY idx_historical_candles_time (category, symbol, interval_name, candle_timestamp_ms)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"""
        )
        cursor.execute(
            """CREATE TABLE IF NOT EXISTS historical_candle_coverage (
                category VARCHAR(24) NOT NULL,
                symbol VARCHAR(40) NOT NULL,
                interval_name VARCHAR(8) NOT NULL,
                start_timestamp_ms BIGINT NOT NULL,
                end_exclusive_timestamp_ms BIGINT NOT NULL,
                checked_at_utc DATETIME(3) NOT NULL,
                PRIMARY KEY (category, symbol, interval_name, start_timestamp_ms, end_exclusive_timestamp_ms),
                KEY idx_historical_candle_coverage_range (category, symbol, interval_name, start_timestamp_ms, end_exclusive_timestamp_ms)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"""
        )
    connection.commit()


def latest_snapshot_timestamp_ms(connection: Any, category: str, symbol: str) -> int | None:
    with connection.cursor() as cursor:
        cursor.execute(
            """SELECT MAX(received_at_utc)
               FROM quote_snapshots
               WHERE category = %s AND symbol = %s AND quote_source = 'ticker'""",
            (category, symbol),
        )
        row = cursor.fetchone()
    if not row or row[0] is None:
        return None
    timestamp = row[0].replace(tzinfo=timezone.utc).timestamp()
    return int(timestamp * 1000)


def latest_quote_timestamp_ms(connection: Any, category: str, symbol: str) -> int | None:
    """Return the newest saved quote timestamp, including imported history."""
    with connection.cursor() as cursor:
        cursor.execute(
            """SELECT MAX(received_at_utc)
               FROM quote_snapshots
               WHERE category = %s AND symbol = %s""",
            (category, symbol),
        )
        row = cursor.fetchone()
    if not row or row[0] is None:
        return None
    return int(row[0].replace(tzinfo=timezone.utc).timestamp() * 1000)


def first_snapshot_timestamp_ms(connection: Any, category: str, symbol: str) -> int | None:
    with connection.cursor() as cursor:
        cursor.execute(
            """SELECT MIN(received_at_utc)
               FROM quote_snapshots
               WHERE category = %s AND symbol = %s AND quote_source = 'ticker'""",
            (category, symbol),
        )
        row = cursor.fetchone()
    if not row or row[0] is None:
        return None
    timestamp = row[0].replace(tzinfo=timezone.utc).timestamp()
    return int(timestamp * 1000)


def latest_historical_candle_timestamp_ms(
    connection: Any, category: str, symbol: str, interval_name: str
) -> int | None:
    with connection.cursor() as cursor:
        cursor.execute(
            """SELECT MAX(candle_timestamp_ms)
               FROM historical_candles
               WHERE category = %s AND symbol = %s AND interval_name = %s""",
            (category, symbol, interval_name),
        )
        row = cursor.fetchone()
    return int(row[0]) if row and row[0] is not None else None


def insert_historical_candles(
    connection: Any,
    category: str,
    symbol: str,
    interval_name: str,
    candles: list[list[str]],
) -> int:
    retrieved_at = datetime.now(timezone.utc).replace(tzinfo=None)
    rows = [
        (category, symbol, interval_name, int(candle[0]), *map(str, candle[1:7]), retrieved_at)
        for candle in candles
    ]
    if not rows:
        return 0
    with connection.cursor() as cursor:
        cursor.executemany(
            """INSERT IGNORE INTO historical_candles (
                   category, symbol, interval_name, candle_timestamp_ms,
                   open_price, high_price, low_price, close_price, base_volume, quote_volume,
                   retrieved_at_utc
               ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
            rows,
        )
        inserted = cursor.rowcount
    connection.commit()
    return inserted


def historical_candle_timestamps(
    connection: Any,
    category: str,
    symbol: str,
    interval_name: str,
    start_ms: int,
    end_exclusive_ms: int,
) -> list[int]:
    """Return archived candle timestamps in [start_ms, end_exclusive_ms)."""
    with connection.cursor() as cursor:
        cursor.execute(
            """SELECT candle_timestamp_ms
               FROM historical_candles
               WHERE category = %s AND symbol = %s AND interval_name = %s
                 AND candle_timestamp_ms >= %s AND candle_timestamp_ms < %s
               ORDER BY candle_timestamp_ms""",
            (category, symbol, interval_name, start_ms, end_exclusive_ms),
        )
        return [int(row[0]) for row in cursor.fetchall()]


def historical_candle_coverage(
    connection: Any,
    category: str,
    symbol: str,
    interval_name: str,
    start_ms: int,
    end_exclusive_ms: int,
) -> list[tuple[int, int]]:
    """Return successfully queried historical ranges overlapping a request."""
    with connection.cursor() as cursor:
        cursor.execute(
            """SELECT start_timestamp_ms, end_exclusive_timestamp_ms
               FROM historical_candle_coverage
               WHERE category = %s AND symbol = %s AND interval_name = %s
                 AND start_timestamp_ms < %s AND end_exclusive_timestamp_ms > %s
               ORDER BY start_timestamp_ms""",
            (category, symbol, interval_name, end_exclusive_ms, start_ms),
        )
        return [(int(row[0]), int(row[1])) for row in cursor.fetchall()]


def mark_historical_candle_coverage(
    connection: Any,
    category: str,
    symbol: str,
    interval_name: str,
    start_ms: int,
    end_exclusive_ms: int,
) -> None:
    """Record a range only after its response and derived snapshot write succeeded."""
    if end_exclusive_ms <= start_ms:
        raise ValueError("Historical candle coverage range must be non-empty")
    checked_at = datetime.now(timezone.utc).replace(tzinfo=None)
    with connection.cursor() as cursor:
        cursor.execute(
            """INSERT IGNORE INTO historical_candle_coverage (
                   category, symbol, interval_name, start_timestamp_ms,
                   end_exclusive_timestamp_ms, checked_at_utc
               ) VALUES (%s, %s, %s, %s, %s, %s)""",
            (category, symbol, interval_name, start_ms, end_exclusive_ms, checked_at),
        )
    connection.commit()


def insert_historical_candle_snapshots(
    connection: Any,
    category: str,
    symbol: str,
    interval_name: str,
    start_ms: int,
    end_exclusive_ms: int,
) -> int:
    start_time = datetime.fromtimestamp(start_ms / 1000, timezone.utc).replace(tzinfo=None)
    end_time = datetime.fromtimestamp(end_exclusive_ms / 1000, timezone.utc).replace(tzinfo=None)
    with connection.cursor() as cursor:
        cursor.execute(
            """SELECT candle_timestamp_ms, open_price, high_price, low_price,
                      close_price, base_volume, quote_volume
               FROM historical_candles
               WHERE category = %s AND symbol = %s AND interval_name = %s
                 AND candle_timestamp_ms >= %s AND candle_timestamp_ms < %s
               ORDER BY candle_timestamp_ms""",
            (category, symbol, interval_name, start_ms, end_exclusive_ms),
        )
        candles = cursor.fetchall()
        if not candles:
            return 0
        cursor.execute(
            """SELECT exchange_timestamp_ms
               FROM quote_snapshots
               WHERE category = %s AND symbol = %s
                 AND quote_source = 'historical_candle_close'
                 AND exchange_timestamp_ms >= %s AND exchange_timestamp_ms < %s""",
            (category, symbol, start_ms, end_exclusive_ms),
        )
        existing = {int(row[0]) for row in cursor.fetchall() if row[0] is not None}
        cursor.execute(
            """SELECT received_at_utc
               FROM quote_snapshots
               WHERE category = %s AND symbol = %s AND quote_source = 'ticker'
                 AND received_at_utc >= %s AND received_at_utc < %s""",
            (category, symbol, start_time, end_time),
        )
        ticker_minutes = {
            int(row[0].replace(tzinfo=timezone.utc).timestamp() * 1000) // 60_000
            for row in cursor.fetchall()
        }
        rows = []
        for candle in candles:
            timestamp_ms = int(candle[0])
            if timestamp_ms in existing or timestamp_ms // 60_000 in ticker_minutes:
                continue
            candle_time = datetime.fromtimestamp(timestamp_ms / 1000, timezone.utc).replace(tzinfo=None)
            close = str(candle[4])
            rows.append((
                category, symbol, close, close, close, None, None, timestamp_ms, candle_time,
                'historical_candle_close', *map(str, candle[1:]),
            ))
        if not rows:
            return 0
        cursor.executemany(
            """INSERT INTO quote_snapshots (
                   category, symbol, bid_price, ask_price, last_price, bid_size, ask_size,
                   exchange_timestamp_ms, received_at_utc, quote_source,
                   candle_open_price, candle_high_price, candle_low_price, candle_close_price,
                   base_volume, quote_volume
               ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
            rows,
        )
        inserted = cursor.rowcount
    connection.commit()
    return inserted


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
                    bid_size, ask_size, exchange_timestamp_ms, received_at_utc, quote_source
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, 'ticker')""",
                rows,
            )
        connection.commit()
    return len(rows)
