from __future__ import annotations

import io
import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path
from unittest.mock import patch

from autotrading.paper import SQLiteStateStore
from autotrading.pi_sync import (
    REMOTE_PACKAGE,
    REMOTE_RELEASE,
    REMOTE_STATE,
    _ssh,
    pull_status,
    remote_stage_release,
    remote_status,
    stage_release,
)


class PiSyncTests(unittest.TestCase):
    def test_status_uses_batch_ssh_with_strict_host_key_checking(self) -> None:
        remote = {"mode": "paper", "service_running": True, "state": {"open_positions": 0}}
        with patch("autotrading.pi_sync.subprocess.run") as run:
            run.return_value.returncode = 0
            run.return_value.stdout = json.dumps(remote)
            self.assertEqual(pull_status("admin@raspberry.local"), remote)
        argv = run.call_args.args[0]
        self.assertIn("-oBatchMode=yes", argv)
        self.assertIn("-oStrictHostKeyChecking=yes", argv)
        self.assertNotIn("shell", run.call_args.kwargs)
        self.assertIn(REMOTE_PACKAGE, argv[-1])
        self.assertIn(REMOTE_STATE, argv[-1])
        self.assertIn(REMOTE_RELEASE, argv[-1])

    def test_strategy_is_validated_and_sent_on_stdin_over_ssh(self) -> None:
        release_path = Path(__file__).parents[1] / "autotrading" / "example-release.json"
        with patch("autotrading.pi_sync.subprocess.run") as run:
            run.return_value.returncode = 0
            run.return_value.stdout = json.dumps({"staged": True, "restart_required": True, "release_id": "example"})
            result = stage_release("raspberry", release_path)
        self.assertTrue(result["staged"])
        kwargs = run.call_args.kwargs
        self.assertIn('"release_id":"btc-paper-example-001"', kwargs["input"])
        self.assertIn("pi-remote-stage-release", run.call_args.args[0][-1])
        self.assertNotIn("BITGET_LIVE_API", kwargs["input"])

    def test_invalid_ssh_target_is_rejected_before_running_ssh(self) -> None:
        with patch("autotrading.pi_sync.subprocess.run") as run:
            with self.assertRaisesRegex(ValueError, "SSH target"):
                _ssh("host; touch /tmp/pwned", "true")
        run.assert_not_called()

    def test_remote_status_does_not_create_missing_state_file(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            state = Path(temp) / "missing.sqlite"
            release = Path(temp) / "missing-release.json"
            with patch("autotrading.pi_sync.subprocess.run") as run:
                run.return_value.returncode = 3
                status = remote_status(str(state), str(release))
            self.assertFalse(state.exists())
            self.assertFalse(status["service_running"])
            self.assertFalse(status["state"]["initialized"])

    def test_remote_release_stage_requires_flat_state_and_writes_private_file(self) -> None:
        source = Path(__file__).parents[1] / "autotrading" / "example-release.json"
        payload = source.read_text()
        with tempfile.TemporaryDirectory() as temp:
            target = Path(temp) / "active-release.json"
            result = remote_stage_release(str(Path(temp) / "no-state.sqlite"), str(target), io.StringIO(payload))
            self.assertTrue(result["staged"])
            self.assertEqual(target.stat().st_mode & 0o777, 0o600)
            self.assertEqual(json.loads(target.read_text())["release_id"], "btc-paper-example-001")

            state_path = Path(temp) / "state.sqlite"
            store = SQLiteStateStore(state_path)
            store.activate(json.loads(payload))
            store.open_position("BTCUSDT", "long", Decimal("0.001"), Decimal("100"), Decimal("0"), 1_700_000_000_000)
            store.close()
            with self.assertRaisesRegex(RuntimeError, "positions are open"):
                remote_stage_release(str(state_path), str(target), io.StringIO(payload))


if __name__ == "__main__":
    unittest.main()
