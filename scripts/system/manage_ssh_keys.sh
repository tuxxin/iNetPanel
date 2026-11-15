#!/bin/bash
# FILE: /root/scripts/manage_ssh_keys.sh
# iNetPanel — SSH Authorized Key Manager
# Manages ~/.ssh/authorized_keys for hosting accounts and the root user
#
# Usage:
#   manage_ssh_keys.sh --domain <domain|root> --action <list|add|delete|generate>
#                      [--key "ssh-ed25519 AAAA..."] [--comment "label"]

set -euo pipefail

DOMAIN=""
ACTION=""
KEY_DATA=""
COMMENT=""

# Parse arguments
while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)   DOMAIN="$2";  shift 2 ;;
        --action)   ACTION="$2";  shift 2 ;;
        --key)      KEY_DATA="$2"; shift 2 ;;
        --comment)  COMMENT="$2";  shift 2 ;;
        *) echo "Unknown argument: $1" >&2; exit 1 ;;
    esac
done

# Validate required args
if [[ -z "$DOMAIN" || -z "$ACTION" ]]; then
    echo '{"success":false,"error":"Missing --domain or --action"}' >&2
    exit 1
fi

# Resolve home directory
if [[ "$DOMAIN" == "root" ]]; then
    HOME_DIR="/root"
    SSH_USER="root"
else
    # Validate domain name (alphanumeric, dots, hyphens only)
    if ! [[ "$DOMAIN" =~ ^[a-zA-Z0-9._-]+$ ]]; then
        echo '{"success":false,"error":"Invalid domain name"}' >&2
        exit 1
    fi
    HOME_DIR="/home/${DOMAIN}"
    SSH_USER="${DOMAIN}"
    if [[ ! -d "$HOME_DIR" ]]; then
        echo '{"success":false,"error":"Home directory not found"}' >&2
        exit 1
    fi
    if ! SSH_GID=$(id -g "$SSH_USER" 2>/dev/null); then
        echo '{"success":false,"error":"Account user not found"}' >&2
        exit 1
    fi
fi

SSH_DIR="${HOME_DIR}/.ssh"
AUTH_KEYS="${SSH_DIR}/authorized_keys"

# An account's ~/.ssh belongs to the account: read and write it as the account
# itself, with the account's own permissions.
as_owner() {
    if [[ "$DOMAIN" == "root" ]]; then
        "$@"
    else
        setpriv --reuid="$SSH_USER" --regid="$SSH_GID" --init-groups -- "$@"
    fi
}

# Ensure .ssh directory and authorized_keys file exist with correct permissions
ensure_ssh_dir() {
    if ! as_owner sh -c 'umask 077 && mkdir -p "$1" && chmod 700 "$1" && touch "$2" && chmod 600 "$2"' \
            sh "$SSH_DIR" "$AUTH_KEYS" 2>/dev/null; then
        echo '{"success":false,"error":"Cannot open ~/.ssh/authorized_keys as this account. Check that the account owns ~/.ssh and the file."}' >&2
        exit 1
    fi
}

# Print authorized_keys (as the owner, see above)
read_auth_keys() {
    as_owner cat -- "$AUTH_KEYS"
}

# Reject control characters (newlines included): one key is one line
has_control_chars() {
    [[ "$1" == *[[:cntrl:]]* ]]
}

if has_control_chars "$COMMENT"; then
    echo '{"success":false,"error":"The comment must be a single line of text"}' >&2
    exit 1
fi

# Get fingerprint of a key string
get_fingerprint() {
    local key_line="$1"
    echo "$key_line" | ssh-keygen -l -f - 2>/dev/null | awk '{print $2}'
}

# Get key type from first field
get_key_type() {
    echo "$1" | awk '{print $1}'
}

# Get comment from key line (third field onward)
get_key_comment() {
    echo "$1" | awk '{$1=""; $2=""; sub(/^ +/, ""); print}'
}

