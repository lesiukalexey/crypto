"""Isolated Bitget Classic USDT-futures demo execution adapter.

This adapter always uses Bitget's documented demo-trading header. It has no
configurable base URL, so a release or environment variable cannot redirect
private credentials or orders to another host.
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import re
import time
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation, ROUND_DOWN
from typing import Any, Callable, Mapping, Sequence
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import HTTPRedirectHandler, Request, build_opener

from .contracts import ExecutionResult, OrderRequest, Position


API_BASE = "https://api.bitget.com"
PRODUCT_TYPE = "USDT-FUTURES"
MARGIN_COIN = "USDT"


class BitgetAPIError(RuntimeError):
    pass


class ExchangeStateMismatch(RuntimeError):
    pass


def _safe_api_message(response: object) -> str:
    if not isinstance(response, dict) or not isinstance(response.get("msg"), str):
        return ""
    message = " ".join(response["msg"].split())
    return message[:200] if message and all(ord(character) >= 32 for character in message) else ""


@dataclass(frozen=True)
class Reconciliation:
    matched: bool
    discrepancies: tuple[str, ...]
    positions: tuple[Position, ...]
    orders: tuple[dict[str, Any], ...] = ()
    fills: tuple[dict[str, Any], ...] = ()


Transport = Callable[[str, str, Mapping[str, str], str | None, float], object]


def _decimal(value: object, name: str, *, allow_zero: bool = False) -> Decimal:
    try:
        result = Decimal(str(value))
    except (InvalidOperation, ValueError) as exc:
        raise BitgetAPIError(f"Bitget returned an invalid {name}") from exc
    if not result.is_finite() or result < 0 or (not allow_zero and result == 0):
        raise BitgetAPIError(f"Bitget returned an invalid {name}")
    return result


def _default_transport(method: str, url: str, headers: Mapping[str, str], body: str | None, timeout: float) -> object:
    request = Request(url, data=body.encode("utf-8") if body is not None else None, headers=dict(headers), method=method)
    try:
        class RejectRedirects(HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, response_headers, new_url):
                return None

        with build_opener(RejectRedirects).open(request, timeout=timeout) as response:
            return json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        # Avoid including response headers or request credentials in logs/errors.
        try:
            payload = json.loads(exc.read().decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            raise BitgetAPIError(f"Bitget API returned HTTP {exc.code}") from exc
        # Bitget sends structured API errors with HTTP 4xx statuses. Preserve
        # only the parsed response so _request can allow narrowly-defined
        # cases such as a missing client order ID without exposing the body.
        if isinstance(payload, dict) and isinstance(payload.get("code"), str):
            return payload
        raise BitgetAPIError(f"Bitget API returned HTTP {exc.code}") from exc
    except (URLError, TimeoutError, json.JSONDecodeError) as exc:
        raise BitgetAPIError(f"Bitget API request failed: {type(exc).__name__}") from exc


class BitgetDemoAdapter:
    """Demo-only adapter for Bitget Classic linear USDT futures.

    Construction requires explicit credentials from the executor environment.
    Call :meth:`reconcile` successfully before submitting any order. A failed
    reconciliation leaves the adapter fail-closed until a later successful one.
    """

    mode = "demo"

    def __init__(
        self,
        symbols: Sequence[str],
        *,
        api_key: str | None = None,
        secret: str | None = None,
        passphrase: str | None = None,
        timeout_seconds: float = 10,
        transport: Transport = _default_transport,
        clock_ms: Callable[[], int] | None = None,
    ) -> None:
        self.api_key = api_key if api_key is not None else os.getenv("BITGET_DEMO_API_KEY", "")
        self.secret = secret if secret is not None else os.getenv("BITGET_DEMO_API_SECRET", "")
        self.passphrase = passphrase if passphrase is not None else os.getenv("BITGET_DEMO_API_PASSPHRASE", "")
        if not all((self.api_key, self.secret, self.passphrase)):
            raise ValueError("Bitget demo credentials must be supplied through protected executor environment variables")
        if len(set(symbols)) != len(symbols) or not symbols or any(not isinstance(s, str) or not s.isascii() or not s.isalnum() or s.upper() != s for s in symbols):
            raise ValueError("Demo adapter requires a non-empty list of unique uppercase symbols")
        if timeout_seconds <= 0:
            raise ValueError("timeout_seconds must be positive")
        self.symbols = frozenset(symbols)
        self.timeout_seconds = timeout_seconds
        self._transport = transport
        self._clock_ms = clock_ms or (lambda: int(time.time() * 1000))
        self._reconciled = False
        self._last_reconciliation: Reconciliation | None = None
        self._open_order_ids: set[str] = set()

    def _request(self, method: str, path: str, params: Mapping[str, str] | None = None, payload: Mapping[str, Any] | None = None, *, allow_error_codes: frozenset[str] = frozenset()) -> dict[str, Any]:
        query = urlencode(sorted((params or {}).items()))
        request_path = f"{path}?{query}" if query else path
        body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False) if payload is not None else None
        timestamp = str(self._clock_ms())
        prehash = timestamp + method.upper() + request_path + (body or "")
        signature = base64.b64encode(hmac.new(self.secret.encode(), prehash.encode(), hashlib.sha256).digest()).decode()
        headers = {
            "ACCESS-KEY": self.api_key,
            "ACCESS-SIGN": signature,
            "ACCESS-PASSPHRASE": self.passphrase,
            "ACCESS-TIMESTAMP": timestamp,
            "paptrading": "1",
            "Content-Type": "application/json",
            "Accept": "application/json",
        }
        try:
            response = self._transport(method.upper(), API_BASE + request_path, headers, body, self.timeout_seconds)
        except Exception:
            self._reconciled = False
            raise
        if isinstance(response, dict) and response.get("code") in allow_error_codes:
            return {"code": "00000", "data": None}
        if not isinstance(response, dict) or response.get("code") != "00000":
            self._reconciled = False
            code = response.get("code", "invalid") if isinstance(response, dict) else "invalid"
            detail = _safe_api_message(response)
            suffix = f": {detail}" if detail else ""
            raise BitgetAPIError(f"Bitget demo API rejected {method.upper()} {path} (code {code}){suffix}")
        data = response.get("data")
        if not isinstance(data, (dict, list, str, type(None))):
            self._reconciled = False
            raise BitgetAPIError("Bitget demo API returned malformed data")
        return response

    def _check_symbol(self, symbol: str) -> None:
        if symbol not in self.symbols:
            raise ValueError("Order symbol is outside the configured demo allowlist")

    def query_order(self, symbol: str, *, client_order_id: str | None = None, order_id: str | None = None) -> dict[str, Any] | None:
        self._check_symbol(symbol)
        if not client_order_id and not order_id:
            raise ValueError("client_order_id or order_id is required")
        params = {"productType": PRODUCT_TYPE, "symbol": symbol}
        params["clientOid" if client_order_id else "orderId"] = client_order_id or order_id or ""
        try:
            data = self._request("GET", "/api/v2/mix/order/detail", params=params, allow_error_codes=frozenset({"43001", "40109"})).get("data")
        except BitgetAPIError:
            # Lookup errors other than Bitget's explicit order-not-found code
            # are uncertainty, never permission to place a replacement.
            raise
        if data is None:
            return None
        if not isinstance(data, dict) or not data.get("orderId"):
            raise BitgetAPIError("Bitget returned an ambiguous demo order lookup")
        return data

    def submit(self, order: OrderRequest) -> ExecutionResult:
        self._check_symbol(order.symbol)
        if not self._reconciled:
            raise ExchangeStateMismatch("A successful exchange reconciliation is required before demo orders")
        if not re.fullmatch(r"[0-9A-Za-z_:#\-+]{1,32}", order.client_order_id):
            raise ValueError("Demo client order ID must use 1–32 safe ASCII characters")
        # Resolve uncertain previous submissions by deterministic client ID
        # before attempting a POST. A failed lookup fails closed.
        existing = self.query_order(order.symbol, client_order_id=order.client_order_id)
        if existing is not None:
            return ExecutionResult(True, str(existing["orderId"]), str(existing.get("state", "known")), "Existing client order ID found; not resubmitted")
        if self._open_order_ids:
            raise ExchangeStateMismatch("An exchange order is still pending; reconcile or resolve it before another order")
        self._validate_contract_size(order.symbol, order.quantity)
        payload: dict[str, Any] = {
            "symbol": order.symbol,
            "productType": PRODUCT_TYPE,
            "marginMode": "crossed",
            "marginCoin": MARGIN_COIN,
            "size": format(order.quantity, "f"),
            "side": order.side,
            "orderType": "market",
            "clientOid": order.client_order_id,
        }
        if order.reduce_only:
            payload["reduceOnly"] = "YES"
        data = self._request("POST", "/api/v2/mix/order/place-order", payload=payload).get("data")
        if not isinstance(data, dict) or not data.get("orderId"):
            self._reconciled = False
            raise BitgetAPIError("Bitget accepted no identifiable demo order")
        # Exchange state may have changed. Require the caller to persist its
        # order and reconcile again before another new order can be submitted.
        self._reconciled = False
        self._open_order_ids.add(str(data["orderId"]))
        return ExecutionResult(True, str(data["orderId"]), "submitted")

    def _validate_contract_size(self, symbol: str, quantity: Decimal) -> None:
        response = self._request("GET", "/api/v2/mix/market/contracts", params={"productType": PRODUCT_TYPE, "symbol": symbol})
        rows = response.get("data")
        if not isinstance(rows, list):
            raise BitgetAPIError("Bitget returned malformed demo contract metadata")
        contract = next((row for row in rows if isinstance(row, dict) and row.get("symbol") == symbol), None)
        if contract is None or contract.get("symbolStatus") != "normal" or contract.get("symbolType") != "perpetual":
            raise ExchangeStateMismatch(f"Contract {symbol} is unavailable for futures trading")
        margin_coins = contract.get("supportMarginCoins")
        if not isinstance(margin_coins, list) or MARGIN_COIN not in margin_coins:
            raise ExchangeStateMismatch(f"Contract {symbol} does not support USDT margin")
        step = _decimal(contract.get("sizeMultiplier"), "contract size multiplier")
        minimum = _decimal(contract.get("minTradeNum"), "minimum contract quantity")
        maximum_value = contract.get("maxMarketOrderQty")
        if not maximum_value:
            # The Demo contracts endpoint currently omits the market cap.
            # Resolve that public, non-authenticated contract constraint
            # without forwarding demo credentials or the paptrading header.
            query = urlencode(sorted({"productType": PRODUCT_TYPE, "symbol": symbol}.items()))
            public_response = self._transport(
                "GET", f"{API_BASE}/api/v2/mix/market/contracts?{query}",
                {"Accept": "application/json"}, None, self.timeout_seconds,
            )
            public_rows = public_response.get("data") if isinstance(public_response, dict) and public_response.get("code") == "00000" else None
            public_contract = next((row for row in public_rows if isinstance(row, dict) and row.get("symbol") == symbol), None) if isinstance(public_rows, list) else None
            maximum_value = public_contract.get("maxMarketOrderQty") if public_contract else None
        maximum = _decimal(maximum_value, "maximum market order quantity")
        if quantity < minimum or quantity > maximum or quantity % step != 0:
            raise ValueError(f"Quantity for {symbol} violates Bitget contract size rules")

    def normalize_quantity(self, symbol: str, quantity: Decimal) -> Decimal:
        """Round a proposed base quantity down to contract step and validate it."""
        self._check_symbol(symbol)
        response = self._request("GET", "/api/v2/mix/market/contracts", params={"productType": PRODUCT_TYPE, "symbol": symbol})
        rows = response.get("data")
        contract = next((row for row in rows if isinstance(row, dict) and row.get("symbol") == symbol), None) if isinstance(rows, list) else None
        if contract is None:
            raise BitgetAPIError("Bitget returned no demo contract metadata")
        step = _decimal(contract.get("sizeMultiplier"), "contract size multiplier")
        minimum = _decimal(contract.get("minTradeNum"), "minimum contract quantity")
        maximum_value = contract.get("maxMarketOrderQty")
        if not maximum_value:
            query = urlencode(sorted({"productType": PRODUCT_TYPE, "symbol": symbol}.items()))
            public_response = self._transport("GET", f"{API_BASE}/api/v2/mix/market/contracts?{query}", {"Accept": "application/json"}, None, self.timeout_seconds)
            public_rows = public_response.get("data") if isinstance(public_response, dict) and public_response.get("code") == "00000" else None
            public_contract = next((row for row in public_rows if isinstance(row, dict) and row.get("symbol") == symbol), None) if isinstance(public_rows, list) else None
            maximum_value = public_contract.get("maxMarketOrderQty") if public_contract else None
        maximum = _decimal(maximum_value, "maximum market order quantity")
        normalized = (quantity / step).to_integral_value(rounding=ROUND_DOWN) * step
        if normalized < minimum or normalized > maximum:
            raise ValueError(f"Quantity for {symbol} violates Bitget contract size rules")
        if normalized != quantity:
            self._validate_contract_size(symbol, normalized)
        return normalized

    def fills(self, symbol: str, order_id: str) -> tuple[dict[str, Any], ...]:
        self._check_symbol(symbol)
        data = self._request("GET", "/api/v2/mix/order/fills", params={"orderId": order_id, "productType": PRODUCT_TYPE, "symbol": symbol}).get("data")
        rows = data.get("fillList") if isinstance(data, dict) else None
        if not isinstance(rows, list):
            raise BitgetAPIError("Bitget returned malformed fill history")
        if any(not isinstance(row, dict) for row in rows):
            raise BitgetAPIError("Bitget returned a malformed fill")
        return tuple(rows)

    def positions(self) -> tuple[Position, ...]:
        response = self._request("GET", "/api/v2/mix/position/all-position", params={"productType": PRODUCT_TYPE, "marginCoin": MARGIN_COIN})
        data = response.get("data")
        if not isinstance(data, list):
            raise BitgetAPIError("Bitget returned malformed positions")
        result: list[Position] = []
        for row in data:
            if not isinstance(row, dict):
                raise BitgetAPIError("Bitget returned a malformed position")
            symbol = row.get("symbol")
            side = row.get("holdSide")
            quantity = _decimal(row.get("total", "0"), "position quantity", allow_zero=True)
            if quantity == 0:
                continue
            if symbol not in self.symbols or side not in {"long", "short"}:
                # Preserve visibility of unexpected positions so reconcile()
                # can block new orders instead of silently filtering them.
                raise ExchangeStateMismatch(f"Exchange contains an unmanaged position: {symbol}/{side}")
            result.append(Position(symbol, side, quantity, _decimal(row.get("openPriceAvg"), "average entry price")))
        return tuple(sorted(result, key=lambda p: (p.symbol, p.side)))

    def reconcile(
        self,
        expected_positions: Sequence[Position],
        pending_orders: Sequence[tuple[str, str]] = (),
    ) -> Reconciliation:
        """Compare local positions and pending client IDs with Bitget.

        ``pending_orders`` contains ``(symbol, client_order_id)`` tuples from
        durable local state. Fills are returned for caller-side idempotent
        ingestion using Bitget's stable ``tradeId``.
        """
        self._reconciled = False
        try:
            for symbol in sorted(self.symbols):
                account = self._request("GET", "/api/v2/mix/account/account", params={
                    "marginCoin": MARGIN_COIN,
                    "productType": PRODUCT_TYPE,
                    "symbol": symbol,
                }).get("data")
                if not isinstance(account, dict) or account.get("posMode") != "one_way_mode" or account.get("marginMode") != "crossed":
                    raise ExchangeStateMismatch(f"Account configuration for {symbol} must be one-way and crossed margin")
            remote = self.positions()
            exchange_pending: dict[str, dict[str, Any]] = {}
            for status in ("live", "partially_filled"):
                pending_data = self._request("GET", "/api/v2/mix/order/orders-pending", params={
                    "productType": PRODUCT_TYPE,
                    "status": status,
                    "limit": "100",
                }).get("data")
                pending_rows = pending_data.get("entrustedList") if isinstance(pending_data, dict) else None
                # Bitget returns null for an empty order list on the demo API,
                # although the documented response type is an array.
                if pending_rows is None:
                    pending_rows = []
                if not isinstance(pending_rows, list):
                    raise BitgetAPIError("Bitget returned malformed pending orders")
                for row in pending_rows:
                    if not isinstance(row, dict) or not row.get("clientOid") or not row.get("orderId") or row.get("symbol") not in self.symbols:
                        raise ExchangeStateMismatch("Bitget returned an unmanaged or malformed pending demo order")
                    exchange_pending[str(row["clientOid"])] = row
            remote_orders_by_client_id: dict[str, dict[str, Any]] = {
                client_id: row for client_id, row in exchange_pending.items()
            }
            remote_fills: list[dict[str, Any]] = []
            order_discrepancies: list[str] = []
            for symbol, client_order_id in pending_orders:
                order = self.query_order(symbol, client_order_id=client_order_id)
                if order is None:
                    order_discrepancies.append(f"local pending order missing on exchange {symbol}/{client_order_id}")
                    continue
                remote_orders_by_client_id[client_order_id] = order
                order_id = order.get("orderId")
                if order_id:
                    remote_fills.extend(self.fills(symbol, str(order_id)))
            local_pending_ids = {client_order_id for _, client_order_id in pending_orders}
            order_discrepancies.extend(
                f"untracked exchange pending order {row.get('symbol')}/{client_id}"
                for client_id, row in exchange_pending.items()
                if client_id not in local_pending_ids
            )
        except Exception:
            self._last_reconciliation = None
            raise
        expected_map = {(p.symbol, p.side): (p.quantity, p.average_entry_price) for p in expected_positions}
        remote_map = {(p.symbol, p.side): (p.quantity, p.average_entry_price) for p in remote}
        discrepancies: list[str] = list(order_discrepancies)
        for key in sorted(expected_map.keys() | remote_map.keys()):
            expected = expected_map.get(key)
            actual = remote_map.get(key)
            if expected is None:
                discrepancies.append(f"untracked exchange position {key[0]}/{key[1]}")
            elif actual is None:
                discrepancies.append(f"local position missing on exchange {key[0]}/{key[1]}")
            elif expected != actual:
                discrepancies.append(f"position mismatch {key[0]}/{key[1]}: local={expected}, exchange={actual}")
        result = Reconciliation(not discrepancies, tuple(discrepancies), remote, tuple(remote_orders_by_client_id.values()), tuple(remote_fills))
        self._last_reconciliation = result
        self._reconciled = result.matched
        self._open_order_ids = {str(row["orderId"]) for row in exchange_pending.values()}
        return result
