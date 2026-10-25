#!/usr/bin/env bash
#
# Give Hermes a full IOMS development workspace -- the power it needs to
# analyse and fix code, run the tests and push changes -- without root and
# without production secrets.
#
#   sudo bash setup-dev-workspace.sh [--agent-user ubuntu] [--no-node]
#
# Creates, owned by the agent account:
#   ~/IOMS                a clone of the repository (branch main)
#   ~/IOMS/.env           LOCAL settings: APP_ENV=local, SQLite file database,
#                         mail to the log, no payment gateway. Never a copy of
#                         production's .env.
#   vendor/, node_modules dev dependencies, so `php artisan test`,
#                         `npm run build` and `npm run lint` work
#
# The agent then works the way a developer does:
#   cd ~/IOMS && git switch -c fix/<topic> && <edit> && php artisan test
#   git push origin fix/<topic>            (needs a GitHub credential -- see below)
#   ... after the change reaches main:     sudo ioms-ops deploy
#
# GITHUB CREDENTIAL (owner's decision, not created here): a fine-grained
# personal access token limited to ysrgroup26/IOMS with Contents read/write,
# stored for the agent only:
#   sudo -u ubuntu git config --global credential.helper store   # then one push prompts once
# Protect `main` on GitHub (require a pull request) if Hermes should not push
# to production code directly; `ioms-ops deploy` only ever deploys main.

set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$here/../lib.sh"

AGENT=ubuntu NODE=1 REPO="${IOMS_REPO:-https://github.com/ysrgroup26/IOMS.git}"
while [[ $# -gt 0 ]]; do
    case "$1" in --agent-user) AGENT="$2"; shift 2 ;; --no-node) NODE=0; shift ;; *) die "Unknown option: $1" ;; esac
done
[[ $EUID -eq 0 ]] || die "Run as root."
HOME_A="$(getent passwd "$AGENT" | cut -d: -f6)"
WS="$HOME_A/IOMS"

log "Toolchain (system packages, installed by root once)"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y -q git unzip sqlite3 php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-zip \
    php8.3-gd php8.3-curl php8.3-intl php8.3-bcmath php8.3-gmp >/dev/null
[[ $NODE -eq 1 ]] && apt-get install -y -q nodejs npm >/dev/null
command -v composer >/dev/null || die "composer missing: run provision.sh first."
echo "  php $(php -r 'echo PHP_VERSION;'), composer $(composer --version 2>/dev/null | awk '{print $3}')$( [[ $NODE -eq 1 ]] && echo ", node $(node -v)")"

log "Workspace $WS (owned by $AGENT)"
A() { sudo -u "$AGENT" -H bash -c "$*"; }
if [[ ! -d "$WS/.git" ]]; then A "git clone -q '$REPO' '$WS'"; else A "cd '$WS' && git fetch -q origin"; fi
A "cd '$WS' && git config user.name 'Hermes Agent' && git config user.email 'hermes@iomsuite.com'"
if [[ ! -f "$WS/.env" ]]; then
    A "cd '$WS' && cp .env.example .env && touch database/database.sqlite \
       && sed -i -e 's/^APP_ENV=.*/APP_ENV=local/' -e 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' \
                 -e 's#^DB_DATABASE=.*#DB_DATABASE=$WS/database/database.sqlite#' -e 's/^MAIL_MAILER=.*/MAIL_MAILER=log/' .env"
fi
A "cd '$WS' && composer install -q --no-interaction --no-progress"
grep -qE '^APP_KEY=.+' "$WS/.env" || A "cd '$WS' && php artisan key:generate -q"
A "cd '$WS' && php artisan migrate -q --force"
if [[ $NODE -eq 1 ]]; then
    # Frontend tooling is optional for the agent's PHP work; a broken npm must
    # not leave the workspace half-built (seen in the rehearsal container,
    # where a preinstalled Node 22 clashed with Ubuntu's npm).
    A "cd '$WS' && npm ci --silent --no-audit --no-fund" || warn "npm ci failed: frontend build/lint unavailable until fixed; PHP tests are unaffected."
fi

log "Proof: the agent can run tests (one fast file)"
A "cd '$WS' && php artisan test tests/Feature/RegistrationSurvivesMailFailureTest.php --compact 2>&1 | tail -2"

log "Isolation check"
grep -qE '^DB_CONNECTION=sqlite' "$WS/.env" && echo "  ok    workspace uses its own SQLite file, not the production database"
sudo -u "$AGENT" test -r /srv/ioms/app/.env && warn "agent can read production .env!" || echo "  ok    agent cannot read the production .env"
echo
echo "Hermes can now: edit code, run php artisan test / npm run build / npm run lint, commit, and"
echo "(with a GitHub credential) push branches. Production changes go through: sudo ioms-ops deploy"
