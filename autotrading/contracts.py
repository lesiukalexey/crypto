"""Versioned strategy releases and interfaces shared by local and Pi runtimes.

This module deliberately has no exchange client, database, or web-framework
dependency. A release is an allow-listed description of a strategy, never a
container for credentials or executable code.
"""

from __future__ import annotations

import hashlib
import json
import re
from dataclasses import dataclass
from datetime import datetime, timedelta
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any, Iterator, Protocol, Sequence


SUPPORTED_SCHEMA_VERSION = 1
_SYMBOL = re.compile(r"^[A-Z0-9]{2,40}$")
_RELEASE_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$")
_CONFIG_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:+-]{0,159}$")
_CATEGORIES = {"SPOT", "USDT-FUTURES", "COIN-FUTURES", "USDC-FUTURES"}
_SIGNAL_MODES = {"exact", "within"}
_DIRECTION_MODES = {"follow", "reverse"}
_MARTINGALE_MODES = {"none", "simple", "reverse", "simple_profit", "reverse_profit"}
_MARTINGALE_TIMINGS = {"immediate", "rules"}
_MARTINGALE_TARGET_POLICIES = {"scaled", "fixed"}
_EXIT_CONFIRMATION_MODES = {"immediate", "both_delayed", "tp_delayed", "sl_delayed"}

_ROOT_FIELDS = {
    "schema_version", "release_id", "created_at_utc", "market", "strategy",
    "execution", "risk", "backtest",
}
_MARKET_FIELDS = {"category", "symbols", "margin_coin"}
_STRATEGY_FIELDS = {
    "entry_config", "entry_signal_mode", "entry_direction_mode", "exit_config",
    "martingale_mode", "martingale_timing", "martingale_attempts",
    "martingale_target_policy", "max_chain_loss_usdt", "max_day_loss_usdt",
    "exit_confirmation_mode", "exit_confirmation_snapshots",
}
_EXECUTION_FIELDS = {
    "starting_balance_usdt", "leverage", "taker_fee_rate", "slippage_bps", "include_funding",
}
_RISK_FIELDS = {
    "max_order_notional_usdt", "max_total_exposure_usdt", "max_daily_loss_usdt",
    "max_open_positions",
}
_BACKTEST_FIELDS = {
    "start_date", "end_date", "result_key", "net_result_usdt", "trade_count",
}


