#!/bin/bash
# ==============================================================================
# inetp-update.sh — Unattended system package update
#   Runs: apt-get update, upgrade, dist-upgrade, autoremove, autoclean
#   Log:  /var/log/lamp_update.log
# Usage: inetp update   (or runs automatically at midnight via cron)
# ==============================================================================
LOG="/var/log/lamp_update.log"
export DEBIAN_FRONTEND=noninteractive

echo "" >> "$LOG"
echo "============================================================" >> "$LOG"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting system update" >> "$LOG"
echo "============================================================" >> "$LOG"

# Refresh package lists
apt-get update -q >> "$LOG" 2>&1

# Upgrade installed packages (non-interactive, keep existing configs)
apt-get upgrade -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    >> "$LOG" 2>&1

# Upgrade packages that require dependency changes (new kernel, etc.)
apt-get dist-upgrade -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    >> "$LOG" 2>&1

# Remove packages no longer needed
apt-get autoremove -y -qq >> "$LOG" 2>&1

# Clean up downloaded package files from cache
apt-get autoclean -qq >> "$LOG" 2>&1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Update complete" >> "$LOG"