case "$ACTION" in

    list)
        ensure_ssh_dir
        CONTENT=$(read_auth_keys)
        if [[ -z "$CONTENT" ]]; then
            echo '{"success":true,"data":[]}'
            exit 0
        fi

        echo -n '{"success":true,"data":['
        first=1
        idx=0
        while IFS= read -r line || [[ -n "$line" ]]; do
            # Skip empty lines and comments
            [[ -z "$line" || "$line" =~ ^# ]] && continue

            FP=$(get_fingerprint "$line")
            TYPE=$(get_key_type "$line")
            CMT=$(get_key_comment "$line")

            # Escape for JSON
            FP_ESC="${FP//\\/\\\\}"; FP_ESC="${FP_ESC//\"/\\\"}"
            TYPE_ESC="${TYPE//\\/\\\\}"; TYPE_ESC="${TYPE_ESC//\"/\\\"}"
            CMT_ESC="${CMT//\\/\\\\}"; CMT_ESC="${CMT_ESC//\"/\\\"}"
            LINE_ESC="${line//\\/\\\\}"; LINE_ESC="${LINE_ESC//\"/\\\"}"

            if [[ $first -eq 0 ]]; then echo -n ','; fi
            echo -n "{\"index\":${idx},\"type\":\"${TYPE_ESC}\",\"fingerprint\":\"${FP_ESC}\",\"comment\":\"${CMT_ESC}\",\"raw\":\"${LINE_ESC}\"}"
            first=0
            ((idx++)) || true
        done <<< "$CONTENT"
        echo ']}'
        ;;

    add)
        if [[ -z "$KEY_DATA" ]]; then
            echo '{"success":false,"error":"Missing --key"}' >&2; exit 1
        fi
        # One line: <type> <base64> [comment]. No options prefix, no second line.
        if has_control_chars "$KEY_DATA"; then
            echo '{"success":false,"error":"The key must be a single line"}' >&2; exit 1
        fi
        if ! [[ "$KEY_DATA" =~ ^(ssh-(rsa|dss|ed25519|ecdsa)|ecdsa-sha2-nistp(256|384|521))\ +[A-Za-z0-9+/]+={0,3}(\ .*)?$ ]]; then
            echo '{"success":false,"error":"Invalid public key format"}' >&2; exit 1
        fi

        ensure_ssh_dir

        # Append comment if provided and key doesn't already have one
        KEY_PARTS=$(echo "$KEY_DATA" | awk '{print NF}')
        if [[ -n "$COMMENT" && "$KEY_PARTS" -lt 3 ]]; then
            KEY_DATA="${KEY_DATA} ${COMMENT}"
        fi

        # Must be exactly one key ssh-keygen accepts
        NEW_FP=$(get_fingerprint "$KEY_DATA") || NEW_FP=""
        if [[ -z "$NEW_FP" || "$NEW_FP" == *$'\n'* ]]; then
            echo '{"success":false,"error":"Invalid public key"}' >&2; exit 1
        fi

        # Check if key already exists (by fingerprint)
        CONTENT=$(read_auth_keys)
        if [[ -n "$CONTENT" ]]; then
            while IFS= read -r line; do
                [[ -z "$line" || "$line" =~ ^# ]] && continue
                EXISTING_FP=$(get_fingerprint "$line")
                if [[ "$EXISTING_FP" == "$NEW_FP" ]]; then
                    echo '{"success":false,"error":"Key already exists"}' >&2; exit 1
                fi
            done <<< "$CONTENT"
        fi

        # Start on a fresh line if the file does not end with one
        if [[ -n "$(as_owner tail -c1 -- "$AUTH_KEYS")" ]]; then
            KEY_DATA=$'\n'"$KEY_DATA"
        fi
        printf '%s\n' "$KEY_DATA" | as_owner tee -a -- "$AUTH_KEYS" >/dev/null
        ensure_ssh_dir  # re-apply permissions

        FP_ESC="${NEW_FP//\"/\\\"}"
        echo "{\"success\":true,\"fingerprint\":\"${FP_ESC}\"}"
        ;;

    delete)
        if [[ -z "$KEY_DATA" ]]; then
            echo '{"success":false,"error":"Missing --key (fingerprint to delete)"}' >&2; exit 1
        fi

        ensure_ssh_dir
        TARGET_FP="$KEY_DATA"

        CONTENT=$(read_auth_keys)
        KEPT=""
        DELETED=0

        while IFS= read -r line || [[ -n "$line" ]]; do
            if [[ -z "$line" || "$line" =~ ^# ]]; then
                KEPT+="${line}"$'\n'
                continue
            fi
            EXISTING_FP=$(get_fingerprint "$line")
            if [[ "$EXISTING_FP" == "$TARGET_FP" ]]; then
                DELETED=1
            else
                KEPT+="${line}"$'\n'
            fi
        done <<< "$CONTENT"

        if [[ $DELETED -eq 0 ]]; then
            echo '{"success":false,"error":"Key not found"}' >&2; exit 1
        fi

        # Write beside the file and rename over it, as the owner
        printf '%s' "$KEPT" | as_owner sh -c 'umask 077 && cat > "$1.inetp-new" && mv -f -- "$1.inetp-new" "$1"' sh "$AUTH_KEYS"
        ensure_ssh_dir  # re-apply permissions
        echo '{"success":true}'
        ;;

    generate)
        ensure_ssh_dir

        LABEL="${COMMENT:-inetpanel-$(date +%Y%m%d)}"
        # Private, unpredictable directory (mktemp -d is 0700)
        TMP_DIR=$(mktemp -d)
        trap 'rm -rf "$TMP_DIR"' EXIT
        TMP_KEY="${TMP_DIR}/key"

        # Generate ed25519 keypair
        ssh-keygen -t ed25519 -N "" -C "$LABEL" -f "$TMP_KEY" -q

        PUB_KEY=$(cat "${TMP_KEY}.pub")
        PRIV_KEY=$(cat "$TMP_KEY")

        # Clean up temp key files immediately
        rm -rf "$TMP_DIR"

        # Add public key to authorized_keys (as the owner, on a fresh line)
        if [[ -n "$(as_owner tail -c1 -- "$AUTH_KEYS")" ]]; then
            printf '\n' | as_owner tee -a -- "$AUTH_KEYS" >/dev/null
        fi
        printf '%s\n' "$PUB_KEY" | as_owner tee -a -- "$AUTH_KEYS" >/dev/null
        ensure_ssh_dir  # re-apply permissions

        FP=$(get_fingerprint "$PUB_KEY")

        # Return private key (one-time) + fingerprint in JSON
        # Escape private key for JSON
        PRIV_ESC=$(echo "$PRIV_KEY" | awk '{printf "%s\\n", $0}')
        FP_ESC="${FP//\"/\\\"}"
        LABEL_ESC="${LABEL//\"/\\\"}"

        echo "{\"success\":true,\"private_key\":\"${PRIV_ESC}\",\"fingerprint\":\"${FP_ESC}\",\"comment\":\"${LABEL_ESC}\"}"
        ;;

    *)
        echo '{"success":false,"error":"Unknown action"}' >&2
        exit 1
        ;;
esac
