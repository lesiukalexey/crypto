"""Local Linux process and file controls for the paper executor."""

from __future__ import annotations

import json
import fcntl
import os
import signal
import subprocess
import sys
import time
from pathlib import Path
from typing import Any


def write_json_atomic(path: str | Path, payload: dict[str, Any]) -> None:
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = target.with_name(f".{target.name}.{os.getpid()}.tmp")
    temporary.write_text(json.dumps(payload, sort_keys=True), encoding="utf-8")
    os.chmod(temporary, 0o600)
    temporary.replace(target)


def read_json(path: str | Path, default: dict[str, Any]) -> dict[str, Any]:
    try:
        value = json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return default
    return value if isinstance(value, dict) else default


def _process_matches(pid: int, state: Path) -> bool:
    try:
        if pid <= 1:
            return False
        command = Path(f"/proc/{pid}/cmdline").read_bytes().replace(b"\0", b" ").decode(errors="replace")
        return "-m autotrading paper-run" in command and str(state.resolve()) in command
    except OSError:
        return False


def service_status(pid_file: str | Path, state: str | Path) -> dict[str, Any]:
    pid_path, state_path = Path(pid_file), Path(state)
    record = read_json(pid_path, {})
    pid = record.get("pid")
    running = isinstance(pid, int) and _process_matches(pid, state_path)
    if not running and record:
        try:
            pid_path.unlink()
        except OSError:
            pass
    return {"running": running, "pid": pid if running else None}


def start_service(release: str | Path, state: str | Path, control: str | Path, pid_file: str | Path, log: str | Path, interval: float) -> dict[str, Any]:
    pid_path = Path(pid_file)
    pid_path.parent.mkdir(parents=True, exist_ok=True)
    with pid_path.with_suffix(pid_path.suffix + ".lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        return _start_service(release, state, control, pid_file, log, interval)


def _start_service(release: str | Path, state: str | Path, control: str | Path, pid_file: str | Path, log: str | Path, interval: float) -> dict[str, Any]:
    release_path, state_path = Path(release).resolve(), Path(state).resolve()
    control_path, pid_path, log_path = Path(control).resolve(), Path(pid_file).resolve(), Path(log).resolve()
    status = service_status(pid_path, state_path)
    if status["running"]:
        return {"started": False, "already_running": True, "pid": status["pid"]}
    for path in (release_path, state_path, state_path.parent, control_path, control_path.parent, pid_path, pid_path.parent, log_path, log_path.parent):
        if path.exists() and path.is_symlink():
            raise ValueError(f"Runtime path cannot be a symlink: {path}")
    log_path.parent.mkdir(parents=True, exist_ok=True)
    log_handle = log_path.open("ab", buffering=0)
    package_root = Path(__file__).resolve().parents[1]
    env = os.environ.copy()
    env["PYTHONPATH"] = str(package_root) + (os.pathsep + env["PYTHONPATH"] if env.get("PYTHONPATH") else "")
    process = subprocess.Popen(
        [sys.executable, "-m", "autotrading", "paper-run", str(release_path), "--state", str(state_path), "--control", str(control_path), "--interval", str(interval)],
        cwd=package_root, env=env, stdin=subprocess.DEVNULL, stdout=log_handle, stderr=subprocess.STDOUT,
        start_new_session=True, close_fds=True,
    )
    log_handle.close()
    write_json_atomic(pid_path, {"pid": process.pid, "state": str(state_path), "started_at_ms": int(time.time() * 1000)})
    time.sleep(0.15)
    if process.poll() is not None:
        pid_path.unlink(missing_ok=True)
        raise RuntimeError(f"Paper service exited during startup; see {log_path}")
    return {"started": True, "pid": process.pid}


def stop_service(pid_file: str | Path, state: str | Path, timeout_seconds: float = 8) -> dict[str, Any]:
    pid_path = Path(pid_file)
    pid_path.parent.mkdir(parents=True, exist_ok=True)
    with pid_path.with_suffix(pid_path.suffix + ".lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        return _stop_service(pid_file, state, timeout_seconds)


def _stop_service(pid_file: str | Path, state: str | Path, timeout_seconds: float = 8) -> dict[str, Any]:
    status = service_status(pid_file, state)
    if not status["running"]:
        return {"stopped": False, "already_stopped": True}
    pid = status["pid"]
    os.kill(pid, signal.SIGTERM)
    until = time.monotonic() + timeout_seconds
    while time.monotonic() < until and _process_matches(pid, Path(state)):
        time.sleep(0.1)
    stopped = not _process_matches(pid, Path(state))
    if stopped:
        Path(pid_file).unlink(missing_ok=True)
    return {"stopped": stopped, "pid": pid}


def set_controls(path: str | Path, *, entries_paused: bool | None = None, close_positions: bool | None = None) -> dict[str, Any]:
    controls = read_json(path, {"entries_paused": False, "close_positions": False})
    if entries_paused is not None:
        controls["entries_paused"] = entries_paused
    if close_positions is not None:
        controls["close_positions"] = close_positions
    write_json_atomic(path, controls)
    return controls
