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
#   `COMPOSER_BIN`, if you want to set it explicitly, always wins over
#   auto-detection below:
#     export COMPOSER_BIN=/opt/alt/php83/usr/bin/composer
#   You do NOT have to set it, though -- if it's unset (e.g. because this
#   script was invoked in a way that never sourced ~/.bashrc, such as
#   cron or some cPanel "run script" features -- confirmed to happen in
#   practice, not hypothetical), `resolve_composer()` below finds it on
#   its own, every run, with no manual step before any future deploy.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

# Resolves a working `composer` command, in order: an explicit
# COMPOSER_BIN (if set and actually executable -- a stale/wrong override
# should fail loudly, not silently fall through to a different
# composer), then PATH, then the shared-hosting locations composer is
# actually known to hide at (this project's own host puts it at
# /opt/alt/php83/usr/bin/composer; the alt-php version number varies by
# host and PHP version, hence the glob). Errors out with a clear message
# if none of that finds an executable file, rather than silently
# swallowing the failure into "command not found" further down.
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

if ! COMPOSER_BIN="$(resolve_composer)"; then
    exit 1
fi
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
"$COMPOSER_BIN" install --no-dev --optimize-autoloader

echo "==> [3/3] Running deployment tasks (migrate, cache, ready)"
php artisan app:deploy $SEED_FLAG $DEPLOY_ARGS

echo "==> Deployment complete."
