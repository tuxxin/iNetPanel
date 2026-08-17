#!/usr/bin/env bash
# =============================================================================
# release.sh — iNetPanel stable release automation
# =============================================================================
#
# WHAT THIS PROJECT'S RELEASE MODEL ACTUALLY IS
# ---------------------------------------------
# iNetPanel ships on two channels, and they work in completely different ways.
# Understanding this is the whole point of the script, so read this bit:
#
#   BETA    Any commit pushed to `main` IS the beta release. There is no build
#           step and no version number involved. A panel with
#           `update_channel = beta` asks GitHub for the newest commit SHA on
#           main, and pulls https://api.github.com/repos/tuxxin/iNetPanel/zipball/main
#           whenever that SHA differs from the one it installed.
#           See scripts/panel_update.php — the beta path deliberately skips the
#           version comparison ("For beta, always update (can't reliably
#           compare versions)"). So: push to main, and testers have it.
#           Nothing else is required. Do NOT run this script for a beta.
#
#   STABLE  A tagged GitHub Release carrying three assets:
#               inetpanel-latest.zip  — the panel source
#               latest                — the LAMP installer (stable variant)
#               latest-beta           — the LAMP installer (beta variant)
#           A panel on the stable channel reads /releases/latest and compares
#           version numbers, so the version in TiCore/Version.php MUST be
#           bumped or existing installs will never see the release.
#           That is what this script does.
#
# So the day-to-day loop is:
#
#     edit -> commit -> push to main        (beta testers get it immediately)
#     ...soak on beta...
#     ./release.sh --bump patch --notes "..."   (promote that state to stable)
#
#
# USAGE
# -----
#   ./release.sh --bump patch  --notes "Fix orphaned vhosts"
#   ./release.sh --version 1.25.0 --notes-file NOTES.md
#   ./release.sh --bump patch  --notes "..." --dry-run     # rehearse, change nothing
#
#   --version X.Y.Z     Set an exact version.
#   --bump LEVEL        patch | minor | major — derive it from the current one.
#   --notes TEXT        Release notes body.
#   --notes-file PATH   Release notes from a file (wins over --notes).
#   --installer PATH    LAMP installer source (default: /root/install_LAMP.sh).
#   --dry-run           Do everything except commit, tag, push and publish.
#   --skip-lint         Skip the local CI mirror. Don't.
#   --yes               No interactive confirmation (for automation).
#   -h | --help         This text.
#
#
# AUTHENTICATION
# --------------
# Needs a GitHub token with contents:write on tuxxin/iNetPanel. It is read from,
# in order:
#     1. $GITHUB_TOKEN in the environment
#     2. the GITHUB_TOKEN= line in $TOKEN_FILE (default /root/.env)
# The token is never printed, never written to .git/config, and never placed in
# a URL — it is passed to git through a one-shot credential helper and to the
# API through an Authorization header.
#
#
# A NOTE ON THE OLD VERSION BUMPERS
# ---------------------------------
# scripts/version-bump.sh and TiCore/Version.php::bump() both predate the
# current X.Y.Z scheme — they assume the old two-part "0.107" format and
# increment the SECOND field with zero padding. Fed the current version they
# produce garbage:  1.24.4 -> 1.025.  They also write to TiCore/.env, which is
# gitignored, so their output never reaches the repo at all. This script does
# its own version handling and does not call either of them. If you ever revive
# the post-commit hook in scripts/install-hooks.sh, fix those two first.
#
#
# WHAT IT DOES, IN ORDER
# ----------------------
#   1. Preflight   — right repo, on main, clean tree, in sync with origin,
#                    tools present, token present, tag not already taken.
#   2. Lint        — the same three gates CI runs (bash -n, shellcheck -S error,
#                    php -l), locally, BEFORE anything is published. Cheaper to
#                    fail here than to yank a release.
#   3. Version     — rewrite the APP_VERSION constant in TiCore/Version.php.
#   4. Commit+tag  — "Release vX.Y.Z — <first line of notes>", tag vX.Y.Z.
#   5. Push        — main and the tag.
#   6. Build       — the three release assets, into $RELEASE_DIR.
#   7. Publish     — create the GitHub Release and upload all three assets.
#
# If something fails after step 5, the script prints the exact commands to undo
# the tag and the commit. Nothing is left half-published silently.
#
# =============================================================================

