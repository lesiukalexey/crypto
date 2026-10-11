from __future__ import annotations

import base64
import hashlib
import hmac
import unittest
from decimal import Decimal
from urllib.parse import urlsplit

from autotrading.bitget_demo import (
    API_BASE,
    BitgetAPIError,
    BitgetDemoAdapter,
    ExchangeStateMismatch,
)
from autotrading.contracts import OrderRequest, Position


class FakeBitget:
    def __init__(self) -> None:
        self.calls: list[tuple[str, str, dict[str, str], str | None, float]] = []
        self.positions: list[dict] = []
        self.orders: dict[str, dict] = {}
        self.account_mode = "one_way_mode"
        self.margin_mode = "crossed"
        self.fail_positions = False
        self.null_empty_pending = False
        self.missing_order_code = "43001"
        self.demo_max_missing = False

    def __call__(self, method, url, headers, body, timeout):
        self.calls.append((method, url, dict(headers), body, timeout))
        path = urlsplit(url).path
        if path.endswith("/account/account"):
            return {"code": "00000", "data": {"posMode": self.account_mode, "marginMode": self.margin_mode}}
        if path.endswith("/position/all-position"):
            if self.fail_positions:
                raise BitgetAPIError("network failure")
            return {"code": "00000", "data": self.positions}
        if path.endswith("/market/contracts"):
            return {"code": "00000", "data": [{
                "symbol": "BTCUSDT", "symbolStatus": "normal", "symbolType": "perpetual",
                "supportMarginCoins": ["USDT"], "sizeMultiplier": "0.001",
                "minTradeNum": "0.001", "maxMarketOrderQty": "" if self.demo_max_missing and headers.get("paptrading") == "1" else "100",
            }]}
        if path.endswith("/order/orders-pending"):
            from urllib.parse import parse_qs
            status = parse_qs(urlsplit(url).query).get("status", [""])[0]
            rows = [row for row in self.orders.values() if row.get("state") == status]
            return {"code": "00000", "data": {"entrustedList": None if self.null_empty_pending and not rows else rows}}
        if path.endswith("/order/detail"):
            client_oid = dict(kv.split("=", 1) for kv in urlsplit(url).query.split("&")).get("clientOid")
            existing = self.orders.get(client_oid)
            return {"code": "00000", "data": existing} if existing else {"code": self.missing_order_code, "data": None}
        if path.endswith("/order/place-order"):
            import json
            payload = json.loads(body or "{}")
            order = {"orderId": "order-1", "clientOid": payload["clientOid"], "state": "live", "symbol": payload["symbol"]}
            self.orders[payload["clientOid"]] = order
            return {"code": "00000", "data": order}
        if path.endswith("/order/fills"):
            return {"code": "00000", "data": {"fillList": [{"tradeId": "fill-1", "baseVolume": "0.1"}]}}
        raise AssertionError(f"Unexpected Bitget request: {method} {url}")


def make_adapter(fake: FakeBitget) -> BitgetDemoAdapter:
    return BitgetDemoAdapter(
        ["BTCUSDT"], api_key="demo-key", secret="demo-secret", passphrase="demo-pass",
        transport=fake, clock_ms=lambda: 1_700_000_000_000,
    )


