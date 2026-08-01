#!/bin/bash
# LRob - Calendar - Forgejo Release Publisher
# Tags, creates the Forgejo release and uploads the zip built by release.sh.
# Build tool = release.sh (run it as often as you like). Publish tool = this.

set -e

SCRIPT_DIR="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_SLUG="lrob-calendar"
PLUGIN_FILE="${SCRIPT_DIR}/${PLUGIN_SLUG}.php"
LANGUAGES_DIR="${SCRIPT_DIR}/languages"
RELEASES_DIR="${PARENT_DIR}/releases"
GIT_REMOTE="forgejo"
TOKEN_FILE="${FORGEJO_TOKEN_FILE:-$HOME/.config/forgejo/token}"

ok()    { echo "[OK] $1"; }
fail()  { echo "[FAIL] $1" >&2; }
warn()  { echo "[WARN] $1"; }
step()  { echo "> $1"; }

need() { command -v "$1" >/dev/null 2>&1 || { fail "missing: $1"; exit 1; }; }

usage() {
    cat <<EOF
Usage: ./publish.sh [version] [options]

  version              defaults to the Version: header in ${PLUGIN_SLUG}.php

  -n, --notes FILE     release notes body (markdown). "-" reads stdin.
  -t, --title TITLE    release title (default: "v<version>")
      --clobber        replace the zip on an existing release for this tag
      --dry-run        print every call without touching the API or git
  -h, --help           this

Reads the API token from ${TOKEN_FILE} (override: \$FORGEJO_TOKEN_FILE,
or \$FORGEJO_TOKEN for the raw value).
EOF
}

VERSION=""; NOTES_FILE=""; TITLE=""; CLOBBER=0; DRY=0
while [ $# -gt 0 ]; do
    case "$1" in
        -n|--notes)  NOTES_FILE="$2"; shift 2 ;;
        -t|--title)  TITLE="$2"; shift 2 ;;
        --clobber)   CLOBBER=1; shift ;;
        --dry-run)   DRY=1; shift ;;
        -h|--help)   usage; exit 0 ;;
        -*)          fail "unknown option: $1"; usage; exit 1 ;;
        *)           VERSION="$1"; shift ;;
    esac
done

# Repo URL is single-sourced from the constant the auto-updater reads, so the
# publish target can never drift from where installed sites look for updates.
resolve_repo() {
    local url; url=$(grep -oP "LROB_CALENDAR_REPO_URL',\s*'\K[^']+" "$PLUGIN_FILE")
    [ -n "$url" ] || { fail "LROB_CALENDAR_REPO_URL not found in $PLUGIN_FILE"; exit 1; }
    local host="${url%/*/*}"
    local path="${url#$host/}"
    API="${host}/api/v1/repos/${path}"
    REPO_URL="$url"
}

read_token() {
    if [ -n "$FORGEJO_TOKEN" ]; then TOKEN="$FORGEJO_TOKEN"; return; fi
    [ -f "$TOKEN_FILE" ] || { fail "no token at $TOKEN_FILE"; exit 1; }
    TOKEN=$(tr -d '[:space:]' < "$TOKEN_FILE")
    [ -n "$TOKEN" ] || { fail "token file is empty"; exit 1; }
}

api() {
    local method=$1 path=$2; shift 2
    curl -sS -X "$method" -H "Authorization: token $TOKEN" "$@" "${API}${path}"
}

check_deps() {
    step "deps"
    need curl; need jq; need git; need msgfmt
    ok "curl / jq / git"
}

