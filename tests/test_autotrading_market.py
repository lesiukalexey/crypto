from __future__ import annotations

import unittest
from decimal import Decimal

from autotrading.market import parse_tickers


class PublicTickerTests(unittest.TestCase):
    def test_parses_requested_symbols_only(self) -> None:
        payload = {"code": "00000", "data": [
            {"symbol": "BTCUSDT", "bid1Price": "99.5", "ask1Price": "100", "lastPrice": "99.8", "ts": "1700000000000"},
            {"symbol": "ETHUSDT", "bid1Price": "10", "ask1Price": "11", "lastPrice": "10.5", "ts": "1700000000001"},
        ]}
        quotes = parse_tickers(payload, ["BTCUSDT"])
        self.assertEqual(len(quotes), 1)
        self.assertEqual(quotes[0].symbol, "BTCUSDT")
        self.assertEqual(quotes[0].bid, Decimal("99.5"))
        self.assertEqual(quotes[0].timestamp_ms, 1700000000000)

    def test_rejects_invalid_public_response_and_crossed_book(self) -> None:
        with self.assertRaises(RuntimeError):
            parse_tickers({"code": "400", "data": []}, ["BTCUSDT"])
        with self.assertRaises(RuntimeError):
            parse_tickers({"code": "00000", "data": [{"symbol": "BTCUSDT", "bid1Price": "101", "ask1Price": "100", "lastPrice": "100.5", "ts": "1700000000000"}]}, ["BTCUSDT"])


if __name__ == "__main__":
    unittest.main()
