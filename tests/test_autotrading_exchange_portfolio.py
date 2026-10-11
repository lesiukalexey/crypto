from __future__ import annotations

import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from autotrading.bitget_demo import Reconciliation
from autotrading.contracts import ExecutionResult, OrderRequest, Position, Quote
from autotrading.exchange_portfolio import ExchangePortfolioExecutor
from autotrading.paper import SQLiteStateStore


def release() -> dict:
    payload = json.loads(Path("autotrading/example-release.json").read_text())
    payload["execution"].update(starting_balance_usdt="100", taker_fee_rate="0")
    payload["risk"].update(max_order_notional_usdt="100", max_total_exposure_usdt="100", max_daily_loss_usdt="20")
    payload["strategy"].update(entry_config="immediate:long", exit_config="fixed:100:100", exit_confirmation_mode="immediate")
    return payload


class FakeExchange:
    mode = "demo"

    def __init__(self, *, lose_submit_response: bool = False) -> None:
        self.remote: dict[str, Position] = {}
        self.orders: list[dict] = []
        self.fills: list[dict] = []
        self.lose_submit_response = lose_submit_response

    def normalize_quantity(self, _symbol: str, quantity: Decimal) -> Decimal:
        return quantity

    def submit(self, request: OrderRequest) -> ExecutionResult:
        order_id = f"order-{len(self.orders) + 1}"
        quantity = request.quantity
        price = Decimal("101") if request.reduce_only else Decimal("100")
        side = "long" if request.side == "buy" else "short"
        prior = self.remote.get(request.symbol)
        if request.reduce_only:
            if prior is None or quantity > prior.quantity:
                raise AssertionError("invalid reduce-only test order")
            remaining = prior.quantity - quantity
            if remaining:
                self.remote[request.symbol] = Position(request.symbol, prior.side, remaining, prior.average_entry_price)
            else:
                del self.remote[request.symbol]
        elif prior is None:
            self.remote[request.symbol] = Position(request.symbol, side, quantity, price)
        else:
            total = prior.quantity + quantity
            self.remote[request.symbol] = Position(request.symbol, side, total, (prior.quantity * prior.average_entry_price + quantity * price) / total)
        self.orders.append({"orderId": order_id, "clientOid": request.client_order_id, "state": "filled"})
        self.fills.append({
            "tradeId": f"trade-{len(self.fills) + 1}", "orderId": order_id,
            "baseVolume": str(quantity), "price": str(price), "fee": "0", "cTime": "1000000",
        })
        if self.lose_submit_response:
            self.lose_submit_response = False
            raise TimeoutError("simulated lost response after exchange acceptance")
        return ExecutionResult(True, order_id, "filled")

    def reconcile(self, expected_positions, pending_orders=()) -> Reconciliation:
        expected = {(p.symbol, p.side): (p.quantity, p.average_entry_price) for p in expected_positions}
        remote = tuple(self.remote.values())
        actual = {(p.symbol, p.side): (p.quantity, p.average_entry_price) for p in remote}
        discrepancies = tuple(f"position mismatch {key}" for key in expected.keys() | actual.keys() if expected.get(key) != actual.get(key))
        return Reconciliation(not discrepancies, discrepancies, remote, tuple(self.orders), tuple(self.fills))


class ExchangePortfolioExecutorTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.store = SQLiteStateStore(Path(self.temp.name) / "state.sqlite")
        self.store.add_portfolio_strategy(release(), name="Demo strategy")
        self.exchange = FakeExchange()
        self.executor = ExchangePortfolioExecutor(self.store, self.exchange, mode="demo")

    def tearDown(self) -> None:
        self.store.close()
        self.temp.cleanup()

    def quote(self, bid: str, ask: str, ts: int) -> Quote:
        return Quote("BTCUSDT", Decimal(bid), Decimal(ask), (Decimal(bid) + Decimal(ask)) / 2, ts)

    def test_position_is_created_from_fill_and_recovery_is_idempotent(self) -> None:
        self.executor.on_quotes([self.quote("99.9", "100", 1_000_000)], now_ms=1_000_000)
        self.assertEqual(self.store.health()["open_positions"], 0)
        self.assertEqual(self.store.db.execute("SELECT COUNT(*) FROM exchange_orders").fetchone()[0], 1)

        self.executor.on_quotes([self.quote("99.9", "100", 1_005_000)], now_ms=1_005_000)
        self.assertEqual(self.store.health()["open_positions"], 1)
        self.assertEqual(self.store.db.execute("SELECT COUNT(*) FROM exchange_fills").fetchone()[0], 1)
        self.executor = ExchangePortfolioExecutor(self.store, self.exchange, mode="demo")
        self.assertTrue(self.executor.reconcile())
        self.assertEqual(self.store.health()["open_positions"], 1)
        self.assertEqual(self.store.db.execute("SELECT COUNT(*) FROM exchange_fills").fetchone()[0], 1)

    def test_lost_submit_response_is_recovered_by_client_order_id(self) -> None:
        self.exchange.lose_submit_response = True
        self.executor.on_quotes([self.quote("99.9", "100", 1_000_000)], now_ms=1_000_000)
        row = self.store.db.execute("SELECT status,order_id FROM exchange_orders").fetchone()
        self.assertEqual(row["status"], "uncertain")
        self.assertIsNone(row["order_id"])
        self.assertTrue(self.executor.reconcile())
        row = self.store.db.execute("SELECT status,order_id FROM exchange_orders").fetchone()
        self.assertEqual(row["order_id"], "order-1")
        self.assertEqual(self.store.health()["open_positions"], 1)
        self.assertEqual(self.store.db.execute("SELECT COUNT(*) FROM exchange_fills").fetchone()[0], 1)

    def test_manual_close_updates_pnl_only_after_exchange_fill(self) -> None:
        self.executor.on_quotes([self.quote("99.9", "100", 1_000_000)], now_ms=1_000_000)
        self.executor.on_quotes([self.quote("99.9", "100", 1_005_000)], now_ms=1_005_000)
        self.executor.set_controls(entries_paused=True, close_positions=True)
        self.executor.on_quotes([self.quote("101", "101.1", 1_010_000)], now_ms=1_010_000)
        self.assertEqual(self.store.health()["open_positions"], 1)
        self.assertEqual(self.store.health()["trades"], 0)
        self.executor.on_quotes([self.quote("101", "101.1", 1_015_000)], now_ms=1_015_000)
        self.assertEqual(self.store.health()["open_positions"], 0)
        self.assertEqual(self.store.health()["trades"], 1)
        self.assertGreater(Decimal(self.store.account()["realized_pnl"]), 0)

    def test_hedge_pair_is_sent_sequentially_and_both_legs_are_fill_backed(self) -> None:
        self.store.close()
        hedge_temp = tempfile.TemporaryDirectory()
        try:
            store = SQLiteStateStore(Path(hedge_temp.name) / "hedge.sqlite")
            payload = release()
            payload["release_id"] = "exchange-hedge"
            payload["market"]["symbols"] = ["BTCUSDT", "ETHUSDT"]
            store.add_portfolio_strategy(payload, kind="hedge", name="BTC / ETH")
            exchange = FakeExchange()
            executor = ExchangePortfolioExecutor(store, exchange, mode="demo")
            btc = self.quote("99.9", "100", 1_000_000)
            eth = Quote("ETHUSDT", Decimal("49.9"), Decimal("50"), Decimal("49.95"), 1_000_000)
            executor.on_quotes([btc, eth], now_ms=1_000_000)
            self.assertEqual(len(exchange.orders), 1)
            self.assertEqual(store.health()["open_positions"], 0)
            btc2 = self.quote("99.9", "100", 1_005_000)
            eth2 = Quote("ETHUSDT", Decimal("49.9"), Decimal("50"), Decimal("49.95"), 1_005_000)
            executor.on_quotes([btc2, eth2], now_ms=1_005_000)
            self.assertEqual(len(exchange.orders), 2)
            self.assertEqual(store.health()["open_positions"], 1)
            btc3 = self.quote("99.9", "100", 1_010_000)
            eth3 = Quote("ETHUSDT", Decimal("49.9"), Decimal("50"), Decimal("49.95"), 1_010_000)
            executor.on_quotes([btc3, eth3], now_ms=1_010_000)
            legs = store.db.execute("SELECT symbol,leg,side FROM positions ORDER BY leg").fetchall()
            self.assertEqual([(row["symbol"], row["leg"], row["side"]) for row in legs], [("BTCUSDT", "long", "long"), ("ETHUSDT", "short", "short")])
            store.close()
        finally:
            self.temp.cleanup()
            hedge_temp.cleanup()


if __name__ == "__main__":
    unittest.main()
