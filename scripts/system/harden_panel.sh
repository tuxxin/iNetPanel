#!/bin/bash
# ==============================================================================
# harden_panel.sh — keep the panel's private files private
# Usage: inetp harden_panel [--detach] [--quiet]
#
# Idempotent; run by panel_update.php and install_LAMP.sh, and once by the panel
# itself after an update (the update that ships a change here is run by the
# previous panel_update.php, which does not know this step).
#
#   1. The panel database and its directory are the panel's alone:
#      db/ 0700, inetpanel.db (+ -wal, -shm, .installed, .admin_pass) 0600,
#      all www-data. Root scripts reach them as root.
#
#   2. Apache serves the hosted sites; the panel is served by lighttpd + PHP-FPM.
#      Apache has no business reading the panel's database, its staging and state
#      directories, or PHP sessions, so a systemd drop-in makes those paths
#      inaccessible to the apache2 service. That holds however a path is reached
#      — directly, through a symlink, or a renamed one.
#      /var/www/inetpanel/suspended stays visible: suspended accounts' vhosts
#      serve their notice from there.
#
# Apache is restarted (a namespace change needs a restart, not a reload) only
# when the drop-in is new or changed. If it does not come back, the drop-in is
# removed and Apache restarted without it, so a site outage is never the result.
# ==============================================================================

PANEL_DIR="/var/www/inetpanel"
DROPIN_DIR="/etc/systemd/system/apache2.service.d"
LOCK="/run/inetpanel-harden.lock"
LOG="/var/log/inetpanel_harden.log"
HARDENING_VERSION="1"

# Tests only: run against a scratch tree, with the commands found on the caller's
# PATH (stand-ins for systemctl and apache2ctl). sudo's env_reset drops this
# variable, so the panel can never reach this mode.
TEST_ROOT="${INETP_HARDEN_TEST_ROOT:-}"
if [ -n "$TEST_ROOT" ]; then
    [ -d "$TEST_ROOT" ] && [ "$TEST_ROOT" != "/" ] || { echo "bad test root" >&2; exit 2; }
    PANEL_DIR="${TEST_ROOT}${PANEL_DIR}"
    DROPIN_DIR="${TEST_ROOT}${DROPIN_DIR}"
    LOCK="${TEST_ROOT}/harden.lock"
    LOG="${TEST_ROOT}/harden.log"
fi
DB_DIR="${PANEL_DIR}/db"
DROPIN="${DROPIN_DIR}/inetpanel-isolation.conf"

DETACH=0
QUIET=0
for a in "$@"; do
    case "$a" in
        --detach) DETACH=1 ;;
        --quiet)  QUIET=1 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "harden_panel: must run as root" >&2
    exit 1
fi

# The panel triggers this from a page request; run the work in its own unit so
# the request returns at once and an Apache restart cannot hold it up.
if [ "$DETACH" -eq 1 ]; then
    if command -v systemd-run >/dev/null 2>&1 &&
       systemd-run --quiet --collect --unit="inetpanel-harden-$(date +%s)" \
           --description="iNetPanel: keep private files private" \
           /bin/bash "$(readlink -f "$0")" --quiet >/dev/null 2>&1; then
        echo "started"
        exit 0
    fi
    nohup /bin/bash "$(readlink -f "$0")" --quiet >>"$LOG" 2>&1 </dev/null &
    echo "started"
    exit 0
fi

[ -z "$TEST_ROOT" ] && PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
umask 077

say() {
    local line
    line="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    printf '%s\n' "$line" >> "$LOG"
    [ "$QUIET" -eq 0 ] && printf '%s\n' "$*"
    return 0
}

exec 9>"$LOCK"
if ! flock -w 120 9; then
    say "another run is still in progress; giving up"
    exit 1
fi
chmod 600 "$LOG" 2>/dev/null

FAILED=0

# ── 1. Panel database ─────────────────────────────────────────────────────────
if [ -d "$DB_DIR" ] && [ ! -L "$DB_DIR" ]; then
    chown www-data:www-data "$DB_DIR"
    chmod 700 "$DB_DIR"
    for f in inetpanel.db inetpanel.db-wal inetpanel.db-shm .installed .admin_pass; do
        p="${DB_DIR}/${f}"
        [ -f "$p" ] && [ ! -L "$p" ] || continue
        chown www-data:www-data "$p"
        chmod 600 "$p"
    done
    if [ "$(stat -c '%a' "$DB_DIR")" = "700" ] &&
       { [ ! -f "${DB_DIR}/inetpanel.db" ] || [ "$(stat -c '%a' "${DB_DIR}/inetpanel.db")" = "600" ]; }; then
        say "panel database: directory 0700, files 0600 (www-data)"
    else
        say "ERROR: could not set panel database permissions"
        FAILED=1
    fi
