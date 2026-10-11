"""Small local command-line tools for portable strategy releases."""

from __future__ import annotations

import argparse
import sys

from .contracts import load_strategy_release, release_digest


def main() -> int:
    parser = argparse.ArgumentParser(prog="python -m autotrading")
    commands = parser.add_subparsers(dest="command", required=True)
    validate = commands.add_parser("validate", help="validate a strategy release")
    validate.add_argument("release", help="path to a strategy release JSON file")
    args = parser.parse_args()
    try:
        release = load_strategy_release(args.release)
        print(f"Valid strategy release: {release['release_id']}")
        print(f"SHA-256: {release_digest(release)}")
        return 0
    except ValueError as exc:
        print(f"Invalid strategy release: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
