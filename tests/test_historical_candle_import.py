from __future__ import annotations

import unittest

from import_historical_candles import (
    INTERVAL_MS,
    PAGE_CANDLES,
    chunk_candle_ranges,
    missing_candle_ranges,
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


if __name__ == "__main__":
    unittest.main()
