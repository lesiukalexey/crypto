from __future__ import annotations

import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from autotrading.market import parse_tickers
from autotrading.service import read_json, set_controls


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

    def test_control_file_keeps_pause_and_close_actions_independent(self) -> None:
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "controls.json"
            set_controls(path, entries_paused=True)
            set_controls(path, close_positions=True)
            self.assertEqual(read_json(path, {}), {"entries_paused": True, "close_positions": True})
            set_controls(path, entries_paused=False)
            self.assertEqual(read_json(path, {}), {"entries_paused": False, "close_positions": True})


if __name__ == "__main__":
    unittest.main()
