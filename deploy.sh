#!/usr/bin/env bash
#
# Production deploy flow (docs/ADR/027, revised: production has no
# Node.js -- see docs/ADR/028). git pull -> composer install -> deploy ->
# cache -> ready. Deliberately does NOT build frontend assets here --
# `public/build/` is committed to git (see .gitignore's own comment on
# `/public/build`) and built on a machine that HAS Node, before pushing;
# `git pull` alone is what brings the correct, already-built assets to
# this server. Never run `npm` on this server -- it isn't installed, and
# it was never actually needed here in the first place.
#
# Usage:
#   ./deploy.sh              # standard deploy (migrate, no seed)
#   ./deploy.sh --seed       # also run seeders (safe every time -- idempotent)
#   ./deploy.sh --first      # first-ever deploy: seed + skip maintenance mode
#
# One-time-per-environment configuration (see README.md "Deployment" for
# the full one-time cPanel setup this script assumes is already done --
# public_html symlinked to ioms/public, .env configured, cron registered):
#   `COMPOSER_BIN`/`PHP_BIN`, if you want to set either explicitly, always
#   win over auto-detection below:
#     export PHP_BIN=/opt/alt/php83/usr/bin/php
#     export COMPOSER_BIN=/opt/alt/php83/usr/bin/composer
#   You do NOT have to set either, though -- if unset (e.g. because this
#   script was invoked in a way that never sourced ~/.bashrc, such as
#   cron or some cPanel "run script" features -- confirmed to happen in
#   practice, not hypothetical), `resolve_composer()`/`resolve_php()`
#   below find them on their own, every run, with no manual step before
#   any future deploy.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

# Minimum PHP this app requires (composer.json: "php": "^8.2").
readonly MIN_PHP_MAJOR=8
readonly MIN_PHP_MINOR=2

# True if $1 is a PHP CLI binary reporting a version >= MIN_PHP_MAJOR.MIN_PHP_MINOR.
# Actually running `--version` (not just trusting the path name) matters:
# a file living under an "alt-php83" *directory* is not proof it runs as
# PHP 8.3 -- confirmed on this project's own host, where composer found
# at a php83 path still executed under PHP 7.2. Never trust the path,
# always ask the binary itself.
php_version_ok() {
    local phpbin="$1" ver major minor
    ver="$("$phpbin" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)" || return 1
    major="${ver%%.*}"
    minor="${ver##*.}"
    [[ "$major" =~ ^[0-9]+$ && "$minor" =~ ^[0-9]+$ ]] || return 1
    if ((major > MIN_PHP_MAJOR)); then return 0; fi
    if ((major == MIN_PHP_MAJOR && minor >= MIN_PHP_MINOR)); then return 0; fi
    return 1
}

# Resolves a PHP >= 8.2 CLI binary, in order: explicit PHP_BIN (fails
# loudly if set but not executable or too old), PATH, then known
# shared-hosting/CloudLinux/cPanel "alt-php"/"ea-php" locations. Every
# candidate is version-checked via php_version_ok(), not just found --
# this is what makes composer/artisan actually run under a guaranteed
# >= 8.2 interpreter regardless of which PHP happens to be first on this
# account's PATH (commonly an older system default on shared hosting).
resolve_php() {
    if [[ -n "${PHP_BIN:-}" ]]; then
        if [[ -x "$PHP_BIN" ]] && php_version_ok "$PHP_BIN"; then
            echo "$PHP_BIN"
            return 0
        fi
        echo "PHP_BIN is set to '$PHP_BIN' but it's not executable, or not PHP >= ${MIN_PHP_MAJOR}.${MIN_PHP_MINOR}." >&2
        return 1
    fi

    local candidates=()
    if command -v php >/dev/null 2>&1; then
        candidates+=("$(command -v php)")
    fi
    candidates+=(
        /opt/alt/php83/usr/bin/php
        /opt/alt/php82/usr/bin/php
        /opt/cpanel/ea-php83/root/usr/bin/php
        /opt/cpanel/ea-php82/root/usr/bin/php
        /opt/alt/php*/usr/bin/php
        /opt/cpanel/ea-php*/root/usr/bin/php
        /usr/local/bin/php
    )
    local path
    for path in "${candidates[@]}"; do
        if [[ -x "$path" ]] && php_version_ok "$path"; then
            echo "$path"
            return 0
        fi
    done

    echo "Could not find a PHP >= ${MIN_PHP_MAJOR}.${MIN_PHP_MINOR} CLI binary anywhere -- not on" >&2
    echo "PATH, not at any known shared-hosting location. Set PHP_BIN explicitly, e.g.:" >&2
    echo "  PHP_BIN=/path/to/php83 ./deploy.sh" >&2
    return 1
}

# Resolves a working `composer` file, in order: an explicit COMPOSER_BIN
# (if set and actually executable -- a stale/wrong override should fail
# loudly, not silently fall through to a different composer), then
# PATH, then the shared-hosting locations composer is actually known to
# hide at. This is deliberately just "find the file" -- it is NOT what
# guarantees the PHP version composer runs under; that's resolve_php()
# above, since a composer file's own shebang is not trustworthy (see
# php_version_ok()'s comment). deploy.sh always invokes composer as
# `"$PHP_BIN" "$COMPOSER_BIN" ...`, explicitly forcing the interpreter,
# never `"$COMPOSER_BIN" ...` alone.
resolve_composer() {
    if [[ -n "${COMPOSER_BIN:-}" ]]; then
        if [[ -x "$COMPOSER_BIN" ]]; then
            echo "$COMPOSER_BIN"
            return 0
        fi
        echo "COMPOSER_BIN is set to '$COMPOSER_BIN' but that isn't an executable file." >&2
        return 1
    fi

    if command -v composer >/dev/null 2>&1; then
        command -v composer
        return 0
    fi

    local candidates=(
        /opt/alt/php*/usr/bin/composer
        /opt/cpanel/ea-php*/root/usr/bin/composer
        /usr/local/bin/composer
        /usr/local/cpanel/3rdparty/bin/composer
        "$HOME/bin/composer"
        "$HOME/.composer/composer.phar"
        "$HOME/composer.phar"
    )
    local path
    for path in "${candidates[@]}"; do
        if [[ -x "$path" ]]; then
            echo "$path"
            return 0
        fi
    done

    echo "Could not find composer anywhere -- not on PATH, not at any known shared-hosting" >&2
    echo "location. Set COMPOSER_BIN explicitly, e.g.:" >&2
    echo "  COMPOSER_BIN=/path/to/composer ./deploy.sh" >&2
    return 1
}

if ! PHP_BIN="$(resolve_php)"; then
    exit 1
fi
if ! COMPOSER_BIN="$(resolve_composer)"; then
    exit 1
fi
echo "Using PHP: $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"
echo "Using Composer: $COMPOSER_BIN"

SEED_FLAG=""
DEPLOY_ARGS=""

for arg in "$@"; do
    case "$arg" in
        --seed) SEED_FLAG="--seed" ;;
        --first) SEED_FLAG="--seed"; DEPLOY_ARGS="--no-maintenance" ;;
    esac
done

echo "==> [1/3] Pulling latest code (includes pre-built frontend assets)"
git pull

echo "==> [2/3] Installing PHP dependencies (production, no dev packages)"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader

echo "==> [3/3] Running deployment tasks (migrate, cache, ready)"
"$PHP_BIN" artisan app:deploy $SEED_FLAG $DEPLOY_ARGS

echo "==> Deployment complete."
