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
# SAFE AND IDEMPOTENT BY CONSTRUCTION (rewritten after the instance answered
# "held broken packages"):
#   - only MISSING packages are requested, so a re-run requests nothing;
#   - every install is SIMULATED first (apt-get -s), and refused if the
#     simulation fails, would REMOVE anything (--no-remove), or would UPGRADE
#     an installed PHP, MySQL or Node.js package;
#   - Node.js is never installed over an existing one. The Hermes image ships
#     its own Node.js (NodeSource style: npm bundled, and Conflicts: npm), and
#     asking for Ubuntu's `npm` beside it is exactly what made APT refuse the
#     whole request -- PHP included;
#   - no `apt-get upgrade`, no `--fix-broken`, no removals, ever.
export DEBIAN_FRONTEND=noninteractive LANG=C LC_ALL=C

missing() { local p; for p in "$@"; do dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q 'install ok installed' || echo "$p"; done; }

safe_install() { # label pkg...
    local label="$1"; shift
    local need; need="$(missing "$@")"
    if [[ -z "$need" ]]; then echo "  ok    $label: already installed"; return 0; fi
    local sim; sim="$(apt-get install -s -y --no-remove $need 2>&1)" || {
        echo "  FAIL  $label: APT cannot install: $(tr '\n' ' ' <<<"$need")"
        grep -E '^ [a-z0-9].* : |^E: ' <<<"$sim" | sed 's/^/        /' | head -15
        return 1
    }
    local risky; risky="$(grep -E '^Inst ' <<<"$sim" | grep -E '\[' | awk '{print $2}' | grep -E '^(php|mysql|libmysql|mariadb|nodejs|libnode)' || true)"
    if [[ -n "$risky" ]]; then
        echo "  FAIL  $label: would UPGRADE installed runtime packages: $(tr '\n' ' ' <<<"$risky") -- not doing that here."
        return 1
    fi
    apt-get install -y -q --no-remove $need >/dev/null && echo "  ok    $label: installed $(tr '\n' ' ' <<<"$need")"
}

safe_install "PHP 8.3 toolchain" git unzip sqlite3 php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml \
    php8.3-zip php8.3-gd php8.3-curl php8.3-intl php8.3-bcmath php8.3-gmp \
    || die "PHP toolchain not installable as-is. Nothing was changed. Send the lines above."

if ! command -v composer >/dev/null 2>&1; then
    # Checksum-verified, same as provision.sh, so this script does not depend
    # on provision having run.
    expected="$(curl -fsSL https://composer.github.io/installer.sig)" || die "Cannot download Composer's signature (network?). Nothing else was changed."
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php || die "Cannot download the Composer installer (network?)."
    [[ "$expected" == "$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" ]] || die "Composer installer checksum mismatch."
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer; rm -f /tmp/composer-setup.php
    echo "  ok    composer installed (checksum verified)"
else
    echo "  ok    composer: already installed"
fi

if [[ $NODE -eq 1 ]]; then
    if command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1; then
        echo "  ok    Node.js: using the existing $(node -v) / npm $(npm -v) ($(command -v node)) -- not touched"
    elif command -v node >/dev/null 2>&1; then
        warn "Node.js $(node -v) exists without npm; NOT installing Ubuntu's npm beside it (that conflict is what broke APT). Frontend build/lint unavailable; PHP work unaffected."
        NODE=0
    else
        safe_install "Node.js (Ubuntu)" nodejs npm || { warn "Node.js not installable cleanly; skipped. PHP work unaffected."; NODE=0; }
    fi
fi
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
