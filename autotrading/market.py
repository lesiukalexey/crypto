"""Public Bitget ticker polling for the workstation/Pi executor."""

from __future__ import annotations

import json
import time
from decimal import Decimal, InvalidOperation
from typing import Iterator, Sequence
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from .contracts import Quote


TICKERS_URL = "https://api.bitget.com/api/v3/market/tickers"


def parse_tickers(payload: object, symbols: Sequence[str]) -> list[Quote]:
    """Parse the public v3 ticker payload and return only requested symbols."""
    if not isinstance(payload, dict) or payload.get("code") != "00000":
        raise RuntimeError("Bitget returned an invalid public ticker response")
    rows = payload.get("data")
    if not isinstance(rows, list):
        raise RuntimeError("Bitget ticker response data is not a list")
    requested = set(symbols)
    result: list[Quote] = []
    received_at_ms = int(time.time() * 1000)
    for row in rows:
        if not isinstance(row, dict):
            continue
        symbol = row.get("symbol")
        if symbol not in requested:
            continue
        try:
            bid = Decimal(str(row["bid1Price"]))
            ask = Decimal(str(row["ask1Price"]))
            last = Decimal(str(row["lastPrice"]))
            raw_timestamp = row.get("ts")
            timestamp_ms = int(raw_timestamp) if raw_timestamp not in (None, "") else received_at_ms
            result.append(Quote(symbol=symbol, bid=bid, ask=ask, last=last, timestamp_ms=timestamp_ms))
        except (KeyError, TypeError, ValueError, InvalidOperation) as exc:
            raise RuntimeError(f"Bitget ticker for {symbol} is malformed") from exc
    result.sort(key=lambda quote: quote.symbol)
    return result


class BitgetPublicTickerPoller:
    def __init__(
        self,
        category: str,
        *,
        interval_seconds: float = 30,
        request_timeout_seconds: float = 10,
    ) -> None:
        if category not in {"SPOT", "USDT-FUTURES", "COIN-FUTURES", "USDC-FUTURES"}:
            raise ValueError("Unsupported Bitget market category")
        if interval_seconds < 1 or request_timeout_seconds <= 0:
            raise ValueError("Polling interval must be at least one second and timeout must be positive")
        self.category = category
        self.interval_seconds = interval_seconds
        self.request_timeout_seconds = request_timeout_seconds

    def fetch(self, symbols: Sequence[str]) -> list[Quote]:
        query = urlencode({"category": self.category})
        request = Request(
            f"{TICKERS_URL}?{query}",
            headers={"Accept": "application/json", "User-Agent": "bitget-autotrading-paper/1.0"},
        )
        try:
            with urlopen(request, timeout=self.request_timeout_seconds) as response:
                payload = json.loads(response.read().decode("utf-8"))
        except HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")
            raise RuntimeError(f"Bitget ticker request returned HTTP {exc.code}: {detail[:300]}") from exc
        except (URLError, TimeoutError, json.JSONDecodeError) as exc:
            raise RuntimeError(f"Could not read Bitget ticker response: {exc}") from exc
        return parse_tickers(payload, symbols)

    def stream(self, symbols: Sequence[str]) -> Iterator[Quote]:
        """Yield ticker updates until the caller stops the service."""
        while True:
            started = time.monotonic()
            yield from self.fetch(symbols)
            time.sleep(max(0.0, self.interval_seconds - (time.monotonic() - started)))
