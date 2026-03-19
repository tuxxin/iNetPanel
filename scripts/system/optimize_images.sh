#!/bin/bash
# ==============================================================================
# optimize_images.sh — Recursively optimizes images in a target directory
#   - JPEG: jpegoptim  (in-place, preserves ICC color profiles)
#   - PNG:  pngquant   (in-place)
#   - GIF:  gifsicle   (in-place)
#   - WebP: cwebp      (generates .webp alongside each JPEG/PNG)
#   - AVIF: avifenc    (generates .avif alongside each JPEG/PNG, if available)
#   - SVG:  svgo       (in-place, if available)
#   - Large images (>2560px) are resized before optimization
#   - Already-optimized files are skipped on re-runs
#   - File ownership is preserved after each operation
# Quality: JPEG=85%, PNG=65-80, WebP=80%, AVIF=63, GIF=O3
# Usage: inetp optimize_images --dir /path [--dry-run] [--verbose]
#        inetp optimize_images --username user1 [--domain example.com]
# ==============================================================================

if [ -t 1 ]; then
    BOLD='\033[1m'; GREEN='\033[1;32m'; YELLOW='\033[1;33m'; RED='\033[1;31m'; BLUE='\033[1;34m'; CYAN='\033[1;36m'; DIM='\033[2m'; NC='\033[0m'
else
    BOLD=''; GREEN=''; YELLOW=''; RED=''; BLUE=''; CYAN=''; DIM=''; NC=''
fi

JPEG_QUALITY=85
PNG_QUALITY="65-80"
WEBP_QUALITY=80
AVIF_QUALITY=63
GIF_OPT_LEVEL=3
MAX_WIDTH=2560

# --- Parse flags ---
NON_INTERACTIVE=0
USERNAME=""
DOMAIN=""
TARGET_DIR=""
DRY_RUN=0
VERBOSE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --username)  USERNAME="$2"; shift 2 ;;
        --domain)    DOMAIN="$2";   shift 2 ;;
        --dir)       TARGET_DIR="$2"; shift 2 ;;
        --dry-run)   DRY_RUN=1; shift ;;
        --verbose)   VERBOSE=1; shift ;;
        *) shift ;;
    esac
done

if [ -n "$TARGET_DIR" ]; then
    NON_INTERACTIVE=1
elif [ -n "$USERNAME" ] && [ -n "$DOMAIN" ]; then
    TARGET_DIR="/home/${USERNAME}/${DOMAIN}/www"
    NON_INTERACTIVE=1
elif [ -n "$USERNAME" ]; then
    TARGET_DIR="/home/${USERNAME}"
    NON_INTERACTIVE=1
elif [ -n "$DOMAIN" ]; then
    PANEL_DB="/var/www/inetpanel/db/inetpanel.db"
    if [ -f "$PANEL_DB" ]; then
        DB_USER=$(sqlite3 "$PANEL_DB" "SELECT h.username FROM hosting_users h JOIN domains d ON d.hosting_user_id = h.id WHERE d.domain_name='${DOMAIN}' LIMIT 1;" 2>/dev/null)
    fi
    if [ -n "$DB_USER" ] && [ -d "/home/${DB_USER}/${DOMAIN}/www" ]; then
        TARGET_DIR="/home/${DB_USER}/${DOMAIN}/www"
    else
        TARGET_DIR="/home/${DOMAIN}/www"
    fi
    NON_INTERACTIVE=1
fi

# Feature detection
HAS_AVIF=0; command -v avifenc &>/dev/null && HAS_AVIF=1
HAS_SVGO=0; command -v svgo &>/dev/null && HAS_SVGO=1
HAS_CONVERT=0; command -v convert &>/dev/null && HAS_CONVERT=1

echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}  Image Optimizer${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════${NC}"
echo -e "  ${BLUE}JPEG:${NC} ${JPEG_QUALITY}%  ${BLUE}PNG:${NC} ${PNG_QUALITY}  ${BLUE}WebP:${NC} ${WEBP_QUALITY}%  ${BLUE}GIF:${NC} O${GIF_OPT_LEVEL}"
if [ "$HAS_AVIF" -eq 1 ]; then
    echo -e "  ${BLUE}AVIF:${NC} ${AVIF_QUALITY}  ${BLUE}Max width:${NC} ${MAX_WIDTH}px"
else
    echo -e "  ${DIM}AVIF: not available (install libavif-bin for AVIF support)${NC}"
fi
if [ "$HAS_SVGO" -eq 1 ]; then
    echo -e "  ${BLUE}SVG:${NC} enabled"
fi
[ "$DRY_RUN" -eq 1 ] && echo -e "  ${YELLOW}Mode: DRY RUN (no files will be modified)${NC}"
echo ""

if [ "$NON_INTERACTIVE" -eq 0 ]; then
    read -p "Enter target directory path: " TARGET_DIR
fi
if [ -z "$TARGET_DIR" ] || [ ! -d "$TARGET_DIR" ]; then
    echo -e "${RED}Invalid directory: $TARGET_DIR${NC}"; exit 1
fi

# Optimization tracking dir (skip already-optimized files)
OPT_TRACK="/tmp/inetp_img_optimized"
mkdir -p "$OPT_TRACK" 2>/dev/null

is_optimized() {
    local file="$1"
    local mtime=$(stat -c%Y "$file" 2>/dev/null)
    local hash=$(echo "$file" | md5sum | cut -d' ' -f1)
    local marker="$OPT_TRACK/${hash}"
    if [ -f "$marker" ] && [ "$(cat "$marker")" = "$mtime" ]; then
        return 0
    fi
    return 1
}

mark_optimized() {
    local file="$1"
    local mtime=$(stat -c%Y "$file" 2>/dev/null)
    local hash=$(echo "$file" | md5sum | cut -d' ' -f1)
    echo "$mtime" > "$OPT_TRACK/${hash}"
}

# Resize large images
resize_if_needed() {
    local file="$1"
    [ "$HAS_CONVERT" -eq 0 ] && return
    local width=$(identify -format '%w' "$file" 2>/dev/null)
    if [ -n "$width" ] && [ "$width" -gt "$MAX_WIDTH" ]; then
        if [ "$DRY_RUN" -eq 0 ]; then
            convert "$file" -resize "${MAX_WIDTH}x>" -quality 95 "$file" 2>/dev/null
            [ "$VERBOSE" -eq 1 ] && echo -e "    ${DIM}↳ Resized from ${width}px to ${MAX_WIDTH}px${NC}"
        else
            [ "$VERBOSE" -eq 1 ] && echo -e "    ${DIM}↳ Would resize from ${width}px to ${MAX_WIDTH}px${NC}"
        fi
    fi
}

TOTAL_BEFORE=0
TOTAL_AFTER=0
SKIPPED=0
COUNT_JPEG=0; COUNT_PNG=0; COUNT_GIF=0; COUNT_WEBP=0; COUNT_AVIF=0; COUNT_SVG=0

echo -e "${YELLOW}Processing: $TARGET_DIR${NC}"
echo ""

# ── JPEG ──────────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ JPEG${NC}"
while IFS= read -r -d '' file; do
    if is_optimized "$file"; then
        SKIPPED=$((SKIPPED + 1))
        continue
    fi

    OWNER=$(stat -c "%u:%g" "$file")
    BEFORE=$(stat -c%s "$file")

    if [ "$DRY_RUN" -eq 0 ]; then
        resize_if_needed "$file"
        jpegoptim --max="$JPEG_QUALITY" --strip-com --strip-exif --quiet "$file"
        chown "$OWNER" "$file"

        WEBP_OUT="${file%.*}.webp"
        cwebp -q "$WEBP_QUALITY" "$file" -o "$WEBP_OUT" -quiet 2>/dev/null
        if [ -f "$WEBP_OUT" ]; then chown "$OWNER" "$WEBP_OUT"; COUNT_WEBP=$((COUNT_WEBP + 1)); fi

        if [ "$HAS_AVIF" -eq 1 ]; then
            AVIF_OUT="${file%.*}.avif"
            avifenc -q "$AVIF_QUALITY" -s 6 "$file" "$AVIF_OUT" 2>/dev/null
            if [ -f "$AVIF_OUT" ]; then chown "$OWNER" "$AVIF_OUT"; COUNT_AVIF=$((COUNT_AVIF + 1)); fi
        fi

        mark_optimized "$file"
    fi

    AFTER=$(stat -c%s "$file")
    TOTAL_BEFORE=$((TOTAL_BEFORE + BEFORE))
    TOTAL_AFTER=$((TOTAL_AFTER + AFTER))
    COUNT_JPEG=$((COUNT_JPEG + 1))

    if [ "$VERBOSE" -eq 1 ]; then
        SAVED_FILE=$((BEFORE - AFTER))
        SAVED_HR=$(numfmt --to=iec "$SAVED_FILE" 2>/dev/null || echo "${SAVED_FILE}B")
        echo -e "  ${GREEN}✓${NC} $(basename "$file") — saved ${SAVED_HR}"
    fi
done < <(find "$TARGET_DIR" -type f \( -iname "*.jpg" -o -iname "*.jpeg" \) -print0)
echo -e "  Processed: ${BOLD}${COUNT_JPEG}${NC}"
echo ""

# ── PNG ───────────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ PNG${NC}"
while IFS= read -r -d '' file; do
    if is_optimized "$file"; then
        SKIPPED=$((SKIPPED + 1))
        continue
    fi

    OWNER=$(stat -c "%u:%g" "$file")
    BEFORE=$(stat -c%s "$file")

    if [ "$DRY_RUN" -eq 0 ]; then
        resize_if_needed "$file"
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

        if [ "$HAS_AVIF" -eq 1 ]; then
            AVIF_OUT="${file%.*}.avif"
            avifenc -q "$AVIF_QUALITY" -s 6 "$file" "$AVIF_OUT" 2>/dev/null
            if [ -f "$AVIF_OUT" ]; then chown "$OWNER" "$AVIF_OUT"; COUNT_AVIF=$((COUNT_AVIF + 1)); fi
        fi

        mark_optimized "$file"
    fi

    AFTER=$(stat -c%s "$file")
    TOTAL_BEFORE=$((TOTAL_BEFORE + BEFORE))
    TOTAL_AFTER=$((TOTAL_AFTER + AFTER))
    COUNT_PNG=$((COUNT_PNG + 1))

    if [ "$VERBOSE" -eq 1 ]; then
        SAVED_FILE=$((BEFORE - AFTER))
        SAVED_HR=$(numfmt --to=iec "$SAVED_FILE" 2>/dev/null || echo "${SAVED_FILE}B")
        echo -e "  ${GREEN}✓${NC} $(basename "$file") — saved ${SAVED_HR}"
    fi
done < <(find "$TARGET_DIR" -type f -iname "*.png" -print0)
echo -e "  Processed: ${BOLD}${COUNT_PNG}${NC}"
echo ""

# ── GIF ───────────────────────────────────────────────────────────────────────
echo -e "${CYAN}▸ GIF${NC}"
while IFS= read -r -d '' file; do
    if is_optimized "$file"; then
        SKIPPED=$((SKIPPED + 1))
        continue
    fi

    OWNER=$(stat -c "%u:%g" "$file")
    BEFORE=$(stat -c%s "$file")

    if [ "$DRY_RUN" -eq 0 ]; then
        gifsicle -O"$GIF_OPT_LEVEL" --batch "$file" 2>/dev/null
        chown "$OWNER" "$file"
        mark_optimized "$file"
    fi

    AFTER=$(stat -c%s "$file")
    TOTAL_BEFORE=$((TOTAL_BEFORE + BEFORE))
    TOTAL_AFTER=$((TOTAL_AFTER + AFTER))
    COUNT_GIF=$((COUNT_GIF + 1))

    if [ "$VERBOSE" -eq 1 ]; then
        SAVED_FILE=$((BEFORE - AFTER))
        SAVED_HR=$(numfmt --to=iec "$SAVED_FILE" 2>/dev/null || echo "${SAVED_FILE}B")
        echo -e "  ${GREEN}✓${NC} $(basename "$file") — saved ${SAVED_HR}"
    fi
done < <(find "$TARGET_DIR" -type f -iname "*.gif" -print0)
echo -e "  Processed: ${BOLD}${COUNT_GIF}${NC}"
echo ""

# ── SVG ───────────────────────────────────────────────────────────────────────
if [ "$HAS_SVGO" -eq 1 ]; then
    echo -e "${CYAN}▸ SVG${NC}"
    while IFS= read -r -d '' file; do
        if is_optimized "$file"; then
            SKIPPED=$((SKIPPED + 1))
            continue
        fi

        OWNER=$(stat -c "%u:%g" "$file")
        BEFORE=$(stat -c%s "$file")

        if [ "$DRY_RUN" -eq 0 ]; then
            svgo --quiet -i "$file" -o "$file" 2>/dev/null
            chown "$OWNER" "$file"
            mark_optimized "$file"
        fi

        AFTER=$(stat -c%s "$file")
        TOTAL_BEFORE=$((TOTAL_BEFORE + BEFORE))
        TOTAL_AFTER=$((TOTAL_AFTER + AFTER))
        COUNT_SVG=$((COUNT_SVG + 1))

        if [ "$VERBOSE" -eq 1 ]; then
            SAVED_FILE=$((BEFORE - AFTER))
            SAVED_HR=$(numfmt --to=iec "$SAVED_FILE" 2>/dev/null || echo "${SAVED_FILE}B")
            echo -e "  ${GREEN}✓${NC} $(basename "$file") — saved ${SAVED_HR}"
        fi
    done < <(find "$TARGET_DIR" -type f -iname "*.svg" -print0)
    echo -e "  Processed: ${BOLD}${COUNT_SVG}${NC}"
    echo ""
fi

# ── Summary ───────────────────────────────────────────────────────────────────
SAVED=$((TOTAL_BEFORE - TOTAL_AFTER))
if [ "$TOTAL_BEFORE" -gt 0 ]; then
    PCT=$(awk "BEGIN { printf \"%.1f\", ($SAVED / $TOTAL_BEFORE) * 100 }")
else
    PCT="0.0"
fi
BEFORE_HR=$(numfmt --to=iec "$TOTAL_BEFORE" 2>/dev/null || echo "${TOTAL_BEFORE}B")
SAVED_HR=$(numfmt  --to=iec "$SAVED"        2>/dev/null || echo "${SAVED}B")

echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
if [ "$DRY_RUN" -eq 1 ]; then
    echo -e "${GREEN} Dry Run Complete (no files modified)${NC}"
else
    echo -e "${GREEN} Optimization Complete!${NC}"
fi
echo -e "${GREEN}═══════════════════════════════════════════════════${NC}"
echo -e "  JPEG processed:  $COUNT_JPEG"
echo -e "  PNG processed:   $COUNT_PNG"
echo -e "  GIF processed:   $COUNT_GIF"
[ "$HAS_SVGO" -eq 1 ] && echo -e "  SVG processed:   $COUNT_SVG"
echo -e "  WebP generated:  $COUNT_WEBP"
[ "$HAS_AVIF" -eq 1 ] && echo -e "  AVIF generated:  $COUNT_AVIF"
echo -e "  Skipped (cached): $SKIPPED"
echo -e "  Original size:   $BEFORE_HR"
echo -e "  Space saved:     ${GREEN}${SAVED_HR} (${PCT}%)${NC}"
echo ""
if [ "$DRY_RUN" -eq 0 ]; then
    echo -e "${YELLOW}Note: Originals optimized in-place. WebP/AVIF files generated alongside originals.${NC}"
    echo -e "${YELLOW}ICC color profiles are preserved for accurate color rendering.${NC}"
fi
