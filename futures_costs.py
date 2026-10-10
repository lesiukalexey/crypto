"""Public historical futures funding data and deterministic replay cost helpers."""

from __future__ import annotations

import fcntl
import json
import os
import tempfile
import time
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


FUNDING_URL = "https://api.bitget.com/api/v2/mix/market/history-fund-rate"
CACHE_ROOT = Path.home() / ".ai" / "home" / ".local" / "bitget-funding-rates"
CACHE_TTL_SECONDS = 6 * 60 * 60
MAX_PAGE_SIZE = 100


def funding_cache_path(product_type: str, symbol: str) -> Path:
    safe = "".join(ch for ch in f"{product_type}-{symbol}".lower() if ch.isalnum() or ch == "-")
    return CACHE_ROOT / f"{safe}.json"


def _read_cache(path: Path) -> dict[str, Any] | None:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    return payload if isinstance(payload, dict) and isinstance(payload.get("rates"), list) else None


def load_historical_funding_rates(
    product_type: str,
    symbol: str,
    start_timestamp: float,
    end_timestamp: float,
    *,
    force_refresh: bool = False,
) -> list[tuple[float, float]]:
    """Return (UTC epoch seconds, rate) rows covering [start, end].

    Bitget's public history endpoint is paginated by page number rather than a
    requested time window. Cache each symbol's fetched pages outside the repo.
    A response that cannot establish coverage raises instead of silently
    substituting a current rate or treating missing settlements as zero.
    """
    if not symbol or start_timestamp > end_timestamp:
        raise ValueError("Invalid symbol or funding-history interval")
    path = funding_cache_path(product_type, symbol)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    with path.with_suffix(".lock").open("a", encoding="utf-8") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        cached = _read_cache(path)
        if (
            not force_refresh
            and cached is not None
            and int(cached.get("fetched_at", 0)) >= int(time.time()) - CACHE_TTL_SECONDS
            and float(cached.get("first_timestamp", float("inf"))) <= start_timestamp
            and (float(cached.get("last_timestamp", float("-inf"))) >= end_timestamp or float(cached.get("latest_request_time", 0)) >= end_timestamp)
        ):
            return [(float(ts), float(rate)) for ts, rate in cached["rates"] if start_timestamp <= float(ts) <= end_timestamp]

        rows: dict[int, float] = {}
        page_no = 1
        first_timestamp = float("inf")
        last_timestamp = float("-inf")
        latest_request_time = 0
        while page_no <= 100:
            query = urllib.parse.urlencode({
                "symbol": symbol,
                "productType": product_type,
                "pageSize": MAX_PAGE_SIZE,
                "pageNo": page_no,
            })
            request = urllib.request.Request(
                f"{FUNDING_URL}?{query}",
                headers={"User-Agent": "BitgetHistoricalReplay/1.0"},
            )
            try:
                with urllib.request.urlopen(request, timeout=15) as response:
                    payload = json.loads(response.read().decode("utf-8"))
            except Exception as exc:
                raise RuntimeError(f"Could not load Bitget historical funding for {symbol}: {exc}") from exc
            if payload.get("code") != "00000" or not isinstance(payload.get("data"), list):
                raise RuntimeError(f"Bitget returned invalid historical funding for {symbol}")
            latest_request_time = max(latest_request_time, int(payload.get("requestTime", 0)) / 1000)
            page = payload["data"]
            if not page:
                break
            for item in page:
                try:
                    timestamp = int(item["fundingTime"])
                    rate = float(item["fundingRate"])
                except (KeyError, TypeError, ValueError) as exc:
                    raise RuntimeError(f"Bitget returned a malformed funding row for {symbol}") from exc
                if not (rate == rate and abs(rate) != float("inf")):
                    raise RuntimeError(f"Bitget returned a non-finite funding rate for {symbol}")
                rows[timestamp] = rate
                first_timestamp = min(first_timestamp, timestamp / 1000)
                last_timestamp = max(last_timestamp, timestamp / 1000)
            # The endpoint returns newest records first. Stop once this page
            # reaches the start boundary instead of downloading years of data.
            if min(int(item["fundingTime"]) for item in page) / 1000 <= start_timestamp:
                break
            if len(page) < MAX_PAGE_SIZE:
                break
            page_no += 1
        else:
            raise RuntimeError(f"Bitget funding history pagination exceeded 100 pages for {symbol}")

        if not rows or first_timestamp > start_timestamp or (last_timestamp < end_timestamp and latest_request_time < end_timestamp):
            raise RuntimeError(
                f"Bitget funding history for {symbol} does not cover the requested interval "
                f"({datetime.fromtimestamp(first_timestamp, timezone.utc).isoformat() if rows else 'empty'} — "
                f"{datetime.fromtimestamp(last_timestamp, timezone.utc).isoformat() if rows else 'empty'} UTC)."
            )
        ordered = sorted((timestamp / 1000, rate) for timestamp, rate in rows.items())
        cache = {
            "fetched_at": int(time.time()),
            "product_type": product_type,
            "symbol": symbol,
            "first_timestamp": first_timestamp,
            "last_timestamp": last_timestamp,
            "latest_request_time": latest_request_time,
            "rates": ordered,
        }
        fd, temporary = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                json.dump(cache, handle, separators=(",", ":"))
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary, path)
        finally:
            try:
                os.unlink(temporary)
            except FileNotFoundError:
                pass
        return [(timestamp, rate) for timestamp, rate in ordered if start_timestamp <= timestamp <= end_timestamp]


def adverse_fill_price(price: float, is_buy: bool, slippage_bps: float) -> float:
    """Move a fill against the simulated trader by basis points."""
    if price <= 0 or slippage_bps < 0 or slippage_bps >= 10_000:
        raise ValueError("Price must be positive and slippage must be from 0 to 9,999 bps")
    factor = slippage_bps / 10_000
    return price * (1 + factor if is_buy else 1 - factor)


def funding_cashflow(quantity: float, is_short: bool, mark_price: float, rate: float) -> float:
    """Funding cash flow in USDT; positive rates charge longs and credit shorts."""
    if quantity < 0 or mark_price <= 0:
        raise ValueError("Quantity must be non-negative and mark price positive")
    return quantity * mark_price * rate * (1 if is_short else -1)
