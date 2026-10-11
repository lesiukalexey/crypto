from __future__ import annotations

import json
import sqlite3
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from autotrading.contracts import Quote, release_digest
from autotrading.paper import PaperExecutor, PortfolioExecutor, SQLiteStateStore


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

    def test_two_portfolio_strategies_share_balance_and_keep_separate_positions(self) -> None:
        store = SQLiteStateStore(self.path)
        first = json.loads(json.dumps(self.payload))
        second = json.loads(json.dumps(self.payload))
        second["release_id"] = "second-strategy"
        second["market"]["symbols"] = ["BTCUSDT"]
        store.add_portfolio_strategy(first, name="Первая")
        store.add_portfolio_strategy(second, name="Вторая")
        executor = PortfolioExecutor(store)
        executor.on_quotes([self.quote("99.9", "100", 1_000_000)], now_ms=1_000_000)
        health = store.health()
        self.assertEqual(health["initial_balance_usdt"], "100")
        self.assertEqual(health["open_positions"], 2)
        self.assertEqual(len({position["strategy_id"] for position in health["positions"]}), 2)
        exposure = sum(Decimal(row["quantity"]) * Decimal(row["entry_price"]) for row in store.db.execute("SELECT quantity,entry_price FROM positions"))
        self.assertLessEqual(exposure, Decimal("100"))
        store.close()

    def test_favorite_hedge_opens_and_closes_both_legs_together(self) -> None:
        store = SQLiteStateStore(self.path)
        hedge = json.loads(json.dumps(self.payload))
        hedge["release_id"] = "favorite-hedge"
        hedge["market"]["symbols"] = ["BTCUSDT", "ETHUSDT"]
        hedge["execution"]["starting_balance_usdt"] = "100"
        store.add_portfolio_strategy(hedge, kind="hedge", name="BTC / ETH")
        executor = PortfolioExecutor(store)
        btc = self.quote("99.9", "100", 1_000_000)
        eth = Quote("ETHUSDT", Decimal("49.9"), Decimal("50"), Decimal("49.95"), 1_000_000)
        executor.on_quotes([btc, eth], now_ms=1_000_000)
        legs = store.db.execute("SELECT leg,side FROM positions ORDER BY leg").fetchall()
        self.assertEqual([(row["leg"], row["side"]) for row in legs], [("long", "long"), ("short", "short")])
        executor.set_controls(entries_paused=True, close_positions=True)
        btc2 = self.quote("99.8", "99.9", 1_005_000)
        eth2 = Quote("ETHUSDT", Decimal("50.1"), Decimal("50.2"), Decimal("50.15"), 1_005_000)
        executor.on_quotes([btc2, eth2], now_ms=1_005_000)
        self.assertEqual(store.health()["open_positions"], 0)
        self.assertEqual(store.health()["trades"], 2)
        store.close()

    def test_shared_drawdown_floor_tracks_peak_equity_and_stops_paper_strategy(self) -> None:
        store = SQLiteStateStore(self.path)
        payload = json.loads(json.dumps(self.payload))
        payload["execution"].update(starting_balance_usdt="10", taker_fee_rate="0")
        payload["risk"].update(max_order_notional_usdt="10", max_total_exposure_usdt="10")
        store.add_portfolio_strategy(payload, name="Ten dollars")
        executor = PortfolioExecutor(store, minimum_equity_percent=Decimal("75"))
        strategy_id = store.db.execute("SELECT strategy_id FROM portfolio_strategies").fetchone()[0]
        executor.on_quotes([self.quote("99.9", "100", 1_000_000)], now_ms=1_000_000)
        self.assertFalse(store.update_strategy_equity(strategy_id, Decimal("100"), Decimal("75"), 1_001_000))
        self.assertTrue(store.update_strategy_equity(strategy_id, Decimal("75"), Decimal("75"), 1_002_000))
        executor.on_quotes([self.quote("75", "75.1", 1_005_000)], now_ms=1_005_000)
        run = store.strategy_runs()[0]
        self.assertEqual(run["status"], "stopped")
        self.assertEqual(run["stop_reason"], "loss_threshold")
        self.assertEqual(run["peak_equity_usdt"], "100")
        self.assertEqual(run["ending_equity_usdt"], "7.5")
        self.assertEqual(store.health()["open_positions"], 0)
        self.assertEqual(store.db.execute("SELECT enabled FROM portfolio_strategies").fetchone()[0], 0)
        store.close()

    def test_seventy_five_percent_floor_stops_ten_dollar_allocation_at_seven_fifty(self) -> None:
        store = SQLiteStateStore(self.path)
        payload = json.loads(json.dumps(self.payload))
        payload["execution"]["starting_balance_usdt"] = "10"
        store.add_portfolio_strategy(payload, name="Ten dollars")
        executor = PortfolioExecutor(store, minimum_equity_percent=Decimal("75"))
        strategy_id = store.db.execute("SELECT strategy_id FROM portfolio_strategies").fetchone()[0]
        run = store.active_strategy_run(strategy_id)
        self.assertEqual(run["allocation_usdt"], "10")
        self.assertFalse(store.update_strategy_equity(strategy_id, Decimal("10"), Decimal("75"), 1_001_000))
        self.assertTrue(store.update_strategy_equity(strategy_id, Decimal("7.5"), Decimal("75"), 1_002_000))
        store.close()

    def test_manual_stop_ends_only_selected_strategy_and_can_be_resumed(self) -> None:
        store = SQLiteStateStore(self.path)
        first = json.loads(json.dumps(self.payload))
        second = json.loads(json.dumps(self.payload))
        second["release_id"] = "manual-stop-second"
        second["market"]["symbols"] = ["ETHUSDT"]
        store.add_portfolio_strategy(first, name="First")
        second_id = store.add_portfolio_strategy(second, name="Second")
        executor = PortfolioExecutor(store)
        executor.on_quotes([
            self.quote("99.9", "100", 1_000_000),
            Quote("ETHUSDT", Decimal("49.9"), Decimal("50"), Decimal("49.95"), 1_000_000),
        ], now_ms=1_000_000)
        self.assertEqual(store.health()["open_positions"], 2)
        store.request_strategy_stop(second_id, now_ms=1_001_000)
        executor.on_quotes([Quote("ETHUSDT", Decimal("49.9"), Decimal("50"), Decimal("49.95"), 1_005_000)], now_ms=1_005_000)
        runs = {run["strategy_name"]: run for run in store.strategy_runs()}
        self.assertEqual(runs["Second"]["stop_reason"], "manual")
        self.assertEqual(runs["Second"]["status"], "stopped")
        self.assertEqual(runs["First"]["status"], "running")
        self.assertEqual(store.health()["open_positions"], 1)
        store.resume_strategy(second_id)
        executor.refresh()
        self.assertEqual(store.active_strategy_run(second_id)["status"], "running")
        store.close()

    def test_legacy_single_strategy_positions_migrate_without_being_lost(self) -> None:
        connection = sqlite3.connect(self.path)
        connection.executescript("""
          CREATE TABLE releases(digest TEXT PRIMARY KEY,payload TEXT NOT NULL,activated_at_ms INTEGER NOT NULL);
          CREATE TABLE runtime_meta(key TEXT PRIMARY KEY, value TEXT NOT NULL);
          CREATE TABLE quotes(symbol TEXT NOT NULL,ts INTEGER NOT NULL,bid TEXT NOT NULL,ask TEXT NOT NULL,last TEXT NOT NULL,PRIMARY KEY(symbol,ts));
          CREATE TABLE events(id INTEGER PRIMARY KEY,ts INTEGER NOT NULL,type TEXT NOT NULL,symbol TEXT,payload TEXT NOT NULL);
          CREATE TABLE positions(symbol TEXT PRIMARY KEY,side TEXT NOT NULL,quantity TEXT NOT NULL,entry_price TEXT NOT NULL,entry_fee TEXT NOT NULL,entry_ts INTEGER NOT NULL,pending_exit TEXT,pending_count INTEGER NOT NULL DEFAULT 0);
          CREATE TABLE trades(id INTEGER PRIMARY KEY,symbol TEXT NOT NULL,side TEXT NOT NULL,quantity TEXT NOT NULL,entry_price TEXT NOT NULL,exit_price TEXT NOT NULL,entry_ts INTEGER NOT NULL,exit_ts INTEGER NOT NULL,pnl TEXT NOT NULL,release_digest TEXT NOT NULL DEFAULT '');
          CREATE TABLE account(id INTEGER PRIMARY KEY CHECK(id=1),balance TEXT NOT NULL,realized_pnl TEXT NOT NULL,day TEXT NOT NULL,daily_pnl TEXT NOT NULL);
          INSERT INTO runtime_meta VALUES('active_release_digest','old-release');
          INSERT INTO positions VALUES('BTCUSDT','long','1','100','0','1000000',NULL,0);
          INSERT INTO account VALUES(1,'100','0','2026-10-11','0');
        """)
        connection.commit()
        connection.close()
        legacy_connection = sqlite3.connect(self.path)
        legacy_payload = json.dumps(self.payload)
        legacy_connection.execute("INSERT INTO releases VALUES('old-release',?,1)", (legacy_payload,))
        legacy_connection.commit()
        legacy_connection.close()
        read_only_store = SQLiteStateStore.read_only(self.path)
        try:
            self.assertEqual(read_only_store.health()["open_positions"], 1)
        finally:
            read_only_store.close()
        store = SQLiteStateStore(self.path)
        position = store.db.execute("SELECT strategy_id,symbol,leg FROM positions").fetchone()
        self.assertEqual((position["strategy_id"], position["symbol"], position["leg"]), ("old-release", "BTCUSDT", "single"))
        self.assertEqual(store.account()["initial_balance"], "100")
        store.close()


if __name__ == "__main__":
    unittest.main()
