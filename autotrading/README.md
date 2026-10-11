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

Open `Автоторговля` from **Базовые проверки** to carry over the currently
selected symbol, entry/exit rules, dates, fees and backtest result. Set paper
risk limits there, publish the immutable release, then start the service. The
workstation page shows process status, current position/PnL, recent paper
trades and runtime events. It supports pausing new entries, resuming them,
requesting position closes at the next fresh quote, and stopping the process.
Stopping preserves virtual positions for the next start.

The page stores runtime files outside Git under `/var/tmp/bitget-autotrading-<uid>`
with private permissions; this path survives ordinary reboots. It can be changed
with `BITGET_AUTOTRADING_DIR`. For direct terminal use, supply any durable path:

```bash
python3 -m autotrading paper-run autotrading/example-release.json \
  --state /var/lib/bitget-autotrading/state.sqlite
python3 -m autotrading status --state /var/lib/bitget-autotrading/state.sqlite
```

For the same paper executor on Raspberry Pi OS, see
[`RASPBERRY_PI.md`](RASPBERRY_PI.md). The Pi service uses systemd and local
SQLite state; the workstation sends a validated strategy and reads telemetry
over SSH with strict host-key checking.

Paper v1 implements standard entry rules (`immediate:*` and `entry:*`), fixed,
profit and loss exits, bid/ask fills, fees, slippage, exit confirmations,
exposure limits, stale quote checks and restart recovery. It fails closed for
martingale and funding because those calculations are not implemented in this
paper executor. Set `execution.include_funding` to `false`. A release cannot be
switched while a virtual position is open. This is an initial local paper
runtime; it does not place exchange orders and is not evidence that live fills
will match the analytics backtest.

## Bitget demo adapter (API building block)

`autotrading.bitget_demo.BitgetDemoAdapter` is a separate adapter for the
Bitget Classic USDT futures demo environment. It is not wired into `paper-run`
or the dashboard, so the existing service continues to place no exchange
orders. The adapter has a fixed Bitget API host, always sends
`paptrading: 1`, and accepts demo credentials only from the executor
environment (`BITGET_DEMO_API_KEY`, `BITGET_DEMO_API_SECRET`,
`BITGET_DEMO_API_PASSPHRASE`). Keep those variables in a protected local
service environment file; never put them in a release or this repository.

Before any order, the caller must successfully reconcile local positions and
pending client order IDs. Reconciliation checks each allowed symbol's account
is in one-way/crossed mode, compares open positions, queries pending order
details and fills, and blocks new orders when exchange state is unknown or
does not match local state. Persist returned fill rows by `tradeId`. A network
failure resets the adapter to a locked state; reconcile again after reconnect.
Order IDs must be deterministic and unique. The adapter checks the same ID
before a POST, and requires another reconciliation after order submission.
Only market orders are currently supported; quantities must meet live Bitget
contract metadata. Position mode, margin mode, product, and symbol allowlist
are deliberately restricted.

For example, initialize and reconcile without sending an order:

```python
from autotrading.bitget_demo import BitgetDemoAdapter

adapter = BitgetDemoAdapter(["BTCUSDT"])
state = adapter.reconcile(expected_positions=[], pending_orders=[])
if not state.matched:
    raise RuntimeError("Demo account differs from local executor state")
```

An actual Bitget demo order smoke test still requires a Demo API key and the
matching demo account. Unit tests use an injected transport and never contact
the exchange. See [Bitget demo REST setup](https://www.bitget.com/docs/classic/demo-trading/rest-api),
[futures order API](https://www.bitget.com/docs/catalog/classic-contract-trade/classic-contract-trade),
[position API](https://www.bitget.com/docs/catalog/classic-contract-position/classic-contract-position),
and [contract metadata](https://www.bitget.com/docs/catalog/classic-contract-market/classic-contract-market).

The dashboard only accepts linear USDT futures releases and is writable from
loopback with a CSRF-protected session. PHP starts the managed Python service
as the same local web-server user; the PID file is checked against the actual
`/proc` command before a stop signal is sent. Releases, SQLite, control files,
and logs are stored in the per-user runtime directory with restrictive file
permissions.

The dashboard's **Сверка Bitget Demo** panel runs a read-only check against
the selected symbol and compares the exchange with an empty local Demo ledger.
It reports account mode issues, untracked positions and pending orders; it does
not connect the paper executor to exchange execution. Configure
`BITGET_DEMO_ENV_FILE` for the local web process to point at a regular,
owner-only environment file with the three `BITGET_DEMO_API_*` variables. On a
workstation, the page also checks for the protected file under
`$HOME/.ai/home/.local/bitget-autotrading-demo.env`; on another machine, set the
environment variable to that machine's protected path. The CLI check is
`python3 -m autotrading demo-reconcile --symbols BTCUSDT`.

## Bitget production adapter (disabled)

`BitgetProductionAdapter` is separate from the Demo adapter and reads only
`BITGET_LIVE_API_KEY`, `BITGET_LIVE_API_SECRET`, and
`BITGET_LIVE_API_PASSPHRASE`. It never adds `paptrading: 1`. Order submission
is off by default and requires all three explicit gates: a caller-provided
`allow_live_orders=True`, `BITGET_LIVE_TRADING_ENABLED=1`, and the exact
`I_ACKNOWLEDGE_LIVE_ORDERS` acknowledgement. The environment switch is checked
again at each submission. No production credentials are configured, and the
dashboard and paper service do not construct this adapter.

The Autotrading page has a Paper / Bitget Demo / Bitget Real selector. It
records the selected target in the browser session and clearly shows the
executor's actual mode. Only Paper currently starts: Demo is limited to
read-only reconciliation, and Demo/Real starts are rejected both in the UI and
server action until the shared portfolio runner has exchange order lifecycle,
account reconciliation, and restart recovery connected to those adapters.
Selecting a mode does not enable or submit orders.

The adapter requires a successful one-way/crossed reconciliation, validates
the symbol/quantity and release risk limits, checks a fresh daily-loss snapshot
from a trusted durable-ledger provider, and estimates order/current exposure
from fresh mark/last prices. It submits price-bounded IOC limit orders using
the configured `max_slippage_bps`; movement beyond that cap leaves the order
unfilled or partially filled for reconciliation. Reduce-only orders must
match an existing reconciled position and cannot exceed its size. The client
order ID lookup prevents duplicate submissions after a retry.

Bitget does not expose a check here that proves an API key lacks transfer or
withdrawal scopes. Before any future production switch, use a dedicated
sub-account and a key with only read/trade permissions, no transfer or
withdrawal permission, and an IP allowlist where available. This task does not
create such a key or activate live trading. See Bitget's
[API key and security guidance](https://www.bitget.com/docs/classic/rest-api),
[USDT futures order endpoint](https://www.bitget.com/api-doc/classic/contract/trade/Place-Order),
and [futures market price endpoint](https://www.bitget.com/docs/catalog/classic-contract-market/classic-contract-market).
