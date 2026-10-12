"""Restart-safe paper trading over public quotes only."""

from __future__ import annotations

import json
import re
import sqlite3
import time
from datetime import datetime
from decimal import Decimal
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

from .contracts import Quote, release_digest, validate_strategy_release

KYIV = ZoneInfo("Europe/Kyiv")

class SQLiteStateStore:
    """Small local event store; database path is always supplied externally."""

    def __init__(self, path: str | Path) -> None:
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(self.path, timeout=10)
        self.db.row_factory = sqlite3.Row
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("PRAGMA synchronous=FULL")
        self.db.executescript("""
          CREATE TABLE IF NOT EXISTS releases(digest TEXT PRIMARY KEY, payload TEXT NOT NULL, activated_at_ms INTEGER NOT NULL);
          CREATE TABLE IF NOT EXISTS runtime_meta(key TEXT PRIMARY KEY, value TEXT NOT NULL);
          CREATE TABLE IF NOT EXISTS quotes(symbol TEXT NOT NULL, ts INTEGER NOT NULL, bid TEXT NOT NULL, ask TEXT NOT NULL, last TEXT NOT NULL, PRIMARY KEY(symbol,ts));
          CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY, ts INTEGER NOT NULL, type TEXT NOT NULL, symbol TEXT, payload TEXT NOT NULL);
          CREATE TABLE IF NOT EXISTS positions(strategy_id TEXT NOT NULL, symbol TEXT NOT NULL, leg TEXT NOT NULL DEFAULT 'single', side TEXT NOT NULL, quantity TEXT NOT NULL, entry_price TEXT NOT NULL, entry_fee TEXT NOT NULL, entry_ts INTEGER NOT NULL, pending_exit TEXT, pending_count INTEGER NOT NULL DEFAULT 0, release_digest TEXT NOT NULL DEFAULT '', PRIMARY KEY(strategy_id,symbol,leg));
          CREATE TABLE IF NOT EXISTS trades(id INTEGER PRIMARY KEY, symbol TEXT NOT NULL, side TEXT NOT NULL, quantity TEXT NOT NULL, entry_price TEXT NOT NULL, exit_price TEXT NOT NULL, entry_ts INTEGER NOT NULL, exit_ts INTEGER NOT NULL, pnl TEXT NOT NULL, release_digest TEXT NOT NULL DEFAULT '', strategy_id TEXT NOT NULL DEFAULT 'legacy', leg TEXT NOT NULL DEFAULT 'single');
          CREATE TABLE IF NOT EXISTS account(id INTEGER PRIMARY KEY CHECK(id=1), balance TEXT NOT NULL, realized_pnl TEXT NOT NULL, day TEXT NOT NULL, daily_pnl TEXT NOT NULL, initial_balance TEXT NOT NULL);
          CREATE TABLE IF NOT EXISTS portfolio_strategies(strategy_id TEXT PRIMARY KEY, kind TEXT NOT NULL, digest TEXT NOT NULL, payload TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, added_at_ms INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '');
          CREATE TABLE IF NOT EXISTS portfolio_removed(strategy_id TEXT PRIMARY KEY, removed_at_ms INTEGER NOT NULL);
          CREATE TABLE IF NOT EXISTS strategy_runs(id INTEGER PRIMARY KEY, strategy_id TEXT NOT NULL, strategy_name TEXT NOT NULL, kind TEXT NOT NULL, started_at_ms INTEGER NOT NULL, ended_at_ms INTEGER, status TEXT NOT NULL, stop_reason TEXT, allocation_usdt TEXT NOT NULL, peak_equity_usdt TEXT NOT NULL, last_equity_usdt TEXT NOT NULL, ending_equity_usdt TEXT, minimum_equity_percent TEXT NOT NULL, starting_trade_id INTEGER NOT NULL DEFAULT 0);
          CREATE INDEX IF NOT EXISTS idx_strategy_runs_active ON strategy_runs(strategy_id,status,started_at_ms);
          CREATE TABLE IF NOT EXISTS exchange_orders(client_order_id TEXT PRIMARY KEY, mode TEXT NOT NULL, strategy_id TEXT NOT NULL, symbol TEXT NOT NULL, leg TEXT NOT NULL, intent TEXT NOT NULL, side TEXT NOT NULL, requested_qty TEXT NOT NULL, status TEXT NOT NULL, order_id TEXT, reason TEXT NOT NULL, digest TEXT NOT NULL, created_at_ms INTEGER NOT NULL, updated_at_ms INTEGER NOT NULL);
          CREATE TABLE IF NOT EXISTS exchange_fills(trade_id TEXT PRIMARY KEY, client_order_id TEXT NOT NULL, quantity TEXT NOT NULL, price TEXT NOT NULL, fee TEXT NOT NULL, timestamp_ms INTEGER NOT NULL);
        """)
        position_columns = {row[1] for row in self.db.execute("PRAGMA table_info(positions)")}
        if "strategy_id" not in position_columns:
            self.db.execute("ALTER TABLE positions RENAME TO positions_single_strategy")
            self.db.execute("CREATE TABLE positions(strategy_id TEXT NOT NULL, symbol TEXT NOT NULL, leg TEXT NOT NULL DEFAULT 'single', side TEXT NOT NULL, quantity TEXT NOT NULL, entry_price TEXT NOT NULL, entry_fee TEXT NOT NULL, entry_ts INTEGER NOT NULL, pending_exit TEXT, pending_count INTEGER NOT NULL DEFAULT 0, release_digest TEXT NOT NULL DEFAULT '', PRIMARY KEY(strategy_id,symbol,leg))")
            active = self.db.execute("SELECT value FROM runtime_meta WHERE key='active_release_digest'").fetchone()
            strategy_id = str(active[0]) if active else "legacy"
            self.db.execute("INSERT INTO positions(strategy_id,symbol,leg,side,quantity,entry_price,entry_fee,entry_ts,pending_exit,pending_count,release_digest) SELECT ?,symbol,'single',side,quantity,entry_price,entry_fee,entry_ts,pending_exit,pending_count,? FROM positions_single_strategy", (strategy_id, strategy_id if strategy_id != "legacy" else ""))
            self.db.execute("DROP TABLE positions_single_strategy")
        columns = {row[1] for row in self.db.execute("PRAGMA table_info(trades)")}
        if "release_digest" not in columns:
            self.db.execute("ALTER TABLE trades ADD COLUMN release_digest TEXT NOT NULL DEFAULT ''")
        if "strategy_id" not in columns:
            self.db.execute("ALTER TABLE trades ADD COLUMN strategy_id TEXT NOT NULL DEFAULT 'legacy'")
        if "leg" not in columns:
            self.db.execute("ALTER TABLE trades ADD COLUMN leg TEXT NOT NULL DEFAULT 'single'")
        account_columns = {row[1] for row in self.db.execute("PRAGMA table_info(account)")}
        if "initial_balance" not in account_columns:
            self.db.execute("ALTER TABLE account ADD COLUMN initial_balance TEXT NOT NULL DEFAULT '0'")
            self.db.execute("UPDATE account SET initial_balance=balance WHERE initial_balance='0'")
        portfolio_columns = {row[1] for row in self.db.execute("PRAGMA table_info(portfolio_strategies)")}
        if "name" not in portfolio_columns:
            self.db.execute("ALTER TABLE portfolio_strategies ADD COLUMN name TEXT NOT NULL DEFAULT ''")
        self.db.commit()

    @classmethod
    def read_only(cls, path: str | Path) -> "SQLiteStateStore":
        """Open an existing executor database without schema or journal writes."""
        instance = cls.__new__(cls)
        instance.path = Path(path)
        instance.db = sqlite3.connect(f"{instance.path.resolve().as_uri()}?mode=ro", uri=True, timeout=5)
        instance.db.row_factory = sqlite3.Row
        instance.db.execute("PRAGMA query_only=ON")
        return instance

    def activate(self, release: dict[str, Any]) -> str:
        payload = validate_strategy_release(release)
        digest = release_digest(payload)
        active = self.db.execute("SELECT value AS digest FROM runtime_meta WHERE key='active_release_digest'").fetchone()
        if active is None:
            active = self.db.execute("SELECT digest FROM releases ORDER BY activated_at_ms DESC LIMIT 1").fetchone()
        if active and active["digest"] != digest and self.db.execute("SELECT COUNT(*) FROM positions").fetchone()[0]:
            raise ValueError("Cannot switch strategy release while paper positions are open")
        if active and active["digest"] != digest:
            self.db.execute("UPDATE account SET balance=?,initial_balance=?,realized_pnl='0',daily_pnl='0',day=? WHERE id=1", (payload["execution"]["starting_balance_usdt"], payload["execution"]["starting_balance_usdt"], datetime.now(KYIV).date().isoformat()))
        self.db.execute("INSERT INTO releases VALUES(?,?,?) ON CONFLICT(digest) DO UPDATE SET activated_at_ms=excluded.activated_at_ms", (digest, json.dumps(payload, sort_keys=True), int(time.time() * 1000)))
        self.db.execute("INSERT OR IGNORE INTO account(id,balance,realized_pnl,day,daily_pnl,initial_balance) VALUES(1,?,?,?,?,?)", (payload["execution"]["starting_balance_usdt"], "0", datetime.now(KYIV).date().isoformat(), "0", payload["execution"]["starting_balance_usdt"]))
        self.db.execute("INSERT INTO runtime_meta VALUES('active_release_digest',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (digest,))
        self.db.commit()
        return digest

    def event(self, kind: str, payload: dict[str, Any], symbol: str | None = None, ts: int | None = None) -> None:
        self.db.execute("INSERT INTO events(ts,type,symbol,payload) VALUES(?,?,?,?)", (ts or int(time.time() * 1000), kind, symbol, json.dumps(payload, sort_keys=True)))
        self.db.commit()

    def event_error(self, kind: str, payload: dict[str, Any], symbol: str | None = None, ts: int | None = None) -> None:
        """Keep one recent row per recurring error signature and count repeats."""
        timestamp = ts or int(time.time() * 1000)
        identity_fields = ("error_code", "code", "exception_type", "message", "endpoint", "method", "phase", "intent", "category")
        identity = {key: payload[key] for key in identity_fields if key in payload}
        if not identity:
            identity = payload
        for row in self.db.execute(
            "SELECT id, ts, payload FROM events WHERE type=? AND symbol IS ? AND ts>=? ORDER BY id DESC LIMIT 100",
            (kind, symbol, timestamp - 60_000),
        ):
            previous = json.loads(row["payload"])
            previous_identity = {key: previous[key] for key in identity_fields if key in previous}
            if not previous_identity:
                previous_identity = {key: value for key, value in previous.items() if key not in {"first_seen_ms", "last_seen_ms", "repeat_count"}}
            if previous_identity != identity:
                continue
            payload_with_count = dict(payload)
            payload_with_count.update({
                "first_seen_ms": previous.get("first_seen_ms", row["ts"]),
                "last_seen_ms": timestamp,
                "repeat_count": int(previous.get("repeat_count", 1)) + 1,
            })
            self.db.execute("UPDATE events SET ts=?,payload=? WHERE id=?", (timestamp, json.dumps(payload_with_count, sort_keys=True), row["id"]))
            self.db.commit()
            return
        payload_with_count = dict(payload)
        payload_with_count.update({"first_seen_ms": timestamp, "last_seen_ms": timestamp, "repeat_count": 1})
        self.db.execute("INSERT INTO events(ts,type,symbol,payload) VALUES(?,?,?,?)", (timestamp, kind, symbol, json.dumps(payload_with_count, sort_keys=True)))
        self.db.commit()

    def quote(self, quote: Quote) -> bool:
        cur = self.db.execute("INSERT OR IGNORE INTO quotes VALUES(?,?,?,?,?)", (quote.symbol, quote.timestamp_ms, str(quote.bid), str(quote.ask), str(quote.last)))
        self.db.commit()
        return cur.rowcount == 1

    def latest_quote_timestamp(self, symbol: str) -> int | None:
        row = self.db.execute("SELECT MAX(ts) FROM quotes WHERE symbol=?", (symbol,)).fetchone()
        return row[0]

    def prior_quotes(self, symbol: str, before: int, limit: int) -> list[Quote]:
        rows = self.db.execute("SELECT * FROM quotes WHERE symbol=? AND ts<? ORDER BY ts DESC LIMIT ?", (symbol, before, limit)).fetchall()
        return [Quote(row["symbol"], Decimal(row["bid"]), Decimal(row["ask"]), Decimal(row["last"]), row["ts"]) for row in reversed(rows)]

    def position(self, symbol: str, strategy_id: str | None = None, leg: str = "single") -> sqlite3.Row | None:
        if strategy_id is None:
            return self.db.execute("SELECT * FROM positions WHERE symbol=? ORDER BY entry_ts LIMIT 1", (symbol,)).fetchone()
        return self.db.execute("SELECT * FROM positions WHERE symbol=? AND strategy_id=? AND leg=?", (symbol, strategy_id, leg)).fetchone()

    def add_portfolio_strategy(self, release: dict[str, Any], *, kind: str = "strategy", name: str = "") -> str:
        payload = PaperExecutor.validate_release(release)
        digest = release_digest(payload)
        serialized = json.dumps(payload, sort_keys=True)
        row = self.db.execute("SELECT initial_balance FROM account WHERE id=1").fetchone()
        if row is None:
            starting = payload["execution"]["starting_balance_usdt"]
            self.db.execute("INSERT INTO account(id,balance,realized_pnl,day,daily_pnl,initial_balance) VALUES(1,?,'0',?,'0',?)", (starting, datetime.now(KYIV).date().isoformat(), starting))
        self.db.execute("INSERT INTO releases(digest,payload,activated_at_ms) VALUES(?,?,?) ON CONFLICT(digest) DO NOTHING", (digest, serialized, int(time.time() * 1000)))
        self.db.execute("INSERT INTO portfolio_strategies(strategy_id,kind,digest,payload,enabled,added_at_ms,name) VALUES(?,?,?,?,1,?,?) ON CONFLICT(strategy_id) DO UPDATE SET enabled=1,name=excluded.name", (digest, kind, digest, serialized, int(time.time() * 1000), name or payload["release_id"]))
        self.db.execute("DELETE FROM portfolio_removed WHERE strategy_id=?", (digest,))
        # Each strategy keeps the budget configured in its own release. The shared
        # account is the sum of those budgets, with PnL still booked once globally.
        self._sync_portfolio_budget()
        self.db.commit()
        return digest

    def remove_portfolio_strategy(self, strategy_id: str) -> None:
        row = self.db.execute("SELECT strategy_id FROM portfolio_strategies WHERE strategy_id=?", (strategy_id,)).fetchone()
        if row is None:
            raise ValueError("Стратегия не найдена в портфеле")
        if self.db.execute("SELECT 1 FROM positions WHERE strategy_id=? LIMIT 1", (strategy_id,)).fetchone():
            raise ValueError("Нельзя удалить стратегию с открытыми позициями")
        if self.db.execute("SELECT 1 FROM exchange_orders WHERE strategy_id=? AND status IN ('submitting','uncertain','submitted','partial') LIMIT 1", (strategy_id,)).fetchone():
            raise ValueError("Сначала дождитесь сверки незавершённых заявок")
        with self.db:
            now_ms = int(time.time() * 1000)
            self.db.execute("UPDATE strategy_runs SET status='stopped',ended_at_ms=COALESCE(ended_at_ms,?),ending_equity_usdt=COALESCE(ending_equity_usdt,last_equity_usdt),stop_reason=COALESCE(stop_reason,'removed_from_portfolio') WHERE strategy_id=? AND status IN ('running','stopping')", (now_ms, strategy_id))
            self.db.execute("DELETE FROM portfolio_strategies WHERE strategy_id=?", (strategy_id,))
            self.db.execute("INSERT INTO portfolio_removed(strategy_id,removed_at_ms) VALUES(?,?) ON CONFLICT(strategy_id) DO UPDATE SET removed_at_ms=excluded.removed_at_ms", (strategy_id, now_ms))
            self._sync_portfolio_budget()

    def reject_initial_strategy_entry(self, strategy_id: str, *, now_ms: int | None = None) -> None:
        """Release an entry that was definitely rejected, closing any accepted hedge leg first."""
        now_ms = now_ms or int(time.time() * 1000)
        row = self.db.execute("SELECT strategy_id FROM portfolio_strategies WHERE strategy_id=?", (strategy_id,)).fetchone()
        if row is None:
            return
        if self.db.execute("SELECT 1 FROM trades WHERE strategy_id=? LIMIT 1", (strategy_id,)).fetchone():
            return
        if self.db.execute("SELECT 1 FROM exchange_orders WHERE strategy_id=? AND status IN ('submitting','uncertain','submitted','partial') LIMIT 1", (strategy_id,)).fetchone():
            return
        run = self.active_strategy_run(strategy_id)
        with self.db:
            self.db.execute("UPDATE portfolio_strategies SET enabled=0 WHERE strategy_id=?", (strategy_id,))
            if run is not None and run["status"] == "running":
                self.db.execute("UPDATE strategy_runs SET status='stopping',stop_reason='initial_order_rejected' WHERE id=?", (run["id"],))
                self.db.execute("INSERT INTO events(ts,type,payload) VALUES(?,?,?)", (now_ms, "strategy_entry_failed", json.dumps({"strategy_id": strategy_id, "cleanup": "close_exposure_then_remove"}, sort_keys=True)))
        if not self.db.execute("SELECT 1 FROM positions WHERE strategy_id=? LIMIT 1", (strategy_id,)).fetchone():
            if run is not None and run["status"] == "running":
                self.finish_strategy_run(strategy_id, now_ms=now_ms)
            else:
                with self.db:
                    self.db.execute("DELETE FROM portfolio_strategies WHERE strategy_id=?", (strategy_id,))
                    self.db.execute("INSERT INTO portfolio_removed(strategy_id,removed_at_ms) VALUES(?,?) ON CONFLICT(strategy_id) DO UPDATE SET removed_at_ms=excluded.removed_at_ms", (strategy_id, now_ms))
                    self._sync_portfolio_budget()

    def _sync_portfolio_budget(self) -> None:
        account = self.account()
        if account is None:
            return
        allocations = [Decimal(json.loads(row[0])["execution"]["starting_balance_usdt"]) for row in self.db.execute("SELECT payload FROM portfolio_strategies")]
        portfolio_budget = sum(allocations, Decimal(0))
        previous_budget = Decimal(account["initial_balance"])
        if portfolio_budget != previous_budget:
            self.db.execute("UPDATE account SET balance=?,initial_balance=? WHERE id=1", (str(Decimal(account["balance"]) + portfolio_budget - previous_budget), str(portfolio_budget)))

    def active_strategy_run(self, strategy_id: str) -> sqlite3.Row | None:
        return self.db.execute(
            "SELECT * FROM strategy_runs WHERE strategy_id=? AND status IN ('running','stopping') ORDER BY id DESC LIMIT 1",
            (strategy_id,),
        ).fetchone()

    def begin_strategy_runs(self, minimum_equity_percent: Decimal, now_ms: int | None = None) -> None:
        now_ms = now_ms or int(time.time() * 1000)
        self._sync_portfolio_budget()
        rows = self.db.execute("SELECT * FROM portfolio_strategies WHERE enabled=1 ORDER BY added_at_ms").fetchall()
        pending = [row for row in rows if self.active_strategy_run(row["strategy_id"]) is None]
        if not pending:
            return
        account = self.account()
        if account is None:
            return
        last_trade_id = int(self.db.execute("SELECT COALESCE(MAX(id),0) FROM trades").fetchone()[0])
        with self.db:
            for row in pending:
                payload = json.loads(row["payload"])
                allocation = Decimal(payload["execution"]["starting_balance_usdt"])
                if allocation <= 0:
                    continue
                name = row["name"] or payload.get("release_id", row["strategy_id"])
                self.db.execute(
                    "INSERT INTO strategy_runs(strategy_id,strategy_name,kind,started_at_ms,status,allocation_usdt,peak_equity_usdt,last_equity_usdt,minimum_equity_percent,starting_trade_id) VALUES(?,?,?,?,?,?,?,?,?,?)",
                    (row["strategy_id"], name, row["kind"], now_ms, "running", str(allocation), str(allocation), str(allocation), str(minimum_equity_percent), last_trade_id),
                )
                self.db.execute("INSERT INTO events(ts,type,payload) VALUES(?,?,?)", (now_ms, "strategy_run_started", json.dumps({"strategy_id": row["strategy_id"], "name": name, "allocation_usdt": str(allocation), "minimum_equity_percent": str(minimum_equity_percent)}, sort_keys=True)))

    def strategy_equity(self, strategy_id: str, payload: dict[str, Any], *, now_ms: int, max_quote_age_ms: int) -> Decimal | None:
        run = self.active_strategy_run(strategy_id)
        if run is None:
            return None
        realized = Decimal(0)
        for row in self.db.execute("SELECT pnl FROM trades WHERE strategy_id=? AND id>?", (strategy_id, run["starting_trade_id"])):
            realized += Decimal(row[0])
        equity = Decimal(run["allocation_usdt"]) + realized
        execution = payload["execution"]
        fee_rate = Decimal(execution["taker_fee_rate"])
        slip = Decimal(execution["slippage_bps"]) / 10000
        positions = self.db.execute("SELECT * FROM positions WHERE strategy_id=?", (strategy_id,)).fetchall()
        for pos in positions:
            quote = self.db.execute("SELECT * FROM quotes WHERE symbol=? ORDER BY ts DESC LIMIT 1", (pos["symbol"],)).fetchone()
            if quote is None or now_ms - int(quote["ts"]) > max_quote_age_ms:
                return None
            qty, entry = Decimal(pos["quantity"]), Decimal(pos["entry_price"])
            market = Decimal(quote["ask"] if pos["side"] == "short" else quote["bid"])
            exit_price = market * (1 + slip) if pos["side"] == "short" else market * (1 - slip)
            equity += qty * (entry - exit_price if pos["side"] == "short" else exit_price - entry)
            equity -= Decimal(pos["entry_fee"]) + qty * exit_price * fee_rate
        return equity

    def update_strategy_equity(self, strategy_id: str, equity: Decimal, minimum_equity_percent: Decimal, now_ms: int) -> bool:
        run = self.active_strategy_run(strategy_id)
        if run is None or run["status"] != "running":
            return False
        peak = max(Decimal(run["peak_equity_usdt"]), equity)
        triggered = equity < peak and equity <= peak * minimum_equity_percent / Decimal(100)
        with self.db:
            self.db.execute("UPDATE strategy_runs SET peak_equity_usdt=?,last_equity_usdt=?,minimum_equity_percent=? WHERE id=?", (str(peak), str(equity), str(minimum_equity_percent), run["id"]))
            if triggered:
                self.db.execute("UPDATE strategy_runs SET status='stopping',stop_reason='loss_threshold' WHERE id=? AND status='running'", (run["id"],))
                self.db.execute("INSERT INTO events(ts,type,payload) VALUES(?,?,?)", (now_ms, "strategy_loss_stop_requested", json.dumps({"strategy_id": strategy_id, "equity_usdt": str(equity), "peak_equity_usdt": str(peak), "minimum_equity_percent": str(minimum_equity_percent)}, sort_keys=True)))
        return triggered

    def request_strategy_stop(self, strategy_id: str, *, now_ms: int | None = None) -> None:
        now_ms = now_ms or int(time.time() * 1000)
        run = self.active_strategy_run(strategy_id)
        if run is None:
            raise ValueError("Стратегия сейчас не запущена")
        if run["status"] == "running":
            with self.db:
                self.db.execute("UPDATE strategy_runs SET status='stopping',stop_reason='manual' WHERE id=?", (run["id"],))
                self.db.execute("INSERT INTO events(ts,type,payload) VALUES(?,?,?)", (now_ms, "strategy_manual_stop_requested", json.dumps({"strategy_id": strategy_id}, sort_keys=True)))
        if not self.db.execute("SELECT 1 FROM positions WHERE strategy_id=? LIMIT 1", (strategy_id,)).fetchone():
            self.finish_strategy_run(strategy_id, now_ms=now_ms)

    def resume_strategy(self, strategy_id: str) -> None:
        row = self.db.execute("SELECT enabled FROM portfolio_strategies WHERE strategy_id=?", (strategy_id,)).fetchone()
        if row is None:
            raise ValueError("Стратегия не найдена")
        if self.db.execute("SELECT 1 FROM positions WHERE strategy_id=? LIMIT 1", (strategy_id,)).fetchone():
            raise ValueError("Сначала дождитесь закрытия позиций стратегии")
        with self.db:
            self.db.execute("UPDATE portfolio_strategies SET enabled=1 WHERE strategy_id=?", (strategy_id,))

    def finish_strategy_run(self, strategy_id: str, *, equity: Decimal | None = None, now_ms: int | None = None) -> bool:
        run = self.active_strategy_run(strategy_id)
        if run is None or run["status"] != "stopping":
            return False
        if self.db.execute("SELECT 1 FROM positions WHERE strategy_id=? LIMIT 1", (strategy_id,)).fetchone():
            return False
        ending = equity if equity is not None else Decimal(run["last_equity_usdt"])
        now_ms = now_ms or int(time.time() * 1000)
        with self.db:
            self.db.execute("UPDATE strategy_runs SET status='stopped',ended_at_ms=?,ending_equity_usdt=?,last_equity_usdt=? WHERE id=?", (now_ms, str(ending), str(ending), run["id"]))
            self.db.execute("UPDATE portfolio_strategies SET enabled=0 WHERE strategy_id=?", (strategy_id,))
            self.db.execute("INSERT INTO events(ts,type,payload) VALUES(?,?,?)", (now_ms, "strategy_run_ended", json.dumps({"strategy_id": strategy_id, "reason": run["stop_reason"], "ending_equity_usdt": str(ending)}, sort_keys=True)))
            if run["stop_reason"] == "initial_order_rejected":
                self.db.execute("DELETE FROM portfolio_strategies WHERE strategy_id=?", (strategy_id,))
                self.db.execute("INSERT INTO portfolio_removed(strategy_id,removed_at_ms) VALUES(?,?) ON CONFLICT(strategy_id) DO UPDATE SET removed_at_ms=excluded.removed_at_ms", (strategy_id, now_ms))
                self._sync_portfolio_budget()
        return True

    def strategy_runs(self, limit: int = 100) -> list[dict[str, Any]]:
        if not self.db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='strategy_runs'").fetchone():
            return []
        rows = self.db.execute("SELECT * FROM strategy_runs ORDER BY id DESC LIMIT ?", (limit,)).fetchall()
        return [dict(row) for row in rows]

    def open_position(self, symbol: str, side: str, quantity: Decimal, price: Decimal, fee: Decimal, ts: int, strategy_id: str = "legacy", leg: str = "single", digest: str = "") -> None:
        self.db.execute("INSERT INTO positions(strategy_id,symbol,leg,side,quantity,entry_price,entry_fee,entry_ts,release_digest) VALUES(?,?,?,?,?,?,?,?,?)", (strategy_id, symbol, leg, side, str(quantity), str(price), str(fee), ts, digest))
        self.db.commit()

    def open_hedge_positions(self, positions: list[tuple[str, str, str, Decimal, Decimal, Decimal, int]], strategy_id: str, digest: str) -> None:
        with self.db:
            self.db.executemany("INSERT INTO positions(strategy_id,symbol,leg,side,quantity,entry_price,entry_fee,entry_ts,release_digest) VALUES(?,?,?,?,?,?,?,?,?)", [(strategy_id, symbol, leg, side, str(quantity), str(price), str(fee), ts, digest) for symbol, leg, side, quantity, price, fee, ts in positions])

    def close_hedge_positions(self, trades: list[tuple[sqlite3.Row, Quote, Decimal, Decimal]]) -> Decimal:
        total_pnl = sum((trade[3] for trade in trades), Decimal(0))
        with self.db:
            account = self.account()
            day = datetime.fromtimestamp(max(item[1].timestamp_ms for item in trades) / 1000, KYIV).date().isoformat()
            daily = Decimal(account["daily_pnl"]) + total_pnl if account["day"] == day else total_pnl
            for pos, quote, price, pnl in trades:
                self.db.execute("INSERT INTO trades(symbol,side,quantity,entry_price,exit_price,entry_ts,exit_ts,pnl,release_digest,strategy_id,leg) VALUES(?,?,?,?,?,?,?,?,?,?,?)", (pos["symbol"], pos["side"], pos["quantity"], pos["entry_price"], str(price), pos["entry_ts"], quote.timestamp_ms, str(pnl), pos["release_digest"], pos["strategy_id"], pos["leg"]))
                self.db.execute("DELETE FROM positions WHERE strategy_id=? AND symbol=? AND leg=?", (pos["strategy_id"], pos["symbol"], pos["leg"]))
            self.db.execute("UPDATE account SET balance=?,realized_pnl=?,daily_pnl=?,day=? WHERE id=1", (str(Decimal(account["balance"]) + total_pnl), str(Decimal(account["realized_pnl"]) + total_pnl), str(daily), day))
        return total_pnl

    def update_pending(self, symbol: str, kind: str | None, count: int, strategy_id: str = "legacy", leg: str = "single") -> None:
        self.db.execute("UPDATE positions SET pending_exit=?,pending_count=? WHERE symbol=? AND strategy_id=? AND leg=?", (kind, count, symbol, strategy_id, leg)); self.db.commit()

    def close_position(self, pos: sqlite3.Row, quote: Quote, price: Decimal, pnl: Decimal) -> None:
        digest = pos["release_digest"] if "release_digest" in pos.keys() else ""
        strategy_id = pos["strategy_id"] if "strategy_id" in pos.keys() else "legacy"
        leg = pos["leg"] if "leg" in pos.keys() else "single"
        self.db.execute("INSERT INTO trades(symbol,side,quantity,entry_price,exit_price,entry_ts,exit_ts,pnl,release_digest,strategy_id,leg) VALUES(?,?,?,?,?,?,?,?,?,?,?)", (pos["symbol"], pos["side"], pos["quantity"], pos["entry_price"], str(price), pos["entry_ts"], quote.timestamp_ms, str(pnl), digest, strategy_id, leg))
        self.db.execute("DELETE FROM positions WHERE symbol=? AND strategy_id=? AND leg=?", (pos["symbol"], strategy_id, leg))
        account = self.account()
        day = datetime.fromtimestamp(quote.timestamp_ms / 1000, KYIV).date().isoformat()
        daily = Decimal(account["daily_pnl"]) + pnl if account["day"] == day else pnl
        self.db.execute("UPDATE account SET balance=?,realized_pnl=?,daily_pnl=?,day=? WHERE id=1", (str(Decimal(account["balance"]) + pnl), str(Decimal(account["realized_pnl"]) + pnl), str(daily), day))
        self.db.commit()

    def account(self) -> sqlite3.Row:
        return self.db.execute("SELECT * FROM account WHERE id=1").fetchone()

    def health(self) -> dict[str, Any]:
        mode_row = self.db.execute("SELECT value FROM runtime_meta WHERE key='execution_mode'").fetchone()
        execution_mode = str(mode_row[0]) if mode_row and mode_row[0] in {"paper", "demo", "live"} else "paper"
        account = self.account()
        active = self.db.execute("SELECT r.payload,r.digest FROM runtime_meta m JOIN releases r ON r.digest=m.value WHERE m.key='active_release_digest'").fetchone()
        if active is None:
            active = self.db.execute("SELECT payload,digest FROM releases ORDER BY activated_at_ms DESC LIMIT 1").fetchone()
        if account is None:
            return {"mode": execution_mode, "initialized": False, "active_release_digest": active["digest"] if active else None, "open_positions": 0, "positions": [], "unrealized_pnl_usdt": "0", "trades": 0, "recent_trades": [], "recent_events": [], "latest_event": None, "strategy_runs": []}
        positions: list[dict[str, Any]] = []
        unrealized = Decimal(0)
        tables = {row[0] for row in self.db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        strategies = {row["strategy_id"]: json.loads(row["payload"]) for row in self.db.execute("SELECT strategy_id,payload FROM portfolio_strategies WHERE enabled=1")} if "portfolio_strategies" in tables else {}
        if active:
            strategies.setdefault(active["digest"], json.loads(active["payload"]))
        position_columns = {row[1] for row in self.db.execute("PRAGMA table_info(positions)")}
        position_order = "strategy_id,symbol,leg" if "strategy_id" in position_columns else "symbol"
        if strategies:
            for pos in self.db.execute(f"SELECT * FROM positions ORDER BY {position_order}"):
                strategy_id = pos["strategy_id"] if "strategy_id" in position_columns else (active["digest"] if active else "legacy")
                leg = pos["leg"] if "leg" in position_columns else "single"
                position_digest = pos["release_digest"] if "release_digest" in position_columns else ""
                strategy = strategies.get(strategy_id)
                if strategy is None:
                    row_release = self.db.execute("SELECT payload FROM releases WHERE digest=?", (position_digest,)).fetchone()
                    strategy = json.loads(row_release["payload"]) if row_release else None
                if strategy is None and active:
                    strategy = json.loads(active["payload"])
                if strategy is None:
                    positions.append({"strategy_id": strategy_id, "leg": leg, "symbol": pos["symbol"], "side": pos["side"], "quantity": pos["quantity"], "entry_price": pos["entry_price"], "entry_timestamp_ms": pos["entry_ts"], "unrealized_pnl_usdt": None, "quote_timestamp_ms": None})
                    continue
                execution = strategy["execution"]
                fee_rate, slip = Decimal(execution["taker_fee_rate"]), Decimal(execution["slippage_bps"]) / 10000
                row = self.db.execute("SELECT * FROM quotes WHERE symbol=? ORDER BY ts DESC LIMIT 1", (pos["symbol"],)).fetchone()
                pnl = None
                ts = None
                if row:
                    qty, entry = Decimal(pos["quantity"]), Decimal(pos["entry_price"])
                    market = Decimal(row["ask"] if pos["side"] == "short" else row["bid"])
                    exit_price = market * (1 + slip) if pos["side"] == "short" else market * (1 - slip)
                    pnl = qty * (entry - exit_price if pos["side"] == "short" else exit_price - entry) - Decimal(pos["entry_fee"]) - qty * exit_price * fee_rate
                    unrealized += pnl
                    ts = row["ts"]
                positions.append({"strategy_id": strategy_id, "leg": leg, "symbol": pos["symbol"], "side": pos["side"], "quantity": pos["quantity"], "entry_price": pos["entry_price"], "entry_timestamp_ms": pos["entry_ts"], "unrealized_pnl_usdt": None if pnl is None else str(pnl), "quote_timestamp_ms": ts})
        latest = self.db.execute("SELECT type,ts FROM events ORDER BY ts DESC,id DESC LIMIT 1").fetchone()
        recent_trades = [dict(row) for row in self.db.execute("SELECT * FROM trades ORDER BY id DESC LIMIT 50")]
        recent_events = [{"id": row["id"], "timestamp_ms": row["ts"], "type": row["type"], "symbol": row["symbol"], "payload": json.loads(row["payload"])} for row in self.db.execute("SELECT * FROM events ORDER BY ts DESC,id DESC LIMIT 100")]
        portfolio = [{"strategy_id": row["strategy_id"], "kind": row["kind"], "digest": row["digest"], "enabled": bool(row["enabled"]), "name": row["name"] or row["strategy_id"], "symbols": json.loads(row["payload"])["market"]["symbols"], "allocation_usdt": json.loads(row["payload"])["execution"]["starting_balance_usdt"]} for row in self.db.execute("SELECT * FROM portfolio_strategies ORDER BY added_at_ms")] if "portfolio_strategies" in tables else []
        account_columns = {row[1] for row in self.db.execute("PRAGMA table_info(account)")}
        initial_balance = account["initial_balance"] if "initial_balance" in account_columns else account["balance"]
        return {"mode": execution_mode, "initialized": True, "active_release_digest": active["digest"] if active else None, "balance_usdt": account["balance"], "initial_balance_usdt": initial_balance, "realized_pnl_usdt": account["realized_pnl"], "daily_pnl_usdt": account["daily_pnl"], "open_positions": len(positions), "positions": positions, "unrealized_pnl_usdt": str(unrealized), "trades": self.db.execute("SELECT COUNT(*) FROM trades").fetchone()[0], "recent_trades": recent_trades, "recent_events": recent_events, "latest_event": None if latest is None else dict(latest), "portfolio_strategies": portfolio, "strategy_runs": self.strategy_runs()}

    def close(self) -> None:
        self.db.close()


class PaperExecutor:
    def __init__(self, release: dict[str, Any], store: SQLiteStateStore, *, stale_after_seconds: int = 30, strategy_id: str | None = None, activate: bool = True) -> None:
        self.release = self.validate_release(release)
        self.store = store
        self.stale_after_ms = stale_after_seconds * 1000
        strategy = self.release["strategy"]
        self.entry = self._parse_entry(strategy["entry_config"], strategy["entry_signal_mode"], strategy["entry_direction_mode"])
        self.take, self.stop = self._parse_exit(strategy["exit_config"])
        self.entries_paused = False
        self.close_requested = False
        self.strategy_id = strategy_id or release_digest(self.release)
        if activate:
            self.store.activate(self.release)

    @classmethod
    def validate_release(cls, release: dict[str, Any]) -> dict[str, Any]:
        payload = validate_strategy_release(release)
        if payload["market"]["category"] != "USDT-FUTURES":
            raise ValueError("Paper v1 currently supports only linear USDT futures")
        strategy = payload["strategy"]
        if strategy["martingale_mode"] != "none":
            raise ValueError("Paper v1 supports only martingale_mode=none")
        if payload["execution"]["include_funding"]:
            raise ValueError("Paper v1 does not model funding; set execution.include_funding=false")
        cls._parse_entry(strategy["entry_config"], strategy["entry_signal_mode"], strategy["entry_direction_mode"])
        cls._parse_exit(strategy["exit_config"])
        return payload

    @staticmethod
    def _parse_entry(value: str, signal_mode: str, direction_mode: str) -> tuple[str, int, Decimal, bool, bool]:
        if value in {"immediate:long", "immediate:short"}:
            return (value, 0, Decimal(0), False, False)
        match = re.fullmatch(r"entry(?::(long|short))?:(\d+):(\d+)(?::(within))?(?::(reverse))?", value)
        if not match:
            raise ValueError(f"Unsupported entry_config for paper v1: {value}")
        direction, window, threshold, within, reverse = match.groups()
        return (direction or "both", int(window), Decimal(threshold) / 10, (within == "within") if within else signal_mode == "within", bool(reverse) if reverse else direction_mode == "reverse")

    @staticmethod
    def _parse_exit(value: str) -> tuple[Decimal | None, Decimal | None]:
        fixed = re.fullmatch(r"fixed:(\d+):(\d+)", value)
        loss = re.fullmatch(r"loss:(\d+)", value)
        profit = re.fullmatch(r"profit:(\d+)", value)
        if fixed:
            return Decimal(fixed.group(1)) / 10000, Decimal(fixed.group(2)) / 10000
        if loss:
            return None, Decimal(loss.group(1)) / 1000
        if profit:
            return Decimal(profit.group(1)) / 1000, None
        raise ValueError(f"Unsupported exit_config for paper v1: {value}")

    def on_quote(self, quote: Quote, *, now_ms: int | None = None, record_quote: bool = True) -> None:
        now_ms = now_ms or int(time.time() * 1000)
        if quote.symbol not in self.release["market"]["symbols"]:
            raise ValueError("Quote symbol is outside the release allowlist")
        if quote.timestamp_ms > now_ms + 5000 or now_ms - quote.timestamp_ms > self.stale_after_ms:
            self.store.event("stale_quote", {"quote_ts": quote.timestamp_ms, "age_ms": now_ms - quote.timestamp_ms}, quote.symbol)
            return
        latest = self.store.latest_quote_timestamp(quote.symbol)
        if latest is not None and quote.timestamp_ms < latest:
            self.store.event("out_of_order_quote", {"quote_ts": quote.timestamp_ms, "latest_ts": latest}, quote.symbol, quote.timestamp_ms)
            return
        if record_quote and not self.store.quote(quote):
            self.store.event("duplicate_quote", {"quote_ts": quote.timestamp_ms}, quote.symbol, quote.timestamp_ms)
            return
        pos = self.store.position(quote.symbol, self.strategy_id)
        run = self.store.active_strategy_run(self.strategy_id)
        if pos:
            if run is not None and run["status"] == "stopping":
                self._close_position(pos, quote, "loss_threshold" if run["stop_reason"] == "loss_threshold" else "manual_strategy_stop")
            elif self.close_requested:
                self._close_position(pos, quote, "manual_close")
            else:
                self._manage_position(pos, quote)
        else:
            if run is None or run["status"] == "running":
                self._consider_entry(quote)

    def set_controls(self, *, entries_paused: bool, close_positions: bool) -> None:
        was_paused = self.entries_paused
        self.entries_paused = entries_paused
        self.close_requested = close_positions
        if was_paused != entries_paused:
            self.store.event("entries_paused" if entries_paused else "entries_resumed", {"entries_paused": entries_paused})

    def _consider_entry(self, quote: Quote) -> None:
        if self.entries_paused or self.close_requested:
            return
        direction, window, threshold, within, reverse = self.entry
        prior = self.store.prior_quotes(quote.symbol, quote.timestamp_ms, window + 1)
        if direction.startswith("immediate:"):
            side = direction.split(":", 1)[1]
        else:
            points = prior[-window - 1:] + [quote]
            if len(prior) < window + 1 or any(b.timestamp_ms - a.timestamp_ms > 120_000 for a, b in zip(points, points[1:])):
                return
            base = prior[-window].last
            prev_base = prior[-window - 1].last
            movement = (quote.last / base - 1) * 100
            previous = (prior[-1].last / prev_base - 1) * 100
            if within:
                current_moves = [(quote.last / q.last - 1) * 100 for q in prior[-window:]]
                previous_moves = [(prior[-1].last / q.last - 1) * 100 for q in prior[-window - 1:-1]]
                up, prev_up = max(current_moves), max(previous_moves)
                down, prev_down = min(current_moves), min(previous_moves)
            else:
                up = prev_up = movement
                down = prev_down = movement
                prev_up = previous
                prev_down = previous
            up_signal = direction != "short" and up >= threshold and prev_up < threshold
            down_signal = direction != "long" and down <= -threshold and prev_down > -threshold
            if not up_signal and not down_signal:
                return
            short_signal = down_signal
            side = ("short" if short_signal else "long") if not reverse else ("long" if short_signal else "short")
        self._open(quote, side)

    def _open(self, quote: Quote, side: str) -> None:
        execution = self.release["execution"]
        risk = self.release["risk"]
        account = self.store.account()
        day = datetime.fromtimestamp(quote.timestamp_ms / 1000, KYIV).date().isoformat()
        daily_pnl = Decimal(account["daily_pnl"]) if account["day"] == day else Decimal(0)
        if daily_pnl <= -Decimal(risk["max_daily_loss_usdt"]):
            self.store.event("risk_block", {"reason": "daily_loss_limit"}, quote.symbol, quote.timestamp_ms); return
        allocation = Decimal(execution["starting_balance_usdt"])
        notional = min(allocation * Decimal(execution["leverage"]), Decimal(risk["max_order_notional_usdt"]), allocation)
        open_rows = self.store.db.execute("SELECT quantity,entry_price FROM positions").fetchall()
        strategy_position_count = int(self.store.db.execute("SELECT COUNT(*) FROM positions WHERE strategy_id=?", (self.strategy_id,)).fetchone()[0])
        open_exposure = sum((Decimal(row["quantity"]) * Decimal(row["entry_price"]) for row in open_rows), Decimal(0))
        if strategy_position_count >= risk["max_open_positions"] or open_exposure + notional > Decimal(risk["max_total_exposure_usdt"]):
            self.store.event("risk_block", {"reason": "exposure_limit"}, quote.symbol, quote.timestamp_ms); return
        price = quote.bid * (1 - Decimal(execution["slippage_bps"]) / 10000) if side == "short" else quote.ask * (1 + Decimal(execution["slippage_bps"]) / 10000)
        qty = notional / price
        fee = notional * Decimal(execution["taker_fee_rate"])
        self.store.open_position(quote.symbol, side, qty, price, fee, quote.timestamp_ms, self.strategy_id, "single", release_digest(self.release))
        self.store.event("paper_entry", {"side": side, "quantity": str(qty), "price": str(price), "fee": str(fee)}, quote.symbol, quote.timestamp_ms)

    def _manage_position(self, pos: sqlite3.Row, quote: Quote) -> None:
        execution = self.release["execution"]
        quantity, entry = Decimal(pos["quantity"]), Decimal(pos["entry_price"])
        side = pos["side"]
        raw_exit = quote.ask if side == "short" else quote.bid
        exit_price = raw_exit * (1 + Decimal(execution["slippage_bps"]) / 10000) if side == "short" else raw_exit * (1 - Decimal(execution["slippage_bps"]) / 10000)
        gross = quantity * (entry - exit_price if side == "short" else exit_price - entry)
        exit_fee = quantity * exit_price * Decimal(execution["taker_fee_rate"])
        pnl = gross - Decimal(pos["entry_fee"]) - exit_fee
        balance = Decimal(execution["starting_balance_usdt"])
        take = self.take is not None and pnl >= balance * self.take
        stop = self.stop is not None and pnl <= -(balance * self.stop)
        mode = self.release["strategy"]["exit_confirmation_mode"]
        if mode == "tp_delayed": stop = False
        elif mode == "sl_delayed": take = False
        kind = "take_profit" if take else "stop_loss" if stop else None
        if kind is None:
            if pos["pending_exit"]:
                self.store.update_pending(quote.symbol, None, 0, self.strategy_id)
            return
        count = pos["pending_count"] + 1 if pos["pending_exit"] == kind else 1
        needed = self.release["strategy"]["exit_confirmation_snapshots"] if mode != "immediate" else 0
        if count <= needed:
            self.store.update_pending(quote.symbol, kind, count, self.strategy_id); return
        self._close_position(pos, quote, kind, exit_price=exit_price, pnl=pnl)

    def _close_position(self, pos: sqlite3.Row, quote: Quote, reason: str, *, exit_price: Decimal | None = None, pnl: Decimal | None = None) -> None:
        if exit_price is None or pnl is None:
            execution = self.release["execution"]
            quantity, entry = Decimal(pos["quantity"]), Decimal(pos["entry_price"])
            market = quote.ask if pos["side"] == "short" else quote.bid
            slip = Decimal(execution["slippage_bps"]) / 10000
            exit_price = market * (1 + slip) if pos["side"] == "short" else market * (1 - slip)
            gross = quantity * (entry - exit_price if pos["side"] == "short" else exit_price - entry)
            pnl = gross - Decimal(pos["entry_fee"]) - quantity * exit_price * Decimal(execution["taker_fee_rate"])
        self.store.close_position(pos, quote, exit_price, pnl)
        self.store.event("paper_exit", {"reason": reason, "pnl": str(pnl), "price": str(exit_price)}, quote.symbol, quote.timestamp_ms)


class PortfolioExecutor:
    """Runs multiple strategies against one virtual account and quote stream."""

    def __init__(self, store: SQLiteStateStore, *, stale_after_seconds: int = 30, minimum_equity_percent: Decimal = Decimal("75")) -> None:
        self.store, self.stale_after_seconds = store, stale_after_seconds
        self.executors: dict[str, PaperExecutor] = {}
        self.hedges: dict[str, dict[str, Any]] = {}
        self.entries_paused = False
        self.close_requested = False
        self.minimum_equity_percent = minimum_equity_percent
        self.shared_exposure_limit = Decimal(0)
        self.refresh()

    def refresh(self) -> None:
        self.store.begin_strategy_runs(self.minimum_equity_percent)
        rows = self.store.db.execute("SELECT p.strategy_id,p.kind,p.payload FROM portfolio_strategies p WHERE p.enabled=1 OR EXISTS (SELECT 1 FROM strategy_runs r WHERE r.strategy_id=p.strategy_id AND r.status='stopping') ORDER BY p.added_at_ms").fetchall()
        total_balance = Decimal((self.store.account() or {"initial_balance": "0"})["initial_balance"])
        configured_exposure = sum((Decimal(json.loads(row["payload"])["risk"]["max_total_exposure_usdt"]) for row in rows), Decimal(0))
        self.shared_exposure_limit = min(total_balance, configured_exposure)
        active_ids: set[str] = set()
        for row in rows:
            strategy_id, payload = row["strategy_id"], json.loads(row["payload"])
            run = self.store.active_strategy_run(strategy_id)
            if run is None:
                continue
            active_ids.add(strategy_id)
            if row["kind"] == "hedge":
                scaled_hedge = json.loads(json.dumps(payload))
                scaled_hedge["risk"]["max_total_exposure_usdt"] = str(self.shared_exposure_limit)
                self.hedges[strategy_id] = scaled_hedge
                self.executors.pop(strategy_id, None)
                continue
            self.hedges.pop(strategy_id, None)
            scaled = json.loads(json.dumps(payload))
            allocation = Decimal(run["allocation_usdt"])
            scaled["execution"]["starting_balance_usdt"] = str(allocation)
            scaled["risk"]["max_order_notional_usdt"] = str(min(Decimal(scaled["risk"]["max_order_notional_usdt"]), allocation))
            scaled["risk"]["max_total_exposure_usdt"] = str(self.shared_exposure_limit)
            self.executors[strategy_id] = PaperExecutor(scaled, self.store, stale_after_seconds=self.stale_after_seconds, strategy_id=strategy_id, activate=False)
        self.executors = {key: value for key, value in self.executors.items() if key in active_ids}
        self.hedges = {key: value for key, value in self.hedges.items() if key in active_ids}

    def symbols(self) -> list[str]:
        symbols = {symbol for executor in self.executors.values() for symbol in executor.release["market"]["symbols"]}
        symbols.update(symbol for payload in self.hedges.values() for symbol in payload["market"]["symbols"])
        return sorted(symbols)

    def set_controls(self, *, entries_paused: bool, close_positions: bool) -> None:
        for executor in self.executors.values():
            executor.set_controls(entries_paused=entries_paused, close_positions=close_positions)
        self.entries_paused, self.close_requested = entries_paused, close_positions

    def on_quotes(self, quotes: list[Quote], *, now_ms: int | None = None) -> None:
        now_ms = now_ms or int(time.time() * 1000)
        fresh = {quote.symbol: quote for quote in quotes if self.store.quote(quote)}
        for strategy_id, payload in {**self.executors_payloads(), **self.hedges}.items():
            equity = self.store.strategy_equity(strategy_id, payload, now_ms=now_ms, max_quote_age_ms=self.stale_after_seconds * 1000)
            if equity is not None:
                self.store.update_strategy_equity(strategy_id, equity, self.minimum_equity_percent, now_ms)
        for executor in self.executors.values():
            for quote in fresh.values():
                if quote.symbol in executor.release["market"]["symbols"]:
                    executor.on_quote(quote, now_ms=now_ms, record_quote=False)
        for strategy_id, payload in self.hedges.items():
            long_symbol, short_symbol = payload["market"]["symbols"]
            long_quote, short_quote = fresh.get(long_symbol), fresh.get(short_symbol)
            positions = self.store.db.execute("SELECT * FROM positions WHERE strategy_id=? ORDER BY leg", (strategy_id,)).fetchall()
            run = self.store.active_strategy_run(strategy_id)
            if positions:
                if run is not None and run["status"] == "stopping" and long_quote and short_quote:
                    self._close_hedge(strategy_id, positions, {long_symbol: long_quote, short_symbol: short_quote}, payload)
                elif self.close_requested and long_quote and short_quote:
                    self._close_hedge(strategy_id, positions, {long_symbol: long_quote, short_symbol: short_quote}, payload)
                continue
            if run is not None and run["status"] == "stopping":
                continue
            if self.entries_paused or not long_quote or not short_quote or abs(long_quote.timestamp_ms - short_quote.timestamp_ms) > 120_000:
                continue
            self._open_hedge(strategy_id, long_quote, short_quote, payload)
        for strategy_id in list(self.executors) + list(self.hedges):
            run = self.store.active_strategy_run(strategy_id)
            if run is not None and run["status"] == "stopping":
                payload = self.executors[strategy_id].release if strategy_id in self.executors else self.hedges[strategy_id]
                equity = self.store.strategy_equity(strategy_id, payload, now_ms=now_ms, max_quote_age_ms=self.stale_after_seconds * 1000)
                self.store.finish_strategy_run(strategy_id, equity=equity, now_ms=now_ms)

    def executors_payloads(self) -> dict[str, dict[str, Any]]:
        return {strategy_id: executor.release for strategy_id, executor in self.executors.items()}

    def _open_hedge(self, strategy_id: str, long_quote: Quote, short_quote: Quote, payload: dict[str, Any]) -> None:
        account = self.store.account()
        run = self.store.active_strategy_run(strategy_id)
        allocation = Decimal(run["allocation_usdt"]) if run else Decimal(0)
        day = datetime.fromtimestamp(long_quote.timestamp_ms / 1000, KYIV).date().isoformat()
        daily = Decimal(account["daily_pnl"]) if account and account["day"] == day else Decimal(0)
        if daily <= -Decimal(payload["risk"]["max_daily_loss_usdt"]):
            self.store.event("risk_block", {"reason": "daily_loss_limit"}, long_quote.symbol, long_quote.timestamp_ms)
            return
        open_rows = self.store.db.execute("SELECT quantity,entry_price FROM positions").fetchall()
        exposure = sum((Decimal(row["quantity"]) * Decimal(row["entry_price"]) for row in open_rows), Decimal(0))
        maximum_exposure = self.shared_exposure_limit
        if exposure + allocation > maximum_exposure:
            self.store.event("risk_block", {"reason": "exposure_limit", "current_usdt": str(exposure), "requested_usdt": str(allocation)}, long_quote.symbol, long_quote.timestamp_ms)
            return
        per_leg = allocation / 2
        execution = payload["execution"]
        fee_rate, slip = Decimal(execution["taker_fee_rate"]), Decimal(execution["slippage_bps"]) / 10000
        opening: list[tuple[str, str, str, Decimal, Decimal, Decimal, int]] = []
        for leg, quote, side in (("long", long_quote, "long"), ("short", short_quote, "short")):
            price = quote.ask * (1 + slip) if side == "long" else quote.bid * (1 - slip)
            quantity = per_leg / price
            opening.append((quote.symbol, leg, side, quantity, price, per_leg * fee_rate, quote.timestamp_ms))
        self.store.open_hedge_positions(opening, strategy_id, release_digest(payload))
        self.store.event("paper_hedge_entry", {"long_symbol": long_quote.symbol, "short_symbol": short_quote.symbol, "allocation_usdt": str(allocation)}, long_quote.symbol, long_quote.timestamp_ms)

    def _close_hedge(self, strategy_id: str, positions: list[sqlite3.Row], quotes: dict[str, Quote], payload: dict[str, Any]) -> None:
        execution = payload["execution"]
        slip, fee_rate = Decimal(execution["slippage_bps"]) / 10000, Decimal(execution["taker_fee_rate"])
        closed: list[tuple[sqlite3.Row, Quote, Decimal, Decimal]] = []
        for pos in positions:
            quote = quotes[pos["symbol"]]
            quantity, entry = Decimal(pos["quantity"]), Decimal(pos["entry_price"])
            market = quote.ask if pos["side"] == "short" else quote.bid
            price = market * (1 + slip) if pos["side"] == "short" else market * (1 - slip)
            pnl = quantity * (entry - price if pos["side"] == "short" else price - entry) - Decimal(pos["entry_fee"]) - quantity * price * fee_rate
            closed.append((pos, quote, price, pnl))
        total_pnl = self.store.close_hedge_positions(closed)
        self.store.event("paper_hedge_exit", {"pnl_usdt": str(total_pnl), "strategy_id": strategy_id}, positions[0]["symbol"] if positions else None)
