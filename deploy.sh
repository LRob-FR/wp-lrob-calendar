#!/bin/bash
# LRob - Calendar - Test-site Deployer
# Ships the zip release.sh built to a remote WordPress install over SSH.
# Deploys the ARTIFACT, never the working tree — what lands on the site is
# byte-identical to what users get, so dev-only files can't mask a bug.

set -e

SCRIPT_DIR="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_SLUG="lrob-calendar"
PLUGIN_FILE="${SCRIPT_DIR}/${PLUGIN_SLUG}.php"
RELEASES_DIR="${PARENT_DIR}/releases"
CONF_FILE="${SCRIPT_DIR}/.deploy.conf"

ok()    { echo "[OK] $1"; }
fail()  { echo "[FAIL] $1" >&2; }
warn()  { echo "[WARN] $1"; }
step()  { echo "> $1"; }

need() { command -v "$1" >/dev/null 2>&1 || { fail "missing: $1"; exit 1; }; }

usage() {
    cat <<EOF
Usage: ./deploy.sh [version] [options]

  version              defaults to the Version: header in ${PLUGIN_SLUG}.php

  -t, --target NAME    which site (see .deploy.conf); default: \$DEFAULT_TARGET
  -b, --build          run ./release.sh first
      --activate       activate the plugin if it isn't already
      --confirm-prod   required for any target listed in \$PROD_TARGETS
      --dry-run        print every command without running it
  -h, --help           this

Config lives in .deploy.conf (gitignored — it holds host paths):
  SSH_TARGET="user@host"
  REMOTE_PATH_PREPEND="/path/to/php/shims"   # optional, for phpenv/plesk
  TARGET_demo="/var/www/.../demo"
  TARGET_prod="/var/www/.../httpdocs"
  DEFAULT_TARGET="demo"
  PROD_TARGETS="prod"
EOF
}

VERSION=""; TARGET=""; BUILD=0; DRY=0; ACTIVATE=0; CONFIRM_PROD=0
while [ $# -gt 0 ]; do
    case "$1" in
        -t|--target)    TARGET="$2"; shift 2 ;;
        -b|--build)     BUILD=1; shift ;;
        --activate)     ACTIVATE=1; shift ;;
        --confirm-prod) CONFIRM_PROD=1; shift ;;
        --dry-run)      DRY=1; shift ;;
        -h|--help)      usage; exit 0 ;;
        -*)             fail "unknown option: $1"; usage; exit 1 ;;
        *)              VERSION="$1"; shift ;;
    esac
done

load_conf() {
    [ -f "$CONF_FILE" ] || { fail "no $CONF_FILE — see ./deploy.sh --help"; exit 1; }
    # shellcheck disable=SC1090
    . "$CONF_FILE"
    [ -n "$SSH_TARGET" ] || { fail "SSH_TARGET not set in $CONF_FILE"; exit 1; }
    [ -n "$TARGET" ] || TARGET="${DEFAULT_TARGET:-demo}"

    local var="TARGET_${TARGET}"
    WP_PATH="${!var}"
    [ -n "$WP_PATH" ] || { fail "unknown target '$TARGET' (no $var in $CONF_FILE)"; exit 1; }

    # Production needs an explicit flag: same command, one word apart from the
    # live site, is exactly how a test build reaches real users by accident.
    case " ${PROD_TARGETS:-} " in
        *" $TARGET "*)
            if [ $CONFIRM_PROD -eq 0 ]; then
                fail "target '$TARGET' is production — re-run with --confirm-prod"
                exit 1
            fi
            warn "deploying to PRODUCTION target '$TARGET'"
            ;;
    esac
}

# phpenv/Plesk shims are absent from a non-interactive ssh PATH, so `wp`
# resolves but `php` doesn't — hence the PATH prefix on every call.
#
# Plesk's vendored wp-cli also spams PHP 8.5 deprecations from its own
# php-cli-tools during `plugin install`. Suppressing via `php -d` doesn't work
# (WP-CLI resets error_reporting() at runtime), so the noise is filtered by its
# file path — narrow on purpose, so a deprecation from OUR code still shows.
NOISE='php-cli-tools/lib/cli/Colors\.php'

