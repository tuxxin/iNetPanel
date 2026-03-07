#!/bin/bash
# get_account_hash.sh — Returns the shadow password hash for a valid hosting account.
# Used for web portal credential verification. Restricted to users in vsftpd.userlist
# (hosting accounts only — prevents root/system user login attempts).
USER="$1"

# Reject empty or obviously invalid input
[[ -z "$USER" || "$USER" =~ [^a-zA-Z0-9._-] ]] && exit 1

# User must exist as a Linux user
id "$USER" &>/dev/null || exit 1

# Security gate: only allow users listed in vsftpd.userlist (hosting accounts)
grep -qxF "$USER" /etc/vsftpd.userlist 2>/dev/null || exit 1

# Return the shadow hash field only
getent shadow "$USER" | cut -d: -f2
