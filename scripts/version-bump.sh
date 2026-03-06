#!/usr/bin/env bash
# =============================================================================
# scripts/version-bump.sh
# iNetPanel - Automatic Version Bumper
# =============================================================================
# Increments APP_VERSION in TiCore/.env by 0.001 (1/1000th) on each call.
# Called automatically by the git post-commit hook.
#
# Version format: 0.XXX (three decimal places)
# Example: 0.107 -> 0.108 -> 0.109 -> ... -> 0.999 -> 1.000
# =============================================================================

set -euo pipefail

# Resolve project root (one level up from scripts/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
ENV_FILE="$PROJECT_ROOT/TiCore/.env"

# --- Ensure .env exists ---
if [[ ! -f "$ENV_FILE" ]]; then
    echo "[version-bump] ERROR: .env not found at $ENV_FILE"
    exit 1
fi

# --- Read current version ---
CURRENT_VERSION=$(grep -m1 '^APP_VERSION=' "$ENV_FILE" | cut -d'=' -f2 | tr -d '[:space:]')

if [[ -z "$CURRENT_VERSION" ]]; then
    echo "[version-bump] ERROR: APP_VERSION not found in $ENV_FILE"
    exit 1
fi

# --- Parse major and minor (padded to 3 digits) ---
MAJOR="${CURRENT_VERSION%%.*}"
MINOR="${CURRENT_VERSION#*.}"

# Remove leading zeros for arithmetic, then increment
MINOR_INT=$(echo "$MINOR" | sed 's/^0*//' | awk '{print $1+0}')
MINOR_INT=$((MINOR_INT + 1))

# Pad minor back to 3 digits
MINOR_NEW=$(printf "%03d" "$MINOR_INT")

NEW_VERSION="${MAJOR}.${MINOR_NEW}"

# --- Update .env in-place ---
if [[ "$(uname)" == "Darwin" ]]; then
    # macOS requires '' after -i
    sed -i '' "s/^APP_VERSION=.*/APP_VERSION=${NEW_VERSION}/" "$ENV_FILE"
else
    sed -i "s/^APP_VERSION=.*/APP_VERSION=${NEW_VERSION}/" "$ENV_FILE"
fi

echo "[version-bump] v${CURRENT_VERSION} -> v${NEW_VERSION}"

# --- Stage the .env update if inside a git repo ---
if git -C "$PROJECT_ROOT" rev-parse --git-dir > /dev/null 2>&1; then
    git -C "$PROJECT_ROOT" add "$ENV_FILE"
fi