# The translation gate from CLAUDE.md, enforced where it actually matters:
# release.sh runs constantly during dev and a fuzzy .po mid-iteration is fine.
# Shipping one is not.
check_translations() {
    step "translation gate"
    shopt -s nullglob
    local po_files=("$LANGUAGES_DIR"/*.po)
    shopt -u nullglob
    [ ${#po_files[@]} -eq 0 ] && { warn "no .po files"; return 0; }
    local bad=0
    for po in "${po_files[@]}"; do
        local stats; stats=$(msgfmt --statistics "$po" -o /dev/null 2>&1 | tr -d '\n')
        if echo "$stats" | grep -qE '[0-9]+ (fuzzy|untranslated)'; then
            fail "$(basename "$po") — $stats"
            bad=1
        else
            ok "$(basename "$po") — $stats"
        fi
    done
    if [ $bad -eq 1 ]; then
        fail "fix the .po (see memory feedback_translations_before_every_release) — not publishing"
        exit 1
    fi
}

check_archive() {
    step "archive"
    ARCHIVE="${RELEASES_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
    [ -f "$ARCHIVE" ] || { fail "not found: $ARCHIVE — run ./release.sh first"; exit 1; }

    # A zip older than the working tree means release.sh hasn't run since the
    # last edit; publishing it ships code the user never actually built.
    local newest
    newest=$(find "$SCRIPT_DIR" -type f \( -name "*.php" -o -name "*.js" -o -name "*.css" \) \
        ! -path "*/.git/*" ! -path "*/node_modules/*" ! -path "*/vendor/*" \
        -newer "$ARCHIVE" -print -quit)
    [ -n "$newest" ] && warn "source newer than zip (${newest#$SCRIPT_DIR/}) — rebuild?"

    ok "$(basename "$ARCHIVE") ($(du -h "$ARCHIVE" | cut -f1))"
}

check_git() {
    step "git"
    TAG="v${VERSION}"
    [ -z "$(git -C "$SCRIPT_DIR" status --porcelain)" ] || warn "working tree is dirty"

    local local_head remote_head
    local_head=$(git -C "$SCRIPT_DIR" rev-parse HEAD)
    remote_head=$(git -C "$SCRIPT_DIR" rev-parse "${GIT_REMOTE}/main" 2>/dev/null || echo "")
    [ "$local_head" = "$remote_head" ] || warn "HEAD differs from ${GIT_REMOTE}/main — push first?"

    if git -C "$SCRIPT_DIR" ls-remote --exit-code --tags "$GIT_REMOTE" "$TAG" >/dev/null 2>&1; then
        ok "$TAG already on $GIT_REMOTE"
        PUSH_TAG=0
    else
        ok "$TAG will be created at $(git -C "$SCRIPT_DIR" rev-parse --short HEAD)"
        PUSH_TAG=1
    fi
}

push_tag() {
    [ "$PUSH_TAG" = "1" ] || return 0
    step "tag"
    if [ $DRY -eq 1 ]; then
        echo "  git tag -a $TAG -m $TAG && git push $GIT_REMOTE $TAG"
        return 0
    fi
    git -C "$SCRIPT_DIR" rev-parse "$TAG" >/dev/null 2>&1 \
        || git -C "$SCRIPT_DIR" tag -a "$TAG" -m "$TAG"
    git -C "$SCRIPT_DIR" push "$GIT_REMOTE" "$TAG"
    ok "pushed $TAG"
}

read_notes() {
    if [ -z "$NOTES_FILE" ]; then
        NOTES=""
        warn "no --notes given — publishing with an empty body"
        return 0
    fi
    if [ "$NOTES_FILE" = "-" ]; then
        NOTES=$(cat)
    else
        [ -f "$NOTES_FILE" ] || { fail "notes file not found: $NOTES_FILE"; exit 1; }
        NOTES=$(cat "$NOTES_FILE")
    fi
}

# prerelease is hardcoded false on purpose: the auto-updater only offers the
# latest non-prerelease, so flagging one would silently stall every install.
create_release() {
    step "release"
    local existing; existing=$(api GET "/releases/tags/${TAG}")
    RELEASE_ID=$(echo "$existing" | jq -r '.id // empty')

    if [ -n "$RELEASE_ID" ]; then
        [ $CLOBBER -eq 1 ] || {
            fail "release $TAG already exists (id $RELEASE_ID) — pass --clobber to replace its zip"
            exit 1
        }
        ok "reusing release $RELEASE_ID (--clobber)"
        return 0
    fi

    local payload
    payload=$(jq -n --arg tag "$TAG" --arg name "${TITLE:-$TAG}" --arg body "$NOTES" \
        '{tag_name:$tag, target_commitish:"main", name:$name, body:$body, draft:false, prerelease:false}')

    if [ $DRY -eq 1 ]; then
        echo "  POST ${API}/releases"
        echo "$payload" | jq .
        RELEASE_ID="<dry-run>"
        return 0
    fi

    local created; created=$(api POST "/releases" -H 'Content-Type: application/json' -d "$payload")
    RELEASE_ID=$(echo "$created" | jq -r '.id // empty')
    [ -n "$RELEASE_ID" ] || { fail "create failed: $(echo "$created" | jq -r '.message // .')"; exit 1; }
    ok "created $TAG (id $RELEASE_ID)"
}

# Two-step by nature: a release can exist with no zip if this half fails, which
# is exactly the state that breaks the updater — so it's a hard failure.
upload_asset() {
    step "asset"
    local name; name=$(basename "$ARCHIVE")

    if [ $DRY -eq 1 ]; then
        echo "  POST ${API}/releases/${RELEASE_ID}/assets  (attachment=@${ARCHIVE})"
        return 0
    fi

    local old_id
    old_id=$(api GET "/releases/${RELEASE_ID}" | jq -r --arg n "$name" '.assets[]? | select(.name==$n) | .id')
    if [ -n "$old_id" ]; then
        api DELETE "/releases/${RELEASE_ID}/assets/${old_id}" >/dev/null
        ok "removed previous $name"
    fi

    local up; up=$(api POST "/releases/${RELEASE_ID}/assets" -F "attachment=@${ARCHIVE}")
    local url; url=$(echo "$up" | jq -r '.browser_download_url // empty')
    [ -n "$url" ] || { fail "upload failed: $(echo "$up" | jq -r '.message // .')"; exit 1; }
    ok "$name uploaded"
}

main() {
    check_deps
    resolve_repo
    read_token
    [ -n "$VERSION" ] || VERSION=$(grep -oP "Version:\s*\K[\d.]+" "$PLUGIN_FILE")
    step "version $VERSION → $REPO_URL"
    check_translations
    check_archive
    check_git
    read_notes
    push_tag
    create_release
    upload_asset
    ok "published ${REPO_URL}/releases/tag/${TAG}"
}

main "$@"