remote() {
    local cmd="$1"
    local full="export PATH=\"${REMOTE_PATH_PREPEND:+$REMOTE_PATH_PREPEND:}\$PATH\"; $cmd"
    # stderr, so command substitution (installed_version) can't swallow it
    if [ $DRY -eq 1 ]; then
        echo "  ssh $SSH_TARGET '$cmd'" >&2
        return 0
    fi
    local out status=0
    out=$(ssh "$SSH_TARGET" "$full" 2>&1) || status=$?
    [ -n "$out" ] && printf '%s\n' "$out" | grep -v "$NOISE" || true
    return $status
}

installed_version() {
    remote "wp --path='$WP_PATH' plugin list --name='$PLUGIN_SLUG' --field=version 2>/dev/null" || true
}

build() {
    [ $BUILD -eq 1 ] || return 0
    step "build"
    if [ $DRY -eq 1 ]; then echo "  ./release.sh"; return 0; fi
    "$SCRIPT_DIR/release.sh" >/dev/null || { fail "release.sh failed — not deploying"; exit 1; }
    ok "release.sh done"
}

check_archive() {
    step "archive"
    ARCHIVE="${RELEASES_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
    [ -f "$ARCHIVE" ] || { fail "not found: $ARCHIVE — run ./release.sh (or pass -b)"; exit 1; }

    local newest
    newest=$(find "$SCRIPT_DIR" -type f \( -name "*.php" -o -name "*.js" -o -name "*.css" \) \
        ! -path "*/.git/*" ! -path "*/node_modules/*" ! -path "*/vendor/*" \
        -newer "$ARCHIVE" -print -quit)
    [ -n "$newest" ] && warn "source newer than zip (${newest#$SCRIPT_DIR/}) — pass -b to rebuild"

    ok "$(basename "$ARCHIVE") ($(du -h "$ARCHIVE" | cut -f1))"
}

upload() {
    step "upload"
    REMOTE_ZIP="/tmp/${PLUGIN_SLUG}-${VERSION}.zip"
    if [ $DRY -eq 1 ]; then
        echo "  scp $ARCHIVE ${SSH_TARGET}:${REMOTE_ZIP}"
        return 0
    fi
    scp -q "$ARCHIVE" "${SSH_TARGET}:${REMOTE_ZIP}"
    ok "→ ${SSH_TARGET}:${REMOTE_ZIP}"
}

# --force so WP deletes the old plugin dir before extracting: an unzip -o would
# leave files you removed this build in place and silently mask the breakage.
# Activation state survives the replace.
install_plugin() {
    step "install"
    remote "wp --path='$WP_PATH' plugin install '$REMOTE_ZIP' --force" || {
        fail "wp plugin install failed"
        remote "rm -f '$REMOTE_ZIP'" || true
        exit 1
    }
    [ $ACTIVATE -eq 1 ] && remote "wp --path='$WP_PATH' plugin activate '$PLUGIN_SLUG'"
    remote "rm -f '$REMOTE_ZIP'" || true
}

main() {
    need ssh; need scp
    load_conf
    [ -n "$VERSION" ] || VERSION=$(grep -oP "Version:\s*\K[\d.]+" "$PLUGIN_FILE")
    step "version $VERSION → $TARGET ($SSH_TARGET:$WP_PATH)"
    build
    check_archive

    if [ $DRY -eq 0 ]; then
        local before; before=$(installed_version)
        [ -n "$before" ] && ok "currently installed: $before" || warn "not currently installed"
    fi

    upload
    install_plugin

    if [ $DRY -eq 0 ]; then
        local after; after=$(installed_version)
        ok "now live: ${after:-unknown} on $TARGET"
    else
        ok "dry run — nothing changed"
    fi
}

main "$@"
