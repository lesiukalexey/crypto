"""Private SSH transport for the workstation-to-Pi paper runtime."""

from __future__ import annotations

import json
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from .contracts import load_strategy_release, release_digest, validate_strategy_release
from .paper import PaperExecutor, SQLiteStateStore
from .service import write_json_atomic


REMOTE_STATE = "/home/admin/.local/state/bitget-autotrading/state.sqlite"
REMOTE_RELEASE = "/home/admin/.config/bitget-autotrading/active-release.json"
REMOTE_PACKAGE = "/home/admin/.local/lib/bitget-autotrading"
REMOTE_PYTHON = "/usr/bin/python3"
REMOTE_UNIT = "bitget-autotrading.service"
_TARGET = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.@-]{0,127}$")
_SSH_OPTIONS = (
    "-T", "-oBatchMode=yes", "-oStrictHostKeyChecking=yes",
    "-oConnectTimeout=8", "-oServerAliveInterval=10", "-oServerAliveCountMax=2",
)


def _remote_command(module_args: str) -> str:
    return f"env PYTHONPATH={REMOTE_PACKAGE} {REMOTE_PYTHON} -m autotrading {module_args}"


def _ssh(target: str, command: str, *, input_text: str | None = None, timeout: float = 25) -> str:
    if not _TARGET.fullmatch(target):
        raise ValueError("Pi SSH target must be a host or user@host alias")
    try:
        result = subprocess.run(
            ["ssh", *_SSH_OPTIONS, target, command],
            input=input_text,
            text=True,
            capture_output=True,
            timeout=timeout,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise RuntimeError(f"Private SSH connection to Raspberry Pi failed: {type(exc).__name__}") from exc
    if result.returncode != 0:
        raise RuntimeError(f"Private SSH command failed (exit {result.returncode})")
    return result.stdout


def pull_status(target: str) -> dict[str, Any]:
    output = _ssh(target, _remote_command(f"pi-remote-status --state {REMOTE_STATE} --release {REMOTE_RELEASE}"))
    try:
        result = json.loads(output)
    except json.JSONDecodeError as exc:
        raise RuntimeError("Raspberry Pi returned invalid status JSON") from exc
    if not isinstance(result, dict) or result.get("mode") != "paper":
        raise RuntimeError("Raspberry Pi returned an unsupported executor status")
    return result


def stage_release(target: str, release_path: str | Path) -> dict[str, Any]:
    release = PaperExecutor.validate_release(load_strategy_release(release_path))
    payload = json.dumps(release, ensure_ascii=False, separators=(",", ":"))
    output = _ssh(
        target,
        _remote_command(f"pi-remote-stage-release --state {REMOTE_STATE} --release {REMOTE_RELEASE}"),
        input_text=payload,
        timeout=35,
    )
    try:
        result = json.loads(output)
    except json.JSONDecodeError as exc:
        raise RuntimeError("Raspberry Pi returned invalid strategy-stage status") from exc
    if not isinstance(result, dict) or not result.get("staged"):
        raise RuntimeError("Raspberry Pi did not confirm the strategy stage")
    return result


def remote_status(state_path: str, release_path: str) -> dict[str, Any]:
    if Path(state_path).is_file():
        store = SQLiteStateStore.read_only(state_path)
        try:
            status = store.health()
        finally:
            store.close()
    else:
        status = {"mode": "paper", "initialized": False, "open_positions": 0, "positions": [], "recent_trades": [], "recent_events": [], "trades": 0}
    release_id = None
    digest = None
    try:
        release = load_strategy_release(release_path)
        release_id = release["release_id"]
        digest = release_digest(release)
    except ValueError:
        pass
    running = subprocess.run(
        ["systemctl", "--user", "is-active", "--quiet", REMOTE_UNIT],
        capture_output=True,
        timeout=5,
        check=False,
    ).returncode == 0
    return {
        "mode": "paper",
        "checked_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "service_running": running,
        "active_release_id": release_id,
        "active_release_digest": digest,
        "state": status,
    }


def remote_stage_release(state_path: str, release_path: str, input_stream: Any = sys.stdin) -> dict[str, Any]:
    try:
        payload = json.load(input_stream)
    except (json.JSONDecodeError, UnicodeDecodeError) as exc:
        raise ValueError("Strategy payload is not valid JSON") from exc
    release = PaperExecutor.validate_release(validate_strategy_release(payload))
    state = Path(state_path)
    if state.exists():
        store = SQLiteStateStore.read_only(state)
        try:
            if store.health().get("open_positions", 0):
                raise RuntimeError("Cannot stage a different strategy while Pi paper positions are open")
        finally:
            store.close()
    write_json_atomic(release_path, release)
    return {
        "staged": True,
        "release_id": release["release_id"],
        "digest": release_digest(release),
        "restart_required": True,
    }
