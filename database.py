"""MySQL persistence for collected Bitget quotes."""

from __future__ import annotations

import os
import time
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
        cursor.execute(
            """CREATE TABLE IF NOT EXISTS ticker_capability_checks (
                category VARCHAR(24) NOT NULL,
                symbol VARCHAR(40) NOT NULL,
                demo_available TINYINT(1) NOT NULL,
                demo_reason VARCHAR(255) NOT NULL,
                demo_checked_at_utc DATETIME(3) NOT NULL,
                PRIMARY KEY (category, symbol, demo_checked_at_utc),
                KEY idx_ticker_capability_latest (category, symbol, demo_checked_at_utc),
                KEY idx_ticker_capability_demo (category, demo_available, demo_checked_at_utc)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"""
        )
        cursor.execute(
            """CREATE TABLE IF NOT EXISTS strategy_return_variant_types (
                variant_type_id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                entry_type VARCHAR(32) NOT NULL,
                entry_direction VARCHAR(12) NOT NULL,
                exit_type VARCHAR(16) NOT NULL,
                UNIQUE KEY uq_strategy_return_variant (entry_type,entry_direction,exit_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin"""
        )
        cursor.execute(
            """CREATE TABLE IF NOT EXISTS strategy_return_profiles (
                strategy_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                variant_type_id SMALLINT UNSIGNED NOT NULL,
                entry_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                entry_label VARCHAR(255) NOT NULL,
                exit_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                exit_label VARCHAR(255) NOT NULL,
                entry_threshold_tenths_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                entry_window_snapshots SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                take_profit_tenths_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                stop_loss_tenths_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                exit_confirmation_mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'immediate',
                exit_confirmation_snapshots SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uq_strategy_return_profile (entry_id,exit_id,exit_confirmation_mode,exit_confirmation_snapshots),
                KEY idx_strategy_return_profile_type (variant_type_id,strategy_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"""
        )
        cursor.execute(
            """CREATE TABLE IF NOT EXISTS strategy_return_points (
                symbol VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                strategy_id INT UNSIGNED NOT NULL,
                point_minute DATETIME NOT NULL,
                return_percent DECIMAL(20,10) NOT NULL,
                trade_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (symbol,strategy_id,point_minute),
                KEY idx_strategy_return_point_time (point_minute,strategy_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin"""
        )
        variants = []
        for entry_type in ("momentum_exact_follow", "momentum_within_follow", "momentum_exact_reverse", "momentum_within_reverse", "immediate"):
            directions = ("long", "short") if entry_type == "immediate" else ("both", "long", "short")
            for direction in directions:
                for exit_type in ("fixed", "loss", "profit", "none"):
                    variants.append((entry_type, direction, exit_type))
    # Ranking requests can start several Python processes at once. Serialize
    # the first seed so simultaneous INSERT IGNORE batches do not deadlock on
    # the catalog's supremum record. Once populated, later schema checks are
    # read-only for this lookup table.
    seed_lock = "bitget_strategy_return_variant_types_seed"
    with connection.cursor() as cursor:
        cursor.execute("SELECT GET_LOCK(%s, 60)", (seed_lock,))
        acquired = cursor.fetchone()[0]
    if acquired != 1:
        raise RuntimeError("Timed out waiting to seed strategy return variant types")
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT COUNT(*) FROM strategy_return_variant_types")
            existing_count = int(cursor.fetchone()[0])
            if existing_count < len(variants):
                cursor.executemany(
                    "INSERT IGNORE INTO strategy_return_variant_types (entry_type,entry_direction,exit_type) VALUES (%s,%s,%s)",
                    variants,
                )
        connection.commit()
    finally:
        with connection.cursor() as cursor:
            cursor.execute("SELECT RELEASE_LOCK(%s)", (seed_lock,))
        connection.commit()

