#!/usr/bin/env bash
#
# RC1 deployment architecture redesign (docs/ADR/027). ONE flow, every
# environment: git pull -> composer install -> npm build -> deploy ->
# cache -> ready. Everything Laravel-specific lives in the `app:deploy`
# Artisan command (app/Console/Commands/DeployCommand.php) so it's
# testable and identical across shells; this script is only the
# OS-level orchestration around it (pull, install, build).
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

echo "==> [1/4] Pulling latest code"
git pull

echo "==> [2/4] Installing PHP dependencies (production, no dev packages)"
"$COMPOSER_BIN" install --no-dev --optimize-autoloader

echo "==> [3/4] Building frontend assets"
npm ci
npm run build

echo "==> [4/4] Running deployment tasks (migrate, cache, ready)"
php artisan app:deploy $SEED_FLAG $DEPLOY_ARGS

echo "==> Deployment complete."
