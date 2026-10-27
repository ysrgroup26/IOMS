#!/usr/bin/env bash
#
# IOMS -- migration orchestrator for the Tencent Lighthouse instance.
# Run as root ON THE INSTANCE. Every phase is idempotent and appends to
# /var/log/ioms-migration.log. No phase prints a secret.
#
#   migrate.sh preflight                      what is here, what will collide
#   migrate.sh app [--branch main]            clone/update the code, create .env
#   migrate.sh env-set KEY                    set a SECRET in .env from stdin (hidden)
#   migrate.sh env-set KEY VALUE              set a NON-secret value
#   migrate.sh env-show                       .env keys, secrets masked
#   migrate.sh import --from DIR [--replace]  load an export-cpanel.sh export, prove parity
#   migrate.sh fresh                          no export: empty database + seeders
#   migrate.sh verify --url URL [--resolve IP] [--mail-to ADDR] [--e2e] [--allow-no-payment]
#   migrate.sh status                         one screen: versions, services, release
#   migrate.sh restore --from BACKUPDIR --yes-overwrite-database
#                                             RECOVERY: put a /var/backups/ioms/<stamp> back
#
# Order on a new instance: preflight -> provision.sh -> app -> env-set (secrets)
# -> import (or fresh) -> verify. Re-running any phase is safe.

set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$here/lib.sh"

APP_USER="${APP_USER:-ioms}"
APP_DIR="${APP_DIR:-/srv/ioms/app}"
REPO="${IOMS_REPO:-https://github.com/ysrgroup26/IOMS.git}"
LOG=/var/log/ioms-migration.log

[[ $EUID -eq 0 ]] || die "Run as root (sudo)."
touch "$LOG"; chmod 600 "$LOG"
note() { printf '%s %s\n' "$(date -Is)" "$*" >> "$LOG"; }
as_app() { sudo -u "$APP_USER" -H bash -c "cd '$APP_DIR' && $*"; }
artisan() { as_app "php artisan $*"; }

# Keys whose values are secrets: never printed, only accepted on stdin.
SECRET_KEYS='^(APP_KEY|DB_PASSWORD|MAIL_PASSWORD|DUITKU_API_KEY|MIDTRANS_SERVER_KEY|IOMS_VAPID_PRIVATE_KEY|VAPID_PRIVATE_KEY|GOOGLE_CLIENT_SECRET|AWS_SECRET_ACCESS_KEY|REDIS_PASSWORD)$'