class BitgetDemoAdapterTests(unittest.TestCase):
    def test_requires_credentials_and_has_no_production_url_override(self) -> None:
        with self.assertRaisesRegex(ValueError, "protected executor environment"):
            BitgetDemoAdapter(["BTCUSDT"], api_key="", secret="", passphrase="", transport=FakeBitget())
        fake = FakeBitget()
        adapter = make_adapter(fake)
        self.assertEqual(adapter.mode, "demo")
        self.assertEqual(API_BASE, "https://api.bitget.com")
        with self.assertRaisesRegex(ValueError, "uppercase symbols"):
            BitgetDemoAdapter(["btc-usdt"], api_key="a", secret="b", passphrase="c", transport=fake)

    def test_reconciles_account_and_positions_before_order_and_sets_demo_header(self) -> None:
        fake = FakeBitget()
        adapter = make_adapter(fake)
        with self.assertRaises(ExchangeStateMismatch):
            adapter.submit(OrderRequest("BTCUSDT", "buy", Decimal("0.01"), "entry-1"))
        result = adapter.reconcile([])
        self.assertTrue(result.matched)
        self.assertEqual(len(fake.calls), 4)
        headers = fake.calls[0][2]
        self.assertEqual(headers["paptrading"], "1")
        request_path = urlsplit(fake.calls[0][1]).path + "?" + urlsplit(fake.calls[0][1]).query
        expected = base64.b64encode(hmac.new(b"demo-secret", ("1700000000000GET" + request_path).encode(), hashlib.sha256).digest()).decode()
        self.assertEqual(headers["ACCESS-SIGN"], expected)

        order = OrderRequest("BTCUSDT", "buy", Decimal("0.01"), "entry-1")
        submitted = adapter.submit(order)
        self.assertEqual(submitted.order_id, "order-1")
        body = fake.calls[-1][3]
        self.assertIn('"clientOid":"entry-1"', body or "")
        self.assertIn('"orderType":"market"', body or "")

        # A retry with the same deterministic client ID queries first and does not POST again.
        self.assertTrue(adapter.reconcile([], pending_orders=[("BTCUSDT", "entry-1")]).matched)
        calls_before = len(fake.calls)
        retried = adapter.submit(order)
        self.assertEqual(retried.order_id, "order-1")
        self.assertEqual(len(fake.calls), calls_before + 1)
        self.assertTrue(fake.calls[-1][1].endswith("/order/detail?clientOid=entry-1&productType=USDT-FUTURES&symbol=BTCUSDT"))

    def test_reconciles_when_bitget_returns_null_for_empty_pending_orders(self) -> None:
        fake = FakeBitget()
        fake.null_empty_pending = True
        result = make_adapter(fake).reconcile([])
        self.assertTrue(result.matched)
        self.assertEqual(result.orders, ())
        self.assertEqual(result.fills, ())

    def test_missing_order_lookup_accepts_current_bitget_classic_error_code(self) -> None:
        fake = FakeBitget()
        fake.missing_order_code = "40109"
        adapter = make_adapter(fake)
        adapter.reconcile([])
        self.assertIsNone(adapter.query_order("BTCUSDT", client_order_id="missing-1"))

    def test_demo_contracts_missing_market_cap_uses_public_contract_limit(self) -> None:
        fake = FakeBitget()
        fake.demo_max_missing = True
        adapter = make_adapter(fake)
        adapter.reconcile([])
        with self.assertRaisesRegex(ValueError, "size rules"):
            adapter.submit(OrderRequest("BTCUSDT", "buy", Decimal("101"), "entry-cap"))
        public_lookup = next(call for call in fake.calls if call[1].endswith("/market/contracts?productType=USDT-FUTURES&symbol=BTCUSDT") and "paptrading" not in call[2])
        self.assertEqual(public_lookup[2], {"Accept": "application/json"})

    def test_partial_fills_and_reduce_only_close_are_supported(self) -> None:
        fake = FakeBitget()
        adapter = make_adapter(fake)
        adapter.reconcile([])
        adapter.submit(OrderRequest("BTCUSDT", "sell", Decimal("0.02"), "close-1", reduce_only=True))
        self.assertIn('"reduceOnly":"YES"', fake.calls[-1][3] or "")
        self.assertEqual(adapter.fills("BTCUSDT", "order-1")[0]["baseVolume"], "0.1")

    def test_reconciliation_recovers_pending_order_and_its_fill_ledger(self) -> None:
        fake = FakeBitget()
        fake.orders["entry-7"] = {"orderId": "order-7", "clientOid": "entry-7", "state": "partially_filled", "baseVolume": "0.1", "symbol": "BTCUSDT"}
        adapter = make_adapter(fake)
        result = adapter.reconcile([], pending_orders=[("BTCUSDT", "entry-7")])
        self.assertTrue(result.matched)
        self.assertEqual(result.orders[0]["state"], "partially_filled")
        self.assertEqual(result.fills[0]["tradeId"], "fill-1")

    def test_untracked_exchange_pending_order_blocks_new_orders(self) -> None:
        fake = FakeBitget()
        fake.orders["manual-1"] = {"orderId": "order-manual", "clientOid": "manual-1", "state": "live", "symbol": "BTCUSDT"}
        adapter = make_adapter(fake)
        result = adapter.reconcile([])
        self.assertFalse(result.matched)
        self.assertTrue(any("untracked exchange pending order" in item for item in result.discrepancies))
        self.assertEqual(len(result.orders), 1)
        self.assertEqual(result.orders[0]["clientOid"], "manual-1")
        with self.assertRaises(ExchangeStateMismatch):
            adapter.submit(OrderRequest("BTCUSDT", "buy", Decimal("0.01"), "entry-8"))

    def test_position_mismatch_or_unknown_account_state_blocks_ordering(self) -> None:
        fake = FakeBitget()
        adapter = make_adapter(fake)
        fake.positions = [{"symbol": "BTCUSDT", "holdSide": "long", "total": "0.1", "openPriceAvg": "100"}]
        result = adapter.reconcile([])
        self.assertFalse(result.matched)
        with self.assertRaises(ExchangeStateMismatch):
            adapter.submit(OrderRequest("BTCUSDT", "buy", Decimal("0.01"), "entry-2"))

        self.assertFalse(adapter.reconcile([Position("BTCUSDT", "long", Decimal("0.1"), Decimal("99"))]).matched)
        self.assertTrue(adapter.reconcile([Position("BTCUSDT", "long", Decimal("0.1"), Decimal("100"))]).matched)

        fake.positions = []
        fake.account_mode = "hedge_mode"
        with self.assertRaisesRegex(ExchangeStateMismatch, "one-way and crossed"):
            adapter.reconcile([])
        with self.assertRaises(ExchangeStateMismatch):
            adapter.submit(OrderRequest("BTCUSDT", "buy", Decimal("0.01"), "entry-3"))

    def test_failed_reconciliation_requires_reconnect_and_fresh_success(self) -> None:
        fake = FakeBitget()
        adapter = make_adapter(fake)
        self.assertTrue(adapter.reconcile([]).matched)
        fake.fail_positions = True
        with self.assertRaises(BitgetAPIError):
            adapter.reconcile([])
        with self.assertRaises(ExchangeStateMismatch):
            adapter.submit(OrderRequest("BTCUSDT", "buy", Decimal("0.01"), "entry-4"))
        fake.fail_positions = False
        self.assertTrue(adapter.reconcile([]).matched)

    def test_symbol_allowlist_and_order_id_constraints_are_enforced(self) -> None:
        fake = FakeBitget()
        adapter = make_adapter(fake)
        adapter.reconcile([])
        with self.assertRaisesRegex(ValueError, "allowlist"):
            adapter.submit(OrderRequest("ETHUSDT", "buy", Decimal("0.01"), "entry-5"))
        with self.assertRaisesRegex(ValueError, "safe ASCII"):
            adapter.submit(OrderRequest("BTCUSDT", "buy", Decimal("0.01"), "id.with.dots"))
        with self.assertRaisesRegex(ValueError, "size rules"):
            adapter.submit(OrderRequest("BTCUSDT", "buy", Decimal("0.0101"), "entry-6"))


if __name__ == "__main__":
    unittest.main()
