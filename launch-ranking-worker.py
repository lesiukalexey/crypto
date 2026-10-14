#!/usr/bin/env python3
"""Exec a ranking worker without inheriting PHP server descriptors."""

from __future__ import annotations

import os
import resource
import sys
import json
import subprocess
from pathlib import Path


def main() -> None:
    if len(sys.argv) < 3:
        raise SystemExit("usage: launch-ranking-worker.py PYTHON WORKER [ARGS...]")

    executable = sys.argv[1]
    arguments = [executable, *sys.argv[2:]]
    worker_arguments = arguments[2:]
    soft_limit, _ = resource.getrlimit(resource.RLIMIT_NOFILE)
    max_fd = 65_536 if soft_limit == resource.RLIM_INFINITY else int(soft_limit)
    os.closerange(3, max_fd)
    result = subprocess.run(arguments, check=False)
    if result.returncode != 0:
        options = {}
        for index, argument in enumerate(worker_arguments[:-1]):
            if argument in {"--cache-dir", "--cache-key"}:
                options[argument] = worker_arguments[index + 1]
        cache_dir = options.get("--cache-dir")
        cache_key = options.get("--cache-key")
        if cache_dir and cache_key:
            status_path = Path(cache_dir) / f"portfolio-{cache_key}.status.json"
            try:
                status = json.loads(status_path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                status = None
            if not isinstance(status, dict) or status.get("status") == "pending":
                temporary_path = status_path.with_suffix(status_path.suffix + ".tmp")
                temporary_path.write_text(
                    json.dumps({
                        "status": "error", "progress": 0,
                        "message": "Воркер завершился с ошибкой. Подробности сохранены в журнале расчёта.",
                    }, ensure_ascii=False),
                    encoding="utf-8",
                )
                os.replace(temporary_path, status_path)
                try:
                    status_path.chmod(0o600)
                except OSError:
                    pass
    raise SystemExit(result.returncode)


if __name__ == "__main__":
    main()
