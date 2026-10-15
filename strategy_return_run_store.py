"""Keep candidate results and trade events isolated by exact simulation run."""
import json
import re
import sqlite3
from datetime import datetime, timezone
from pathlib import Path


class ReturnRunStore:
    def __init__(self, cache_dir, run_key, symbol, context=None):
        if not re.fullmatch(r'[a-f0-9]{64}', run_key) or not re.fullmatch(r'[A-Z0-9]{2,40}', symbol):
            raise ValueError('Invalid return run identity')
        directory = Path(cache_dir) / 'return-runs' / run_key
        directory.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.db = sqlite3.connect(directory / (symbol + '.sqlite'), timeout=120)
        self.db.execute('PRAGMA journal_mode=WAL')
        self.db.execute('PRAGMA synchronous=NORMAL')
        self.db.execute('CREATE TABLE IF NOT EXISTS metadata (name TEXT PRIMARY KEY,value TEXT NOT NULL)')
        if context is not None:
            self.db.execute('INSERT OR REPLACE INTO metadata VALUES (?,?)',('context',json.dumps(context,sort_keys=True)))
            self.db.commit()
        self.db.execute('''CREATE TABLE IF NOT EXISTS totals (
            strategy_id INTEGER PRIMARY KEY, entry_id TEXT NOT NULL, entry_label TEXT NOT NULL,
            exit_id TEXT NOT NULL, exit_label TEXT NOT NULL, cumulative_pnl REAL,
            closed_trade_count INTEGER NOT NULL, points TEXT NOT NULL,
            UNIQUE(entry_id,exit_id))''')
        self.db.execute('CREATE INDEX IF NOT EXISTS totals_entry ON totals(entry_id)')
        self.pending = 0

    def record(self, entry_id, entry_label, exit_id, exit_label, quotes, events, balance, pnl):
        points = []
        closed = 0
        for entry, exit, net, before, provisional in events:
            if entry < 0 or exit < 0 or before <= 0:
                continue
            if not provisional:
                closed += 1
            exit_time = datetime.fromtimestamp(float(quotes.timestamps[exit]), timezone.utc)
            points.append({
                'trade_index': len(points) + 1, 'entry_at_utc': datetime.fromtimestamp(float(quotes.timestamps[entry]), timezone.utc).isoformat(),
                'exit_at_utc': exit_time.isoformat(), 'point_type': 'provisional' if provisional else 'closed',
                'return_percent': float(net) / float(before) * 100,
                'cumulative_return_percent': (float(before) + float(net) - balance) / balance * 100,
                'closed_trade_count': closed,
            })
        self.db.execute('''INSERT INTO totals(entry_id,entry_label,exit_id,exit_label,cumulative_pnl,closed_trade_count,points)
            VALUES(?,?,?,?,?,?,?) ON CONFLICT(entry_id,exit_id) DO UPDATE SET
            entry_label=excluded.entry_label,exit_label=excluded.exit_label,cumulative_pnl=excluded.cumulative_pnl,
            closed_trade_count=excluded.closed_trade_count,points=excluded.points''',
            (entry_id,entry_label,exit_id,exit_label,float(pnl)/balance*100,closed,json.dumps(points,separators=(',',':'))))

        self.pending += 1
        if self.pending >= 64:
            self.db.commit()
            self.pending = 0

    def close(self):
        self.db.commit()
        self.db.close()
