#!/bin/bash
# ==============================================================================
# qsa_scan.sh — external exposure scan of this server via qsa.sh
# Usage: inetp qsa_scan [--monitor] [--diff] [--quiet] [--test-webhook]
#
# WHAT IT DOES
# ------------
# qsa.sh scans the PUBLIC IP that calls it — so running this from the server
# shows exactly what the internet sees of this box, from the outside. That is a
# genuinely different view from `inetp audit` or `firewall-cmd --list-ports`,
# both of which describe intent rather than reality. A port opened by a
# forgotten container, or exposed upstream of this host, only shows up here.
#
# TIERS (https://qsa.sh/pricing)
#   free   no token   top 1,000 TCP,  ~2,000 checks,  1 scan / 24h,  ~30s
#   full   $5/mo      all 65,535 TCP, ~2,000 checks,  1 scan / hour, ~2-12m
#   deep   $7/scan    all 65,535 TCP, ~10,500 checks, unlimited,     ~13-16m
# Set a token in Settings -> Firewall -> Exposure Scan, or:
#   sqlite3 /var/www/inetpanel/db/inetpanel.db \
#     "INSERT INTO settings (key,value,category) VALUES('qsa_token','<token>','security')
#      ON CONFLICT(key) DO UPDATE SET value=excluded.value;"
#
# WHY THE OPT-IN HEADER
# ---------------------
# qsa.sh classifies callers. An HTTP library UA is "cli_probe" and is NEVER
# auto-scanned — it gets a usage page instead. The documented way for a
# programmatic caller to request a real scan is `X-QSA-Scan: 1` (or ?scan=1).
# Without it this returns help text and looks like a broken integration.
# ==============================================================================

PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
STATE_DIR="/var/lib/inetpanel/qsa"
KEEP_RUNS=30

MODE="scan"
QUIET=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --monitor) MODE="monitor"; shift ;;
        --test-webhook) MODE="test-webhook"; shift ;;
        --diff)    MODE="diff";    shift ;;
        --quiet)   QUIET=1; shift ;;
        *) shift ;;
    esac
done

if [ -t 1 ] && [ "$QUIET" -eq 0 ]; then
    BOLD=$'\033[1m'; GREEN=$'\033[1;32m'; RED=$'\033[1;31m'
    YELLOW=$'\033[1;33m'; DIM=$'\033[2m'; NC=$'\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; DIM=''; NC=''
fi

