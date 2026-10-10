from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from types import SimpleNamespace
import tempfile
import numpy as np

import futures_costs
import unittest
from unittest.mock import patch
from strategy_ranking import (
    PriceTree, QuoteArrays, simulate, simulate_martingale_batch,
    simulate_standard_checkpoint, simulate_standard_checkpoint_days,
)
from ranking_checkpoints import quote_prefix_fingerprint, state_checkpoint_path


class FuturesCostTests(unittest.TestCase):
    def _funded_quotes(self) -> QuoteArrays:
        prices = np.array([100.0, 100.0, 100.0])
        return QuoteArrays(
            prices, prices, prices, np.array([1.0, 2.0, 3.0]),
            funding_quote_indexes=np.array([1], dtype=np.int64),
            funding_mark_prices=np.array([100.0]),
            funding_rates=np.array([0.01]),
        )

    def test_standard_and_checkpoint_paths_round_orders_and_charge_funding(self) -> None:
        quotes = self._funded_quotes()
        bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
        rules = (0.01, 0.01, 2, 5.0, 0.0)
        args = (quotes, [0], [False], bid_tree, ask_tree, 100.0, 0.0, None, None)
        standard = simulate(*args, order_rules=rules)
        checkpoint = simulate_standard_checkpoint(
            *args, (100.0, 0, 0, -1, False), order_rules=rules,
        )
        daily = simulate_standard_checkpoint_days(
            *args, np.array([1, 2, 3]), (100.0, 0, 0, -1, False), order_rules=rules,
        )
        self.assertAlmostEqual(standard[0], -1.0, places=8)
        self.assertAlmostEqual(checkpoint[0], standard[0], places=8)
        self.assertAlmostEqual(daily[0], standard[0], places=8)
        self.assertEqual(standard[1], 1)

    def test_standard_path_rejects_order_below_current_contract_minimum(self) -> None:
        prices = np.array([100.0, 101.0])
        quotes = QuoteArrays(prices, prices, prices, np.array([1.0, 2.0]))
        result = simulate(
            quotes, [0], [False], PriceTree(prices), PriceTree(prices),
            10.0, 0.0, None, None,
            order_rules=(0.1, 0.1, 1, 15.0, 0.0),
        )
        self.assertTrue(np.isnan(result[0]))
        self.assertEqual(result[1], 0)

    def test_standard_funding_changes_take_profit_crossing_time(self) -> None:
        prices = np.array([100.0, 100.3, 101.0, 101.6])
        quotes = QuoteArrays(
            prices, prices, prices, np.arange(4, dtype=np.float64),
            funding_quote_indexes=np.array([2], dtype=np.int64),
            funding_mark_prices=np.array([100.0]),
            funding_rates=np.array([0.01]),
        )
        result = simulate(
            quotes, [0], [False], PriceTree(quotes.bids), PriceTree(quotes.asks),
            100.0, 0.0, 5.0, None, order_rules=(0.01, 0.01, 2, 5.0, 0.0),
        )
        # Funding debits one USDT at index 2, delaying the $0.50 take profit
        # until index 3; the realized amount is $1.60 gross minus $1 funding.
        self.assertAlmostEqual(result[0], 0.6, places=8)

    def test_martingale_funding_is_identical_in_scalar_and_batch_paths(self) -> None:
        quotes = self._funded_quotes()
        bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
        rules = (0.001, 0.001, 3, 1.0, 0.0)
        scalar = simulate(
            quotes, [0], [False], bid_tree, ask_tree, 100.0, 0.0, None, None,
            "simple", "immediate", 2, order_rules=rules,
        )
        batch = simulate_martingale_batch(
            quotes, np.array([0]), np.array([False]), bid_tree, ask_tree,
            100.0, 0.0, "simple", "immediate", 2, None, None,
            [("one", None, None, "scaled")], order_rules=rules,
        )[("one", "scaled")]
        self.assertAlmostEqual(float(scalar[0]), float(batch[0]), places=8)
        self.assertLess(float(scalar[0]), 0.0)

    def test_martingale_funding_adjusts_take_profit_and_risk_crossings(self) -> None:
        prices = np.array([100.0, 100.3, 101.0, 100.4])
        quotes = QuoteArrays(
            prices, prices, prices, np.arange(4, dtype=np.float64),
            funding_quote_indexes=np.array([2], dtype=np.int64),
            funding_mark_prices=np.array([100.0]),
            funding_rates=np.array([0.01]),
        )
        bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
        rules = (0.001, 0.001, 3, 1.0, 0.0)
        scalar = simulate(
            quotes, [0], [False], bid_tree, ask_tree, 100.0, 0.0, 5.0, None,
            "simple", "immediate", 2, order_rules=rules,
        )
        batch = simulate_martingale_batch(
            quotes, np.array([0]), np.array([False]), bid_tree, ask_tree,
            100.0, 0.0, "simple", "immediate", 2, None, None,
            [("tp", 5.0, None, "scaled")], order_rules=rules,
        )[("tp", "scaled")]
        self.assertAlmostEqual(float(scalar[0]), -0.3, places=8)
        self.assertAlmostEqual(float(scalar[0]), float(batch[0]), places=8)

        rising = np.array([100.0, 100.0, 100.0, 110.0])
        risk_quotes = QuoteArrays(
            rising, rising, rising, np.arange(4, dtype=np.float64),
            funding_quote_indexes=np.array([2], dtype=np.int64),
            funding_mark_prices=np.array([100.0]),
            funding_rates=np.array([0.01]),
        )
        risk_result = simulate(
            risk_quotes, [0], [False], PriceTree(rising), PriceTree(rising),
            100.0, 0.0, None, None, "simple", "immediate", 2,
            martingale_max_chain_loss=0.25, order_rules=rules,
        )
        # The funding debit alone crosses the chain cap at index 2, before
        # the later recovery to 110 can turn the position profitable.
        self.assertAlmostEqual(float(risk_result[0]), -0.5, places=8)

    def test_quote_execution_prices_apply_slippage_without_changing_signal_last(self) -> None:
        quote = QuoteArrays.from_rows([{
            "bid_price": "99", "ask_price": "101", "last_price": "100",
            "received_at_utc": datetime(2026, 10, 11, tzinfo=timezone.utc),
        }], 10)
        self.assertAlmostEqual(quote.bids[0], 98.901)
        self.assertAlmostEqual(quote.asks[0], 101.101)
        self.assertEqual(quote.lasts[0], 100)

    def test_quote_and_day_checkpoint_fingerprints_include_shared_costs(self) -> None:
        quotes = self._funded_quotes()
        changed_funding = self._funded_quotes()
        changed_funding.funding_rates[0] = 0.02
        self.assertNotEqual(
            quote_prefix_fingerprint(quotes, len(quotes)),
            quote_prefix_fingerprint(changed_funding, len(changed_funding)),
        )
        args = SimpleNamespace(
            balance=100.0, fee_percent=0.06, ranking_params={}, martingale_mode="none",
            martingale_timing="immediate", martingale_attempts=3,
            include_funding="1", slippage_bps=0.0,
        )
        state = {"args": args, "category": "USDT-FUTURES", "order_rules": (0.01, 0.01, 2, 5.0, 0.0), "checkpoint_root": Path("/tmp/checkpoints")}
        baseline = state_checkpoint_path(state, "ETHUSDT", "entry")
        args.slippage_bps = 10.0
        self.assertNotEqual(baseline, state_checkpoint_path(state, "ETHUSDT", "entry"))
        args.slippage_bps = 0.0
        state["order_rules"] = (0.02, 0.01, 2, 5.0, 0.0)
        self.assertNotEqual(baseline, state_checkpoint_path(state, "ETHUSDT", "entry"))

    def test_adverse_fill_price_moves_each_side_against_the_trader(self) -> None:
        self.assertAlmostEqual(futures_costs.adverse_fill_price(100, True, 10), 100.1)
        self.assertAlmostEqual(futures_costs.adverse_fill_price(100, False, 10), 99.9)
        self.assertEqual(futures_costs.adverse_fill_price(100, True, 0), 100)


    def test_adverse_fill_price_rejects_invalid_inputs(self) -> None:
        for price, bps in ((0, 1), (100, -1), (100, 10_000)):
            with self.subTest(price=price, bps=bps), self.assertRaises(ValueError):
                futures_costs.adverse_fill_price(price, True, bps)


    def test_funding_cashflow_sign_matches_long_and_short_convention(self) -> None:
        self.assertAlmostEqual(futures_costs.funding_cashflow(2, False, 100, 0.001), -0.2)
        self.assertAlmostEqual(futures_costs.funding_cashflow(2, True, 100, 0.001), 0.2)
        self.assertAlmostEqual(futures_costs.funding_cashflow(2, False, 100, -0.001), 0.2)


    def test_funding_history_paginates_and_caches(self) -> None:
        with tempfile.TemporaryDirectory() as directory, patch.object(futures_costs, "CACHE_ROOT", Path(directory)):
            self._test_funding_history_paginates_and_caches()

    def _test_funding_history_paginates_and_caches(self) -> None:
        pages = [[{"fundingTime": "2000000", "fundingRate": "0.003"}, {"fundingTime": "1000000", "fundingRate": "-0.002"}]]

        class Response:
            def __init__(self, payload: dict) -> None:
                self.payload = payload

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def read(self) -> bytes:
                return json.dumps(self.payload).encode()

        calls: list[str] = []

        def open_url(request, timeout=15):
            calls.append(request.full_url)
            return Response({"code": "00000", "requestTime": 3_000_000, "data": pages.pop(0)})

        with patch.object(futures_costs.urllib.request, "urlopen", open_url):
            result = futures_costs.load_historical_funding_rates("USDT-FUTURES", "BTCUSDT", 1000, 3000)
            self.assertEqual(result, [(1000.0, -0.002), (2000.0, 0.003)])
            self.assertIn("pageNo=1", calls[0])
            self.assertEqual(futures_costs.load_historical_funding_rates("USDT-FUTURES", "BTCUSDT", 1000, 3000), result)
            self.assertEqual(len(calls), 1)


if __name__ == "__main__":
    unittest.main()