set -euo pipefail

# --- Configuration -----------------------------------------------------------
REPO_SLUG="tuxxin/iNetPanel"
MAIN_BRANCH="main"
VERSION_FILE="TiCore/Version.php"
RELEASE_DIR="${RELEASE_DIR:-/root/release}"
# The installer now lives in the repo, so the checkout is the source of truth and
# CI lints and secret-scans it like every other shell script. /root/install_LAMP.sh
# remains a fallback for machines that still keep a local copy.
INSTALLER_SRC="${INSTALLER_SRC:-}"
if [ -z "$INSTALLER_SRC" ]; then
    if [ -f "$(dirname "${BASH_SOURCE[0]}")/install_LAMP.sh" ]; then
        INSTALLER_SRC="$(dirname "${BASH_SOURCE[0]}")/install_LAMP.sh"
    else
        INSTALLER_SRC="/root/install_LAMP.sh"
    fi
fi
TOKEN_FILE="${TOKEN_FILE:-/root/.env}"
API="https://api.github.com"
UPLOADS="https://uploads.github.com"

# The host the README's install command actually points at. Distribution runs
# through here rather than GitHub so downloads can be counted, which means
# publishing a GitHub Release is only half of shipping — see the closing step.
DIST_HOST="${DIST_HOST:-inetpanel.info}"

# --- Output helpers ----------------------------------------------------------
if [ -t 1 ]; then
    BOLD=$'\033[1m'; GREEN=$'\033[1;32m'; RED=$'\033[1;31m'
    YELLOW=$'\033[1;33m'; CYAN=$'\033[1;36m'; DIM=$'\033[2m'; NC=$'\033[0m'
else
    BOLD=''; GREEN=''; RED=''; YELLOW=''; CYAN=''; DIM=''; NC=''
fi

step() { printf '%s\n' "${CYAN}==>${NC} ${BOLD}$1${NC}"; }
ok()   { printf '%s\n' "    ${GREEN}ok${NC}    $1"; }
info() { printf '%s\n' "    ${DIM}$1${NC}"; }
warn() { printf '%s\n' "    ${YELLOW}warn${NC}  $1"; }
die()  { printf '%s\n' "${RED}error:${NC} $1" >&2; exit 1; }

# --- Argument parsing --------------------------------------------------------
NEW_VERSION=""
BUMP_LEVEL=""
NOTES=""
NOTES_FILE=""
DRY_RUN=0
SKIP_LINT=0
ASSUME_YES=0
VERIFY_ONLY=0

usage() { sed -n '2,80p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)    NEW_VERSION="${2:-}"; shift 2 ;;
        --bump)       BUMP_LEVEL="${2:-}";  shift 2 ;;
        --notes)      NOTES="${2:-}";       shift 2 ;;
        --notes-file) NOTES_FILE="${2:-}";  shift 2 ;;
        --installer)  INSTALLER_SRC="${2:-}"; shift 2 ;;
        --dry-run)    DRY_RUN=1;   shift ;;
        --skip-lint)  SKIP_LINT=1; shift ;;
        --yes|-y)     ASSUME_YES=1; shift ;;
        --verify-published) VERIFY_ONLY=1; shift ;;
        -h|--help)    usage ;;
        *) die "unknown argument: $1  (try --help)" ;;
    esac
done

