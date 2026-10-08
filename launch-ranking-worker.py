#!/usr/bin/env python3
"""Exec a ranking worker without inheriting PHP server descriptors."""

from __future__ import annotations

import os
import resource
import sys


def main() -> None:
    if len(sys.argv) < 3:
        raise SystemExit("usage: launch-ranking-worker.py PYTHON WORKER [ARGS...]")

    executable = sys.argv[1]
    arguments = [executable, *sys.argv[2:]]
    soft_limit, _ = resource.getrlimit(resource.RLIMIT_NOFILE)
    max_fd = 65_536 if soft_limit == resource.RLIM_INFINITY else int(soft_limit)
    os.closerange(3, max_fd)
    os.execv(executable, arguments)


if __name__ == "__main__":
    main()