else
    say "panel database directory not found (fresh install?) — skipped"
fi

# ── 2. Apache isolation ───────────────────────────────────────────────────────
WANT="# iNetPanel — managed by harden_panel.sh (inetp harden_panel). Do not edit.
# Apache serves the hosted sites; none of these are any of its business, and
# making them inaccessible to the service holds even through a symlink.
[Service]
InaccessiblePaths=-/var/www/inetpanel/db
InaccessiblePaths=-/var/www/inetpanel/TiCore
InaccessiblePaths=-/var/lib/inetpanel
InaccessiblePaths=-/var/lib/php/sessions
InaccessiblePaths=-/root
"

if ! systemctl cat apache2.service >/dev/null 2>&1; then
    say "apache2 is not installed — isolation skipped"
else
    PREV=""
    [ -f "$DROPIN" ] && PREV="$(cat "$DROPIN")"
    if [ "$PREV" != "$(printf '%s' "$WANT")" ]; then
        mkdir -p "$DROPIN_DIR"
        printf '%s' "$WANT" > "${DROPIN}.tmp" && chmod 644 "${DROPIN}.tmp" && mv -f "${DROPIN}.tmp" "$DROPIN"
        systemctl daemon-reload
        say "apache isolation: drop-in written"
    fi

    # The drop-in only binds an Apache started after it was written: compare
    # the running service's start time with the drop-in's.
    NEED_RESTART=0
    if systemctl is-active --quiet apache2; then
        since=$(systemctl show -p ActiveEnterTimestamp --value apache2 2>/dev/null)
        since_epoch=$(date -d "$since" +%s 2>/dev/null || echo 0)
        dropin_epoch=$(stat -c %Y "$DROPIN" 2>/dev/null || echo 0)
        [ "$since_epoch" -le "$dropin_epoch" ] && NEED_RESTART=1
    fi

    if [ "$NEED_RESTART" -eq 0 ]; then
        say "apache isolation: in place"
    elif ! apache2ctl configtest >/dev/null 2>&1; then
        # A broken site config would stop the restart too: never take the sites
        # down for this. Retried on the next run.
        say "apache isolation: waiting — Apache's config test fails for another reason, so Apache was not restarted"
        FAILED=1
    elif systemctl restart apache2 && sleep 2 && systemctl is-active --quiet apache2; then
        say "apache isolation: applied (Apache restarted)"
    else
        say "ERROR: Apache did not come back with the isolation drop-in — rolling back"
        if [ -n "$PREV" ] && [ "$PREV" != "$(printf '%s' "$WANT")" ]; then
            printf '%s' "$PREV" > "$DROPIN"
        else
            rm -f "$DROPIN"
        fi
        systemctl daemon-reload
        systemctl restart apache2
        if systemctl is-active --quiet apache2; then
            say "rollback: Apache is running without the drop-in"
        else
            say "ERROR: Apache is not running after the rollback — check 'systemctl status apache2'"
        fi
        FAILED=1
        ROLLED_BACK=1
    fi
fi

# ── Record ────────────────────────────────────────────────────────────────────
# 'failed' after a rollback stops the panel's automatic hourly retry, so a box
# where Apache will not start with the drop-in is not restarted over and over;
# a panel update or a manual `inetp harden_panel` tries again.
DB="${DB_DIR}/inetpanel.db"
RECORD=""
[ "$FAILED" -eq 0 ] && RECORD="$HARDENING_VERSION"
[ "${ROLLED_BACK:-0}" -eq 1 ] && RECORD="failed"
if [ -n "$RECORD" ] && [ -f "$DB" ]; then
    sqlite3 "$DB" "INSERT INTO settings (key, value, category, updated_at)
        VALUES ('security_hardening', '${RECORD}', 'system', datetime('now'))
        ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at;" \
        || say "warning: could not record the hardening result"
fi

exit "$FAILED"
