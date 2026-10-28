#!/usr/bin/env bash
# staging.sh -- a separate preview of a branch at staging.iomsuite.com, beside production.
#
#   sudo bash staging.sh setup  [branch]   first time: user, DB, .env, FPM pool, nginx, Caddy, seed
#   sudo bash staging.sh deploy [branch]   later: pull the branch, build deps, migrate STAGING only
#
# Isolation from production (/srv/ioms, DB `ioms`, user `ioms`, port 8081):
#   own Linux user `iomsstg`, own dir /srv/ioms-staging, own DB `ioms_staging` with a MySQL user
#   that can reach nothing else, own APP_KEY, own FPM socket, nginx on 127.0.0.1:8082.
#   MAIL_MAILER=log (no email leaves), no payment gateway, no push keys, no cron, no worker.
#   Caddy: only the `# detached: staging...` block changes; every other site is snapshotted
#   before and after the reload and the change is undone if any of them answers differently.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
source "$here/lib.sh"

CMD="${1:-}"; BRANCH="${2:-claude/eloquent-newton-pbxxdg}"
HOST=staging.iomsuite.com
U=iomsstg; ROOT=/srv/ioms-staging; APP=$ROOT/app; PORT=8082
DB=ioms_staging; SOCK=/run/php/ioms-staging-fpm.sock
REPO="$(git -C /srv/ioms/app config --get remote.origin.url 2>/dev/null || echo https://github.com/ysrgroup26/IOMS.git)"
SNIPPET=/etc/caddy/ioms.caddy; CADDYFILE=/etc/caddy/Caddyfile
CRED=/root/ioms-staging-credentials.txt
[[ $EUID -eq 0 ]] || die "Run as root (sudo)."
as() { sudo -u "$U" -H bash -lc "cd $APP && $*"; }

pull() {
    if [[ -d $APP/.git ]]; then
        as "git fetch -q origin '$BRANCH' && git checkout -q -B '$BRANCH' FETCH_HEAD"
    else
        sudo -u "$U" -H git clone -q --branch "$BRANCH" "$REPO" "$APP"
    fi
    as "composer install -q --no-dev --no-interaction --optimize-autoloader"
}

deploy() {
    log "Deploy $BRANCH to staging"
    pull
    as "php artisan migrate --force && php artisan optimize:clear >/dev/null && php artisan config:cache >/dev/null"
    echo "  staging now at $(as 'git rev-parse --short HEAD')"
}

