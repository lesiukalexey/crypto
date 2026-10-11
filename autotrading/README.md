# Portable paper executor and contracts

This package defines the versioned strategy release and the interfaces shared
by the workstation and Raspberry Pi execution service. It deliberately has no
exchange client, private API key, MySQL dependency, or web framework.

Validate the included example:

```bash
python3 -m autotrading validate autotrading/example-release.json
```

V1 releases use decimal strings for monetary and rate values. Unknown fields,
versions, markets, and enum values are rejected. This keeps credentials and
arbitrary executable settings out of a published strategy. `release_digest`
produces a stable SHA-256 for the complete validated release.

The package exposes `MarketDataProvider`, `ExecutionAdapter`, and `StateStore`
protocols plus typed `Quote`, `OrderRequest`, `ExecutionResult`, and `Position`
values. `paper-run` is an independent process that polls only Bitget's public
market ticker endpoint and stores quotes, events, virtual positions and trades
in SQLite. No private endpoint, API key, or order endpoint is used.

Use a state path outside the repository so it can be protected and moved with
the executor:

```bash
python3 -m autotrading paper-run autotrading/example-release.json \
  --state /var/lib/bitget-autotrading/state.sqlite
python3 -m autotrading status --state /var/lib/bitget-autotrading/state.sqlite
```

Paper v1 implements standard entry rules (`immediate:*` and `entry:*`), fixed,
profit and loss exits, bid/ask fills, fees, slippage, exit confirmations,
exposure limits, stale quote checks and restart recovery. It fails closed for
martingale and funding because those calculations are not implemented in this
paper executor. Set `execution.include_funding` to `false`. A release cannot be
switched while a virtual position is open. This is an initial local paper
runtime; it does not place exchange orders and is not evidence that live fills
will match the analytics backtest.
