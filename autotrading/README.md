# Portable execution contracts

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
values. Later stages implement paper, Bitget demo, and production adapters
behind these interfaces. The command-line validator does not start a service
or send requests to Bitget.