# --- --verify-published ------------------------------------------------------
# Standalone check: does the distribution host serve the same bytes as the
# current GitHub release? Run it after uploading to confirm, or any time you
# want to know whether the two have drifted. Publishes nothing, changes nothing.
if [ "$VERIFY_ONLY" -eq 1 ]; then
    step "Comparing ${DIST_HOST} against the latest GitHub release"
    DRIFT=0
    # Only the two installers are hosted on DIST_HOST. inetpanel-latest.zip is
    # NOT — the installer fetches that straight from the GitHub release (see
    # ZIP_URL in install_LAMP.sh), and https://inetpanel.info/inetpanel-latest.zip
    # 404s. Checking it here would report permanent false drift.
    for asset in latest latest-beta; do
        DIST_CODE="$(curl -sS -o /tmp/.rel_dist.$$ -w '%{http_code}' -L --max-time 60 \
            "https://${DIST_HOST}/${asset}" 2>/dev/null || echo 000)"
        if [ "$DIST_CODE" != "200" ]; then
            warn "${asset} — ${DIST_HOST} returned HTTP ${DIST_CODE}"
            DRIFT=1
            rm -f "/tmp/.rel_dist.$$"
            continue
        fi
        GH_SUM="$(curl -sSL --max-time 60 \
            "https://github.com/${REPO_SLUG}/releases/latest/download/${asset}" 2>/dev/null | md5sum | cut -d' ' -f1)"
        DIST_SUM="$(md5sum "/tmp/.rel_dist.$$" | cut -d' ' -f1)"
        rm -f "/tmp/.rel_dist.$$"
        if [ "$GH_SUM" = "$DIST_SUM" ]; then
            ok "${asset} — in sync"
        else
            warn "${asset} — DRIFT (github ${GH_SUM:0:12} != ${DIST_HOST} ${DIST_SUM:0:12})"
            DRIFT=1
        fi
    done
    printf '\n'
    if [ "$DRIFT" -eq 0 ]; then
        printf '%s\n' "${GREEN}All published copies match.${NC}"
    else
        printf '%s\n' "${YELLOW}Upload the files above to ${DIST_HOST} — the documented install command is serving stale bytes.${NC}"
        exit 1
    fi
    exit 0
fi

[ "$DRY_RUN" -eq 1 ] && printf '%s\n\n' "${YELLOW}${BOLD}DRY RUN${NC} — nothing will be committed, pushed or published."

# =============================================================================
# 1. Preflight
# =============================================================================
step "Preflight"

# Run from the repo root regardless of where the user invoked us.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

git rev-parse --git-dir >/dev/null 2>&1 || die "not a git repository: $SCRIPT_DIR"
[ -f "$VERSION_FILE" ] || die "$VERSION_FILE not found — is this the iNetPanel repo?"

for tool in git curl zip python3; do
    command -v "$tool" >/dev/null 2>&1 || die "required tool not found: $tool"
done
ok "tools present"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$BRANCH" = "$MAIN_BRANCH" ] || die "on branch '$BRANCH' — releases are cut from '$MAIN_BRANCH'"
ok "on $MAIN_BRANCH"

# A dirty tree means the release would ship something that isn't in the commit.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    git status --short --untracked-files=no | sed 's/^/      /'
    die "working tree has uncommitted changes — commit or stash them first"
fi
ok "working tree clean"

# The release must match what beta testers have been soaking. If local and
# origin have diverged, the tag would point at something nobody tested.
git fetch --quiet origin "$MAIN_BRANCH"
LOCAL_SHA="$(git rev-parse HEAD)"
REMOTE_SHA="$(git rev-parse "origin/${MAIN_BRANCH}")"
if [ "$LOCAL_SHA" != "$REMOTE_SHA" ]; then
    die "local $MAIN_BRANCH ($(git rev-parse --short HEAD)) != origin/$MAIN_BRANCH ($(git rev-parse --short "origin/${MAIN_BRANCH}")) — push or pull first"
fi
ok "in sync with origin/$MAIN_BRANCH"

# --- Token -------------------------------------------------------------------
if [ -z "${GITHUB_TOKEN:-}" ] && [ -f "$TOKEN_FILE" ]; then
    # shellcheck disable=SC1090
    GITHUB_TOKEN="$(grep -m1 '^GITHUB_TOKEN=' "$TOKEN_FILE" 2>/dev/null | cut -d= -f2- | tr -d '"'"'"' \t\r')"
fi
[ -n "${GITHUB_TOKEN:-}" ] || die "no GitHub token — set \$GITHUB_TOKEN or put GITHUB_TOKEN= in $TOKEN_FILE"
export GITHUB_TOKEN

# Confirm the token actually works and can write, before we tag anything.
TOKEN_PERMS="$(curl -sS -H "Authorization: Bearer ${GITHUB_TOKEN}" "${API}/repos/${REPO_SLUG}" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); print(1 if (d.get("permissions") or {}).get("push") else 0)' 2>/dev/null || echo 0)"
[ "$TOKEN_PERMS" = "1" ] || die "token cannot push to ${REPO_SLUG} (expired, or missing contents:write)"
ok "token authenticated with write access"

# --- Version resolution ------------------------------------------------------
CURRENT_VERSION="$(grep -oP "^\s*const APP_VERSION\s*=\s*'\K[^']+" "$VERSION_FILE" | head -1)"
[ -n "$CURRENT_VERSION" ] || die "could not read APP_VERSION from $VERSION_FILE"