setting() { sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='$1'" 2>/dev/null; }

TOKEN=$(setting qsa_token)

# Free tier is quick; paid tiers are async and can legitimately take many
# minutes, so the deadline follows the tier rather than being one flat number.
if [ -n "$TOKEN" ]; then
    URL="https://qsa.sh/${TOKEN}?nocolor=1"
    MAXTIME=1200
    TIER="token"
else
    URL="https://qsa.sh/?nocolor=1&scan=1"
    MAXTIME=180
    TIER="free"
fi

run_scan() {
    # --fail-with-body so a 429 (rate limit) still returns qsa.sh's explanation
    # rather than an empty file and an opaque curl exit code.
    # Do NOT override the User-Agent. qsa.sh anchors its CLI pattern at the start
    # of the UA (^curl|Wget|HTTPie|PowerShell), so curl's own "curl/8.x" is
    # classified as an interactive CLI and gets the scan. A custom UA like
    # "iNetPanel/1.0 (curl)" matches neither that pattern nor the library one, so
    # it falls through to BROWSER — and browsers get the marketing page, not a
    # scan. That is not a hypothetical: it is what this integration did first.
    # X-QSA-Scan: 1 is kept as the documented belt-and-braces opt-in.
    # -4 is required, not a preference. qsa.sh is dual-stack behind Cloudflare, and
    # curl follows RFC 6724 and prefers the AAAA record on any host with working
    # IPv6. qsa.sh scans the origin it sees, cannot yet reputation-check IPv6
    # origins, and rejects such requests with HTTP 400. Without -4 the scan works
    # on IPv4-only hosts and fails on every dual-stack one.
    local out rc
    out=$(curl -4 -sS --fail-with-body --max-time "$MAXTIME" \
        -H 'X-QSA-Scan: 1' \
        -H 'Accept: text/plain' \
        "$URL" 2>&1)
    rc=$?

    # curl exit 6/7 with -4 on a host with no IPv4 route at all. qsa.sh cannot
    # scan IPv6-only hosts yet, so say that rather than surfacing a DNS error.
    if [ "$rc" -ne 0 ] && [ -z "${out//[[:space:]]/}" ]; then
        if ! ip -4 route get 1.1.1.1 >/dev/null 2>&1; then
            echo "This server has no IPv4 address. qsa.sh cannot scan IPv6-only hosts yet"
            echo "(see https://qsa.sh/contact)."
            return 1
        fi
    fi
    printf '%s\n' "$out"
    return "$rc"
}

# Strip everything that legitimately differs between two identical scans, so the
# diff only fires on a real exposure change. Mirrors qsa-monitor.sh upstream.
normalise() {
    grep -avE "Duration|Scan time|Scan complete|stage timing|cert expires in" \
        | sed -E 's/[0-9]+(\.[0-9]+)?s\b//g' \
        | sed -E 's/[[:space:]]+$//' \
        | grep -av '^$'
}

# ---------------------------------------------------------------------------
# Notification delivery.
#
# A stock iNetPanel box has no MTA, so there is no mail path to fall back on.
# Delivery is therefore: (1) a row in the panel `logs` table, (2) a sticky
# banner flag the panel header reads, and (3) optionally an outbound webhook.
# This function owns (3) — api/firewall.php's "Send test" button calls back
# into this same script rather than re-implementing the POST, so the test
# exercises the real path.
#
# Discord and Slack both accept a simple JSON body but disagree on the field
# name ("content" vs "text"), and Discord hard-caps a message at 2000 chars.
send_webhook() {
    local subject="$1" body="$2"
    local url kind payload code
    url=$(panel_setting qsa_webhook_url)
    [ -z "$url" ] && return 0          # not configured — not an error
    kind=$(panel_setting qsa_webhook_kind)
    [ -z "$kind" ] && kind="generic"

    # Trim to Discord's limit with room for the fences and subject line.
    local max=1500
    [ "${#body}" -gt "$max" ] && body="${body:0:$max}"$'\n'"... (truncated — see the panel for the full diff)"

    local text="**${subject}** — $(hostname)"$'\n'"\`\`\`"$'\n'"${body}"$'\n'"\`\`\`"

    case "$kind" in
        discord) payload=$(json_obj content "$text") ;;
        slack)   payload=$(json_obj text    "$text") ;;
        *)       payload=$(json_obj text    "$text") ;;
    esac

    code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 \
                -H 'Content-Type: application/json' \
                -X POST --data "$payload" "$url" 2>/dev/null) || code="000"

    if [ "$code" -ge 200 ] 2>/dev/null && [ "$code" -lt 300 ]; then
        return 0
    fi
    echo "webhook delivery failed (HTTP ${code})" >&2
    return 1
}

# Build a one-key JSON object with the value properly escaped. Doing this with
# printf and sed gets control characters wrong; python3 is already a hard
# dependency of the panel (ht_manage.py, verify_account_credentials.py).
json_obj() {
    python3 -c 'import json,sys; print(json.dumps({sys.argv[1]: sys.argv[2]}))' "$1" "$2"
}

panel_setting() {
    sqlite3 "$PANEL_DB" "SELECT value FROM settings WHERE key='$1'" 2>/dev/null
}

# The settings table is (key, value, category, updated_at) — a bare
# INSERT OR REPLACE ... VALUES('k','v') is rejected with "table settings has 4
# columns but 2 values were supplied". Every such write here was suppressed with
# 2>/dev/null, so the monitor recorded neither its last run nor any detected
# change and the panel could only ever show "—". Name the columns.
panel_set_setting() {
    sqlite3 "$PANEL_DB" \
        "INSERT INTO settings (key, value, category, updated_at)
         VALUES ('$1', '$2', 'security', datetime('now'))
         ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at;" \
        || echo "warning: could not record '$1' in the panel database" >&2
}

