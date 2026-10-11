from __future__ import annotations

import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from autotrading.contracts import Quote, release_digest
from autotrading.paper import PaperExecutor, SQLiteStateStore


def release() -> dict:
    return json.loads(Path("autotrading/example-release.json").read_text())


class PaperExecutorTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.path = Path(self.temp.name) / "state.sqlite"
        self.payload = release()
        self.payload["execution"]["starting_balance_usdt"] = "100"
        self.payload["risk"].update(max_order_notional_usdt="100", max_total_exposure_usdt="100", max_daily_loss_usdt="20")
        self.payload["strategy"].update(entry_config="immediate:long", exit_config="fixed:100:100", exit_confirmation_mode="immediate")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def quote(self, bid: str, ask: str, ts: int) -> Quote:
        return Quote("BTCUSDT", Decimal(bid), Decimal(ask), (Decimal(bid) + Decimal(ask)) / 2, ts)

    def test_entry_exit_are_persisted_and_recovered(self) -> None:
        store = SQLiteStateStore(self.path)
        executor = PaperExecutor(self.payload, store)
        executor.on_quote(self.quote("99.9", "100", 1_000_000), now_ms=1_000_000)
        self.assertEqual(store.health()["open_positions"], 1)
        self.assertIsNotNone(store.health()["positions"][0]["unrealized_pnl_usdt"])
        store.close()

        store = SQLiteStateStore(self.path)
        executor = PaperExecutor(self.payload, store)
        executor.on_quote(self.quote("101.5", "101.6", 1_005_000), now_ms=1_005_000)
        self.assertEqual(store.health()["open_positions"], 0)
        self.assertEqual(store.health()["trades"], 1)
        trade = store.db.execute("SELECT pnl FROM trades").fetchone()
        self.assertEqual(Decimal(trade["pnl"]), Decimal("1.3791"))
        store.close()

    def test_stale_quote_is_rejected_and_duplicate_timestamp_is_not_replayed(self) -> None:
        store = SQLiteStateStore(self.path)
        executor = PaperExecutor(self.payload, store)
        quote = self.quote("99.9", "100", 1_000_000)
        executor.on_quote(quote, now_ms=1_040_000)
        fresh = self.quote("99.9", "100", 1_041_000)
        executor.on_quote(fresh, now_ms=1_041_000)
        executor.on_quote(fresh, now_ms=1_041_000)
        self.assertEqual(store.health()["open_positions"], 1)
        kinds = [row[0] for row in store.db.execute("SELECT type FROM events ORDER BY id")]
        self.assertEqual(kinds, ["stale_quote", "paper_entry", "duplicate_quote"])
        store.close()

    def test_unsupported_funding_and_martingale_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as folder:
            store = SQLiteStateStore(Path(folder) / "state.sqlite")
            funded = release()
            funded["execution"]["include_funding"] = True
            with self.assertRaisesRegex(ValueError, "does not model funding"):
                PaperExecutor(funded, store)
            martingale = release()
            martingale["execution"]["include_funding"] = False
            martingale["strategy"]["martingale_mode"] = "simple"
            with self.assertRaisesRegex(ValueError, "martingale_mode=none"):
                PaperExecutor(martingale, store)
            malformed = release()
            malformed["execution"]["include_funding"] = False
            malformed["strategy"]["exit_config"] = "unknown-exit"
            with self.assertRaisesRegex(ValueError, "Unsupported exit_config"):
                PaperExecutor.validate_release(malformed)
            store.close()

    def test_release_cannot_change_while_position_is_open(self) -> None:
        store = SQLiteStateStore(self.path)
        executor = PaperExecutor(self.payload, store)
        executor.on_quote(self.quote("99.9", "100", 1_000_000), now_ms=1_000_000)
        changed = dict(self.payload)
        changed["release_id"] = "another-paper-release"
        changed["strategy"] = dict(self.payload["strategy"], exit_config="fixed:200:100")
        with self.assertRaisesRegex(ValueError, "positions are open"):
            PaperExecutor(changed, store)
        store.close()

    def test_entry_uses_release_signal_and_direction_modes(self) -> None:
        payload = dict(self.payload)
        payload["strategy"] = dict(self.payload["strategy"], entry_config="entry:2:10", entry_direction_mode="reverse")
        store = SQLiteStateStore(self.path)
        executor = PaperExecutor(payload, store)
        for index, price in enumerate(("100", "100", "100", "101.2")):
            tick = index * 5_000 + 1_000_000
            executor.on_quote(self.quote(price, str(Decimal(price) + Decimal("0.1")), tick), now_ms=tick)
        position = store.position("BTCUSDT")
        self.assertIsNotNone(position)
        self.assertEqual(position["side"], "short")
        store.close()

    def test_pause_entries_and_close_position_are_separate_controls(self) -> None:
        store = SQLiteStateStore(self.path)
        executor = PaperExecutor(self.payload, store)
        executor.set_controls(entries_paused=True, close_positions=False)
        executor.on_quote(self.quote("99.9", "100", 1_000_000), now_ms=1_000_000)
        self.assertEqual(store.health()["open_positions"], 0)
        executor.set_controls(entries_paused=False, close_positions=False)
        executor.on_quote(self.quote("99.9", "100", 1_005_000), now_ms=1_005_000)
        self.assertEqual(store.health()["open_positions"], 1)
        executor.set_controls(entries_paused=True, close_positions=True)
        executor.on_quote(self.quote("99.8", "99.9", 1_010_000), now_ms=1_010_000)
        self.assertEqual(store.health()["open_positions"], 0)
        self.assertEqual(store.db.execute("SELECT json_extract(payload,'$.reason') FROM events WHERE type='paper_exit'").fetchone()[0], "manual_close")
        store.close()

    def test_strategy_change_starts_a_new_paper_balance_and_tags_old_trades(self) -> None:
        store = SQLiteStateStore(self.path)
        executor = PaperExecutor(self.payload, store)
        old_digest = release_digest(self.payload)
        executor.on_quote(self.quote("99.9", "100", 1_000_000), now_ms=1_000_000)
        executor.set_controls(entries_paused=True, close_positions=True)
        executor.on_quote(self.quote("99.8", "99.9", 1_005_000), now_ms=1_005_000)
        changed = json.loads(json.dumps(self.payload))
        changed["release_id"] = "new-paper-session"
        changed["execution"]["starting_balance_usdt"] = "50"
        PaperExecutor(changed, store)
        account = store.account()
        self.assertEqual(account["balance"], "50")
        self.assertEqual(account["realized_pnl"], "0")
        self.assertEqual(store.db.execute("SELECT release_digest FROM trades").fetchone()[0], old_digest)
        store.close()


if __name__ == "__main__":
    unittest.main()
