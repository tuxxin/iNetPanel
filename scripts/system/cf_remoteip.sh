#!/bin/bash
# ==============================================================================
# cf_remoteip.sh — restore the real client IP behind Cloudflare Tunnel
# Usage: inetp cf_remoteip [--check]
#
# WHY
# ---
# cloudflared connects to Apache over loopback, so without mod_remoteip every
# hosted site sees REMOTE_ADDR = 127.0.0.1 or ::1 for EVERY request. Consequences
# reported from a fleet audit:
#
#   - per-IP rate limiting in any hosted app becomes one global bucket
#   - access logs are useless for abuse investigation
#   - the panel's own fail2ban jail records loopback for failed logins, and
#     jail.local ignores loopback — so it can never ban anything
#   - apps that hand-parse X-Forwarded-For get it WRONG, because Cloudflare
#     APPENDS to XFF: the left-most entry is attacker-controlled. One audited
#     site was remotely bypassable this way.
#
# mod_remoteip fixes all of it centrally, so hosted apps need no special code.
#
# TRUST
# -----
# RemoteIPHeader is only honoured from RemoteIPTrustedProxy sources. We trust
# loopback (where cloudflared hands off) and Cloudflare's published ranges. If
# the ranges go stale the real client IP silently reverts to Cloudflare edge
# IPs, so this refreshes on a timer and NEVER installs a partial list.
# ==============================================================================

# cron runs with PATH=/usr/bin:/bin — /etc/cron.d entries do NOT inherit the PATH
# set in /etc/crontab — and apache2ctl, a2enconf, a2disconf and a2enmod all live in
# /usr/sbin. Under cron they were therefore "command not found", silently, because
# every call site suppressed output. The success test below is
# `apache2ctl configtest | grep -q "Syntax OK"`, which can never match when
# apache2ctl does not resolve, so the script concluded its OWN config was invalid
# and took the revert path: a2disconf silently failed (leaving the symlink) while
# rm -f removed the target. Result: a dangling symlink every Monday, with Apache
# still applying the deleted directives from memory until the next reload.
# Set PATH explicitly so this does not depend on how we were invoked.
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH

CONF=/etc/apache2/conf-available/inetpanel-remoteip.conf
V4=https://www.cloudflare.com/ips-v4
V6=https://www.cloudflare.com/ips-v6

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; NC=''
fi

if [ "$1" = "--check" ]; then
    echo -e "${BOLD}Cloudflare RemoteIP status${NC}"
    apache2ctl -M 2>/dev/null | grep -q remoteip \
        && echo -e "  ${GREEN}mod_remoteip loaded${NC}" \
        || echo -e "  ${RED}mod_remoteip NOT loaded${NC}"
    if [ -f "$CONF" ]; then
        echo "  ranges trusted: $(grep -c RemoteIPTrustedProxy "$CONF")"
        echo "  last updated:   $(stat -c %y "$CONF" | cut -d. -f1)"
    else
        echo -e "  ${RED}${CONF} missing${NC}"
    fi
    exit 0
fi

# Verify every external tool up front. A missing tool must never be able to send
# this script down a path where it deletes a working configuration.
MISSING=""
for _t in apache2ctl a2enconf a2disconf a2enmod install curl systemctl; do
    command -v "$_t" >/dev/null 2>&1 || MISSING="${MISSING} ${_t}"
done
if [ -n "$MISSING" ]; then
    echo -e "${RED}Required tool(s) not found:${MISSING}${NC}" >&2
    echo "Leaving the existing configuration untouched." >&2
    exit 1
fi

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT

# Fetch both lists before writing anything. A half-written trust list is worse
# than none: it silently drops the real client IP for the ranges it missed.
RANGES=""
for url in "$V4" "$V6"; do
    body=$(curl -fsSL --max-time 20 "$url" 2>/dev/null) || {
        echo -e "${RED}Could not fetch ${url} — leaving the existing config untouched.${NC}" >&2
        exit 1
    }
    # Only accept things that actually look like CIDRs.
    body=$(printf '%s\n' "$body" | grep -E '^[0-9a-fA-F:.]+/[0-9]{1,3}$')
    [ -z "$body" ] && { echo -e "${RED}${url} returned no usable ranges.${NC}" >&2; exit 1; }
    RANGES="${RANGES}${body}"$'\n'
done

COUNT=$(printf '%s' "$RANGES" | grep -c .)
[ "$COUNT" -lt 10 ] && { echo -e "${RED}Only ${COUNT} ranges parsed — refusing to install a suspiciously short list.${NC}" >&2; exit 1; }

{
    echo "# Managed by iNetPanel — inetp cf_remoteip. Do not edit by hand."
    echo "# Restores the real client IP behind Cloudflare Tunnel so hosted apps,"
    echo "# access logs and fail2ban all see the actual visitor rather than loopback."
    echo "# Refreshed on a timer; regenerate with: inetp cf_remoteip"
    echo "<IfModule mod_remoteip.c>"
    echo "    RemoteIPHeader CF-Connecting-IP"
    echo "    # cloudflared hands off over loopback."
    echo "    RemoteIPTrustedProxy 127.0.0.1"
    echo "    RemoteIPTrustedProxy ::1"
    printf '%s\n' "$RANGES" | grep . | sed 's/^/    RemoteIPTrustedProxy /'
    echo "</IfModule>"
} > "$TMP"

if ! install -m 0644 "$TMP" "$CONF"; then
    echo -e "${RED}Could not write ${CONF} — leaving the previous configuration in place.${NC}" >&2
    exit 1
fi
a2enmod remoteip   >/dev/null 2>&1
a2enconf inetpanel-remoteip >/dev/null 2>&1

if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
    systemctl reload apache2 2>/dev/null
    echo -e "${GREEN}mod_remoteip active — ${COUNT} Cloudflare ranges trusted.${NC}"
    exit 0
fi

# configtest failed. That does not prove THIS config is at fault — an unrelated
# error elsewhere in the Apache tree fails the same test, and the old code then
# deleted a perfectly good file because of someone else's mistake. Disable ours
# and re-test: only if Apache becomes valid without it is the fault actually ours.
a2disconf inetpanel-remoteip >/dev/null 2>&1

if apache2ctl configtest 2>&1 | grep -q "Syntax OK"; then
    rm -f "$CONF"
    echo -e "${RED}This RemoteIP config is invalid — removed it and left Apache as it was.${NC}" >&2
    exit 1
fi

# Still invalid without us: the breakage is pre-existing. Put ours back exactly as
# it was and report the real error rather than silently discarding our own config.
a2enconf inetpanel-remoteip >/dev/null 2>&1
echo -e "${RED}Apache config was already invalid before this change — RemoteIP config left in place.${NC}" >&2
echo -e "${YELLOW}Fix the error below, then re-run: inetp cf_remoteip${NC}" >&2
apache2ctl configtest 2>&1 | sed 's/^/    /' >&2
exit 1