setup() {
    log "User and directory"
    id "$U" >/dev/null 2>&1 || useradd --system --create-home --shell /bin/bash "$U"
    install -d -o "$U" -g "$U" "$ROOT"
    pull

    log "Database (staging only)"
    local dbpass; dbpass="$(openssl rand -hex 24)"
    mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$U'@'localhost' IDENTIFIED BY '$dbpass';
ALTER USER '$U'@'localhost' IDENTIFIED BY '$dbpass';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$U'@'localhost';
FLUSH PRIVILEGES;
SQL

    log ".env (no mail, no payments, no push)"
    cat > "$APP/.env" <<ENV
APP_NAME=IOMS
APP_ENV=staging
APP_KEY=
APP_DEBUG=false
APP_URL=https://$HOST
IOMS_PUBLIC_URL=https://$HOST
LOG_CHANNEL=daily
DB_CONNECTION=mysql
DB_HOST=localhost
DB_SOCKET=/run/mysqld/mysqld.sock
DB_DATABASE=$DB
DB_USERNAME=$U
DB_PASSWORD=$dbpass
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync
MAIL_MAILER=log
PAYMENT_GATEWAY=
DUITKU_MERCHANT_CODE=
DUITKU_API_KEY=
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
ENV
    chown "$U:$U" "$APP/.env"; chmod 600 "$APP/.env"
    as "php artisan key:generate --force -q && php artisan storage:link -q || true"
    chmod -R ug+rwX "$APP/storage" "$APP/bootstrap/cache"

    log "Schema and test data"
    as "php artisan migrate --force --seed"
    # Seeded accounts ship with the password 'password'; give staging its own.
    local apppass; apppass="$(openssl rand -base64 12 | tr -d '/+=')"
    as "php artisan tinker --execute=\"App\\\\Models\\\\User::query()->update(['password' => bcrypt('$apppass')]);\"" >/dev/null
    as "php artisan config:cache >/dev/null"

    log "PHP-FPM pool $U"
    cat > /etc/php/8.3/fpm/pool.d/ioms-staging.conf <<POOL
[ioms-staging]
user = $U
group = $U
listen = $SOCK
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 4
php_admin_value[error_log] = /var/log/php8.3-fpm-ioms-staging.log
php_admin_flag[log_errors] = on
POOL
    php-fpm8.3 -t >/dev/null 2>&1 || die "php-fpm config test failed."
    svc reload php8.3-fpm

    log "nginx on 127.0.0.1:$PORT"
    sed "s/__SERVER_NAMES__/$HOST/g; s#__ROOT__#$APP/public#g; s/__PORT__/$PORT/g; s#/run/php/ioms-fpm.sock#$SOCK#g" \
        "$here/nginx-ioms-behind-caddy.conf" > /etc/nginx/sites-available/ioms-staging
    ln -sf /etc/nginx/sites-available/ioms-staging /etc/nginx/sites-enabled/ioms-staging
    nginx -t 2>/dev/null || { rm -f /etc/nginx/sites-enabled/ioms-staging; die "nginx -t failed; staging site removed."; }
    svc reload nginx

    log "Caddy: $HOST -> 127.0.0.1:$PORT behind a password"
    local bk; bk="/root/caddy-backup-staging-$(date +%Y%m%d%H%M%S)"; install -d -m 700 "$bk"
    cp -a "$SNIPPET" "$bk/ioms.caddy"
    local snap='for h in iomsuite.com www.iomsuite.com; do curl -sk -o /dev/null -w "$h %{http_code}\n" --resolve $h:443:127.0.0.1 https://$h/login; done'
    bash -c "$snap" > "$bk/before.txt"
    local tls_line; tls_line="$(grep -m1 -E '^\s*tls ' "$SNIPPET" | sed 's/^\s*//')"
    local webpass; webpass="$(openssl rand -base64 12 | tr -d '/+=')"
    local hash; hash="$(caddy hash-password --plaintext "$webpass")"
    # Drop the detach block (marker line, comments, two sites) and any earlier staging block.
    awk -v m="# detached: $HOST" -v s="# staging: $HOST" '
        $0 == m || $0 == s { skip = 1; closes = 0; next }
        skip { if ($0 ~ /^}/) { closes++; if (closes == 2) skip = 0 } next }
        { print }' "$bk/ioms.caddy" > "$SNIPPET"
    cat >> "$SNIPPET" <<CADDY
# staging: $HOST
$HOST {
    $tls_line
    basic_auth {
        preview $hash
    }
    header X-Robots-Tag "noindex, nofollow"
    reverse_proxy 127.0.0.1:$PORT
}
http://$HOST {
    redir https://$HOST{uri} permanent
}
CADDY
    if ! caddy validate --config "$CADDYFILE" --adapter caddyfile >/dev/null 2>&1 || ! systemctl reload caddy; then
        cp -a "$bk/ioms.caddy" "$SNIPPET"; systemctl reload caddy || true
        die "Caddy rejected the staging site; restored."
    fi
    sleep 2; bash -c "$snap" > "$bk/after.txt"
    if ! diff -q "$bk/before.txt" "$bk/after.txt" >/dev/null; then
        cp -a "$bk/ioms.caddy" "$SNIPPET"; systemctl reload caddy || true
        die "Production answered differently after the reload; restored."
    fi
    echo "  ok    production answers exactly as before"

    umask 077
    cat > "$CRED" <<C
Browser password (first prompt):  user preview  /  $webpass
App login (any seeded account, e.g. admin@ioms.local, hse@ioms.local):  $apppass
C
    log "Done"
    echo "  local check: $(curl -sk -o /dev/null -w '%{http_code}' -u "preview:$webpass" --resolve $HOST:443:127.0.0.1 https://$HOST/login)"
    echo "  credentials: cat $CRED   (shown once here, not logged)"
}

case "$CMD" in
    setup) setup ;;
    deploy) deploy ;;
    *) die "usage: staging.sh setup|deploy [branch]" ;;
esac