def _object(value: Any, fields: set[str], name: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ValueError(f"{name} must be an object")
    missing = fields - value.keys()
    extra = value.keys() - fields
    if missing:
        raise ValueError(f"{name} is missing fields: {', '.join(sorted(missing))}")
    if extra:
        raise ValueError(f"{name} contains unsupported fields: {', '.join(sorted(extra))}")
    return value


def _text(value: Any, name: str, pattern: re.Pattern[str] | None = None) -> str:
    if not isinstance(value, str) or not value.strip():
        raise ValueError(f"{name} must be a non-empty string")
    if value != value.strip():
        raise ValueError(f"{name} cannot have leading or trailing whitespace")
    if pattern is not None and not pattern.fullmatch(value):
        raise ValueError(f"{name} has an invalid format")
    return value


def _choice(value: Any, choices: set[str], name: str) -> None:
    if not isinstance(value, str) or value not in choices:
        raise ValueError(f"{name} is not supported")


def _decimal(value: Any, name: str, *, minimum: Decimal, maximum: Decimal | None = None) -> Decimal:
    if not isinstance(value, str):
        raise ValueError(f"{name} must be a decimal string")
    try:
        number = Decimal(value)
    except InvalidOperation as exc:
        raise ValueError(f"{name} must be a decimal string") from exc
    if not number.is_finite() or number < minimum or (maximum is not None and number > maximum):
        raise ValueError(f"{name} is outside the allowed range")
    return number


def _date(value: Any, name: str) -> None:
    if not isinstance(value, str):
        raise ValueError(f"{name} must be an ISO date")
    try:
        parsed = datetime.strptime(value, "%Y-%m-%d")
    except ValueError as exc:
        raise ValueError(f"{name} must be an ISO date (YYYY-MM-DD)") from exc
    if parsed.strftime("%Y-%m-%d") != value:
        raise ValueError(f"{name} must be an ISO date (YYYY-MM-DD)")


def validate_strategy_release(release: Any) -> dict[str, Any]:
    """Validate a v1 strategy release and return a detached plain dictionary."""
    root = _object(release, _ROOT_FIELDS, "release")
    version = root["schema_version"]
    if isinstance(version, bool) or version != SUPPORTED_SCHEMA_VERSION:
        raise ValueError(f"Unsupported strategy schema version: {version!r}")
    _text(root["release_id"], "release_id", _RELEASE_ID)

    created = root["created_at_utc"]
    if not isinstance(created, str):
        raise ValueError("created_at_utc must be an ISO timestamp")
    try:
        parsed_created = datetime.fromisoformat(created.replace("Z", "+00:00"))
    except ValueError as exc:
        raise ValueError("created_at_utc must be an ISO timestamp") from exc
    if parsed_created.tzinfo is None or parsed_created.utcoffset() != timedelta(0):
        raise ValueError("created_at_utc must use UTC")

    market = _object(root["market"], _MARKET_FIELDS, "market")
    _choice(market["category"], _CATEGORIES, "market.category")
    if not isinstance(market["symbols"], list) or not market["symbols"]:
        raise ValueError("market.symbols must be a non-empty list")
    symbols = [_text(symbol, "market.symbol", _SYMBOL) for symbol in market["symbols"]]
    if len(set(symbols)) != len(symbols):
        raise ValueError("market.symbols must not contain duplicates")
    _text(market["margin_coin"], "market.margin_coin", _SYMBOL)

    strategy = _object(root["strategy"], _STRATEGY_FIELDS, "strategy")
    _text(strategy["entry_config"], "strategy.entry_config", _CONFIG_ID)
    _text(strategy["exit_config"], "strategy.exit_config", _CONFIG_ID)
    _choice(strategy["entry_signal_mode"], _SIGNAL_MODES, "strategy.entry_signal_mode")
    _choice(strategy["entry_direction_mode"], _DIRECTION_MODES, "strategy.entry_direction_mode")
    _choice(strategy["martingale_mode"], _MARTINGALE_MODES, "strategy.martingale_mode")
    _choice(strategy["martingale_timing"], _MARTINGALE_TIMINGS, "strategy.martingale_timing")
    _choice(strategy["martingale_target_policy"], _MARTINGALE_TARGET_POLICIES, "strategy.martingale_target_policy")
    _choice(strategy["exit_confirmation_mode"], _EXIT_CONFIRMATION_MODES, "strategy.exit_confirmation_mode")
    attempts = strategy["martingale_attempts"]
    if isinstance(attempts, bool) or not isinstance(attempts, int) or not 2 <= attempts <= 10:
        raise ValueError("strategy.martingale_attempts must be an integer from 2 to 10")
    _decimal(strategy["max_chain_loss_usdt"], "strategy.max_chain_loss_usdt", minimum=Decimal(0))
    _decimal(strategy["max_day_loss_usdt"], "strategy.max_day_loss_usdt", minimum=Decimal(0))
    confirmations = strategy["exit_confirmation_snapshots"]
    if isinstance(confirmations, bool) or not isinstance(confirmations, int) or not 0 <= confirmations <= 10000:
        raise ValueError("strategy.exit_confirmation_snapshots must be an integer from 0 to 10000")

    execution = _object(root["execution"], _EXECUTION_FIELDS, "execution")
    _decimal(execution["starting_balance_usdt"], "execution.starting_balance_usdt", minimum=Decimal("0.01"))
    _decimal(execution["leverage"], "execution.leverage", minimum=Decimal("0.01"), maximum=Decimal("125"))
    _decimal(execution["taker_fee_rate"], "execution.taker_fee_rate", minimum=Decimal(0), maximum=Decimal(1))
    _decimal(execution["slippage_bps"], "execution.slippage_bps", minimum=Decimal(0), maximum=Decimal(9999))
    if not isinstance(execution["include_funding"], bool):
        raise ValueError("execution.include_funding must be a boolean")

    risk = _object(root["risk"], _RISK_FIELDS, "risk")
    for field in ("max_order_notional_usdt", "max_total_exposure_usdt", "max_daily_loss_usdt"):
        _decimal(risk[field], f"risk.{field}", minimum=Decimal("0.01"))
    positions = risk["max_open_positions"]
    if isinstance(positions, bool) or not isinstance(positions, int) or positions < 1:
        raise ValueError("risk.max_open_positions must be a positive integer")
    if Decimal(risk["max_order_notional_usdt"]) > Decimal(risk["max_total_exposure_usdt"]):
        raise ValueError("risk.max_order_notional_usdt cannot exceed max_total_exposure_usdt")

    backtest = _object(root["backtest"], _BACKTEST_FIELDS, "backtest")
    _date(backtest["start_date"], "backtest.start_date")
    _date(backtest["end_date"], "backtest.end_date")
    if backtest["start_date"] > backtest["end_date"]:
        raise ValueError("backtest.start_date cannot be after end_date")
    _text(backtest["result_key"], "backtest.result_key", _CONFIG_ID)
    _decimal(backtest["net_result_usdt"], "backtest.net_result_usdt", minimum=Decimal("-1000000000"), maximum=Decimal("1000000000"))
    trades = backtest["trade_count"]
    if isinstance(trades, bool) or not isinstance(trades, int) or trades < 0:
        raise ValueError("backtest.trade_count must be a non-negative integer")

    # Round-trip through JSON also detaches caller-owned nested dictionaries.
    return json.loads(json.dumps(root, ensure_ascii=False, allow_nan=False))


def release_digest(release: Any) -> str:
    """Return a stable SHA-256 for a valid release."""
    payload = validate_strategy_release(release)
    canonical = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def load_strategy_release(path: str | Path) -> dict[str, Any]:
    """Read and validate one JSON strategy release from an external path."""
    try:
        payload = json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ValueError(f"Could not read strategy release: {exc}") from exc
    return validate_strategy_release(payload)


@dataclass(frozen=True)
class Quote:
    symbol: str
    bid: Decimal
    ask: Decimal
    last: Decimal
    timestamp_ms: int

    def __post_init__(self) -> None:
        _text(self.symbol, "quote.symbol", _SYMBOL)
        for field in ("bid", "ask", "last"):
            value = getattr(self, field)
            if not isinstance(value, Decimal) or not value.is_finite() or value <= 0:
                raise ValueError(f"quote.{field} must be a positive Decimal")
        if self.bid > self.ask:
            raise ValueError("quote.bid cannot exceed quote.ask")
        if isinstance(self.timestamp_ms, bool) or not isinstance(self.timestamp_ms, int) or self.timestamp_ms <= 0:
            raise ValueError("quote.timestamp_ms must be a positive integer")


@dataclass(frozen=True)
class OrderRequest:
    symbol: str
    side: str
    quantity: Decimal
    client_order_id: str
    reduce_only: bool = False

    def __post_init__(self) -> None:
        _text(self.symbol, "order.symbol", _SYMBOL)
        if not isinstance(self.side, str) or self.side not in {"buy", "sell"}:
            raise ValueError("order.side must be buy or sell")
        if not isinstance(self.quantity, Decimal) or not self.quantity.is_finite() or self.quantity <= 0:
            raise ValueError("order.quantity must be a positive Decimal")
        _text(self.client_order_id, "order.client_order_id", _CONFIG_ID)
        if not isinstance(self.reduce_only, bool):
            raise ValueError("order.reduce_only must be a boolean")


@dataclass(frozen=True)
class ExecutionResult:
    accepted: bool
    order_id: str | None
    status: str
    message: str = ""


@dataclass(frozen=True)
class Position:
    symbol: str
    side: str
    quantity: Decimal
    average_entry_price: Decimal


class MarketDataProvider(Protocol):
    def stream(self, symbols: Sequence[str]) -> Iterator[Quote]: ...


class ExecutionAdapter(Protocol):
    @property
    def mode(self) -> str: ...

    def submit(self, order: OrderRequest) -> ExecutionResult: ...

    def positions(self) -> Sequence[Position]: ...


class StateStore(Protocol):
    def save_release(self, release: dict[str, Any], digest: str) -> None: ...

    def record_event(self, event: dict[str, Any]) -> None: ...

    def load_state(self) -> dict[str, Any]: ...