if [ -n "$NEW_VERSION" ] && [ -n "$BUMP_LEVEL" ]; then
    die "use --version or --bump, not both"
fi

if [ -n "$BUMP_LEVEL" ]; then
    # Pad to three fields so "1.24" bumps as cleanly as "1.24.4".
    IFS='.' read -r V_MAJ V_MIN V_PAT <<< "$CURRENT_VERSION"
    V_MAJ="${V_MAJ:-0}"; V_MIN="${V_MIN:-0}"; V_PAT="${V_PAT:-0}"
    case "$BUMP_LEVEL" in
        patch) V_PAT=$((V_PAT + 1)) ;;
        minor) V_MIN=$((V_MIN + 1)); V_PAT=0 ;;
        major) V_MAJ=$((V_MAJ + 1)); V_MIN=0; V_PAT=0 ;;
        *) die "--bump takes patch, minor or major (got '$BUMP_LEVEL')" ;;
    esac
    NEW_VERSION="${V_MAJ}.${V_MIN}.${V_PAT}"
fi

[ -n "$NEW_VERSION" ] || die "specify --version X.Y.Z or --bump patch|minor|major"
[[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] || die "version must look like 1.24.5 (got '$NEW_VERSION')"

# php's version_compare is what the panel uses to decide "is there an update",
# so refuse anything it would not consider newer.
php -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$NEW_VERSION" "$CURRENT_VERSION" 2>/dev/null \
    || die "$NEW_VERSION is not newer than $CURRENT_VERSION — existing installs would never see it"

TAG="v${NEW_VERSION}"
if git rev-parse "$TAG" >/dev/null 2>&1 || git ls-remote --exit-code --tags origin "$TAG" >/dev/null 2>&1; then
    die "tag $TAG already exists — pick another version"
fi
ok "version ${CURRENT_VERSION} -> ${BOLD}${NEW_VERSION}${NC} (tag ${TAG})"

# --- Release notes -----------------------------------------------------------
if [ -n "$NOTES_FILE" ]; then
    [ -f "$NOTES_FILE" ] || die "notes file not found: $NOTES_FILE"
    NOTES="$(cat "$NOTES_FILE")"
fi
if [ -z "$NOTES" ]; then
    # Fall back to the commit subjects since the previous tag — better than nothing.
    PREV_TAG="$(git describe --tags --abbrev=0 2>/dev/null || true)"
    if [ -n "$PREV_TAG" ]; then
        NOTES="$(git log --pretty='- %s' "${PREV_TAG}..HEAD")"
        info "no --notes given; generated from commits since ${PREV_TAG}"
    else
        NOTES="Release ${TAG}"
    fi
fi
NOTES_SUBJECT="$(printf '%s' "$NOTES" | head -1 | cut -c1-60)"

# --- Installer ---------------------------------------------------------------
# NOTE: install_LAMP.sh is NOT tracked in this repository. It lives on the
# maintainer's machine and is only published as a release asset. That means it
# has no history, no code review, no CI lint and no secret scan — despite being
# the script users pipe into a root shell. Committing it to the repo is strongly
# recommended; until then, this script needs to be pointed at a local copy.
[ -f "$INSTALLER_SRC" ] || die "installer not found: $INSTALLER_SRC
       Pass --installer /path/to/install_LAMP.sh, or set \$INSTALLER_SRC.
       (Consider committing it to the repo — see the note in this script.)"
ok "installer found: $INSTALLER_SRC"

# --- Confirm -----------------------------------------------------------------
printf '\n'
printf '%s\n' "  ${BOLD}Release ${TAG}${NC}  from $(git rev-parse --short HEAD)"
printf '%s\n' "  ${DIM}${NOTES_SUBJECT}${NC}"
printf '\n'
if [ "$ASSUME_YES" -eq 0 ] && [ "$DRY_RUN" -eq 0 ]; then
    read -r -p "  Proceed? [y/N] " REPLY
    [[ "$REPLY" =~ ^[Yy]$ ]] || { echo "  aborted."; exit 0; }
fi

# =============================================================================
# 2. Lint — mirror CI locally so a red build never becomes a bad release
# =============================================================================
if [ "$SKIP_LINT" -eq 0 ]; then
    step "Lint (mirrors .github/workflows/ci.yml)"
    LINT_FAIL=0

    while IFS= read -r -d '' f; do
        bash -n "$f" 2>&1 || { warn "bash -n: $f"; LINT_FAIL=1; }
    done < <(find . -path ./.git -prune -o -name '*.sh' -print0)
    ok "bash -n"

    if command -v shellcheck >/dev/null 2>&1; then
        while IFS= read -r -d '' f; do
            shellcheck -S error "$f" || LINT_FAIL=1
        done < <(find . -path ./.git -prune -o -name '*.sh' -print0)
        ok "shellcheck -S error"
    else
        warn "shellcheck not installed — CI will still run it (apt-get install shellcheck)"
    fi

    if command -v php >/dev/null 2>&1; then
        while IFS= read -r -d '' f; do
            php -l "$f" >/dev/null 2>&1 || { warn "php -l: $f"; LINT_FAIL=1; }
        done < <(find . -path ./.git -prune -o -path ./vendor -prune -o -name '*.php' -print0)
        ok "php -l"
    else
        warn "php not installed — skipping php -l (CI will still run it)"
    fi

    # --- The installer ------------------------------------------------------
    # install_LAMP.sh is deliberately NOT in this repository: distribution runs
    # through inetpanel.info so downloads can be counted. The side effect is
    # that CI never sees it — so the one script users pipe into a root shell is
    # the only shell in the project with no lint and no secret scan.
    #
    # We have it on disk right here at build time, so check it now. Same gates
    # CI applies to everything else, without it ever entering git.
    step "Lint installer ($(basename "$INSTALLER_SRC"))"

    bash -n "$INSTALLER_SRC" || { warn "bash -n failed on the installer"; LINT_FAIL=1; }
    ok "bash -n"

    if command -v shellcheck >/dev/null 2>&1; then
        shellcheck -S error "$INSTALLER_SRC" || LINT_FAIL=1
        ok "shellcheck -S error"
    fi

    # Secret scan. This file handles MySQL root passwords and Cloudflare tokens,
    # and it is published publicly — a hardcoded credential here is the worst
    # case in the whole project.
    if command -v gitleaks >/dev/null 2>&1; then
        SCAN_DIR="$(mktemp -d)"
        cp "$INSTALLER_SRC" "${SCAN_DIR}/install_LAMP.sh"
        if ! gitleaks detect --source "$SCAN_DIR" --no-git --redact --no-banner >/dev/null 2>&1; then
            rm -rf "$SCAN_DIR"
            die "gitleaks found a secret in ${INSTALLER_SRC} — refusing to publish"
        fi
        rm -rf "$SCAN_DIR"
        ok "gitleaks"
    else
        # Cheap fallback so this never silently does nothing.
        if grep -nEq '(gh[pousr]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[0-9A-Z]{16})' "$INSTALLER_SRC"; then
            die "possible hardcoded credential in ${INSTALLER_SRC} — refusing to publish"
        fi
        warn "gitleaks not installed — ran a basic pattern check only"
    fi

    [ "$LINT_FAIL" -eq 0 ] || die "lint failed — fix the above before releasing"
else
    warn "lint skipped (--skip-lint)"
fi

# =============================================================================
# 3. Version bump
# =============================================================================
step "Version"
if [ "$DRY_RUN" -eq 0 ]; then
    # Anchored so it only ever touches the constant, never the doc comments or
    # the self-update regex further down the file.
    sed -i "s/^\(\s*const APP_VERSION\s*=\s*\)'[^']*';/\1'${NEW_VERSION}';/" "$VERSION_FILE"
    WROTE="$(grep -oP "^\s*const APP_VERSION\s*=\s*'\K[^']+" "$VERSION_FILE" | head -1)"
    [ "$WROTE" = "$NEW_VERSION" ] || die "failed to write version into $VERSION_FILE (got '$WROTE')"
    ok "$VERSION_FILE -> $NEW_VERSION"
