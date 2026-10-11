#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -un)" != "admin" ]]; then
    echo "Run this installer as the Raspberry Pi user admin." >&2
    exit 2
fi

project_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
install_root="/home/admin/.local/lib/bitget-autotrading"
config_root="/home/admin/.config/bitget-autotrading"
state_root="/home/admin/.local/state/bitget-autotrading"
unit_root="/home/admin/.config/systemd/user"

install -d -m 0755 "$install_root/autotrading"
install -d -m 0700 "$config_root" "$state_root"
install -d -m 0755 "$unit_root"
rsync -a --delete --exclude='__pycache__' --exclude='*.pyc' \
    "$project_root/autotrading/" "$install_root/autotrading/"
install -m 0644 "$project_root/systemd/bitget-autotrading-pi.service" \
    "$unit_root/bitget-autotrading.service"
if [[ ! -e "$config_root/executor.env" ]]; then
    install -m 0600 "$project_root/systemd/bitget-autotrading-pi.env.example" \
        "$config_root/executor.env"
else
    chmod 0600 "$config_root/executor.env"
fi

systemctl --user daemon-reload
systemctl --user enable bitget-autotrading.service
echo "Installed the paper executor. It is enabled but not started; first stage a validated strategy from the workstation."
echo "For boot without an interactive login, run: sudo loginctl enable-linger admin"
