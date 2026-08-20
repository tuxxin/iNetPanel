#!/bin/bash
# ==============================================================================
# session_reaper.sh — expire PHP session files the distro reaper cannot see
# Usage: inetp session_reaper [--dry-run] [--max-age SECONDS] [--batch N]
#
# WHY
# ---
# The panel writes a per-account session path into every generated FPM pool:
#     php_value[session.save_path] = /home/<account>/<domain>/tmp/sessions
#
# Debian's /usr/lib/php/sessionclean reads the SAPI php.ini ONLY. It never parses
# pool.d/*.conf, so those directories are invisible to it — phpsessionclean.timer
# "succeeds" in milliseconds having cleaned nothing. session.gc_probability is 0
# on Debian by design, so PHP does not collect them either. Nothing reaps them.
#
# Measured on one production host: 4.2 million files under a single account, the
# directory ENTRY alone 281 MB. Every session lookup pays that cost.
#
# WHY IT IS BATCHED
# -----------------
# A single unbounded `find … -delete` over millions of files on a loop-mounted
# ext4 image can stall the entire container — that has already happened on the
# audited host from an unrelated recursive scan. So: nice/ionice, a hard cap per
# run, and a deliberate pause between batches. Falling behind is fine; the next
# run continues. Taking the box down is not.
#
# NOTE ON ext4: deleting files does NOT shrink a directory entry that has already
# grown. Reclaiming that space needs the directory recreated — see --dry-run
# output, which reports it so an operator can decide.
# ==============================================================================

MAX_AGE=""
BATCH=50000
DRY=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)  DRY=1; shift ;;
        --max-age)  MAX_AGE="$2"; shift 2 ;;
        --batch)    BATCH="$2"; shift 2 ;;
        *) shift ;;
    esac
done

# Honour the configured lifetime rather than a hardcoded age.
if [ -z "$MAX_AGE" ]; then
    MAX_AGE=$(php -r 'echo (int) ini_get("session.gc_maxlifetime");' 2>/dev/null)
fi
[ -z "$MAX_AGE" ] || [ "$MAX_AGE" -lt 60 ] 2>/dev/null && MAX_AGE=1440
AGE_MIN=$(( MAX_AGE / 60 ))
[ "$AGE_MIN" -lt 1 ] && AGE_MIN=1

TOTAL=0
echo "Reaping sess_* older than ${MAX_AGE}s (${AGE_MIN}m), max ${BATCH} per directory."

# Both layouts: the per-domain path new pools use, and the legacy per-account one.
for dir in /home/*/tmp /home/*/*/tmp/sessions; do
    [ -d "$dir" ] || continue
    count=$(find "$dir" -maxdepth 1 -name 'sess_*' -type f -mmin "+${AGE_MIN}" -printf . 2>/dev/null | head -c "$BATCH" | wc -c)
    [ "${count:-0}" -eq 0 ] && continue

    if [ "$DRY" -eq 1 ]; then
        entry=$(stat -c %s "$dir" 2>/dev/null)
        printf '  %-46s %8s expired  (dir entry %s)\n' "$dir" "$count" "$(numfmt --to=iec "${entry:-0}")"
        TOTAL=$((TOTAL + count))
        continue
    fi

    # -print0 | head -z -n BATCH caps the work; nice/ionice keeps it off the
    # critical path for a box that is also serving sites.
    find "$dir" -maxdepth 1 -name 'sess_*' -type f -mmin "+${AGE_MIN}" -print0 2>/dev/null \
        | head -z -n "$BATCH" \
        | nice -n 19 ionice -c3 xargs -0 --no-run-if-empty rm -f
    printf '  %-46s %8s removed\n' "$dir" "$count"
    TOTAL=$((TOTAL + count))
    sleep 1   # let the box breathe between accounts
done

echo "Total: ${TOTAL}"
[ "$DRY" -eq 1 ] && echo "(dry run — nothing deleted)"
exit 0
