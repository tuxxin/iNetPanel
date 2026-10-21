#!/bin/bash
# ==============================================================================
# inetp-update.sh — Unattended system package update
#   Runs: apt-get update, upgrade, autoremove, autoclean
#   Note: Uses 'upgrade' (not dist-upgrade) to prevent major version jumps
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

# Let every PHP version that is installed here receive updates.
#
# Since 1.26.0, install_LAMP.sh writes /etc/apt/preferences.d/php blocking every
# PHP version except the panel's own at Pin-Priority -1, so that phpmyadmin's
# php-* dependencies cannot drag a second PHP stack in. But -1 means "never
# install", and APT applies it to upgrades as well: a version added later
# through MultiPHP never received another update. Measured with apt 2.6 against
# sury's index: an installed php8.4-common showed "Candidate: (none)" under that
# pin and `apt-get upgrade` skipped it silently, while 8.4.27 — the fix for
# CVE-2026-109618/109619 — was available.
#
# This allows sury's updates for exactly the versions installed right now,
# rewritten on every run. `apt-get upgrade` never installs a package that is not
# already installed, so no new PHP version can arrive this way, and Debian's own
# PHP stays blocked (the allowance is limited to sury's origin). APT takes the
# first matching entry in file order, hence the 00- prefix.
PHP_ALLOW="/etc/apt/preferences.d/00-inetpanel-installed-php"
PHP_INSTALLED=$(dpkg-query -W -f='${db:Status-Abbrev} ${Package}\n' 'php*-common' 2>/dev/null \
    | awk '$1 == "ii" && $2 ~ /^php[0-9]+\.[0-9]+-common$/ { sub(/-common$/, "*", $2); print $2 }' \
    | sort -V | tr '\n' ' ' | sed 's/ $//')
if [ -n "$PHP_INSTALLED" ]; then
    if printf '%s\n' \
        "# Written by /root/scripts/inetp-update.sh on every run — the PHP versions" \
        "# installed on this server keep receiving updates from sury, even those that" \
        "# /etc/apt/preferences.d/php blocks from being installed." \
        "Package: ${PHP_INSTALLED}" \
        "Pin: origin packages.sury.org" \
        "Pin-Priority: 600" > "${PHP_ALLOW}.new" && mv -f "${PHP_ALLOW}.new" "$PHP_ALLOW"; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] PHP versions allowed to update: ${PHP_INSTALLED}" >> "$LOG"
    else
        rm -f "${PHP_ALLOW}.new"
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: could not write ${PHP_ALLOW} — installed PHP versions other than the panel's own may not update" >> "$LOG"
    fi
else
    rm -f "$PHP_ALLOW"
fi

# Upgrade installed packages (non-interactive, keep existing configs)
# Uses 'upgrade' instead of 'dist-upgrade' to prevent installing new
# packages or removing existing ones (e.g. PHP major version jumps)
apt-get upgrade -y -qq \
    -o Dpkg::Options::="--force-confdef" \
    -o Dpkg::Options::="--force-confold" \
    >> "$LOG" 2>&1

# Remove packages no longer needed
apt-get autoremove -y -qq >> "$LOG" 2>&1

# Clean up downloaded package files from cache
apt-get autoclean -qq >> "$LOG" 2>&1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Update complete" >> "$LOG"
