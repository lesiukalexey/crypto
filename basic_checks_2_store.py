"""Small durable SQLite store for Basic Checks 2 runs and candidate results."""

from __future__ import annotations

import json
import sqlite3
from pathlib import Path
from typing import Any


class BasicChecks2Store:
    def __init__(self, path: Path) -> None:
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.db = sqlite3.connect(path, timeout=30)
        self.db.row_factory = sqlite3.Row
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("PRAGMA synchronous=NORMAL")
        self.db.executescript("""
            CREATE TABLE IF NOT EXISTS run_meta (name TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS candidates (
                id INTEGER PRIMARY KEY,
                symbol TEXT NOT NULL,
                signal_mode TEXT NOT NULL,
                direction_mode TEXT NOT NULL,
                exit_mode TEXT NOT NULL,
                entry_id TEXT NOT NULL,
                exit_id TEXT NOT NULL,
                entry_label TEXT NOT NULL,
                exit_label TEXT NOT NULL,
                worst_percent REAL NOT NULL,
                sum_percent REAL NOT NULL,
                total_percent REAL NOT NULL,
                closed_trades INTEGER NOT NULL,
                periods_json TEXT NOT NULL,
                parameters_json TEXT NOT NULL,
                accepted INTEGER NOT NULL,
                UNIQUE(symbol,signal_mode,direction_mode,exit_mode,entry_id,exit_id)
            );
            CREATE INDEX IF NOT EXISTS idx_b2_candidates_rank
              ON candidates(symbol,signal_mode,direction_mode,exit_mode,accepted,worst_percent DESC);
            CREATE INDEX IF NOT EXISTS idx_b2_candidates_sum
              ON candidates(symbol,signal_mode,direction_mode,exit_mode,accepted,sum_percent DESC);
        """)
        self.db.commit()

    def set_meta(self, name: str, value: Any) -> None:
        encoded = value if isinstance(value, str) else json.dumps(value, ensure_ascii=False, separators=(",", ":"))
        self.db.execute("INSERT INTO run_meta(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value", (name, encoded))
        self.db.commit()

    def add_candidate(self, row: dict[str, Any]) -> None:
        self.db.execute("""INSERT OR REPLACE INTO candidates(
          symbol,signal_mode,direction_mode,exit_mode,entry_id,exit_id,entry_label,exit_label,
          worst_percent,sum_percent,total_percent,closed_trades,periods_json,parameters_json,accepted
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""", (
            row["symbol"], row["signal_mode"], row["direction_mode"], row["exit_mode"],
            row["entry_id"], row["exit_id"], row["entry_label"], row["exit_label"],
            row["worst_percent"], row["sum_percent"], row["total_percent"], row["closed_trades"],
            json.dumps(row["periods"], ensure_ascii=False, separators=(",", ":")),
            json.dumps(row["parameters"], ensure_ascii=False, separators=(",", ":")),
            int(row["accepted"]),
        ))

    def flush(self) -> None:
        self.db.commit()

    def close(self) -> None:
        self.db.commit()
        self.db.close()
