"""Persist closed trades and daily totals with both interval boundaries."""
from __future__ import annotations
from typing import Any


def ensure_strategy_return_summaries(connection: Any) -> None:
    lock_name = 'bitget_strategy_return_daily_schema_v2'
    with connection.cursor() as cursor:
        cursor.execute('SELECT GET_LOCK(%s, 30)', (lock_name,))
        if cursor.fetchone()[0] != 1:
            raise RuntimeError('Timed out preparing daily return summaries')
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT CONVERT_TZ('2026-01-01 00:00:00','+00:00','Europe/Kyiv')")
            if cursor.fetchone()[0] is None:
                raise RuntimeError('MySQL timezone tables must include Europe/Kyiv for daily return summaries')
            cursor.execute('''CREATE TABLE IF NOT EXISTS strategy_return_closed_trades (
                symbol VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                strategy_id INT UNSIGNED NOT NULL,
                entry_at_utc DATETIME(3) NOT NULL,
                exit_at_utc DATETIME(3) NOT NULL,
                return_percent DECIMAL(20,10) NOT NULL,
                trade_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY(symbol,strategy_id,entry_at_utc,exit_at_utc),
                KEY idx_return_closed_exit(symbol,exit_at_utc,strategy_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin''')
            cursor.execute('''CREATE TABLE IF NOT EXISTS strategy_return_daily_totals (
                symbol VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                strategy_id INT UNSIGNED NOT NULL,
                entry_day DATE NOT NULL,
                exit_day DATE NOT NULL,
                log_factor_sum DOUBLE NOT NULL,
                valid_factor_count BIGINT UNSIGNED NOT NULL,
                trade_count BIGINT UNSIGNED NOT NULL,
                point_count BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY(symbol,strategy_id,entry_day,exit_day),
                KEY idx_return_daily_days (symbol,exit_day,entry_day,strategy_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin''')
            cursor.execute("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='strategy_return_daily_insert_v2'")
            if cursor.fetchone() is None:
                cursor.execute('''CREATE TRIGGER strategy_return_daily_insert_v2 AFTER INSERT ON strategy_return_closed_trades
                    FOR EACH ROW INSERT INTO strategy_return_daily_totals
                    (symbol,strategy_id,entry_day,exit_day,log_factor_sum,valid_factor_count,trade_count,point_count)
                    VALUES (NEW.symbol,NEW.strategy_id,
                        DATE(CONVERT_TZ(NEW.entry_at_utc,'+00:00','Europe/Kyiv')),
                        DATE(CONVERT_TZ(NEW.exit_at_utc,'+00:00','Europe/Kyiv')),
                        COALESCE(LN(1+NEW.return_percent/100),0),IF(1+NEW.return_percent/100>0,1,0),NEW.trade_count,1)
                    ON DUPLICATE KEY UPDATE log_factor_sum=log_factor_sum+VALUES(log_factor_sum),
                        valid_factor_count=valid_factor_count+VALUES(valid_factor_count),
                        trade_count=trade_count+VALUES(trade_count),point_count=point_count+1''')
        connection.commit()
    finally:
        with connection.cursor() as cursor:
            cursor.execute('SELECT RELEASE_LOCK(%s)', (lock_name,))
        connection.commit()
