"""Public Bitget contract rules used to validate simulated market orders."""

from __future__ import annotations

import fcntl
import json
import os
import tempfile
import time
import urllib.parse
import urllib.request
from decimal import Decimal, InvalidOperation, ROUND_DOWN
from pathlib import Path
from typing import Any

CONTRACTS_URL = "https://api.bitget.com/api/v2/mix/market/contracts"
CACHE_TTL_SECONDS = 3600
CACHE_ROOT = Path.home() / ".ai" / "home" / ".local" / "bitget-contract-specs"
RULE_FIELDS = (
    "symbolStatus", "minTradeUSDT", "minTradeNum", "sizeMultiplier",
    "volumePlace", "pricePlace", "priceEndStep", "maxMarketOrderQty",
    "maxOrderQty", "minLever", "maxLever", "takerFeeRate",
)


def _decimal(value: Any, field: str) -> Decimal:
    try:
        result = Decimal(str(value))
    except (InvalidOperation, ValueError, TypeError) as exc:
        raise ValueError(f"Invalid Bitget contract field {field}") from exc
    if not result.is_finite():
        raise ValueError(f"Invalid Bitget contract field {field}")
    return result


def contract_cache_path(product_type: str) -> Path:
    safe_type = "".join(character for character in product_type.lower() if character.isalnum() or character == "-")
    return CACHE_ROOT / f"{safe_type}.json"


def load_contract_specs(product_type: str, force_refresh: bool = False) -> dict[str, dict[str, Any]]:
    """Load current public specs, refreshing an atomic local cache at most hourly."""
    path = contract_cache_path(product_type)
    path.parent.mkdir(parents=True, exist_ok=True)
    lock_path = path.with_suffix(path.suffix + ".lock")
    with lock_path.open("a", encoding="utf-8") as lock_file:
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX)
        cached: dict[str, Any] | None = None
        try:
            cached = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            pass
        if (
            not force_refresh and isinstance(cached, dict)
            and int(cached.get("fetched_at", 0)) >= int(time.time()) - CACHE_TTL_SECONDS
            and isinstance(cached.get("contracts"), dict)
        ):
            return cached["contracts"]

        query = urllib.parse.urlencode({"productType": product_type})
        request = urllib.request.Request(
            f"{CONTRACTS_URL}?{query}",
            headers={"User-Agent": "BitgetHistoricalReplay/1.0"},
        )
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                payload = json.loads(response.read().decode("utf-8"))
        except Exception as exc:
            raise RuntimeError(f"Could not refresh current Bitget contract rules for {product_type}: {exc}") from exc
        if payload.get("code") != "00000" or not isinstance(payload.get("data"), list):
            raise RuntimeError(f"Bitget returned an invalid contract-rules response for {product_type}")
        contracts: dict[str, dict[str, Any]] = {}
        for item in payload["data"]:
            symbol = item.get("symbol")
            if not isinstance(symbol, str) or not symbol:
                continue
            missing = [field for field in RULE_FIELDS if field not in item]
            if missing:
                raise RuntimeError(f"Bitget contract rules for {symbol} are missing: {', '.join(missing)}")
            contracts[symbol] = {field: item[field] for field in RULE_FIELDS}
        if not contracts:
            raise RuntimeError(f"Bitget returned no contract rules for {product_type}")
        cache_payload = {"fetched_at": int(time.time()), "product_type": product_type, "contracts": contracts}
        fd, temporary_path = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                json.dump(cache_payload, handle, separators=(",", ":"), ensure_ascii=False)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary_path, path)
        finally:
            try:
                os.unlink(temporary_path)
            except FileNotFoundError:
                pass
        return contracts


def validate_market_order(
    notional: float | Decimal,
    price: float | Decimal,
    contract: dict[str, Any],
    leverage: float | Decimal = 1,
    available_balance: float | Decimal | None = None,
    fee_rate: float | Decimal = 0,
) -> dict[str, Any]:
    """Round a market order down to Bitget's quantity step and validate it."""
    requested = _decimal(notional, "notional")
    market_price = _decimal(price, "price")
    leverage_value = _decimal(leverage, "leverage")
    fee = _decimal(fee_rate, "fee_rate")
    if requested <= 0 or market_price <= 0 or leverage_value <= 0 or fee < 0:
        return {"valid": False, "reason": "invalid order parameters"}
    if contract.get("symbolStatus") != "normal":
        return {"valid": False, "reason": "contract is not currently tradable"}

    min_leverage = _decimal(contract["minLever"], "minLever")
    max_leverage = _decimal(contract["maxLever"], "maxLever")
    if leverage_value < min_leverage or leverage_value > max_leverage:
        return {"valid": False, "reason": "leverage is outside the contract limits"}

    quantity_step = _decimal(contract["sizeMultiplier"], "sizeMultiplier")
    minimum_quantity = _decimal(contract["minTradeNum"], "minTradeNum")
    if quantity_step <= 0 or minimum_quantity < 0:
        return {"valid": False, "reason": "invalid quantity rules"}
    raw_quantity = requested / market_price
    quantity = (raw_quantity / quantity_step).to_integral_value(rounding=ROUND_DOWN) * quantity_step
    quantity_places = int(contract["volumePlace"])
    quantity_quantum = Decimal(1).scaleb(-quantity_places)
    quantity = quantity.quantize(quantity_quantum, rounding=ROUND_DOWN)
    if quantity < minimum_quantity:
        return {"valid": False, "reason": "quantity is below the contract minimum"}
    if quantity <= 0 or quantity % quantity_step != 0:
        return {"valid": False, "reason": "quantity does not match the contract step"}

    max_market_quantity = _decimal(contract["maxMarketOrderQty"], "maxMarketOrderQty")
    if max_market_quantity > 0 and quantity > max_market_quantity:
        return {"valid": False, "reason": "quantity exceeds the market-order maximum"}
    actual_notional = quantity * market_price
    min_notional = _decimal(contract["minTradeUSDT"], "minTradeUSDT")
    if actual_notional < min_notional:
        return {"valid": False, "reason": "notional is below the contract minimum"}
    margin_and_fee = quantity * market_price / leverage_value + quantity * market_price * fee
    if available_balance is not None and margin_and_fee > _decimal(available_balance, "available_balance"):
        return {"valid": False, "reason": "insufficient balance for margin and entry fee"}
    return {
        "valid": True,
        "quantity": float(quantity),
        "notional": float(actual_notional),
        "margin_and_fee": float(margin_and_fee),
    }


def numba_market_order_rules(contract: dict[str, Any], leverage: float = 1.0) -> tuple[float, float, int, float, float]:
    """Return numeric market-order rules for the 1x quote replay kernel."""
    if contract.get("symbolStatus") != "normal":
        raise ValueError("Bitget does not currently allow new orders for this contract")
    minimum_leverage = _decimal(contract["minLever"], "minLever")
    maximum_leverage = _decimal(contract["maxLever"], "maxLever")
    if Decimal(str(leverage)) < minimum_leverage or Decimal(str(leverage)) > maximum_leverage:
        raise ValueError("The replay leverage is outside the current Bitget contract limits")
    return (
        float(_decimal(contract["minTradeNum"], "minTradeNum")),
        float(_decimal(contract["sizeMultiplier"], "sizeMultiplier")),
        int(contract["volumePlace"]),
        float(_decimal(contract["minTradeUSDT"], "minTradeUSDT")),
        float(_decimal(contract["maxMarketOrderQty"], "maxMarketOrderQty")),
    )
