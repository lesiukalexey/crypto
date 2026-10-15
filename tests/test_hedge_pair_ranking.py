"""Parity tests for the Numba hedge-return kernel and the PHP reference."""

from __future__ import annotations

import json
import shutil
import struct
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from hedge_pair_ranking import _calculate_chunk_kernel, _calculate_metrics_chunk_kernel  # noqa: E402


class HedgePairRankingParityTests(unittest.TestCase):
    def test_numba_matches_php_for_aligned_stale_duplicate_and_empty_streams(self) -> None:
        if shutil.which("php") is None:
            self.skipTest("PHP CLI is not installed")

        streams = [
            [(1_000, 100.0, 101.0), (2_000, 102.0, 103.0), (2_000, 102.5, 103.5), (3_000, 0.0, 1.0), (4_000, 104.0, 105.0)],
            [(1_200, 50.0, 51.0), (2_500, 52.0, 53.0), (4_000, 54.0, 55.0)],
            [(500_000, 80.0, 81.0), (501_000, 82.0, 83.0)],
            [],
        ]
        dtype = np.dtype([("timestamp", "<u8"), ("bid", "<f8"), ("ask", "<f8")])
        records = np.empty(sum(map(len, streams)), dtype=dtype)
        offsets: list[int] = []
        counts: list[int] = []
        cursor = 0
        for stream in streams:
            offsets.append(cursor)
            counts.append(len(stream))
            if stream:
                records[cursor : cursor + len(stream)] = stream
            cursor += len(stream)
        timestamps, bids, asks = records["timestamp"], records["bid"], records["ask"]
        offsets_array = np.asarray(offsets, dtype=np.int64)
        counts_array = np.asarray(counts, dtype=np.int64)

        numba_results: list[list[float | None]] = []
        for long_index in range(len(streams)):
            values = _calculate_chunk_kernel(
                timestamps,
                bids,
                asks,
                offsets_array,
                counts_array,
                long_index,
                0,
                len(streams),
                250.0,
                0.0006,
                120_000,
            )
            numba_results.append([None if np.isnan(value) else float(value) for value in values])

        php_code = r'''
require $argv[1];
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$packed = [];
foreach ($data as $stream) {
    $bytes = '';
    foreach ($stream as $quote) $bytes .= pack('Pee', $quote[0], $quote[1], $quote[2]);
    $packed[] = $bytes;
}
$result = [];
foreach ($packed as $longIndex => $longRows) {
    $result[$longIndex] = [];
    foreach ($packed as $shortIndex => $shortRows) {
        $result[$longIndex][$shortIndex] = $longIndex === $shortIndex
            ? null
            : cross_hedge_net_pnl_packed($longRows, $shortRows, 250.0, 0.0006);
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
'''
        php_result = subprocess.run(
            ["php", "-r", php_code, str(ROOT / "hedge_simulation.php")],
            input=json.dumps(streams),
            text=True,
            capture_output=True,
            check=True,
        )
        php_results = json.loads(php_result.stdout)

        for long_index, row in enumerate(numba_results):
            for short_index, actual in enumerate(row):
                expected = php_results[long_index][short_index]
                if expected is None:
                    self.assertIsNone(actual, f"pair {long_index}->{short_index} should be unavailable")
                else:
                    self.assertIsNotNone(actual, f"pair {long_index}->{short_index} should have a PnL")
                    self.assertAlmostEqual(actual, expected, delta=1e-10)

    def test_full_path_peak_and_drawdown_match_php_chart(self):
        streams=[[(1000,100.,101.),(2000,140.,141.),(3000,110.,111.),(4000,120.,121.)],
                 [(1100,50.,51.),(2100,45.,46.),(3100,60.,61.),(4000,55.,56.)],
                 [(500000,70.,71.)],[]]
        dtype=np.dtype([('timestamp','<u8'),('bid','<f8'),('ask','<f8')])
        records=np.array([point for stream in streams for point in stream],dtype=dtype)
        counts=np.array(list(map(len,streams)),dtype=np.int64)
        offsets=np.r_[0,np.cumsum(counts[:-1])].astype(np.int64)
        code=r"""
require $argv[1];$streams=json_decode(stream_get_contents(STDIN),true);$out=[];
foreach($streams as $i=>$long){foreach($streams as $j=>$short){
 $convert=static fn($stream)=>array_map(static fn($q)=>['_timestamp_ms'=>$q[0],'bid_price'=>$q[1],'ask_price'=>$q[2],'last_price'=>($q[1]+$q[2])/2],$stream);
 $out[$i][$j]=$i===$j?null:simulate_cross_hedge($convert($long),$convert($short),100.,.0006)['summary'];
}}echo json_encode($out);
"""
        expected=json.loads(subprocess.check_output(['php','-r',code,str(ROOT/'hedge_simulation.php')],input=json.dumps(streams),text=True))
        for i in range(len(streams)):
            metrics=_calculate_metrics_chunk_kernel(records['timestamp'],records['bid'],records['ask'],offsets,counts,i,0,len(streams),100.,.0006,120000)
            for j,values in enumerate(metrics):
                ref=expected[i][j]
                if ref is None:self.assertTrue(np.all(np.isnan(values)))
                else:
                    np.testing.assert_allclose(values,[ref['net_pnl'],ref['maximum_net_pnl'],ref['maximum_drawdown']],rtol=0,atol=1e-10)
        # A monotonic increase has no drawdown; callers must not divide by zero.
        bids=np.array([100.,101.,102.,100.,100.,100.]);asks=bids.copy()
        monotonic=_calculate_metrics_chunk_kernel(np.array([0,1000,2000,0,1000,2000],dtype=np.uint64),bids,asks,np.array([0,3]),np.array([3,3]),0,1,2,100.,0.,120000)
        self.assertEqual(monotonic[0,2],0.)

    def test_worker_reads_packed_file_and_streams_result_chunks(self) -> None:
        streams = [
            [(1_000, 100.0, 101.0), (2_000, 102.0, 103.0)],
            [(1_200, 50.0, 51.0), (2_100, 52.0, 53.0)],
        ]
        with tempfile.TemporaryDirectory() as directory:
            quotes_path = Path(directory) / "quotes.bin"
            metadata_path = Path(directory) / "metadata.json"
            cache_path = Path(directory) / "numba-cache"
            with quotes_path.open("wb") as stream:
                for quotes in streams:
                    for quote in quotes:
                        stream.write(struct.pack("<Qdd", *quote))
            metadata_path.write_text(
                json.dumps({"symbols": ["LONG", "SHORT"], "counts": [2, 2], "balance": 250.0, "fee_rate": 0.0006}),
                encoding="utf-8",
            )
            result = subprocess.run(
                [sys.executable, str(ROOT / "hedge_pair_ranking.py"), str(quotes_path), str(metadata_path), str(cache_path)],
                text=True,
                capture_output=True,
                check=True,
            )

        messages = [json.loads(line) for line in result.stdout.splitlines()]
        chunks = [message for message in messages if message["type"] == "chunk"]
        self.assertEqual([message["type"] for message in messages][-1], "done")
        self.assertEqual(len(chunks), 2)
        self.assertIsNone(chunks[0]["values"][0], "diagonal pair should be unavailable")
        self.assertIsInstance(chunks[0]["values"][1], float)
        self.assertIsInstance(chunks[1]["values"][0], float)
        self.assertIsNone(chunks[1]["values"][1], "diagonal pair should be unavailable")


if __name__ == "__main__":
    unittest.main()
