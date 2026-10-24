#!/bin/bash
# ==============================================================================
# qsa_scan.sh — external exposure scan of this server via qsa.sh
# Usage: inetp qsa_scan [--monitor] [--diff] [--quiet] [--test-webhook]
#
# Since 1.29 the scan itself lives in tuxxin_services.py (`inetp tsvc qsa ...`),
# alongside the other Tuxxin Services modules: it waits for token scans to
# finish (they are asynchronous), tells a finished scan from one cut off at a
# time limit, keeps the archive root-only, and shares one webhook notifier.
#
# This wrapper keeps the old entry points working — `inetp qsa_scan` by hand,
# and cron lines written before 1.29 that call this file directly — until the
# panel rewrites the cron entry on the next update.
# ==============================================================================

HELPER="/root/scripts/tuxxin_services.py"

MODE="run"
for arg in "$@"; do
    case "$arg" in
        --monitor)      MODE="monitor" ;;
        --diff)         MODE="diff" ;;
        --test-webhook) MODE="notify-test" ;;
        --quiet)        ;;   # the helper is quiet unless attached to a terminal
    esac
done

if [ ! -f "$HELPER" ]; then
    echo "qsa_scan: $HELPER is missing. Run a panel update to finish installing it." >&2
    exit 1
fi

case "$MODE" in
    notify-test) exec /usr/bin/python3 -I "$HELPER" notify-test ;;
    *)           exec /usr/bin/python3 -I "$HELPER" qsa "$MODE" ;;
esac
