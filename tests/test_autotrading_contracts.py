from __future__ import annotations

import copy
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from autotrading.contracts import load_strategy_release, release_digest, validate_strategy_release


ROOT = Path(__file__).resolve().parents[1]


def valid_release() -> dict:
    return {
        "schema_version": 1,
        "release_id": "btc-paper-20261011-001",
        "created_at_utc": "2026-10-11T09:00:00Z",
        "market": {"category": "USDT-FUTURES", "symbols": ["BTCUSDT"], "margin_coin": "USDT"},
        "strategy": {
            "entry_config": "entry:10:20:follow",
            "entry_signal_mode": "exact",
            "entry_direction_mode": "follow",
            "exit_config": "exit:fixed:100:50",
            "martingale_mode": "none",
            "martingale_timing": "immediate",
            "martingale_attempts": 3,
            "max_chain_loss_usdt": "0",
            "max_day_loss_usdt": "0",
        },
        "execution": {
            "starting_balance_usdt": "10",
            "leverage": "1",
            "taker_fee_rate": "0.0006",
            "slippage_bps": "0",
        },
        "risk": {
            "max_order_notional_usdt": "10",
            "max_total_exposure_usdt": "10",
            "max_daily_loss_usdt": "1",
            "max_open_positions": 1,
        },
        "backtest": {
            "start_date": "2026-10-01",
            "end_date": "2026-10-10",
            "result_key": "abc123",
            "net_result_usdt": "1.25",
            "trade_count": 4,
        },
    }


class StrategyReleaseTests(unittest.TestCase):
    def assert_invalid(self, payload: dict, message: str) -> None:
        with self.assertRaisesRegex(ValueError, message):
            validate_strategy_release(payload)

    def test_valid_release_round_trips_and_has_stable_digest(self) -> None:
        release = valid_release()
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "release.json"
            path.write_text(json.dumps(release), encoding="utf-8")
            loaded = load_strategy_release(path)
            self.assertEqual(loaded, release)
            self.assertEqual(release_digest(loaded), release_digest(release))
            result = subprocess.run(
                [sys.executable, "-m", "autotrading", "validate", str(path)],
                cwd=ROOT,
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("Valid strategy release", result.stdout)
            self.assertIn("SHA-256:", result.stdout)

    def test_rejects_unknown_version_and_unexpected_secret(self) -> None:
        release = valid_release()
        unknown = copy.deepcopy(release)
        unknown["schema_version"] = 2
        self.assert_invalid(unknown, "Unsupported strategy schema version")

        with_secret = copy.deepcopy(release)
        with_secret["api_secret"] = "must-not-be-in-release"
        self.assert_invalid(with_secret, "unsupported fields")

    def test_rejects_unsafe_symbol_and_inconsistent_risk(self) -> None:
        release = valid_release()
        unsafe = copy.deepcopy(release)
        unsafe["market"]["symbols"] = ["../BTCUSDT"]
        self.assert_invalid(unsafe, "invalid format")

        inconsistent = copy.deepcopy(release)
        inconsistent["risk"]["max_order_notional_usdt"] = "11"
        self.assert_invalid(inconsistent, "cannot exceed")

    def test_rejects_non_string_enums_and_non_utc_timestamps(self) -> None:
        release = valid_release()
        release["market"]["category"] = []
        self.assert_invalid(release, "market.category is not supported")

        release = valid_release()
        release["strategy"]["entry_signal_mode"] = []
        self.assert_invalid(release, "entry_signal_mode is not supported")

        release = valid_release()
        release["created_at_utc"] = "2026-10-11T12:00:00+03:00"
        self.assert_invalid(release, "created_at_utc must use UTC")


if __name__ == "__main__":
    unittest.main()
