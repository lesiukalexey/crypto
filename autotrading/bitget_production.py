"""Fail-closed Bitget production adapter.

The adapter is a separately gated building block. It does not read Demo
credentials, and live order submission is disabled unless both an explicit
constructor flag and a process-level operator switch are present.
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
from decimal import Decimal, ROUND_DOWN, ROUND_UP
from typing import Any, Callable, Mapping
from urllib.parse import urlencode

from .bitget_demo import API_BASE, MARGIN_COIN, PRODUCT_TYPE, BitgetAPIError, BitgetDemoAdapter, ExchangeStateMismatch, Transport, _decimal, _default_transport, _safe_api_message
from .contracts import ExecutionResult, OrderRequest, Position


LIVE_ORDER_ACKNOWLEDGEMENT = "I_ACKNOWLEDGE_LIVE_ORDERS"
LIVE_SWITCH_ENV = "BITGET_LIVE_TRADING_ENABLED"


class LiveTradingDisabled(RuntimeError):
    """Raised when live execution has not been explicitly enabled twice."""


class LiveRiskLimitExceeded(RuntimeError):
    """Raised when an order exceeds its configured live risk boundary."""


@dataclass(frozen=True)
class LiveRiskSnapshot:
    """Fresh daily net PnL (realized plus unrealized) from durable runtime state."""

    daily_pnl_usdt: Decimal
    captured_at_ms: int


@dataclass(frozen=True)
class LiveRiskLimits:
    max_order_notional_usdt: Decimal
    max_total_exposure_usdt: Decimal
    max_daily_loss_usdt: Decimal
    max_open_positions: int


class BitgetProductionAdapter(BitgetDemoAdapter):
    """One-way/crossed USDT futures adapter with a disabled-by-default order path.

    Risk settings use the validated strategy release ``risk`` fields. A caller
    that enables trading must also supply a trusted, fresh daily PnL provider
    backed by its durable live ledger. No live order is sent by construction.
    """

    mode = "live"

    def __init__(
        self,
        symbols: list[str] | tuple[str, ...],
        risk_limits: Mapping[str, Any],
        daily_risk_snapshot: Callable[[], LiveRiskSnapshot] | None = None,
        *,
        api_key: str | None = None,
        secret: str | None = None,
        passphrase: str | None = None,
        allow_live_orders: bool = False,
        live_acknowledgement: str | None = None,
        max_slippage_bps: int,
        timeout_seconds: float = 10,
        transport: Transport = _default_transport,
        clock_ms: Callable[[], int] | None = None,
    ) -> None:
        live_key = api_key if api_key is not None else os.getenv("BITGET_LIVE_API_KEY", "")
        live_secret = secret if secret is not None else os.getenv("BITGET_LIVE_API_SECRET", "")
        live_passphrase = passphrase if passphrase is not None else os.getenv("BITGET_LIVE_API_PASSPHRASE", "")
        if not all((live_key, live_secret, live_passphrase)):
            raise ValueError("Production credentials must use the separate BITGET_LIVE_API_* settings")
        if not isinstance(allow_live_orders, bool):
            raise ValueError("allow_live_orders must be a boolean")
        if allow_live_orders:
            if os.getenv(LIVE_SWITCH_ENV) != "1" or live_acknowledgement != LIVE_ORDER_ACKNOWLEDGEMENT:
                raise LiveTradingDisabled("Live orders require the process switch and explicit acknowledgement")
            if daily_risk_snapshot is None:
                raise ValueError("Enabled live execution requires a durable daily-risk snapshot provider")
        if isinstance(max_slippage_bps, bool) or not isinstance(max_slippage_bps, int) or not 0 <= max_slippage_bps <= 5000:
            raise ValueError("max_slippage_bps must be an integer from 0 to 5000")
        self.risk_limits = self._parse_risk_limits(risk_limits)
        self._daily_risk_snapshot = daily_risk_snapshot
        self._allow_live_orders = allow_live_orders
        self._live_acknowledgement = live_acknowledgement
        self._max_slippage_bps = max_slippage_bps
        super().__init__(
            symbols,
            api_key=live_key,
            secret=live_secret,
            passphrase=live_passphrase,
            timeout_seconds=timeout_seconds,
            transport=transport,
            clock_ms=clock_ms,
        )

    @staticmethod
    def _parse_risk_limits(limits: Mapping[str, Any]) -> LiveRiskLimits:
        expected = {"max_order_notional_usdt", "max_total_exposure_usdt", "max_daily_loss_usdt", "max_open_positions"}
        if not isinstance(limits, Mapping) or set(limits) != expected:
            raise ValueError("Live risk settings must match the validated strategy release fields")
        parsed = {
            key: _decimal(limits[key], key.replace("_", " "))
            for key in ("max_order_notional_usdt", "max_total_exposure_usdt", "max_daily_loss_usdt")
        }
        positions = limits["max_open_positions"]
        if isinstance(positions, bool) or not isinstance(positions, int) or positions < 1:
            raise ValueError("max_open_positions must be a positive integer")
        if parsed["max_order_notional_usdt"] > parsed["max_total_exposure_usdt"]:
            raise ValueError("max_order_notional_usdt cannot exceed max_total_exposure_usdt")
        return LiveRiskLimits(
            parsed["max_order_notional_usdt"], parsed["max_total_exposure_usdt"],
            parsed["max_daily_loss_usdt"], positions,
        )

    @property
    def allow_live_orders(self) -> bool:
        return self._allow_live_orders

    @property
    def max_slippage_bps(self) -> int:
        return self._max_slippage_bps

    def _require_live_enabled(self) -> None:
        if not self._allow_live_orders or os.getenv(LIVE_SWITCH_ENV) != "1" or self._live_acknowledgement != LIVE_ORDER_ACKNOWLEDGEMENT:
            raise LiveTradingDisabled("Live order submission is disabled by default")

    def _request(
        self,
        method: str,
        path: str,
        params: Mapping[str, str] | None = None,
        payload: Mapping[str, Any] | None = None,
        *,
        allow_error_codes: frozenset[str] = frozenset(),
    ) -> dict[str, Any]:
        """Sign a production request without ever attaching the Demo header."""
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
            raise BitgetAPIError(f"Bitget production API rejected {method.upper()} {path} (code {code}){suffix}")
        data = response.get("data")
        if not isinstance(data, (dict, list, str, type(None))):
            self._reconciled = False
            raise BitgetAPIError("Bitget production API returned malformed data")
        return response

    def _market_price(self, symbol: str) -> Decimal:
        data = self._request("GET", "/api/v2/mix/market/symbol-price", params={
            "productType": PRODUCT_TYPE,
            "symbol": symbol,
        }).get("data")
        if not isinstance(data, list):
            raise BitgetAPIError("Bitget returned malformed production price data")
        row = next((item for item in data if isinstance(item, dict) and item.get("symbol") == symbol), None)
        if row is None:
            raise BitgetAPIError(f"Bitget returned no current price for {symbol}")
        timestamp = _decimal(row.get("ts"), "market price timestamp")
        now_ms = self._clock_ms()
        if timestamp > now_ms + 5000 or now_ms - timestamp > 10_000:
            raise ExchangeStateMismatch(f"Market price for {symbol} is stale or outside the accepted clock window")
        mark = _decimal(row.get("markPrice"), "mark price")
        last = _decimal(row.get("price"), "last price")
        return max(mark, last)

    def _check_live_risk(self, order: OrderRequest) -> Decimal:
        if not self._reconciled or self._last_reconciliation is None or not self._last_reconciliation.matched:
            raise ExchangeStateMismatch("A successful production exchange reconciliation is required before live orders")
        if order.reduce_only:
            current = [position for position in self._last_reconciliation.positions if position.symbol == order.symbol]
            if len(current) != 1:
                raise LiveRiskLimitExceeded("A reduce-only order requires exactly one reconciled position")
            position = current[0]
            expected_side = "sell" if position.side == "long" else "buy"
            if order.side != expected_side or order.quantity > position.quantity:
                raise LiveRiskLimitExceeded("Reduce-only order side or size does not match the reconciled position")
            return self._market_price(order.symbol)

        if self._daily_risk_snapshot is None:
            raise LiveRiskLimitExceeded("No trusted daily-risk snapshot is available")
        try:
            snapshot = self._daily_risk_snapshot()
        except Exception as exc:
            self._reconciled = False
            raise LiveRiskLimitExceeded("Daily-risk snapshot provider failed") from exc
        if not isinstance(snapshot, LiveRiskSnapshot) or not isinstance(snapshot.daily_pnl_usdt, Decimal) or not snapshot.daily_pnl_usdt.is_finite():
            raise LiveRiskLimitExceeded("Daily-risk snapshot is missing or malformed")
        now_ms = self._clock_ms()
        if isinstance(snapshot.captured_at_ms, bool) or not isinstance(snapshot.captured_at_ms, int) or snapshot.captured_at_ms > now_ms + 5000 or now_ms - snapshot.captured_at_ms > 60_000:
            raise ExchangeStateMismatch("Daily-risk snapshot is stale or outside the accepted clock window")
        if snapshot.daily_pnl_usdt <= -self.risk_limits.max_daily_loss_usdt:
            raise LiveRiskLimitExceeded("Daily loss limit has been reached")

        positions = self._last_reconciliation.positions
        occupied_symbols = {position.symbol for position in positions}
        if order.symbol not in occupied_symbols and len(occupied_symbols) >= self.risk_limits.max_open_positions:
            raise LiveRiskLimitExceeded("Maximum number of open positions has been reached")
        exposure = Decimal(0)
        for position in positions:
            exposure += position.quantity * self._market_price(position.symbol) * (Decimal(1) + Decimal(self.max_slippage_bps) / Decimal(10_000))
        reference_price = self._market_price(order.symbol)
        order_price = self._limit_price(order.symbol, order.side, reference_price)
        order_notional = order.quantity * order_price
        if order_notional > self.risk_limits.max_order_notional_usdt:
            raise LiveRiskLimitExceeded("Order exceeds max_order_notional_usdt")
        if exposure + order_notional > self.risk_limits.max_total_exposure_usdt:
            raise LiveRiskLimitExceeded("Order would exceed max_total_exposure_usdt")
        return reference_price

    def _limit_price(self, symbol: str, side: str, reference_price: Decimal) -> Decimal:
        rows = self._request("GET", "/api/v2/mix/market/contracts", params={
            "productType": PRODUCT_TYPE,
            "symbol": symbol,
        }).get("data")
        contract = next((row for row in rows if isinstance(row, dict) and row.get("symbol") == symbol), None) if isinstance(rows, list) else None
        if contract is None:
            raise BitgetAPIError("Bitget returned no contract price precision")
        try:
            price_place = int(contract.get("pricePlace"))
            end_step = int(contract.get("priceEndStep"))
        except (TypeError, ValueError) as exc:
            raise BitgetAPIError("Bitget returned invalid contract price precision") from exc
        if price_place < 0 or price_place > 18 or end_step <= 0:
            raise BitgetAPIError("Bitget returned invalid contract price precision")
        tick = Decimal(end_step).scaleb(-price_place)
        slippage = Decimal(self.max_slippage_bps) / Decimal(10_000)
        bound = reference_price * (Decimal(1) + slippage if side == "buy" else Decimal(1) - slippage)
        rounding = ROUND_DOWN if side == "buy" else ROUND_UP
        price = (bound / tick).to_integral_value(rounding=rounding) * tick
        if price <= 0:
            raise LiveRiskLimitExceeded("Price guard produced an invalid limit price")
        return price

    def submit(self, order: OrderRequest) -> ExecutionResult:
        self._require_live_enabled()
        self._check_symbol(order.symbol)
        if not re.fullmatch(r"[0-9A-Za-z_:#\-+]{1,32}", order.client_order_id):
            raise ValueError("Live client order ID must use 1–32 safe ASCII characters")
        if not self._reconciled:
            raise ExchangeStateMismatch("A successful production exchange reconciliation is required before live orders")
        existing = self.query_order(order.symbol, client_order_id=order.client_order_id)
        if existing is not None:
            return ExecutionResult(True, str(existing["orderId"]), str(existing.get("state", "known")), "Existing client order ID found; not resubmitted")
        if self._open_order_ids:
            raise ExchangeStateMismatch("An exchange order is still pending; reconcile or resolve it before another order")
        self._validate_contract_size(order.symbol, order.quantity)
        reference_price = self._check_live_risk(order)
        limit_price = self._limit_price(order.symbol, order.side, reference_price)
        payload: dict[str, Any] = {
            "symbol": order.symbol,
            "productType": PRODUCT_TYPE,
            "marginMode": "crossed",
            "marginCoin": MARGIN_COIN,
            "size": format(order.quantity, "f"),
            "side": order.side,
            "orderType": "limit",
            "force": "ioc",
            "price": format(limit_price, "f"),
            "clientOid": order.client_order_id,
        }
        if order.reduce_only:
            payload["reduceOnly"] = "YES"
        data = self._request("POST", "/api/v2/mix/order/place-order", payload=payload).get("data")
        if not isinstance(data, dict) or not data.get("orderId"):
            self._reconciled = False
            raise BitgetAPIError("Bitget accepted no identifiable production order")
        self._reconciled = False
        self._open_order_ids.add(str(data["orderId"]))
        return ExecutionResult(True, str(data["orderId"]), "submitted")
