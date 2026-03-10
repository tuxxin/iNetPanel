#!/bin/bash
# ==============================================================================
# optimize_images.sh — Recursively optimizes images in a target directory
#   - JPEG: jpegoptim  (in-place, strips metadata)
#   - PNG:  pngquant   (in-place)
#   - GIF:  gifsicle   (in-place)
#   - WebP: cwebp      (generates .webp alongside each JPEG/PNG)
#   - File ownership is preserved after each operation
# Quality defaults: JPEG=85%, PNG=65-80, WebP=80%, GIF=O3
# Usage (interactive):     inetp optimize_images
# Usage (non-interactive): inetp optimize_images --username user1
#                          inetp optimize_images --username user1 --domain example.com
#                          inetp optimize_images --domain example.com
#                          inetp optimize_images --dir /path/to/dir
# ==============================================================================
BOLD='\033[1m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'; RED='\033[1;31m'; BLUE='\033[1;34m'; NC='\033[0m'

JPEG_QUALITY=85
PNG_QUALITY="65-80"
WEBP_QUALITY=80
GIF_OPT_LEVEL=3

# --- Parse non-interactive flags ---
NON_INTERACTIVE=0
USERNAME=""
DOMAIN=""
TARGET_DIR=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --username) USERNAME="$2"; shift 2 ;;
        --domain)   DOMAIN="$2";   shift 2 ;;
        --dir)      TARGET_DIR="$2"; shift 2 ;;
        *) shift ;;
    esac
done

if [ -n "$TARGET_DIR" ]; then
    # Explicit directory — use as-is
    NON_INTERACTIVE=1
elif [ -n "$USERNAME" ] && [ -n "$DOMAIN" ]; then
    # User + domain: optimize specific domain under user
    TARGET_DIR="/home/${USERNAME}/${DOMAIN}/www"
    NON_INTERACTIVE=1
elif [ -n "$USERNAME" ]; then
    # User-level: optimize all domains under this user
    TARGET_DIR="/home/${USERNAME}"
    NON_INTERACTIVE=1
elif [ -n "$DOMAIN" ]; then
    # Domain only: try new structure first, fall back to legacy
    # Look up username from panel DB
    PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
    if [ -f "$PANEL_DB" ]; then
        DB_USER=$(sqlite3 "$PANEL_DB" "SELECT h.username FROM hosting_users h JOIN domains d ON d.hosting_user_id = h.id WHERE d.domain_name='${DOMAIN}' LIMIT 1;" 2>/dev/null)
    fi
    if [ -n "$DB_USER" ] && [ -d "/home/${DB_USER}/${DOMAIN}/www" ]; then
        TARGET_DIR="/home/${DB_USER}/${DOMAIN}/www"
    elif [ -d "/home/${DOMAIN}/www" ]; then
        TARGET_DIR="/home/${DOMAIN}/www"
    else
        TARGET_DIR="/home/${DOMAIN}/www"
    fi
    NON_INTERACTIVE=1
fi

echo -e "${BOLD}--- Image Optimizer ---${NC}"
echo -e "  ${BLUE}JPEG:${NC} ${JPEG_QUALITY}%  ${BLUE}PNG:${NC} ${PNG_QUALITY}  ${BLUE}WebP:${NC} ${WEBP_QUALITY}%  ${BLUE}GIF:${NC} O${GIF_OPT_LEVEL}"
echo ""

if [ "$NON_INTERACTIVE" -eq 0 ]; then
    read -p "Enter target directory path: " TARGET_DIR
fi
if [ -z "$TARGET_DIR" ] || [ ! -d "$TARGET_DIR" ]; then
    echo -e "${RED}Invalid directory: $TARGET_DIR${NC}"; exit 1
fi

TOTAL_BEFORE=0
TOTAL_AFTER=0
COUNT_JPEG=0; COUNT_PNG=0; COUNT_GIF=0; COUNT_WEBP=0

echo -e "${YELLOW}Processing: $TARGET_DIR${NC}"
echo ""

