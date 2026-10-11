"""Durable portfolio execution against an exchange adapter.

Exchange fills, not requested orders or quote estimates, update the local
position ledger. The adapter must reconcile account state before every new
order. Unknown or ambiguous order outcomes keep the executor locked.
"""

from __future__ import annotations

import hashlib
import json
import time
from datetime import datetime
from decimal import Decimal, ROUND_DOWN
from typing import Any, Mapping, Sequence

from .bitget_demo import ExchangeStateMismatch
from .contracts import ExecutionAdapter, OrderRequest, Position, Quote, release_digest
from .paper import KYIV, PaperExecutor, SQLiteStateStore


class ExchangePortfolioExecutor:
    def __init__(self, store: SQLiteStateStore, adapter: ExecutionAdapter, *, mode: str, stale_after_seconds: int = 30) -> None:
        if mode not in {"demo", "live"} or adapter.mode != mode:
            raise ValueError("Exchange portfolio mode and adapter must match Demo or Real")
        self.store, self.adapter, self.mode = store, adapter, mode
        self.stale_after_ms = stale_after_seconds * 1000
        self.entries_paused = False
        self.close_requested = False
        self.strategies: dict[str, dict[str, Any]] = {}
        self.hedges: dict[str, dict[str, Any]] = {}
        self.refresh()

    def refresh(self) -> None:
        rows = self.store.db.execute("SELECT strategy_id,kind,payload FROM portfolio_strategies WHERE enabled=1 ORDER BY added_at_ms").fetchall()
        if not rows:
            raise ValueError("No enabled strategies exist in the shared portfolio")
        strategies: dict[str, dict[str, Any]] = {}
        hedges: dict[str, dict[str, Any]] = {}
        symbol_owners: dict[str, str] = {}
        for row in rows:
            strategy_id, payload = row["strategy_id"], json.loads(row["payload"])
            if payload["market"]["category"] != "USDT-FUTURES":
                raise ValueError("Exchange portfolio currently supports only USDT futures")
            if len(set(payload["market"]["symbols"])) != len(payload["market"]["symbols"]):
                raise ValueError("Exchange hedge legs must use different symbols in one-way mode")
            if payload["strategy"]["martingale_mode"] != "none" or payload["execution"]["include_funding"]:
                raise ValueError("Exchange portfolio supports neither martingale nor funding")
            for symbol in payload["market"]["symbols"]:
                owner = symbol_owners.get(symbol)
                if owner is not None and owner != strategy_id:
                    raise ValueError(f"Exchange mode requires unique symbols per strategy until Bitget one-way positions can be safely allocated: {symbol}")
                symbol_owners[symbol] = strategy_id
            (hedges if row["kind"] == "hedge" else strategies)[strategy_id] = payload
        self.strategies, self.hedges = strategies, hedges

    def symbols(self) -> list[str]:
        return sorted({symbol for payload in (*self.strategies.values(), *self.hedges.values()) for symbol in payload["market"]["symbols"]})

    def set_controls(self, *, entries_paused: bool, close_positions: bool) -> None:
        self.entries_paused, self.close_requested = entries_paused, close_positions

    def _expected_positions(self) -> list[Position]:
        rows = self.store.db.execute("SELECT symbol,side,quantity,entry_price FROM positions ORDER BY symbol").fetchall()
        result: list[Position] = []
        for row in rows:
            result.append(Position(row["symbol"], row["side"], Decimal(row["quantity"]), Decimal(row["entry_price"])))
        return result

    def _pending_orders(self) -> list[tuple[str, str]]:
        rows = self.store.db.execute("SELECT symbol,client_order_id FROM exchange_orders WHERE status IN ('submitting','uncertain','submitted','partial') ORDER BY created_at_ms").fetchall()
        return [(row["symbol"], row["client_order_id"]) for row in rows]

    @staticmethod
    def _fill_fee(row: Mapping[str, Any], fallback: Decimal) -> Decimal:
        details = row.get("feeDetail")
        if isinstance(details, list):
            amount = Decimal(0)
            found = False
            for detail in details:
                if isinstance(detail, dict) and detail.get("totalFee") not in (None, ""):
                    amount += abs(Decimal(str(detail["totalFee"])))
                    found = True
            if found:
                return amount
        if row.get("fee") not in (None, ""):
            return abs(Decimal(str(row["fee"])))
        return fallback

    def _apply_fill(self, fill: Mapping[str, Any], orders_by_id: Mapping[str, Any]) -> bool:
        trade_id = str(fill.get("tradeId", ""))
        order_id = str(fill.get("orderId", ""))
        if not trade_id or not order_id:
            raise ExchangeStateMismatch("Exchange fill is missing its stable trade/order ID")
        order = orders_by_id.get(order_id)
        if order is None:
            raise ExchangeStateMismatch("Exchange returned a fill for an unmanaged order")
        if self.store.db.execute("SELECT 1 FROM exchange_fills WHERE trade_id=?", (trade_id,)).fetchone():
            return False
        quantity = Decimal(str(fill.get("baseVolume", "0")))
        price = Decimal(str(fill.get("price", "0")))
        if not quantity.is_finite() or quantity <= 0 or not price.is_finite() or price <= 0:
            raise ExchangeStateMismatch("Exchange fill has an invalid quantity or price")
        payload = self.strategies.get(order["strategy_id"]) or self.hedges.get(order["strategy_id"])
        if payload is None:
            raise ExchangeStateMismatch("Exchange order belongs to a strategy no longer in the portfolio")
        fallback_fee = quantity * price * Decimal(payload["execution"]["taker_fee_rate"])
        fee = self._fill_fee(fill, fallback_fee)
        ts = int(fill.get("cTime") or order["created_at_ms"])
        symbol, side, strategy_id, leg = order["symbol"], order["side"], order["strategy_id"], order["leg"]
        digest = str(order["digest"])
        with self.store.db:
            self.store.db.execute("INSERT INTO exchange_fills(trade_id,client_order_id,quantity,price,fee,timestamp_ms) VALUES(?,?,?,?,?,?)", (trade_id, order["client_order_id"], str(quantity), str(price), str(fee), ts))
            position = self.store.db.execute("SELECT * FROM positions WHERE strategy_id=? AND symbol=? AND leg=?", (strategy_id, symbol, leg)).fetchone()
            account = self.store.account()
            if order["intent"] == "open":
                position_side = "long" if side == "buy" else "short"
                if position is not None and position["side"] != position_side:
                    raise ExchangeStateMismatch("Fill would reverse an existing strategy position")
                if position is None:
                    self.store.db.execute("INSERT INTO positions(strategy_id,symbol,leg,side,quantity,entry_price,entry_fee,entry_ts,release_digest) VALUES(?,?,?,?,?,?,?,?,?)", (strategy_id, symbol, leg, position_side, str(quantity), str(price), str(fee), ts, digest))
                else:
                    old_qty, old_price = Decimal(position["quantity"]), Decimal(position["entry_price"])
                    total = old_qty + quantity
                    average = (old_qty * old_price + quantity * price) / total
                    self.store.db.execute("UPDATE positions SET quantity=?,entry_price=?,entry_fee=? WHERE strategy_id=? AND symbol=? AND leg=?", (str(total), str(average), str(Decimal(position["entry_fee"]) + fee), strategy_id, symbol, leg))
                self.store.db.execute("INSERT INTO events(ts,type,symbol,payload) VALUES(?,?,?,?)", (ts, "exchange_entry_fill", symbol, json.dumps({"mode": self.mode, "strategy_id": strategy_id, "leg": leg, "quantity": str(quantity), "price": str(price), "fee": str(fee), "order_id": order_id}, sort_keys=True)))
            else:
                if position is None:
                    raise ExchangeStateMismatch("Close fill has no matching local strategy position")
                open_qty = Decimal(position["quantity"])
                if quantity > open_qty:
                    raise ExchangeStateMismatch("Close fill exceeds its allocated local position")
                entry = Decimal(position["entry_price"])
                entry_fee = Decimal(position["entry_fee"]) * quantity / open_qty
                pnl = quantity * (entry - price if position["side"] == "short" else price - entry) - entry_fee - fee
                remaining = open_qty - quantity
                day = datetime.fromtimestamp(ts / 1000, KYIV).date().isoformat()
                daily = Decimal(account["daily_pnl"]) + pnl if account["day"] == day else pnl
                self.store.db.execute("INSERT INTO trades(symbol,side,quantity,entry_price,exit_price,entry_ts,exit_ts,pnl,release_digest,strategy_id,leg) VALUES(?,?,?,?,?,?,?,?,?,?,?)", (symbol, position["side"], str(quantity), str(entry), str(price), position["entry_ts"], ts, str(pnl), digest, strategy_id, leg))
                if remaining == 0:
                    self.store.db.execute("DELETE FROM positions WHERE strategy_id=? AND symbol=? AND leg=?", (strategy_id, symbol, leg))
                else:
                    self.store.db.execute("UPDATE positions SET quantity=?,entry_fee=? WHERE strategy_id=? AND symbol=? AND leg=?", (str(remaining), str(Decimal(position["entry_fee"]) - entry_fee), strategy_id, symbol, leg))
                self.store.db.execute("UPDATE account SET balance=?,realized_pnl=?,daily_pnl=?,day=? WHERE id=1", (str(Decimal(account["balance"]) + pnl), str(Decimal(account["realized_pnl"]) + pnl), str(daily), day))
                self.store.db.execute("INSERT INTO events(ts,type,symbol,payload) VALUES(?,?,?,?)", (ts, "exchange_exit_fill", symbol, json.dumps({"mode": self.mode, "strategy_id": strategy_id, "leg": leg, "quantity": str(quantity), "price": str(price), "fee": str(fee), "pnl": str(pnl), "order_id": order_id}, sort_keys=True)))
        return True

    def reconcile(self) -> bool:
        """Reconcile, apply new fills once, and repeat position check after fills."""
        for attempt in range(2):
            result = self.adapter.reconcile(self._expected_positions(), self._pending_orders())
            order_by_id = {str(order.get("orderId")): order for order in result.orders if order.get("orderId")}
            # A submission can reach Bitget while its response is lost. Resolve
            # the local uncertain row by deterministic client ID before applying
            # the fills returned by reconciliation.
            for remote in result.orders:
                client_id = remote.get("clientOid") or remote.get("clientOrderId")
                remote_order_id = remote.get("orderId")
                if client_id and remote_order_id:
                    self.store.db.execute(
                        "UPDATE exchange_orders SET order_id=?,status=?,updated_at_ms=? WHERE client_order_id=? AND order_id IS NULL",
                        (str(remote_order_id), "partial" if str(remote.get("state", "")).lower() == "partially_filled" else "submitted", int(time.time() * 1000), str(client_id)),
                    )
            self.store.db.commit()
            db_orders = {row["order_id"]: row for row in self.store.db.execute("SELECT * FROM exchange_orders WHERE order_id IS NOT NULL")}
            applied = False
            for fill in result.fills:
                applied = self._apply_fill(fill, db_orders) or applied
            for order_id, order_row in db_orders.items():
                remote = order_by_id.get(str(order_id))
                if remote is None:
                    continue
                state = str(remote.get("state", "")).lower()
                if state in {"filled", "canceled", "cancelled"}:
                    self.store.db.execute("UPDATE exchange_orders SET status='complete',updated_at_ms=? WHERE client_order_id=?", (int(time.time() * 1000), order_row["client_order_id"]))
                elif state in {"live", "partially_filled"}:
                    self.store.db.execute("UPDATE exchange_orders SET status=?,updated_at_ms=? WHERE client_order_id=?", ("partial" if state == "partially_filled" else "submitted", int(time.time() * 1000), order_row["client_order_id"]))
            self.store.db.commit()
            if result.matched and not applied:
                return True
            if not applied:
                self.store.event("exchange_reconciliation_mismatch", {"mode": self.mode, "discrepancies": list(result.discrepancies)})
                return False
        result = self.adapter.reconcile(self._expected_positions(), self._pending_orders())
        if not result.matched:
            self.store.event("exchange_reconciliation_mismatch", {"mode": self.mode, "discrepancies": list(result.discrepancies)})
        return result.matched

    def _new_order(self, strategy_id: str, payload: dict[str, Any], symbol: str, leg: str, intent: str, side: str, quantity: Decimal, reason: str, quote: Quote) -> bool:
        if hasattr(self.adapter, "normalize_quantity"):
            try:
                normalized = self.adapter.normalize_quantity(symbol, quantity)
                if intent == "close" and normalized != quantity:
                    raise ValueError("Position quantity is not an exact Bitget contract step")
                quantity = normalized
            except Exception as exc:
                self.store.event("exchange_order_rejected", {"mode": self.mode, "reason": type(exc).__name__}, symbol, quote.timestamp_ms)
                return False
        now = int(time.time() * 1000)
        seed = f"{self.mode}:{strategy_id}:{symbol}:{leg}:{intent}:{quote.timestamp_ms}:{quantity}"
        client_id = "b" + hashlib.sha256(seed.encode()).hexdigest()[:30]
        digest = release_digest(payload)
        with self.store.db:
            self.store.db.execute("INSERT INTO exchange_orders(client_order_id,mode,strategy_id,symbol,leg,intent,side,requested_qty,status,order_id,reason,digest,created_at_ms,updated_at_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)", (client_id, self.mode, strategy_id, symbol, leg, intent, side, str(quantity), "submitting", None, reason, digest, now, now))
        try:
            result = self.adapter.submit(OrderRequest(symbol, side, quantity, client_id, reduce_only=intent == "close"))
        except Exception as exc:
            self.store.db.execute("UPDATE exchange_orders SET status='uncertain',updated_at_ms=? WHERE client_order_id=?", (int(time.time() * 1000), client_id)); self.store.db.commit()
            self.store.event("exchange_order_uncertain", {"mode": self.mode, "client_order_id": client_id, "error": type(exc).__name__}, symbol, quote.timestamp_ms)
            return False
        if not result.accepted:
            self.store.db.execute("UPDATE exchange_orders SET status='rejected',updated_at_ms=? WHERE client_order_id=?", (int(time.time() * 1000), client_id)); self.store.db.commit()
            self.store.event("exchange_order_rejected", {"mode": self.mode, "client_order_id": client_id, "message": result.message[:300]}, symbol, quote.timestamp_ms)
            return False
        if not result.order_id:
            self.store.db.execute("UPDATE exchange_orders SET status='uncertain',updated_at_ms=? WHERE client_order_id=?", (int(time.time() * 1000), client_id)); self.store.db.commit()
            self.store.event("exchange_order_uncertain", {"mode": self.mode, "client_order_id": client_id, "error": "missing_order_id"}, symbol, quote.timestamp_ms)
            return False
        self.store.db.execute("UPDATE exchange_orders SET status='submitted',order_id=?,updated_at_ms=? WHERE client_order_id=?", (result.order_id, int(time.time() * 1000), client_id)); self.store.db.commit()
        self.store.event("exchange_order_submitted", {"mode": self.mode, "client_order_id": client_id, "order_id": result.order_id, "intent": intent, "strategy_id": strategy_id, "leg": leg}, symbol, quote.timestamp_ms)
        return True

    def _entry_side(self, strategy_id: str, payload: dict[str, Any], quote: Quote) -> str | None:
        entry = PaperExecutor._parse_entry(payload["strategy"]["entry_config"], payload["strategy"]["entry_signal_mode"], payload["strategy"]["entry_direction_mode"])
        direction, window, threshold, within, reverse = entry
        if direction.startswith("immediate:"):
            return direction.split(":", 1)[1]
        prior = self.store.prior_quotes(quote.symbol, quote.timestamp_ms, window + 1)
        points = prior[-window - 1:] + [quote]
        if len(prior) < window + 1 or any(b.timestamp_ms - a.timestamp_ms > 120_000 for a, b in zip(points, points[1:])):
            return None
        base, prev_base = prior[-window].last, prior[-window - 1].last
        movement = (quote.last / base - 1) * 100
        previous = (prior[-1].last / prev_base - 1) * 100
        if within:
            current_moves = [(quote.last / q.last - 1) * 100 for q in prior[-window:]]
            previous_moves = [(prior[-1].last / q.last - 1) * 100 for q in prior[-window - 1:-1]]
            up, prev_up, down, prev_down = max(current_moves), max(previous_moves), min(current_moves), min(previous_moves)
        else:
            up = down = movement; prev_up = prev_down = previous
        up_signal = direction != "short" and up >= threshold and prev_up < threshold
        down_signal = direction != "long" and down <= -threshold and prev_down > -threshold
        if not up_signal and not down_signal:
            return None
        short_signal = down_signal
        return ("short" if short_signal else "long") if not reverse else ("long" if short_signal else "short")

    def _notional(self, strategy_id: str, payload: dict[str, Any]) -> Decimal:
        account = self.store.account()
        total = Decimal(account["initial_balance"] if account else "0")
        count = max(1, len(self.strategies) + len(self.hedges))
        allocation = total / count
        execution, risk = payload["execution"], payload["risk"]
        return min(allocation * Decimal(execution["leverage"]), Decimal(risk["max_order_notional_usdt"]), allocation)

    def _entry_allowed(self, strategy_id: str, payload: dict[str, Any], symbol: str, side: str, price: Decimal, notional: Decimal, ts: int) -> bool:
        account = self.store.account()
        if account is None:
            return False
        day = datetime.fromtimestamp(ts / 1000, KYIV).date().isoformat()
        daily = Decimal(account["daily_pnl"]) if account["day"] == day else Decimal(0)
        active_payloads = (*self.strategies.values(), *self.hedges.values())
        shared_daily_limit = sum((Decimal(item["risk"]["max_daily_loss_usdt"]) for item in active_payloads), Decimal(0))
        if daily <= -shared_daily_limit:
            self.store.event("risk_block", {"mode": self.mode, "reason": "daily_loss_limit"}, symbol, ts); return False
        rows = self.store.db.execute("SELECT strategy_id,symbol,side,quantity,entry_price FROM positions").fetchall()
        exposure = sum((Decimal(row["quantity"]) * Decimal(row["entry_price"]) for row in rows), Decimal(0))
        shared_exposure_limit = sum((Decimal(item["risk"]["max_total_exposure_usdt"]) for item in active_payloads), Decimal(0))
        maximum = min(Decimal(account["initial_balance"]), shared_exposure_limit)
        if exposure + notional > maximum:
            self.store.event("risk_block", {"mode": self.mode, "reason": "exposure_limit"}, symbol, ts); return False
        strategy_positions = sum(1 for row in rows if row["strategy_id"] == strategy_id)
        if strategy_id not in self.hedges and strategy_positions >= payload["risk"]["max_open_positions"]:
            self.store.event("risk_block", {"mode": self.mode, "reason": "position_limit"}, symbol, ts); return False
        target_side = "long" if side == "buy" else "short"
        opposite = [row for row in rows if row["symbol"] == symbol and row["strategy_id"] != strategy_id and row["side"] != target_side]
        if opposite:
            self.store.event("risk_block", {"mode": self.mode, "reason": "one_way_symbol_conflict"}, symbol, ts); return False
        return True

    def _exit_reason(self, pos: Any, payload: dict[str, Any], quote: Quote) -> str | None:
        if pos["strategy_id"] in self.hedges and not self.close_requested:
            return None
        entry_price, quantity = Decimal(pos["entry_price"]), Decimal(pos["quantity"])
        side = pos["side"]
        execution = payload["execution"]
        exit_price = quote.ask if side == "short" else quote.bid
        gross = quantity * (entry_price - exit_price if side == "short" else exit_price - entry_price)
        fees = Decimal(pos["entry_fee"]) + quantity * exit_price * Decimal(execution["taker_fee_rate"])
        pnl = gross - fees
        take, stop = PaperExecutor._parse_exit(payload["strategy"]["exit_config"])
        balance = Decimal(execution["starting_balance_usdt"])
        take_hit = take is not None and pnl >= balance * take
        stop_hit = stop is not None and pnl <= -(balance * stop)
        mode = payload["strategy"]["exit_confirmation_mode"]
        if mode == "tp_delayed": stop_hit = False
        elif mode == "sl_delayed": take_hit = False
        kind = "take_profit" if take_hit else "stop_loss" if stop_hit else None
        if kind is None:
            if pos["pending_exit"]:
                self.store.update_pending(pos["symbol"], None, 0, pos["strategy_id"], pos["leg"])
            return None
        count = pos["pending_count"] + 1 if pos["pending_exit"] == kind else 1
        needed = payload["strategy"]["exit_confirmation_snapshots"] if mode != "immediate" else 0
        if count <= needed:
            self.store.update_pending(pos["symbol"], kind, count, pos["strategy_id"], pos["leg"]); return None
        return kind

    def on_quotes(self, quotes: Sequence[Quote], *, now_ms: int | None = None) -> None:
        now_ms = now_ms or int(time.time() * 1000)
        fresh: dict[str, Quote] = {}
        symbols = set(self.symbols())
        for quote in quotes:
            if quote.symbol not in symbols or quote.timestamp_ms > now_ms + 5000 or now_ms - quote.timestamp_ms > self.stale_after_ms:
                continue
            if self.store.quote(quote):
                fresh[quote.symbol] = quote
        if not self.reconcile():
            return
        if self._pending_orders():
            return
        rows = self.store.db.execute("SELECT * FROM positions ORDER BY strategy_id,symbol,leg").fetchall()
        for pos in rows:
            quote = fresh.get(pos["symbol"])
            if quote is None:
                continue
            payload = self.strategies.get(pos["strategy_id"]) or self.hedges.get(pos["strategy_id"])
            if payload is None:
                continue
            reason = "manual_close" if self.close_requested else self._exit_reason(pos, payload, quote)
            if reason:
                side = "sell" if pos["side"] == "long" else "buy"
                self._new_order(pos["strategy_id"], payload, pos["symbol"], pos["leg"], "close", side, Decimal(pos["quantity"]), reason, quote)
                return
        if self.entries_paused or self.close_requested:
            return
        for strategy_id, payload in self.hedges.items():
            long_symbol, short_symbol = payload["market"]["symbols"]
            have_long = self.store.position(long_symbol, strategy_id, "long") is not None
            have_short = self.store.position(short_symbol, strategy_id, "short") is not None
            if have_long and have_short:
                continue
            if not have_long and not have_short:
                long_quote, short_quote = fresh.get(long_symbol), fresh.get(short_symbol)
                if long_quote is None or short_quote is None or abs(long_quote.timestamp_ms - short_quote.timestamp_ms) > 120_000:
                    continue
                allocation = self._notional(strategy_id, payload) / 2
                price = long_quote.ask
                if not self._entry_allowed(strategy_id, payload, long_symbol, "buy", price, allocation, long_quote.timestamp_ms): return
                self._new_order(strategy_id, payload, long_symbol, "long", "open", "buy", allocation / price, "hedge_entry", long_quote)
                return
            if have_long and not have_short:
                quote = fresh.get(short_symbol)
                if quote is not None:
                    notional = self._notional(strategy_id, payload) / 2
                    if not self._entry_allowed(strategy_id, payload, short_symbol, "sell", quote.bid, notional, quote.timestamp_ms): return
                    self._new_order(strategy_id, payload, short_symbol, "short", "open", "sell", notional / quote.bid, "hedge_entry", quote)
                    return
            if have_short and not have_long:
                quote = fresh.get(long_symbol)
                if quote is not None:
                    notional = self._notional(strategy_id, payload) / 2
                    if not self._entry_allowed(strategy_id, payload, long_symbol, "buy", quote.ask, notional, quote.timestamp_ms): return
                    self._new_order(strategy_id, payload, long_symbol, "long", "open", "buy", notional / quote.ask, "hedge_entry", quote)
                    return
        for strategy_id, payload in self.strategies.items():
            symbol = payload["market"]["symbols"][0]
            quote = fresh.get(symbol)
            if quote is None or self.store.position(symbol, strategy_id):
                continue
            side = self._entry_side(strategy_id, payload, quote)
            if side is None:
                continue
            price = quote.ask if side == "long" else quote.bid
            notional = self._notional(strategy_id, payload)
            order_side = "buy" if side == "long" else "sell"
            if not self._entry_allowed(strategy_id, payload, symbol, order_side, price, notional, quote.timestamp_ms):
                return
            self._new_order(strategy_id, payload, symbol, "single", "open", order_side, notional / price, "strategy_entry", quote)
            return
