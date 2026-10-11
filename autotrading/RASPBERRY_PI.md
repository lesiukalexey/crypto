# Raspberry Pi paper executor

The Pi package runs the same portable paper executor as the workstation. It
uses public Bitget quotes, local SQLite state, and a per-user systemd service.
The production adapter is not loaded by this service; no trading API key is
needed.

## Install on Raspberry Pi OS

Run `scripts/install-autotrading-pi.sh` from a checkout of this project while
logged in as `admin`. The script copies only the `autotrading` package,
installs the user systemd unit, creates private config/state directories, and
enables the service without starting it. The validated release must be staged
from the workstation first. For boot without an interactive login, run
`sudo loginctl enable-linger admin` once.

The unit uses `/home/admin/.config/bitget-autotrading/active-release.json` and
keeps SQLite state under `/home/admin/.local/state/bitget-autotrading/`.
Settings are external in
`/home/admin/.config/bitget-autotrading/executor.env` (mode `0600`). The
example file keeps `BITGET_LIVE_TRADING_ENABLED=0`; the paper service has no
exchange order adapter.

Start and inspect the service on the Pi:

```bash
systemctl --user start bitget-autotrading.service
systemctl --user status bitget-autotrading.service
journalctl --user -u bitget-autotrading.service -f
```

After staging a new release from the workstation, restart only when the Pi
paper account is flat:

```bash
systemctl --user restart bitget-autotrading.service
```

The release staging command checks that no paper positions are open. SQLite
state survives process and Pi restarts. Public-data network errors are logged
and retried by the executor; systemd restarts an unexpected process exit.

## Private workstation transport

The workstation uses SSH, not an inbound HTTP listener. Configure an SSH host
alias with key authentication and verify its host fingerprint before trusting
it. Keep `StrictHostKeyChecking` enabled. Example `~/.ssh/config` entry:

```sshconfig
Host bitget-pi
    HostName raspberry.local
    User admin
    IdentityFile ~/.ssh/id_ed25519
    IdentitiesOnly yes
    StrictHostKeyChecking yes
```

Set `BITGET_PI_SSH_TARGET=bitget-pi` in the local PHP process environment.
The Autotrading → Raspberry Pi page retrieves paper status over SSH and can
stage the workstation's validated release. Status is read from the Pi's
SQLite database; the release travels on SSH stdin. The remote command has
fixed paths and validates the release again. No database port or control API
is exposed to the LAN or Internet.

The initial remote service runs as `admin` so SSH can read status and stage a
release. The package and environment files are still outside Git, but this
per-user boundary is weaker than a dedicated service account. A later Pi
hardening pass can split status and release permissions once its deployment
path is stable.

## Current deployment constraint

The Pi/SSD path recorded in `/home/alex/.ai/home/raspberry-pi.md` has had
repeated USB/SATA and filesystem incidents. This task prepares the package but
does not mount, boot, or write that device. Validate on hardware only after the
storage path has been repaired or replaced and confirmed reliable.