# ----------------------------------------------------------------
# JPEG
# ----------------------------------------------------------------
while IFS= read -r -d '' file; do
    OWNER=$(stat -c "%u:%g" "$file")
    BEFORE=$(stat -c%s "$file")

    jpegoptim --max="$JPEG_QUALITY" --strip-all --quiet "$file"
    chown "$OWNER" "$file"

    WEBP_OUT="${file%.*}.webp"
    cwebp -q "$WEBP_QUALITY" "$file" -o "$WEBP_OUT" -quiet 2>/dev/null
    if [ -f "$WEBP_OUT" ]; then chown "$OWNER" "$WEBP_OUT"; COUNT_WEBP=$((COUNT_WEBP + 1)); fi

    AFTER=$(stat -c%s "$file")
    TOTAL_BEFORE=$((TOTAL_BEFORE + BEFORE))
    TOTAL_AFTER=$((TOTAL_AFTER + AFTER))
    COUNT_JPEG=$((COUNT_JPEG + 1))
done < <(find "$TARGET_DIR" -type f \( -iname "*.jpg" -o -iname "*.jpeg" \) -print0)

# ----------------------------------------------------------------
# PNG
# ----------------------------------------------------------------
while IFS= read -r -d '' file; do
    OWNER=$(stat -c "%u:%g" "$file")
    BEFORE=$(stat -c%s "$file")

    TMP_PNG=$(mktemp --suffix=.png)
    if pngquant --quality="$PNG_QUALITY" --output "$TMP_PNG" "$file" 2>/dev/null; then
        mv "$TMP_PNG" "$file"
    else
        rm -f "$TMP_PNG"
    fi
    chown "$OWNER" "$file"

    WEBP_OUT="${file%.*}.webp"
    cwebp -q "$WEBP_QUALITY" "$file" -o "$WEBP_OUT" -quiet 2>/dev/null
    if [ -f "$WEBP_OUT" ]; then chown "$OWNER" "$WEBP_OUT"; COUNT_WEBP=$((COUNT_WEBP + 1)); fi

    AFTER=$(stat -c%s "$file")
    TOTAL_BEFORE=$((TOTAL_BEFORE + BEFORE))
    TOTAL_AFTER=$((TOTAL_AFTER + AFTER))
    COUNT_PNG=$((COUNT_PNG + 1))
done < <(find "$TARGET_DIR" -type f -iname "*.png" -print0)

# ----------------------------------------------------------------
# GIF
# ----------------------------------------------------------------
while IFS= read -r -d '' file; do
    OWNER=$(stat -c "%u:%g" "$file")
    BEFORE=$(stat -c%s "$file")

    gifsicle -O"$GIF_OPT_LEVEL" --batch "$file" 2>/dev/null
    chown "$OWNER" "$file"

    AFTER=$(stat -c%s "$file")
    TOTAL_BEFORE=$((TOTAL_BEFORE + BEFORE))
    TOTAL_AFTER=$((TOTAL_AFTER + AFTER))
    COUNT_GIF=$((COUNT_GIF + 1))
done < <(find "$TARGET_DIR" -type f -iname "*.gif" -print0)

# ----------------------------------------------------------------
# Summary
# ----------------------------------------------------------------
SAVED=$((TOTAL_BEFORE - TOTAL_AFTER))
if [ "$TOTAL_BEFORE" -gt 0 ]; then
    PCT=$(awk "BEGIN { printf \"%.1f\", ($SAVED / $TOTAL_BEFORE) * 100 }")
else
    PCT="0.0"
fi
BEFORE_HR=$(numfmt --to=iec "$TOTAL_BEFORE" 2>/dev/null || echo "${TOTAL_BEFORE}B")
SAVED_HR=$(numfmt  --to=iec "$SAVED"        2>/dev/null || echo "${SAVED}B")

echo -e "${GREEN}==============================${NC}"
echo -e "${GREEN} Optimization Complete!${NC}"
echo -e "${GREEN}==============================${NC}"
echo -e "  JPEG processed:  $COUNT_JPEG"
echo -e "  PNG processed:   $COUNT_PNG"
echo -e "  GIF processed:   $COUNT_GIF"
echo -e "  WebP generated:  $COUNT_WEBP"
echo -e "  Original size:   $BEFORE_HR"
echo -e "  Space saved:     ${GREEN}${SAVED_HR} (${PCT}%)${NC}"
echo ""
echo -e "${YELLOW}Note: Originals optimized in-place. .webp files generated alongside originals.${NC}"
echo -e "${YELLOW}To auto-serve WebP, add mod_rewrite rules to each site's .htaccess.${NC}"
