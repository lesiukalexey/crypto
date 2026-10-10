"""Deterministic parity checks for the Python and Numba martingale replays."""

import unittest

import numpy as np

from martingale import simulate_martingale
from strategy_ranking import PriceTree, QuoteArrays, martingale_exit_candidates, simulate, simulate_martingale_batch


class MartingaleParityTests(unittest.TestCase):
    def replay(self, prices, signal_indexes, signal_shorts, mode, timing, policy, ask_multiplier=1.001, attempts=3, take_threshold=None):
        values = np.asarray(prices, dtype=np.float64)
        quotes = QuoteArrays(
            bids=values,
            asks=values * ask_multiplier,
            lasts=values,
            timestamps=np.arange(len(values), dtype=np.float64),
        )
        bid_tree = PriceTree(quotes.bids)
        ask_tree = PriceTree(quotes.asks)
        numba_result = simulate(
            quotes,
            signal_indexes,
            signal_shorts,
            bid_tree,
            ask_tree,
            10.0,
            0.0006,
            take_threshold,
            100.0,  # 1 USDT stop threshold at a 10 USDT starting balance.
            mode,
            timing,
            attempts,
            policy,
        )
        python_result = simulate_martingale(
            quotes,
            signal_indexes,
            signal_shorts,
            bid_tree,
            ask_tree,
            10.0,
            0.0006,
            None if take_threshold is None else take_threshold / 100.0,
            -1.0,
            mode,
            timing,
            attempts,
            policy,
        )
        return numba_result, python_result

    def assert_parity(self, numba_result, python_result):
        self.assertAlmostEqual(float(numba_result[0]), float(python_result[0]), delta=1e-9)
        self.assertEqual(numba_result[1], python_result[1])
        if len(numba_result) > 2 and len(python_result) > 2:
            self.assertEqual(numba_result[2], python_result[2])

    def test_new_chain_stake_is_capped_after_previous_chain_loss(self):
        numba_result, python_result = self.replay(
            [10.0, 7.5, 0.5, 0.5, 1.0],
            [0, 3],
            [False, False],
            "simple",
            "immediate",
            "scaled",
            1.0,
            2,
        )
        self.assert_parity(numba_result, python_result)
        self.assertEqual(numba_result[1], 3)

    def test_current_contract_order_constraints_reject_unplaceable_attempts(self):
        prices = np.asarray([10.0, 10.0, 10.0])
        quotes = QuoteArrays(prices, prices, prices, np.arange(len(prices), dtype=np.float64))
        bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
        common = (quotes, [0], [False], bid_tree, ask_tree, 10.0, 0.0, None, None,
                  "simple_profit", "immediate", 2, "scaled", None, None)

        # A 5 USDT first order is placeable at the exact contract minimum.
        valid = simulate(*common, order_rules=(0.5, 0.1, 1, 5.0, 0.0))
        self.assertEqual(valid[1], 1)
        self.assertAlmostEqual(float(valid[0]), 0.0, delta=1e-12)

        # Reject when any independent contract bound makes the first attempt impossible.
        for rules in (
            (0.5, 0.1, 1, 5.01, 0.0),  # minimum notional
            (0.51, 0.1, 2, 5.0, 0.0),  # minimum quantity after rounding
            (0.1, 0.3, 1, 5.0, 0.0),  # quantity step rounds below minimum notional
            (0.1, 0.1, 1, 5.0, 0.49),  # market-order maximum
        ):
            with self.subTest(rules=rules):
                rejected = simulate(*common, order_rules=rules)
                self.assertTrue(np.isnan(rejected[0]))
                self.assertEqual(rejected[1], 0)

    def test_all_martingale_variants_match_on_fixed_quote_fixture(self):
        prices = [10, 8, 8.5, 9, 10.5, 10, 9, 8.5, 8, 9, 10.5, 10.5, 11, 10, 9, 10, 11, 12]
        indexes = [0, 2, 4, 6, 8, 10, 12, 14, 16]
        directions = [False, True, False, True, False, True, False, True, False]
        for mode in ("simple", "reverse", "simple_profit", "reverse_profit"):
            for timing in ("immediate", "rules"):
                for policy in ("scaled", "fixed"):
                    with self.subTest(mode=mode, timing=timing, policy=policy):
                        numba_result, python_result = self.replay(
                            prices, indexes, directions, mode, timing, policy
                        )
                        self.assert_parity(numba_result, python_result)

    def test_simple_rules_closes_if_take_profit_arrives_before_next_signal(self):
        numba_result, python_result = self.replay(
            [10.0, 7.5, 16.0, 10.0, 8.0, 8.0],
            [0, 4],
            [False, False],
            "simple",
            "rules",
            "scaled",
            take_threshold=100.0,  # 1 USDT TP at a 10 USDT starting balance.
        )
        self.assert_parity(numba_result, python_result)
        self.assertAlmostEqual(float(numba_result[0]), 1.1889316321986527, delta=1e-9)
        self.assertEqual(numba_result[1], 2)

    def test_chain_loss_limit_closes_at_the_first_crossing_quote(self):
        prices = [10.0, 9.5, 9.0, 10.0, 10.0]
        values = np.asarray(prices, dtype=np.float64)
        quotes = QuoteArrays(values, values, values, np.arange(len(values), dtype=np.float64))
        bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
        numba_result = simulate(
            quotes, [0], [False], bid_tree, ask_tree, 10.0, 0.0006,
            None, 100.0, "simple", "immediate", 2, "scaled", 0.2, None,
        )
        python_result = simulate_martingale(
            quotes, [0], [False], bid_tree, ask_tree, 10.0, 0.0006,
            None, -1.0, "simple", "immediate", 2, "scaled", 0.2, None,
        )
        self.assert_parity(numba_result, python_result)
        self.assertEqual(numba_result[1], 1)
        self.assertLessEqual(float(numba_result[0]), -0.2)

    def test_chain_loss_crossed_during_quote_gap_closes_at_next_observation(self):
        prices = np.asarray([100.0, 100.0, 60.0])
        quotes = QuoteArrays(
            prices, prices, prices,
            np.asarray([0.0, 60.0, 660.0]),
        )
        bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
        numba_result = simulate(
            quotes, [0], [False], bid_tree, ask_tree, 10.0, 0.0,
            None, None, "simple", "immediate", 2, "scaled", 1.0, None,
        )
        python_result = simulate_martingale(
            quotes, [0], [False], bid_tree, ask_tree, 10.0, 0.0,
            None, None, "simple", "immediate", 2, "scaled", 1.0, None,
        )
        self.assert_parity(numba_result, python_result)
        self.assertEqual(numba_result[1], 1)
        # No quote exists inside the 10-minute gap, so the 1-USDT limit can overshoot.
        self.assertAlmostEqual(float(numba_result[0]), -2.0, delta=1e-9)

    def test_daily_loss_limit_blocks_new_chains_until_the_next_day(self):
        prices = np.asarray([10.0, 7.5, 0.5, 0.5, 1.0, 1.0, 1.0])
        quotes = QuoteArrays(prices, prices, prices, np.arange(len(prices), dtype=np.float64))
        bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
        numba_result = simulate(
            quotes, [0, 3, 5], [False, False, False], bid_tree, ask_tree, 10.0,
            0.0006, None, 100.0, "simple", "immediate", 2, "scaled", None, 0.3,
        )
        python_result = simulate_martingale(
            quotes, [0, 3, 5], [False, False, False], bid_tree, ask_tree, 10.0,
            0.0006, None, -1.0, "simple", "immediate", 2, "scaled", None, 0.3,
        )
        self.assert_parity(numba_result, python_result)
        self.assertEqual(numba_result[1], 1)

    def test_daily_loss_limit_resets_on_a_new_calendar_day(self):
        prices = np.asarray([10.0, 7.5, 0.5, 0.5, 0.5, 1.0, 2.0])
        days = np.asarray([1, 1, 1, 1, 1, 2, 2], dtype=np.int32)
        quotes = QuoteArrays(prices, prices, prices, np.arange(len(prices), dtype=np.float64), days)
        bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
        numba_result = simulate(
            quotes, [0, 3, 5], [False, False, False], bid_tree, ask_tree, 10.0,
            0.0006, None, 100.0, "simple", "immediate", 2, "scaled", None, 0.3,
        )
        python_result = simulate_martingale(
            quotes, [0, 3, 5], [False, False, False], bid_tree, ask_tree, 10.0,
            0.0006, None, -1.0, "simple", "immediate", 2, "scaled", None, 0.3,
        )
        self.assert_parity(numba_result, python_result)
        self.assertEqual(numba_result[1], 2)

    def test_chain_and_day_caps_match_across_modes_and_target_policies(self):
        rng = np.random.default_rng(2409)
        days = np.repeat(np.arange(4, dtype=np.int32), [13, 12, 14, 11])
        for case in range(24):
            prices = 10.0 * np.exp(np.cumsum(rng.normal(0.0, 0.03, len(days))))
            bids = prices * (1.0 - rng.uniform(0.0, 0.001, len(days)))
            asks = prices * (1.0 + rng.uniform(0.0, 0.001, len(days)))
            quotes = QuoteArrays(bids, asks, prices, np.arange(len(days), dtype=np.float64), days)
            bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
            indexes = list(range(0, len(days), 4))
            directions = [bool(value) for value in rng.integers(0, 2, len(indexes))]
            mode = ("simple", "reverse", "simple_profit", "reverse_profit")[case % 4]
            timing = ("immediate", "rules")[(case // 4) % 2]
            policy = ("scaled", "fixed")[(case // 8) % 2]
            max_chain_loss = (None, 0.2, 1.0)[case % 3]
            max_day_loss = (None, 0.3, 1.2)[(case // 3) % 3]
            take_threshold = (None, 10.0)[(case // 2) % 2]
            with self.subTest(case=case, mode=mode, timing=timing, policy=policy):
                numba_result = simulate(
                    quotes, indexes, directions, bid_tree, ask_tree, 10.0, 0.0006,
                    take_threshold, 100.0, mode, timing, 3, policy,
                    max_chain_loss, max_day_loss,
                )
                python_result = simulate_martingale(
                    quotes, indexes, directions, bid_tree, ask_tree, 10.0, 0.0006,
                    None if take_threshold is None else take_threshold / 100.0,
                    -1.0, mode, timing, 3, policy, max_chain_loss, max_day_loss,
                )
                self.assert_parity(numba_result, python_result)

    def test_batched_grid_matches_scalar_kernel_with_risk_limits(self):
        prices = np.asarray([10, 8, 9, 11, 8, 7, 12, 10, 13, 9, 11], dtype=np.float64)
        days = np.asarray([1] * 6 + [2] * 5, dtype=np.int32)
        quotes = QuoteArrays(prices, prices * 1.001, prices, np.arange(len(prices), dtype=np.float64), days)
        bid_tree, ask_tree = PriceTree(quotes.bids), PriceTree(quotes.asks)
        indexes, directions = np.asarray([0, 2, 4, 7]), np.asarray([False, True, False, True])
        candidates = [
            ("fixed:10:10", 1.0, 1.0, "scaled"),
            ("fixed:10:10", 1.0, 1.0, "fixed"),
            ("loss:10", None, 1.0, "scaled"),
            ("profit:10", 1.0, None, "scaled"),
        ]
        for mode in ("simple", "reverse", "simple_profit", "reverse_profit"):
            for timing in ("immediate", "rules"):
                with self.subTest(mode=mode, timing=timing):
                    batch_results = simulate_martingale_batch(
                        quotes, indexes, directions, bid_tree, ask_tree, 10.0, 0.0006,
                        mode, timing, 3, 0.4, 0.6, candidates,
                    )
                    for identifier, target, loss, policy in candidates:
                        scalar_result = simulate(
                            quotes, indexes, directions, bid_tree, ask_tree, 10.0, 0.0006,
                            target, loss, mode, timing, 3, policy, 0.4, 0.6,
                        )
                        self.assert_parity(batch_results[(identifier, policy)], scalar_result)
                        reference_result = simulate_martingale(
                            quotes, indexes, directions, bid_tree, ask_tree, 10.0, 0.0006,
                            None if target is None else target / 100.0,
                            None if loss is None else -10.0 * abs(loss) / 1000.0,
                            mode, timing, 3, policy, 0.4, 0.6,
                        )
                        self.assertEqual(batch_results[(identifier, policy)][2], reference_result[2])

    def test_exit_grid_builder_converts_fixed_basis_points_to_tenths_percent(self):
        profiles = {
            "fixed_tp": {"values_basis_points": [10, 20]},
            "fixed_sl": {"values_basis_points": [10, 20]},
            "loss": {"segments": [{"from": 1, "to": 2, "step": 1}]},
            "profit": {"segments": [{"from": 1, "to": 2, "step": 1}]},
        }
        candidates = martingale_exit_candidates(profiles, "simple")
        self.assertIn(("fixed:10:20", 1.0, 2.0, "scaled"), candidates)
        self.assertIn(("fixed:10:20", 1.0, 2.0, "fixed"), candidates)
        self.assertIn(("loss:2", None, 2.0, "scaled"), candidates)
        self.assertIn(("profit:1", 1.0, None, "scaled"), candidates)


if __name__ == "__main__":
    unittest.main()
