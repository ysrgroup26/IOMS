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
#   Set COMPOSER_BIN if `composer` isn't on PATH (e.g. some shared hosts
#   only expose it at a versioned path):
#     export COMPOSER_BIN=/opt/alt/php83/usr/bin/composer
#   Add that export to ~/.bashrc (or a .deploy.env this script would
#   source, if you prefer not to touch shell profile) so it's set for
#   every future deploy without editing this committed script.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

COMPOSER_BIN="${COMPOSER_BIN:-composer}"
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
