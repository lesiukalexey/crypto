from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from import_historical_candles import (
    INTERVAL_MS,
    PAGE_CANDLES,
    chunk_candle_ranges,
    missing_candle_ranges,
    write_status,
)


class HistoricalCandleCoverageTests(unittest.TestCase):
    def test_fully_archived_range_has_no_gaps(self) -> None:
        start = 1_000_000
        timestamps = [start + offset * INTERVAL_MS for offset in range(4)]
        self.assertEqual(missing_candle_ranges(start, start + 4 * INTERVAL_MS, timestamps, []), [])

    def test_finds_leading_interior_and_trailing_gaps(self) -> None:
        start = 1_000_000
        timestamps = [start + offset * INTERVAL_MS for offset in (1, 2, 5)]
        self.assertEqual(
            missing_candle_ranges(start, start + 7 * INTERVAL_MS, timestamps, []),
            [(start, start + INTERVAL_MS), (start + 3 * INTERVAL_MS, start + 5 * INTERVAL_MS),
             (start + 6 * INTERVAL_MS, start + 7 * INTERVAL_MS)],
        )

    def test_saved_empty_api_range_is_covered(self) -> None:
        start = 1_000_000
        coverage = [(start + INTERVAL_MS, start + 4 * INTERVAL_MS)]
        self.assertEqual(
            missing_candle_ranges(start, start + 5 * INTERVAL_MS, [start + 4 * INTERVAL_MS], coverage),
            [(start, start + INTERVAL_MS)],
        )

    def test_chunks_missing_ranges_under_api_limit(self) -> None:
        start = 1_000_000
        ranges = chunk_candle_ranges([(start, start + (PAGE_CANDLES * 2 + 3) * INTERVAL_MS)])
        self.assertEqual(
            [end - begin for begin, end in ranges],
            [PAGE_CANDLES * INTERVAL_MS, PAGE_CANDLES * INTERVAL_MS, 3 * INTERVAL_MS],
        )

    def test_empty_or_reversed_range_has_no_gaps(self) -> None:
        self.assertEqual(missing_candle_ranges(100, 100, [], []), [])
        self.assertEqual(missing_candle_ranges(200, 100, [], []), [])

    def test_status_preserves_start_time_and_records_finish_time(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "job.json"
            write_status(path, {"status": "running", "progress": 0, "started_at_ms": 1234})
            write_status(path, {"status": "running", "progress": 50})
            running = json.loads(path.read_text(encoding="utf-8"))
            self.assertEqual(running["started_at_ms"], 1234)
            self.assertNotIn("finished_at_ms", running)

            write_status(path, {"status": "ready", "progress": 100})
            finished = json.loads(path.read_text(encoding="utf-8"))
            self.assertEqual(finished["started_at_ms"], 1234)
            self.assertGreaterEqual(finished["finished_at_ms"], finished["started_at_ms"])


if __name__ == "__main__":
    unittest.main()