else
    info "would set APP_VERSION = $NEW_VERSION in $VERSION_FILE"
fi

# =============================================================================
# 4-5. Commit, tag, push
# =============================================================================
step "Commit, tag and push"
if [ "$DRY_RUN" -eq 0 ]; then
    git add "$VERSION_FILE"
    git commit --quiet -m "Release ${TAG} — ${NOTES_SUBJECT}"
    git tag -a "$TAG" -m "${TAG}"
    ok "committed and tagged $TAG"

    # One-shot credential helper: the token reaches git via stdin only. It is
    # never written to .git/config and never appears in a remote URL.
    if ! git -c credential.helper='!f() { echo username=x-access-token; echo "password=${GITHUB_TOKEN}"; }; f' \
         push --quiet origin "${MAIN_BRANCH}" "$TAG"; then
        printf '%s\n' "${RED}push failed.${NC} Undo the local commit and tag with:"
        printf '%s\n' "    git tag -d ${TAG} && git reset --hard HEAD~1"
        exit 1
    fi
    ok "pushed ${MAIN_BRANCH} and ${TAG}"
else
    info "would commit \"Release ${TAG} — ${NOTES_SUBJECT}\", tag ${TAG}, push both"
fi

# From here on, failure leaves a pushed tag with no release behind it. Tell the
# user exactly how to back that out rather than leaving them to guess.
rollback_hint() {
    printf '%s\n' "${YELLOW}The tag is pushed but the release was not published.${NC}"
    printf '%s\n' "  Retry:  gh release create ${TAG} ${RELEASE_DIR}/* --notes '...'   (or re-run this script)"
    printf '%s\n' "  Undo:   git push --delete origin ${TAG} && git tag -d ${TAG} && git revert HEAD"
}