env_set_raw() { # key value -- atomic, keeps mode and owner
    local key="$1" val="$2" f="$APP_DIR/.env" tmp
    tmp="$(mktemp "$APP_DIR/.env.XXXX")"
    if grep -qE "^${key}=" "$f"; then
        KEY="$key" VAL="$val" awk 'BEGIN{k=ENVIRON["KEY"]; v=ENVIRON["VAL"]} $0 ~ "^"k"=" {print k"="v; next} {print}' "$f" > "$tmp"
    else
        cat "$f" > "$tmp"; printf '%s=%s\n' "$key" "$val" >> "$tmp"
    fi
    chown "$APP_USER:$APP_USER" "$tmp"; chmod 640 "$tmp"; mv "$tmp" "$f"
}
quote_if_needed() { [[ "$1" =~ [[:space:]\#\"\$] ]] && printf '"%s"' "${1//\"/\\\"}" || printf '%s' "$1"; }

phase_preflight() {
    log "Preflight"
    . /etc/os-release; echo "OS:        $PRETTY_NAME"
    echo "CPU/RAM:   $(nproc) vCPU, $(free -h | awk '/Mem:/ {print $2}') RAM, $(free -h | awk '/Mem:/ {print $7}') available"
    echo "Disk /:    $(df -h / | awk 'NR==2 {print $4" free of "$2}')"
    echo "systemd:   $(has_systemd && echo yes || echo 'no (container rehearsal)')"
    echo "Ports 80/443:"; ss -tlnpH '( sport = :80 or sport = :443 )' 2>/dev/null | sed 's/^/    /' || true
    echo "Hermes:    $(ps -eo cmd | grep -ci '[h]ermes') process(es); units: $(systemctl list-units --all --no-legend 2>/dev/null | grep -ci hermes || true)"
    echo "App dir:   $([[ -d $APP_DIR/.git ]] && git -c safe.directory="$APP_DIR" -C "$APP_DIR" log -1 --format='%h %s' || echo 'not deployed')"
    echo "GitHub:    $(git ls-remote "$REPO" HEAD >/dev/null 2>&1 && echo reachable || echo 'NOT reachable')"
    note "preflight ok"
}

phase_app() {
    local branch="main"
    [[ "${1:-}" == "--branch" ]] && branch="$2"
    id -u "$APP_USER" >/dev/null 2>&1 || die "User $APP_USER missing: run provision.sh first."
    log "Code: $REPO ($branch) -> $APP_DIR"
    if [[ ! -d "$APP_DIR/.git" ]]; then
        sudo -u "$APP_USER" git clone --branch "$branch" "$REPO" "$APP_DIR"
    else
        # Same rule as production: never reset --hard over local changes.
        if [[ -n "$(git -c safe.directory="$APP_DIR" -C "$APP_DIR" status --porcelain --untracked-files=no)" ]]; then
            git -c safe.directory="$APP_DIR" -C "$APP_DIR" status --short
            die "Local changes in $APP_DIR -- refusing to pull over them. Resolve by hand."
        fi
        as_app "git fetch origin '$branch' && git checkout '$branch' && git pull --ff-only origin '$branch'"
    fi

    if [[ ! -f "$APP_DIR/.env" ]]; then
        log "Creating .env from .env.example with production defaults"
        install -m 640 -o "$APP_USER" -g "$APP_USER" "$APP_DIR/.env.example" "$APP_DIR/.env"
        local dbpass; dbpass="$(awk -F= '/^password=/{print substr($0, index($0,$2))}' /root/ioms-db.cnf)"
        env_set_raw APP_ENV production
        env_set_raw APP_DEBUG false
        env_set_raw APP_URL "https://iomsuite.com"
        env_set_raw DB_CONNECTION mysql
        env_set_raw DB_HOST 127.0.0.1
        env_set_raw DB_PORT 3306
        env_set_raw DB_DATABASE ioms
        env_set_raw DB_USERNAME ioms
        env_set_raw DB_PASSWORD "$dbpass"; unset dbpass
        env_set_raw SESSION_SECURE_COOKIE true
        env_set_raw QUEUE_CONNECTION database
        # Mail and payment stay INERT until their secrets are set on purpose:
        # a fresh copy of production data must not email customers.
        env_set_raw MAIL_MAILER log
        env_set_raw PAYMENT_GATEWAY none
        env_set_raw DUITKU_IS_PRODUCTION false
        note "app: .env created (mail=log, payment=none)"
    fi
    grep -qE '^APP_KEY=.+' "$APP_DIR/.env" || warn "APP_KEY is empty. For the real migration COPY it from IDCloudHost: migrate.sh env-set APP_KEY"

    log "Dependencies"
    as_app "composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader"
    chown -R "$APP_USER:$APP_USER" "$APP_DIR"
    chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
    chmod 640 "$APP_DIR/.env"
    note "app: $(git -c safe.directory="$APP_DIR" -C "$APP_DIR" log -1 --format=%h) on $branch"
    echo "Code ready at $(git -c safe.directory="$APP_DIR" -C "$APP_DIR" log -1 --format='%h %s'). Next: env-set secrets, then import or fresh."
}

phase_env_set() {
    local key="${1:?KEY required}"
    [[ -f "$APP_DIR/.env" ]] || die "No .env yet: run 'migrate.sh app' first."
    local val
    if [[ $# -ge 2 ]]; then
        [[ "$key" =~ $SECRET_KEYS ]] && die "$key is a secret: pipe it on stdin instead of passing it as an argument (arguments land in shell history)."
        val="$2"
    else
        if [[ -t 0 ]]; then read -r -s -p "Value for $key (hidden): " val; echo; else IFS= read -r val; fi
    fi
    [[ -n "$val" ]] || die "Empty value refused."
    env_set_raw "$key" "$(quote_if_needed "$val")"
    unset val
    as_app "php artisan config:clear >/dev/null"
    note "env-set $key"
    echo "$key set."
}

phase_env_show() {
    [[ -f "$APP_DIR/.env" ]] || die "No .env."
    grep -vE '^\s*(#|$)' "$APP_DIR/.env" | while IFS='=' read -r k v; do
        if [[ "$k" =~ $SECRET_KEYS || "$k" =~ (PASSWORD|SECRET|_KEY)$ ]]; then
            [[ -n "$v" && "$v" != "null" ]] && v="(set)" || v="(EMPTY)"
        fi
        printf '%-32s %s\n' "$k" "$v"
    done
}

phase_import() {
    local from="" replace=0
    while [[ $# -gt 0 ]]; do
        case "$1" in --from) from="$2"; shift 2 ;; --replace) replace=1; shift ;; *) die "Unknown: $1" ;; esac
    done
    [[ -d "$from" ]] || die "--from DIR (an export-cpanel.sh directory) is required."
    # The export is every tenant's data and every password hash. Hermes runs
    # as `ubuntu`, so an export left in /home/ubuntu (where an OrcaTerm upload
    # lands when logged in as ubuntu) is readable by the agent: refuse it, and
    # lock any other location down to root before reading it.
    local real; real="$(readlink -f "$from")"
    local agent_home; agent_home="$(getent passwd "${HERMES_USER:-ubuntu}" | cut -d: -f6)"
    if [[ -n "$agent_home" && "$real" == "$agent_home"* ]]; then
        die "The export is inside $agent_home, which the Hermes account can read. Move it first: sudo mv '$real' /root/ && sudo chmod -R go-rwx /root/$(basename "$real")"
    fi
    chown -R root:root "$real"; chmod -R go-rwx "$real"
    log "Verifying the export's checksums"
    ( cd "$from" && sha256sum -c SHA256SUMS )

    local tables
    tables="$(mysql --defaults-extra-file=/root/ioms-db.cnf -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ioms'")"
    if [[ "$tables" -gt 0 ]]; then
        [[ $replace -eq 1 ]] || die "Database 'ioms' already has $tables tables. Re-run with --replace to back it up and replace it."
        log "Backing up the current database before replacing it"
        /usr/local/bin/ioms-backup
        mysql --defaults-extra-file=/root/ioms-db.cnf -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='ioms'" \
          | awk '{print "DROP TABLE IF EXISTS `"$1"`;"}' \
          | { echo "SET FOREIGN_KEY_CHECKS=0;"; cat; echo "SET FOREIGN_KEY_CHECKS=1;"; } \
          | mysql --defaults-extra-file=/root/ioms-db.cnf ioms
        note "import: replaced $tables tables (backup taken first)"
    fi

    log "Loading the database"
    gunzip -c "$from/ioms.sql.gz" | mysql --defaults-extra-file=/root/ioms-db.cnf ioms

    log "Proving row-count parity with the source"
    local bad=0
    while IFS=$'\t' read -r t n; do
        local got; got="$(mysql --defaults-extra-file=/root/ioms-db.cnf -N -e "SELECT COUNT(*) FROM \`ioms\`.\`$t\`" 2>/dev/null || echo MISSING)"
        if [[ "$got" != "$n" ]]; then printf '  MISMATCH %-40s source=%s here=%s\n' "$t" "$n" "$got"; bad=1; fi
    done < "$from/counts.tsv"
    [[ $bad -eq 0 ]] || die "Row counts differ from the source. Nothing further was changed."
    echo "  $(wc -l < "$from/counts.tsv") tables, every row count matches the source."

    log "Restoring storage/app"
    # As root (the export usually sits in root's home, which ioms cannot
    # read), without the archive's owners (those are the cPanel account's
    # uids), then handed to the app user.
    tar -C "$APP_DIR" --no-same-owner -xzf "$from/storage-app.tar.gz"
    chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage/app"
    local files; files="$(tar -tzf "$from/storage-app.tar.gz" | grep -vc '/$')"
    echo "  $files files restored."

    log "Migrating schema to this release"
    as_app "./deploy.sh"
    supervisorctl reread >/dev/null 2>&1 && supervisorctl update >/dev/null 2>&1 || true
    supervisorctl restart ioms-worker >/dev/null 2>&1 || true
    note "import: $from ok ($files files)"
}

phase_fresh() {
    local tables
    tables="$(mysql --defaults-extra-file=/root/ioms-db.cnf -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ioms'")"
    [[ "$tables" -eq 0 ]] || die "Database 'ioms' is not empty ($tables tables). 'fresh' only runs on an empty database."
    grep -qE '^APP_KEY=.+' "$APP_DIR/.env" || artisan "key:generate --force"
    as_app "./deploy.sh --first"
    supervisorctl reread >/dev/null 2>&1 && supervisorctl update >/dev/null 2>&1 || true
    note "fresh install"
}

phase_verify() {
    local url="" resolve="" mailto="" e2e=0 fail=0 payflag=""
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --url) url="$2"; shift 2 ;; --resolve) resolve="$2"; shift 2 ;;
            --mail-to) mailto="$2"; shift 2 ;; --e2e) e2e=1; shift ;;
            --allow-no-payment) payflag="--allow-unconfigured"; shift ;; *) die "Unknown: $1" ;;
        esac
    done
    [[ -n "$url" ]] || die "--url is required."
    local hc=("$url"); [[ -n "$resolve" ]] && hc+=(--resolve "$resolve" --insecure)
    [[ $e2e -eq 1 ]] && hc+=(--e2e)

    log "HTTP checks"
    bash "$here/healthcheck.sh" "${hc[@]}" || fail=1

    log "Release identity (compare with Master Admin > Running Release)"
    echo "  commit     $(git -c safe.directory="$APP_DIR" -C "$APP_DIR" rev-parse --short HEAD)"
    echo "  signature  $(artisan "tinker --execute='echo app(\\App\\Services\\ReleaseStatusService::class)->fingerprint()[\"signature\"];'" 2>/dev/null)"

    log "Services"
    # Ask the init system; the process name is no guide (PHP-FPM's processes are
    # `php-fpm: master process (...)`, which matched neither `php8.3-fpm` nor `php`,
    # so a running FPM was reported FAIL on Tencent).
    for s in nginx php8.3-fpm mysql cron; do
        if service_running "$s"; then echo "  ok    $s"; else echo "  FAIL  $s"; fail=1; fi
    done
    # migrate-all.sh holds the worker and the scheduler until go-live, so a copy of
    # production cannot email customers. Under the hold, HELD is the pass and a
    # running worker or an active schedule line is the failure.
    local hold=/var/lib/ioms-migrate/hold worker
    worker="$(supervisorctl status ioms-worker 2>/dev/null || true)"
    if [[ -f "$hold" ]]; then
        if grep -q RUNNING <<<"$worker"; then echo "  FAIL  queue worker is RUNNING during the migration hold"; fail=1
        else echo "  held  queue worker (migration hold since $(cat "$hold"); released by migrate-all.sh go-live)"; fi
        if grep -qE '^[^#].*schedule:run' /etc/cron.d/ioms 2>/dev/null; then echo "  FAIL  scheduler is active during the migration hold"; fail=1
        else echo "  held  scheduler (same hold)"; fi
    elif grep -q RUNNING <<<"$worker"; then echo "  ok    queue worker"
    else echo "  FAIL  queue worker"; fail=1; fi
    artisan "schedule:list" | sed 's/^/  /'

    log "Permissions"
    local envmode; envmode="$(stat -c '%a %U:%G' "$APP_DIR/.env")"
    [[ "$envmode" == "640 $APP_USER:$APP_USER" ]] && echo "  ok    .env $envmode" || { echo "  FAIL  .env is $envmode (want 640 $APP_USER:$APP_USER)"; fail=1; }
    [[ "$(stat -c '%a' "$(dirname "$APP_DIR")")" == "750" ]] && echo "  ok    $(dirname "$APP_DIR") is 750" || { echo "  FAIL  $(dirname "$APP_DIR") not 750"; fail=1; }
    grep -qE '^APP_DEBUG=false' "$APP_DIR/.env" && echo "  ok    APP_DEBUG=false" || { echo "  FAIL  APP_DEBUG is not false"; fail=1; }

    log "Payment provider (sandbox only)"
    artisan "ioms:duitku-check $payflag" || fail=1

    if [[ -n "$mailto" ]]; then
        log "Mail"
        artisan "ioms:mail-check '$mailto'" || fail=1
    fi

    log "Backup and restore"
    /usr/local/bin/ioms-backup && /usr/local/bin/ioms-restore-check || fail=1

    note "verify $url -> $([[ $fail -eq 0 ]] && echo PASS || echo FAIL)"
    [[ $fail -eq 0 ]] && log "VERIFY PASSED" || die "VERIFY FAILED -- see above."
}

phase_restore() {
    # RECOVERY. Destructive by nature (it replaces the live database with a
    # backup), so it needs an explicit flag AND, on a terminal, typing RESTORE.
    # A fresh backup of the current state is taken first, so a restore can
    # itself be undone.
    local from="" yes=0
    while [[ $# -gt 0 ]]; do
        case "$1" in --from) from="$2"; shift 2 ;; --yes-overwrite-database) yes=1; shift ;; *) die "Unknown: $1" ;; esac
    done
    [[ -f "$from/ioms.sql.gz" ]] || die "--from must be a backup directory (with ioms.sql.gz), e.g. $(ls -1d /var/backups/ioms/*/ 2>/dev/null | tail -1)"
    [[ $yes -eq 1 ]] || die "This REPLACES the live database. Re-run with --yes-overwrite-database."
    ( cd "$from" && sha256sum -c SHA256SUMS >/dev/null ) || die "Backup checksums do not match; refusing."
    if [[ -t 0 ]]; then read -r -p "Type RESTORE to replace the live database with $from: " ok; [[ "$ok" == RESTORE ]] || die "Not restored."; fi
    log "Backing up the CURRENT state first"
    /usr/local/bin/ioms-backup
    as_app "php artisan down --retry=60" || true
    mysql --defaults-extra-file=/root/ioms-db.cnf -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='ioms'" \
      | awk '{print "DROP TABLE IF EXISTS `"$1"`;"}' | { echo "SET FOREIGN_KEY_CHECKS=0;"; cat; echo "SET FOREIGN_KEY_CHECKS=1;"; } \
      | mysql --defaults-extra-file=/root/ioms-db.cnf ioms
    gunzip -c "$from/ioms.sql.gz" | mysql --defaults-extra-file=/root/ioms-db.cnf ioms
    tar -C "$APP_DIR" --no-same-owner -xzf "$from/storage-app.tar.gz"; chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage/app"
    as_app "php artisan up"
    note "restore from $from"
    log "Restored from $from (the state before this restore is the newest backup)."
}

phase_status() {
    echo "Code:     $(git -c safe.directory="$APP_DIR" -C "$APP_DIR" log -1 --format='%h %s (%cr)' 2>/dev/null || echo none)"
    echo "Version:  $(artisan "tinker --execute='echo config(\"ioms.version\");'" 2>/dev/null || echo '?')"
    echo "Worker:   $(supervisorctl status ioms-worker 2>/dev/null || echo 'not running')"
    echo "Backups:  $(ls -1d /var/backups/ioms/*/ 2>/dev/null | tail -1 || echo none)"
    echo "Log:      $LOG ($(wc -l < "$LOG") entries)"
}

cmd="${1:-}"; shift || true
case "$cmd" in
    preflight) phase_preflight "$@" ;;
    app) phase_app "$@" ;;
    env-set) phase_env_set "$@" ;;
    env-show) phase_env_show ;;
    import) phase_import "$@" ;;
    fresh) phase_fresh ;;
    verify) phase_verify "$@" ;;
    status) phase_status ;;
    restore) phase_restore "$@" ;;
    *) sed -n '2,20p' "$0"; exit 2 ;;
esac
