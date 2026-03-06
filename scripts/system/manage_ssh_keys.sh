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
fi

SSH_DIR="${HOME_DIR}/.ssh"
AUTH_KEYS="${SSH_DIR}/authorized_keys"

# Ensure .ssh directory and authorized_keys file exist with correct permissions
ensure_ssh_dir() {
    if [[ ! -d "$SSH_DIR" ]]; then
        mkdir -p "$SSH_DIR"
    fi
    if [[ ! -f "$AUTH_KEYS" ]]; then
        touch "$AUTH_KEYS"
    fi
    if [[ "$DOMAIN" == "root" ]]; then
        chmod 700 "$SSH_DIR"
        chmod 600 "$AUTH_KEYS"
    else
        chown -R "${SSH_USER}:${SSH_USER}" "$SSH_DIR"
        chmod 700 "$SSH_DIR"
        chmod 600 "$AUTH_KEYS"
    fi
}

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
        if [[ ! -s "$AUTH_KEYS" ]]; then
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
        done < "$AUTH_KEYS"
        echo ']}'
        ;;

    add)
        if [[ -z "$KEY_DATA" ]]; then
            echo '{"success":false,"error":"Missing --key"}' >&2; exit 1
        fi
        # Basic key format validation
        if ! echo "$KEY_DATA" | grep -qP '^(ssh-(rsa|dss|ed25519|ecdsa)|ecdsa-sha2-nistp(256|384|521))\s+[A-Za-z0-9+/]+=*'; then
            echo '{"success":false,"error":"Invalid public key format"}' >&2; exit 1
        fi

        ensure_ssh_dir

        # Append comment if provided and key doesn't already have one
        KEY_PARTS=$(echo "$KEY_DATA" | awk '{print NF}')
        if [[ -n "$COMMENT" && "$KEY_PARTS" -lt 3 ]]; then
            KEY_DATA="${KEY_DATA} ${COMMENT}"
        fi

        # Check if key already exists (by fingerprint)
        NEW_FP=$(get_fingerprint "$KEY_DATA")
        if grep -q . "$AUTH_KEYS" 2>/dev/null; then
            while IFS= read -r line; do
                [[ -z "$line" || "$line" =~ ^# ]] && continue
                EXISTING_FP=$(get_fingerprint "$line")
                if [[ "$EXISTING_FP" == "$NEW_FP" ]]; then
                    echo '{"success":false,"error":"Key already exists"}' >&2; exit 1
                fi
            done < "$AUTH_KEYS"
        fi

        echo "$KEY_DATA" >> "$AUTH_KEYS"
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

        TMP_FILE=$(mktemp)
        DELETED=0

        while IFS= read -r line || [[ -n "$line" ]]; do
            if [[ -z "$line" || "$line" =~ ^# ]]; then
                echo "$line" >> "$TMP_FILE"
                continue
            fi
            EXISTING_FP=$(get_fingerprint "$line")
            if [[ "$EXISTING_FP" == "$TARGET_FP" ]]; then
                DELETED=1
            else
                echo "$line" >> "$TMP_FILE"
            fi
        done < "$AUTH_KEYS"

        if [[ $DELETED -eq 0 ]]; then
            rm -f "$TMP_FILE"
            echo '{"success":false,"error":"Key not found"}' >&2; exit 1
        fi

        mv "$TMP_FILE" "$AUTH_KEYS"
        ensure_ssh_dir  # re-apply permissions
        echo '{"success":true}'
        ;;

    generate)
        ensure_ssh_dir

        LABEL="${COMMENT:-inetpanel-$(date +%Y%m%d)}"
        TMP_KEY="/tmp/inetp_sshkey_$$"

        # Generate ed25519 keypair
        ssh-keygen -t ed25519 -N "" -C "$LABEL" -f "$TMP_KEY" -q

        PUB_KEY=$(cat "${TMP_KEY}.pub")
        PRIV_KEY=$(cat "$TMP_KEY")

        # Add public key to authorized_keys
        echo "$PUB_KEY" >> "$AUTH_KEYS"
        ensure_ssh_dir  # re-apply permissions

        FP=$(get_fingerprint "$PUB_KEY")

        # Clean up temp key files immediately
        rm -f "$TMP_KEY" "${TMP_KEY}.pub"

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
