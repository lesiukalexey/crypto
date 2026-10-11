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
          CREATE TABLE IF NOT EXISTS positions(symbol TEXT PRIMARY KEY, side TEXT NOT NULL, quantity TEXT NOT NULL, entry_price TEXT NOT NULL, entry_fee TEXT NOT NULL, entry_ts INTEGER NOT NULL, pending_exit TEXT, pending_count INTEGER NOT NULL DEFAULT 0);
          CREATE TABLE IF NOT EXISTS trades(id INTEGER PRIMARY KEY, symbol TEXT NOT NULL, side TEXT NOT NULL, quantity TEXT NOT NULL, entry_price TEXT NOT NULL, exit_price TEXT NOT NULL, entry_ts INTEGER NOT NULL, exit_ts INTEGER NOT NULL, pnl TEXT NOT NULL, release_digest TEXT NOT NULL DEFAULT '');
          CREATE TABLE IF NOT EXISTS account(id INTEGER PRIMARY KEY CHECK(id=1), balance TEXT NOT NULL, realized_pnl TEXT NOT NULL, day TEXT NOT NULL, daily_pnl TEXT NOT NULL);
        """)
        columns = {row[1] for row in self.db.execute("PRAGMA table_info(trades)")}
        if "release_digest" not in columns:
            self.db.execute("ALTER TABLE trades ADD COLUMN release_digest TEXT NOT NULL DEFAULT ''")
        self.db.commit()

    def activate(self, release: dict[str, Any]) -> str:
        payload = validate_strategy_release(release)
        digest = release_digest(payload)
        active = self.db.execute("SELECT value AS digest FROM runtime_meta WHERE key='active_release_digest'").fetchone()
        if active is None:
            active = self.db.execute("SELECT digest FROM releases ORDER BY activated_at_ms DESC LIMIT 1").fetchone()
        if active and active["digest"] != digest and self.db.execute("SELECT COUNT(*) FROM positions").fetchone()[0]:
            raise ValueError("Cannot switch strategy release while paper positions are open")
        if active and active["digest"] != digest:
            self.db.execute("UPDATE account SET balance=?,realized_pnl='0',daily_pnl='0',day=? WHERE id=1", (payload["execution"]["starting_balance_usdt"], datetime.now(KYIV).date().isoformat()))
        self.db.execute("INSERT INTO releases VALUES(?,?,?) ON CONFLICT(digest) DO UPDATE SET activated_at_ms=excluded.activated_at_ms", (digest, json.dumps(payload, sort_keys=True), int(time.time() * 1000)))
        self.db.execute("INSERT OR IGNORE INTO account VALUES(1,?,?,?,?)", (payload["execution"]["starting_balance_usdt"], "0", datetime.now(KYIV).date().isoformat(), "0"))
        self.db.execute("INSERT INTO runtime_meta VALUES('active_release_digest',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (digest,))
        self.db.commit()
        return digest

    def event(self, kind: str, payload: dict[str, Any], symbol: str | None = None, ts: int | None = None) -> None:
        self.db.execute("INSERT INTO events(ts,type,symbol,payload) VALUES(?,?,?,?)", (ts or int(time.time() * 1000), kind, symbol, json.dumps(payload, sort_keys=True)))
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

    def position(self, symbol: str) -> sqlite3.Row | None:
        return self.db.execute("SELECT * FROM positions WHERE symbol=?", (symbol,)).fetchone()

    def open_position(self, symbol: str, side: str, quantity: Decimal, price: Decimal, fee: Decimal, ts: int) -> None:
        self.db.execute("INSERT INTO positions(symbol,side,quantity,entry_price,entry_fee,entry_ts) VALUES(?,?,?,?,?,?)", (symbol, side, str(quantity), str(price), str(fee), ts))
        self.db.commit()

    def update_pending(self, symbol: str, kind: str | None, count: int) -> None:
        self.db.execute("UPDATE positions SET pending_exit=?,pending_count=? WHERE symbol=?", (kind, count, symbol)); self.db.commit()

    def close_position(self, pos: sqlite3.Row, quote: Quote, price: Decimal, pnl: Decimal) -> None:
        active = self.db.execute("SELECT value FROM runtime_meta WHERE key='active_release_digest'").fetchone()
        self.db.execute("INSERT INTO trades(symbol,side,quantity,entry_price,exit_price,entry_ts,exit_ts,pnl,release_digest) VALUES(?,?,?,?,?,?,?,?,?)", (pos["symbol"], pos["side"], pos["quantity"], pos["entry_price"], str(price), pos["entry_ts"], quote.timestamp_ms, str(pnl), active["value"] if active else ""))
        self.db.execute("DELETE FROM positions WHERE symbol=?", (pos["symbol"],))
        account = self.account()
        day = datetime.fromtimestamp(quote.timestamp_ms / 1000, KYIV).date().isoformat()
        daily = Decimal(account["daily_pnl"]) + pnl if account["day"] == day else pnl
        self.db.execute("UPDATE account SET balance=?,realized_pnl=?,daily_pnl=?,day=? WHERE id=1", (str(Decimal(account["balance"]) + pnl), str(Decimal(account["realized_pnl"]) + pnl), str(daily), day))
        self.db.commit()

    def account(self) -> sqlite3.Row:
        return self.db.execute("SELECT * FROM account WHERE id=1").fetchone()

    def health(self) -> dict[str, Any]:
        account = self.account()
        active = self.db.execute("SELECT r.payload,r.digest FROM runtime_meta m JOIN releases r ON r.digest=m.value WHERE m.key='active_release_digest'").fetchone()
        if active is None:
            active = self.db.execute("SELECT payload,digest FROM releases ORDER BY activated_at_ms DESC LIMIT 1").fetchone()
        if account is None:
            return {"mode": "paper", "initialized": False, "active_release_digest": active["digest"] if active else None, "open_positions": 0, "positions": [], "unrealized_pnl_usdt": "0", "trades": 0, "recent_trades": [], "recent_events": [], "latest_event": None}
        positions: list[dict[str, Any]] = []
        unrealized = Decimal(0)
        if active:
            execution = json.loads(active["payload"])["execution"]
            fee_rate, slip = Decimal(execution["taker_fee_rate"]), Decimal(execution["slippage_bps"]) / 10000
            for pos in self.db.execute("SELECT * FROM positions ORDER BY symbol"):
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
                positions.append({"symbol": pos["symbol"], "side": pos["side"], "quantity": pos["quantity"], "entry_price": pos["entry_price"], "entry_timestamp_ms": pos["entry_ts"], "unrealized_pnl_usdt": None if pnl is None else str(pnl), "quote_timestamp_ms": ts})
        latest = self.db.execute("SELECT type,ts FROM events ORDER BY id DESC LIMIT 1").fetchone()
        recent_trades = [dict(row) for row in self.db.execute("SELECT * FROM trades ORDER BY id DESC LIMIT 50")]
        recent_events = [{"id": row["id"], "timestamp_ms": row["ts"], "type": row["type"], "symbol": row["symbol"], "payload": json.loads(row["payload"])} for row in self.db.execute("SELECT * FROM events ORDER BY id DESC LIMIT 100")]
        return {"mode": "paper", "initialized": True, "active_release_digest": active["digest"] if active else None, "balance_usdt": account["balance"], "realized_pnl_usdt": account["realized_pnl"], "daily_pnl_usdt": account["daily_pnl"], "open_positions": len(positions), "positions": positions, "unrealized_pnl_usdt": str(unrealized), "trades": self.db.execute("SELECT COUNT(*) FROM trades").fetchone()[0], "recent_trades": recent_trades, "recent_events": recent_events, "latest_event": None if latest is None else dict(latest)}

    def close(self) -> None:
        self.db.close()


class PaperExecutor:
    def __init__(self, release: dict[str, Any], store: SQLiteStateStore, *, stale_after_seconds: int = 30) -> None:
        self.release = self.validate_release(release)
        self.store = store
        self.stale_after_ms = stale_after_seconds * 1000
        strategy = self.release["strategy"]
        self.entry = self._parse_entry(strategy["entry_config"], strategy["entry_signal_mode"], strategy["entry_direction_mode"])
        self.take, self.stop = self._parse_exit(strategy["exit_config"])
        self.entries_paused = False
        self.close_requested = False
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

    def on_quote(self, quote: Quote, *, now_ms: int | None = None) -> None:
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
        if not self.store.quote(quote):
            self.store.event("duplicate_quote", {"quote_ts": quote.timestamp_ms}, quote.symbol, quote.timestamp_ms)
            return
        pos = self.store.position(quote.symbol)
        if pos:
            if self.close_requested:
                self._close_position(pos, quote, "manual_close")
            else:
                self._manage_position(pos, quote)
        else:
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
        notional = min(Decimal(execution["starting_balance_usdt"]) * Decimal(execution["leverage"]), Decimal(risk["max_order_notional_usdt"]))
        open_rows = self.store.db.execute("SELECT quantity,entry_price FROM positions").fetchall()
        open_exposure = sum((Decimal(row["quantity"]) * Decimal(row["entry_price"]) for row in open_rows), Decimal(0))
        if len(open_rows) >= risk["max_open_positions"] or open_exposure + notional > Decimal(risk["max_total_exposure_usdt"]):
            self.store.event("risk_block", {"reason": "exposure_limit"}, quote.symbol, quote.timestamp_ms); return
        price = quote.bid * (1 - Decimal(execution["slippage_bps"]) / 10000) if side == "short" else quote.ask * (1 + Decimal(execution["slippage_bps"]) / 10000)
        qty = notional / price
        fee = notional * Decimal(execution["taker_fee_rate"])
        self.store.open_position(quote.symbol, side, qty, price, fee, quote.timestamp_ms)
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
                self.store.update_pending(quote.symbol, None, 0)
            return
        count = pos["pending_count"] + 1 if pos["pending_exit"] == kind else 1
        needed = self.release["strategy"]["exit_confirmation_snapshots"] if mode != "immediate" else 0
        if count <= needed:
            self.store.update_pending(quote.symbol, kind, count); return
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