case "$MODE" in
    scan)
        [ "$QUIET" -eq 0 ] && echo -e "${BOLD}Scanning this server's public exposure via qsa.sh (${TIER} tier)...${NC}" >&2
        run_scan
        exit $?
        ;;

    diff)
        if [ -f "$STATE_DIR/latest.txt" ] && [ -f "$STATE_DIR/previous.txt" ]; then
            diff -u <(normalise < "$STATE_DIR/previous.txt") <(normalise < "$STATE_DIR/latest.txt")
            exit 0
        fi
        echo "Not enough history yet — run at least two monitored scans." >&2
        exit 1
        ;;

    monitor)
        mkdir -p "$STATE_DIR"; chmod 700 "$STATE_DIR"
        NOW=$(date -u +%Y%m%dT%H%M%Sz)
        CURRENT="$STATE_DIR/scan-${NOW}.txt"

        if ! run_scan > "$CURRENT" 2>&1; then
            echo -e "${RED}Scan failed.${NC}" >&2
            sed 's/^/  /' "$CURRENT" >&2
            rm -f "$CURRENT"
            exit 1
        fi
        # A rate-limit or usage page is not a scan result; storing it would
        # produce a spurious "everything changed" on the next run.
        if [ ! -s "$CURRENT" ] || grep -qiE "rate.?limit|try again in|usage:" "$CURRENT"; then
            echo -e "${YELLOW}qsa.sh did not return a scan (rate limited?). Keeping previous state.${NC}" >&2
            head -5 "$CURRENT" | sed 's/^/  /' >&2
            rm -f "$CURRENT"
            exit 1
        fi

        CHANGED=0
        if [ -f "$STATE_DIR/latest.txt" ]; then
            if ! diff -q <(normalise < "$STATE_DIR/latest.txt") <(normalise < "$CURRENT") >/dev/null 2>&1; then
                CHANGED=1
                cp "$STATE_DIR/latest.txt" "$STATE_DIR/previous.txt"
            fi
        fi
        cp "$CURRENT" "$STATE_DIR/latest.txt"

        # Prune old runs, newest first.
        # shellcheck disable=SC2012
        ls -1t "$STATE_DIR"/scan-*.txt 2>/dev/null | tail -n +$((KEEP_RUNS + 1)) | xargs -r rm -f

        if [ "$CHANGED" -eq 1 ]; then
            # --label: without it the ---/+++ header shows the process-substitution
            # paths (/dev/fd/63), which is meaningless in a log row and worse in a
            # Discord message. Named labels make both readable.
            DIFF=$(diff -u --label 'previous scan' --label 'this scan' \
                        <(normalise < "$STATE_DIR/previous.txt") \
                        <(normalise < "$CURRENT") | head -100)
            echo -e "${RED}EXPOSURE CHANGED${NC}"
            printf '%s\n' "$DIFF"
            sqlite3 "$PANEL_DB" "INSERT INTO logs (source, level, message, details, user, created_at)
                VALUES ('qsa','WARNING','External exposure changed',
                        '$(printf '%s' "$DIFF" | sed "s/'/''/g")','system', datetime('now'));" 2>/dev/null
            panel_set_setting qsa_last_change "$(date '+%Y-%m-%d %H:%M:%S')"
            # Sticky until acknowledged in the panel, so a change found at 04:17
            # is still visible the next time someone logs in.
            panel_set_setting qsa_change_unacked 1
            send_webhook "Exposure changed" "$DIFF" || true
        else
            [ "$QUIET" -eq 0 ] && echo -e "${GREEN}No change since the last scan.${NC}"
            sqlite3 "$PANEL_DB" "INSERT INTO logs (source, level, message, details, user, created_at)
                VALUES ('qsa','INFO','External exposure unchanged','','system', datetime('now'));" 2>/dev/null
        fi
        panel_set_setting qsa_last_run "$(date '+%Y-%m-%d %H:%M:%S')"
        exit 0
        ;;

    test-webhook)
        if [ -z "$(panel_setting qsa_webhook_url)" ]; then
            echo "No webhook URL is configured."
            exit 1
        fi
        if send_webhook "Test notification" \
             "If you can read this, exposure-change alerts will reach you here."; then
            echo "Test message delivered."
            exit 0
        fi
        echo "Delivery failed — check the URL is a valid incoming webhook and that this server has outbound HTTPS."
        exit 1
        ;;
esac
