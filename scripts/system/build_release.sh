#!/bin/bash
# ==============================================================================
# build_release.sh — iNetPanel Release Builder
#
# Output:
#   /root/release/latest              — Bash installer (curl | bash style)
#   /root/release/inetpanel-latest.zip — Panel source files
#
# Usage:
#   bash /root/scripts/build_release.sh
#
# Install instructions (after uploading to server):
#   curl -o latest https://inetpanel.tuxxin.com/data/latest && bash latest
# ==============================================================================

BOLD='\033[1m'; GREEN='\033[1;32m'; RED='\033[1;31m'; YELLOW='\033[1;33m'; NC='\033[0m'

RELEASE_DIR="/root/release"
PANEL_SRC="/root/inetpanel"
INSTALLER_SRC="/root/install_LAMP.sh"

step() { echo -e "${BOLD}[→]${NC} $1"; }
ok()   { echo -e "${GREEN}[✓]${NC} $1"; }
fail() { echo -e "${RED}[✗]${NC} $1"; exit 1; }

# ── Verify source files exist ─────────────────────────────────────────────────
[ -f "$INSTALLER_SRC" ] || fail "Installer not found: $INSTALLER_SRC"
[ -d "$PANEL_SRC"     ] || fail "Panel source not found: $PANEL_SRC"
command -v zip &>/dev/null || fail "'zip' is not installed. Run: apt-get install zip"

mkdir -p "$RELEASE_DIR"

# ── 1. Copy installer as 'latest' ─────────────────────────────────────────────
step "Copying installer → latest"
cp "$INSTALLER_SRC" "$RELEASE_DIR/latest"
chmod +x "$RELEASE_DIR/latest"
ok "Installer copied: $RELEASE_DIR/latest"

# ── 2. Build panel zip (exclude secrets + dev artifacts) ─────────────────────
step "Building inetpanel-latest.zip"

TMP_ZIP_DIR=$(mktemp -d)
PANEL_COPY="${TMP_ZIP_DIR}/inetpanel"
mkdir -p "$PANEL_COPY"

# Copy panel source, excluding files that should not be shipped
rsync -a \
    --exclude='.git' \
    --exclude='.git/**' \
    --exclude='db/*.db' \
    --exclude='db/*.db-*' \
    --exclude='TiCore/.env' \
    --exclude='.installed' \
    --exclude='*.log' \
    "$PANEL_SRC/" "$PANEL_COPY/"

# Create a blank db/.gitkeep so the db/ directory exists in the zip
mkdir -p "$PANEL_COPY/db"
touch "$PANEL_COPY/db/.gitkeep"

# Zip from the temp dir so the archive has a clean inetpanel/ root
cd "$TMP_ZIP_DIR"
zip -r "$RELEASE_DIR/inetpanel-latest.zip" inetpanel/ -x "*.DS_Store" -x "__MACOSX/*" -q

# Cleanup
rm -rf "$TMP_ZIP_DIR"

ok "Panel zip created: $RELEASE_DIR/inetpanel-latest.zip"

# ── 3. Summary ────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}======================================================${NC}"
echo -e "${GREEN}   Release Build Complete!${NC}"
echo -e "${BOLD}======================================================${NC}"

LATEST_SIZE=$(du -sh "$RELEASE_DIR/latest"              2>/dev/null | cut -f1)
ZIP_SIZE=$(du -sh    "$RELEASE_DIR/inetpanel-latest.zip" 2>/dev/null | cut -f1)

echo -e "  ${BOLD}latest${NC}                  ${GREEN}$LATEST_SIZE${NC}  →  $RELEASE_DIR/latest"
echo -e "  ${BOLD}inetpanel-latest.zip${NC}    ${GREEN}$ZIP_SIZE${NC}  →  $RELEASE_DIR/inetpanel-latest.zip"
echo ""
echo -e "  ${YELLOW}Upload both files to: https://inetpanel.tuxxin.com/data/${NC}"
echo ""
echo -e "  Install command:"
echo -e "    ${GREEN}curl -o latest https://inetpanel.tuxxin.com/data/latest && bash latest${NC}"
echo -e "${BOLD}======================================================${NC}"