# =============================================================================
# 6. Build the release assets
# =============================================================================
step "Build assets"
mkdir -p "$RELEASE_DIR"

# 'latest' — the stable installer, verbatim.
cp "$INSTALLER_SRC" "${RELEASE_DIR}/latest"
chmod +x "${RELEASE_DIR}/latest"
ok "latest"

# 'latest-beta' — same installer, pointed at the main-branch zipball instead of
# the release asset. Single substitution; the installer's existing extract logic
# copes with the differently-named zipball root directory.
sed 's|https://github.com/tuxxin/iNetPanel/releases/latest/download/inetpanel-latest.zip|https://api.github.com/repos/tuxxin/iNetPanel/zipball/main|' \
    "$INSTALLER_SRC" > "${RELEASE_DIR}/latest-beta"
chmod +x "${RELEASE_DIR}/latest-beta"
if cmp -s "${RELEASE_DIR}/latest" "${RELEASE_DIR}/latest-beta"; then
    warn "latest-beta is identical to latest — the release-zip URL wasn't found in the installer"
    warn "check that install_LAMP.sh still references releases/latest/download/inetpanel-latest.zip"
fi
ok "latest-beta"

# 'inetpanel-latest.zip' — panel source, minus anything that must not ship.
TMP_ZIP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_ZIP_DIR"' EXIT
PANEL_COPY="${TMP_ZIP_DIR}/inetpanel"
mkdir -p "$PANEL_COPY"
rsync -a \
    --exclude='.git' --exclude='.git/**' \
    --exclude='.github' \
    --exclude='db/*.db' --exclude='db/*.db-*' \
    --exclude='TiCore/.env' \
    --exclude='.installed' \
    --exclude='*.log' \
    --exclude='release.sh' \
    --exclude='install_LAMP.sh' \
    ./ "$PANEL_COPY/"
mkdir -p "${PANEL_COPY}/db"
touch "${PANEL_COPY}/db/.gitkeep"
rm -f "${RELEASE_DIR}/inetpanel-latest.zip"
( cd "$TMP_ZIP_DIR" && zip -qr "${RELEASE_DIR}/inetpanel-latest.zip" inetpanel/ -x "*.DS_Store" -x "__MACOSX/*" )
ok "inetpanel-latest.zip ($(du -h "${RELEASE_DIR}/inetpanel-latest.zip" | cut -f1))"

# Guard against shipping a secret inside the zip. The CI gitleaks job scans the
# repo, but the zip is assembled here and is what users actually download.
if unzip -p "${RELEASE_DIR}/inetpanel-latest.zip" 'inetpanel/TiCore/.env' >/dev/null 2>&1; then
    die "TiCore/.env ended up inside the release zip — refusing to publish"
fi
ok "zip contains no TiCore/.env"