def ensure_strategy_return_profile(
    connection: Any, entry_id: str, entry_label: str, exit_id: str, exit_label: str,
    exit_confirmation_mode: str = "immediate", exit_confirmation_snapshots: int = 0,
) -> int:
    """Return the compact profile ID for a complete entry/exit configuration."""
    parts = entry_id.split(":")
    if parts[0] == "immediate":
        entry_type, direction, threshold, window = "immediate", parts[1], 0, 0
    else:
        # IDs for the combined direction are `entry:<window>:<threshold>`;
        # directional IDs are `entry:<long|short>:<window>:<threshold>`.
        if parts[0] == "entry" and parts[1] in {"long", "short"}:
            direction, offset = parts[1], 2
        else:
            direction, offset = "both", 1
        window, threshold = int(parts[offset]), int(parts[offset + 1])
        suffix = parts[offset + 2:]
        mode = "within" if "within" in suffix else "exact"
        signal_direction = "reverse" if "reverse" in suffix else "follow"
        entry_type = f"momentum_{mode}_{signal_direction}"
    exit_parts = exit_id.split(":")
    exit_type = exit_parts[0] if exit_parts[0] in {"fixed", "loss", "profit", "none"} else "none"
    tp = sl = 0
    if exit_type == "fixed" and len(exit_parts) >= 3:
        # Fixed profiles are stored in basis points; table values use tenths of a percent.
        tp, sl = int(exit_parts[1]) // 10, int(exit_parts[2]) // 10
    elif exit_type == "loss" and len(exit_parts) > 1:
        sl = int(exit_parts[1])
    elif exit_type == "profit" and len(exit_parts) > 1:
        tp = int(exit_parts[1])
    with connection.cursor() as cursor:
        cursor.execute(
            "SELECT variant_type_id FROM strategy_return_variant_types WHERE entry_type=%s AND entry_direction=%s AND exit_type=%s",
            (entry_type, direction, exit_type),
        )
        variant_id = cursor.fetchone()
        if not variant_id:
            raise RuntimeError(f"Unsupported saved strategy type: {entry_type}/{direction}/{exit_type}")
    for attempt in range(5):
        try:
            with connection.cursor() as cursor:
                cursor.execute(
                    "INSERT INTO strategy_return_profiles (variant_type_id,entry_id,entry_label,exit_id,exit_label,"
                    "entry_threshold_tenths_percent,entry_window_snapshots,take_profit_tenths_percent,stop_loss_tenths_percent,"
                    "exit_confirmation_mode,exit_confirmation_snapshots) "
                    "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) "
                    "ON DUPLICATE KEY UPDATE strategy_id=LAST_INSERT_ID(strategy_id)",
                    (variant_id[0], entry_id, entry_label, exit_id, exit_label, threshold, window, tp, sl,
                     exit_confirmation_mode, exit_confirmation_snapshots),
                )
                profile_id = cursor.lastrowid
                if not profile_id:
                    cursor.execute(
                        "SELECT strategy_id FROM strategy_return_profiles WHERE entry_id=%s AND exit_id=%s "
                        "AND exit_confirmation_mode=%s AND exit_confirmation_snapshots=%s",
                        (entry_id, exit_id, exit_confirmation_mode, exit_confirmation_snapshots),
                    )
                    profile = cursor.fetchone()
                    if not profile:
                        raise RuntimeError("Strategy return profile was not persisted")
                    profile_id = profile[0]
            # Release profile index locks before the worker stages its next
            # large return-point batch; concurrent workers retry transient
            # deadlocks on this idempotent upsert.
            connection.commit()
            return int(profile_id)
        except Exception as error:
            connection.rollback()
            mysql_error_code = error.args[0] if getattr(error, "args", ()) else None
            if mysql_error_code not in (1205, 1213) or attempt == 4:
                raise
            time.sleep(0.05 * (attempt + 1))
    raise RuntimeError("Unable to persist strategy return profile")


def flush_strategy_return_points(connection: Any, rows: list[tuple[Any, ...]]) -> int:
    """Insert a batch, compounding closes that share a minute before first write."""
    if not rows:
        return 0
    grouped: dict[tuple[str, int, Any], tuple[float, int]] = {}
    for symbol, strategy_id, point_minute, return_percent, trade_count in rows:
        key = (str(symbol), int(strategy_id), point_minute)
        percent, count = grouped.get(key, (0.0, 0))
        grouped[key] = ((1.0 + percent / 100.0) * (1.0 + float(return_percent) / 100.0) - 1.0) * 100.0, count + int(trade_count)
    values = [(symbol, strategy_id, minute, f"{percent:.10f}", min(count, 65535))
              for (symbol, strategy_id, minute), (percent, count) in grouped.items()]
    with connection.cursor() as cursor:
        cursor.executemany(
            "INSERT IGNORE INTO strategy_return_points (symbol,strategy_id,point_minute,return_percent,trade_count) "
            "VALUES (%s,%s,%s,%s,%s)", values,
        )
    connection.commit()
    return len(values)


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