if [ "$DRY_RUN" -eq 1 ]; then
    step "Dry run complete"
    info "assets built in $RELEASE_DIR — nothing was committed, pushed or published"
    ls -la "$RELEASE_DIR" | sed 's/^/    /'
    exit 0
fi

# =============================================================================
# 7. Publish the GitHub Release
# =============================================================================
step "Publish GitHub Release"

RELEASE_JSON="$(python3 -c '
import json, sys
print(json.dumps({
    "tag_name":   sys.argv[1],
    "name":       sys.argv[1] + " — " + sys.argv[2],
    "body":       sys.argv[3],
    "draft":      False,
    "prerelease": False,
}))' "$TAG" "$NOTES_SUBJECT" "$NOTES")"

RELEASE_ID="$(curl -sS -X POST \
    -H "Authorization: Bearer ${GITHUB_TOKEN}" \
    -H "Accept: application/vnd.github+json" \
    "${API}/repos/${REPO_SLUG}/releases" \
    -d "$RELEASE_JSON" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("id") or ""); sys.stderr.write(json.dumps(d)[:400] if not d.get("id") else "")')"

if [ -z "$RELEASE_ID" ]; then
    rollback_hint
    die "could not create the release (see the API response above)"
fi
ok "release created (id ${RELEASE_ID})"

for asset in inetpanel-latest.zip latest latest-beta; do
    UPLOADED="$(curl -sS -X POST \
        -H "Authorization: Bearer ${GITHUB_TOKEN}" \
        -H "Content-Type: application/octet-stream" \
        --data-binary "@${RELEASE_DIR}/${asset}" \
        "${UPLOADS}/repos/${REPO_SLUG}/releases/${RELEASE_ID}/assets?name=${asset}" \
        | python3 -c 'import json,sys; print(json.load(sys.stdin).get("state",""))' 2>/dev/null || echo "")"
    [ "$UPLOADED" = "uploaded" ] || { rollback_hint; die "failed to upload asset: ${asset}"; }
    ok "uploaded ${asset}"
done

# Verify what the stable installer will actually fetch, rather than trusting
# that the upload succeeded. This is the URL baked into every install command.
HTTP="$(curl -sS -o /dev/null -w '%{http_code}' -L \
    "https://github.com/${REPO_SLUG}/releases/latest/download/inetpanel-latest.zip")"
[ "$HTTP" = "200" ] || warn "releases/latest/download/inetpanel-latest.zip returned HTTP ${HTTP} (may take a moment to propagate)"

# =============================================================================
# Done
# =============================================================================
printf '\n'
printf '%s\n' "${GREEN}${BOLD}Released ${TAG}${NC}"
printf '%s\n' "  https://github.com/${REPO_SLUG}/releases/tag/${TAG}"
printf '\n'

# The README's install command points at inetpanel.info, NOT at GitHub — that
# host is the counted distribution path. Publishing the release does not update
# it, so until these are uploaded the documented install command still serves
# the previous version. Show exactly which files are stale rather than leaving
# it as a thing to remember.
step "Distribution host (${DIST_HOST})"
DIST_STALE=0
# Installers only — the zip is served from the GitHub release, not from here.
for asset in latest latest-beta; do
    LOCAL_SUM="$(md5sum "${RELEASE_DIR}/${asset}" | cut -d' ' -f1)"
    LIVE_SUM="$(curl -sSL --max-time 30 "https://${DIST_HOST}/${asset}" 2>/dev/null | md5sum | cut -d' ' -f1)"
    if [ "$LOCAL_SUM" = "$LIVE_SUM" ]; then
        ok "${asset} — already current"
    else
        warn "${asset} — still serving the OLD file"
        DIST_STALE=1
    fi
done

if [ "$DIST_STALE" -eq 1 ]; then
    printf '\n'
    printf '%s\n' "  ${BOLD}${YELLOW}Remaining manual step${NC} — upload to ${DIST_HOST}:"
    printf '%s\n' "    ${RELEASE_DIR}/latest"
    printf '%s\n' "    ${RELEASE_DIR}/latest-beta"
    printf '\n'
    printf '%s\n' "  ${DIM}Until then the README's install command serves ${CURRENT_VERSION}, not ${NEW_VERSION}.${NC}"
    printf '%s\n' "  ${DIM}Re-check with:  ./release.sh --verify-published${NC}"
else
    ok "distribution host is in sync — nothing left to do"
fi
printf '\n'
