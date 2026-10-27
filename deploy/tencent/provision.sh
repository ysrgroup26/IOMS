#!/usr/bin/env bash
#
# IOMS -- provision a Tencent Cloud Lighthouse instance (Ubuntu 22.04 / 24.04)
# to run IOMS behind Cloudflare: nginx + PHP-FPM 8.3 + MySQL 8, a queue
# worker, the scheduler, an origin TLS certificate, a firewall and nightly
# backups.
#
# READ docs/runbooks/TENCENT-MIGRATION.md FIRST, and run inspect-instance.sh
# before this. This script:
#
#   - INSTALLS packages and writes config under /etc and /srv/ioms;
#   - NEVER stops, removes or reconfigures Hermes Agent or anything else
#     already running -- it refuses to continue if ports 80/443 are held
#     by something other than nginx;
#   - NEVER prints a password. The database password it generates is
#     written to /root/ioms-db.cnf (mode 600) and nowhere else;
#   - changes the firewall ONLY with --apply-firewall, because closing a
#     port Hermes depends on is a decision, not a side effect.
#
#   sudo bash provision.sh \
#       --domain iomsuite.com --domain www.iomsuite.com \
#       [--origin-cert /root/origin.pem --origin-key /root/origin.key] \
#       [--apply-firewall --ssh-from 203.0.113.7/32]
#
# Without --origin-cert a SELF-SIGNED placeholder is installed so nginx can
# listen on 443; Cloudflare "Full (strict)" will refuse it until the real
# Cloudflare Origin CA certificate is supplied (re-run with --origin-cert).
#
# Idempotent: safe to re-run.

set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$here/lib.sh"

DOMAINS=()
APPLY_FIREWALL=0
SSH_FROM=""
ORIGIN_CERT=""
ORIGIN_KEY=""
FRONT="auto"          # auto | nginx | caddy
BACKEND_PORT=8081     # caddy mode: the loopback port nginx listens on
APP_USER="ioms"
APP_ROOT="/srv/ioms"
DB_NAME="ioms"
DB_USER="ioms"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain) DOMAINS+=("$2"); shift 2 ;;
        --apply-firewall) APPLY_FIREWALL=1; shift ;;
        --ssh-from) SSH_FROM="$2"; shift 2 ;;
        --origin-cert) ORIGIN_CERT="$2"; shift 2 ;;
        --origin-key) ORIGIN_KEY="$2"; shift 2 ;;
        --front) FRONT="$2"; shift 2 ;;
        --backend-port) BACKEND_PORT="$2"; shift 2 ;;
        *) die "Unknown option: $1" ;;
    esac
done

[[ $EUID -eq 0 ]] || die "Run as root (sudo)."
[[ ${#DOMAINS[@]} -gt 0 ]] || die "At least one --domain is required."

. /etc/os-release
if [[ "${ID:-}" != "ubuntu" || ! "${VERSION_ID:-}" =~ ^(22\.04|24\.04)$ ]]; then
    die "Supports Ubuntu 22.04 and 24.04; found ${PRETTY_NAME:-unknown}. Inspect the instance and decide before adapting this."
fi

# ---------------------------------------------------------------- safety
# WHO OWNS 80/443 DECIDES THE MODE. Nothing that already holds them is ever
# stopped, reconfigured or displaced by this script.
#   free / nginx  -> nginx mode: nginx serves 80/443 itself (as rehearsed).
#   caddy         -> caddy mode: Caddy (on the Hermes image it fronts the Hermes
#                    dashboard) keeps 80/443; nginx listens on 127.0.0.1 only and
#                    Caddy gets ONE extra site for IOMS -- added separately, by
#                    caddy-site.sh, after you have read what it will change.
#   anything else -> stop, and decide by hand.
log "Who holds ports 80/443"
holders="$(ss -tlnpH '( sport = :80 or sport = :443 )' 2>/dev/null || true)"
echo "${holders:-  (nothing)}" | sed 's/^/  /'
others="$(grep -vE '"(nginx|caddy)"' <<<"$holders" | grep -E . || true)"
[[ -z "$others" ]] || die "80/443 are held by something other than nginx or Caddy. Decide by hand; nothing was changed."
if [[ "$FRONT" == auto ]]; then
    if grep -q '"caddy"' <<<"$holders"; then FRONT=caddy; else FRONT=nginx; fi
fi
[[ "$FRONT" =~ ^(nginx|caddy)$ ]] || die "--front must be auto, nginx or caddy."
if [[ "$FRONT" == nginx ]] && grep -q '"caddy"' <<<"$holders"; then
    die "Caddy holds 80/443; nginx mode would collide with it. Use --front caddy (the default, auto)."
fi
if [[ "$FRONT" == caddy ]]; then
    [[ "$BACKEND_PORT" =~ ^[0-9]{2,5}$ ]] || die "--backend-port must be a number."
    busy="$(ss -tlnpH "( sport = :$BACKEND_PORT )" 2>/dev/null | grep -v '"nginx"' || true)"
    [[ -z "$busy" ]] || die "Port $BACKEND_PORT is already used by another program: $busy. Pick one with --backend-port."
    command -v caddy >/dev/null || die "Caddy holds the ports but the caddy binary is not on PATH; inspect by hand."
fi
echo "  mode: $FRONT$( [[ $FRONT == caddy ]] && echo " (nginx on 127.0.0.1:$BACKEND_PORT behind Caddy)")"

# ---------------------------------------------------------------- packages
log "Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
# Caddy mode: Ubuntu's nginx package STARTS ITSELF on install with a default
# site on port 80 -- which Caddy holds -- and a failed start leaves the package
# half-configured. Service starts are blocked for the duration of the install
# (policy-rc.d, the Debian mechanism for exactly this), and nginx is started
# later, once it listens on loopback only.
if [[ "$FRONT" == caddy && ! -e /usr/sbin/policy-rc.d ]]; then
    printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d; chmod 755 /usr/sbin/policy-rc.d
    trap 'rm -f /usr/sbin/policy-rc.d' EXIT
fi
apt-get install -y software-properties-common ca-certificates curl unzip git acl openssl \
    nginx mysql-server supervisor ufw fail2ban unattended-upgrades logrotate cron
rm -f /etc/nginx/sites-enabled/default

if [[ "$VERSION_ID" == "22.04" ]] && ! apt-cache policy php8.3-fpm | grep -qE 'Candidate: [0-9]'; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -y
fi

apt-get install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-zip \
    php8.3-gd php8.3-curl php8.3-intl php8.3-bcmath php8.3-opcache php8.3-gmp php8.3-sqlite3

if ! command -v composer >/dev/null 2>&1; then
    log "Installing Composer (checksum-verified)"
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [[ "$expected" == "$actual" ]] || die "Composer installer checksum mismatch."
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

# ---------------------------------------------------------------- memory
# 4 GB is enough for IOMS + MySQL; a swap file keeps a composer install or a
# large PDF render from being OOM-killed while Hermes shares the box.
if ! swapon --show 2>/dev/null | grep -q .; then
    log "Adding a 2 GB swap file"
    if fallocate -l 2G /swapfile 2>/dev/null && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile 2>/dev/null; then
        grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    else
        rm -f /swapfile
        warn "Swap could not be enabled here (normal inside a container)."
    fi
fi

# ---------------------------------------------------------------- user, dirs
log "Creating the ${APP_USER} user and ${APP_ROOT}"
id -u "$APP_USER" >/dev/null 2>&1 || adduser --system --group --home "$APP_ROOT" --shell /bin/bash "$APP_USER"
usermod -aG "$APP_USER" www-data
mkdir -p "$APP_ROOT" /var/backups/ioms
chown "$APP_USER:$APP_USER" "$APP_ROOT"
# Nobody outside the ioms group can list the application, its .env or storage.
chmod 750 "$APP_ROOT" /var/backups/ioms

# ---------------------------------------------------------------- database
log "Starting MySQL and creating ${DB_NAME} (password goes to /root/ioms-db.cnf only)"
svc enable mysql
for _ in $(seq 1 30); do mysqladmin --protocol=socket -uroot ping >/dev/null 2>&1 && break; sleep 1; done
if [[ -f /root/ioms-db.cnf ]] && grep -q '^database=' /root/ioms-db.cnf && ! grep -q '^\[mysql\]' /root/ioms-db.cnf; then
    # Repair a file written by an earlier version of this script.
    sed -i '/^database=/d' /root/ioms-db.cnf && printf '\n[mysql]\ndatabase=%s\n' "$DB_NAME" >> /root/ioms-db.cnf
fi
if [[ ! -f /root/ioms-db.cnf ]]; then
    pass="$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-32)"
    # `database=` lives under [mysql] only: mysqldump also reads [client] and
    # rejects that key ("unknown variable 'database=ioms'"), which would
    # break every backup. Found by the container rehearsal.
    ( umask 077; printf '[client]\nuser=%s\npassword=%s\n\n[mysql]\ndatabase=%s\n' "$DB_USER" "$pass" "$DB_NAME" > /root/ioms-db.cnf )
    mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${pass}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${pass}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
-- the scratch database restore-check.sh proves backups against
GRANT ALL PRIVILEGES ON \`ioms_restore_check\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
    unset pass
fi
# MySQL listens on localhost only. Never expose 3306.
if [[ -f /etc/mysql/mysql.conf.d/mysqld.cnf ]] && grep -qE '^\s*bind-address' /etc/mysql/mysql.conf.d/mysqld.cnf; then
    sed -i 's/^\s*bind-address\s*=.*/bind-address = 127.0.0.1/' /etc/mysql/mysql.conf.d/mysqld.cnf
fi

# ---------------------------------------------------------------- php
log "Tuning PHP-FPM for a 4 GB host"
cat > /etc/php/8.3/fpm/conf.d/90-ioms.ini <<'INI'
memory_limit = 512M
upload_max_filesize = 25M
post_max_size = 30M
max_execution_time = 120
expose_php = Off
opcache.enable = 1
opcache.memory_consumption = 192
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
INI
# A DEDICATED POOL running as the app user. The stock `www` pool runs as
# www-data, which can read the code but not write storage/logs -- so the
# first 500 the rehearsal hit could not even be logged. The stock pool is
# left alone in case anything else on the host uses it.
cat > /etc/php/8.3/fpm/pool.d/ioms.conf <<POOL
[ioms]
user = ${APP_USER}
group = ${APP_USER}
listen = /run/php/ioms-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 12
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
pm.max_requests = 500
php_admin_value[error_log] = /var/log/php8.3-fpm-ioms.log
php_admin_flag[log_errors] = on
POOL
svc enable php8.3-fpm
svc restart php8.3-fpm

# ---------------------------------------------------------------- tls
log "Installing the origin certificate"
install -d -m 750 -o root -g www-data /etc/ssl/ioms
if [[ -n "$ORIGIN_CERT" && -n "$ORIGIN_KEY" ]]; then
    openssl x509 -in "$ORIGIN_CERT" -noout >/dev/null || die "--origin-cert is not a certificate."
    [[ "$(openssl x509 -in "$ORIGIN_CERT" -noout -pubkey | sha256sum)" == "$(openssl pkey -in "$ORIGIN_KEY" -pubout | sha256sum)" ]] \
        || die "--origin-key does not match --origin-cert."
    install -m 644 "$ORIGIN_CERT" /etc/ssl/ioms/origin.pem
    install -m 640 -g www-data "$ORIGIN_KEY" /etc/ssl/ioms/origin.key
    openssl x509 -in /etc/ssl/ioms/origin.pem -noout -subject -issuer -enddate
elif [[ ! -f /etc/ssl/ioms/origin.pem ]]; then
    warn "No --origin-cert given: installing a SELF-SIGNED placeholder. Cloudflare Full (strict) will refuse it."
    openssl req -x509 -nodes -newkey rsa:2048 -days 30 \
        -subj "/CN=${DOMAINS[0]}" -addext "subjectAltName=$(printf 'DNS:%s,' "${DOMAINS[@]}" | sed 's/,$//')" \
        -keyout /etc/ssl/ioms/origin.key -out /etc/ssl/ioms/origin.pem 2>/dev/null
    chmod 640 /etc/ssl/ioms/origin.key && chgrp www-data /etc/ssl/ioms/origin.key
fi

# ---------------------------------------------------------------- nginx
log "Configuring nginx for: ${DOMAINS[*]} ($FRONT mode)"
if [[ "$FRONT" == caddy ]]; then
    # The only peer is Caddy on loopback; Caddy passes Cloudflare's header through.
    rm -f /etc/nginx/conf.d/cloudflare-realip.conf
    printf '# Generated by provision.sh (caddy mode)\nset_real_ip_from 127.0.0.1;\nreal_ip_header CF-Connecting-IP;\n' > /etc/nginx/conf.d/ioms-realip.conf
    sed "s/__SERVER_NAMES__/${DOMAINS[*]}/g; s#__ROOT__#${APP_ROOT}/app/public#g; s/__PORT__/${BACKEND_PORT}/g" \
        "$here/nginx-ioms-behind-caddy.conf" > /etc/nginx/sites-available/ioms
else
    rm -f /etc/nginx/conf.d/ioms-realip.conf
    {
        echo "# Generated by provision.sh: restore the visitor IP from Cloudflare."
        cloudflare_ranges | sed 's/^/set_real_ip_from /; s/$/;/'
        echo "real_ip_header CF-Connecting-IP;"
    } > /etc/nginx/conf.d/cloudflare-realip.conf
    sed "s/__SERVER_NAMES__/${DOMAINS[*]}/g; s#__ROOT__#${APP_ROOT}/app/public#g" "$here/nginx-ioms.conf" > /etc/nginx/sites-available/ioms
    # IPv6 listeners only where the host has IPv6: nginx refuses to start when a
    # [::] socket cannot be created, and a Lighthouse instance may have none.
    if [[ ! -s /proc/net/if_inet6 ]]; then
        sed -i '/listen \[::\]/d' /etc/nginx/sites-available/ioms
        warn "No IPv6 on this host: IPv6 listeners omitted (Cloudflare reaches the origin over IPv4)."
    fi
fi
ln -sf /etc/nginx/sites-available/ioms /etc/nginx/sites-enabled/ioms
rm -f /etc/nginx/sites-enabled/default
nginx -t
rm -f /usr/sbin/policy-rc.d   # services may start again from here on
svc enable nginx
svc reload nginx
if [[ "$FRONT" == caddy ]]; then
    curl -s -o /dev/null -w '  nginx on 127.0.0.1:%{http_code}\n' -H "Host: ${DOMAINS[0]}" "http://127.0.0.1:${BACKEND_PORT}/" | sed "s/:/:${BACKEND_PORT} answers /" || true

    log "Caddy site for IOMS (written, NOT activated -- caddy-site.sh activates it)"
    # Caddy runs as `caddy` and needs to read the origin key: a copy it owns.
    if getent group caddy >/dev/null; then
        install -d -m 750 -o root -g caddy /etc/caddy/ioms
        install -m 644 /etc/ssl/ioms/origin.pem /etc/caddy/ioms/origin.pem
        install -m 640 -g caddy /etc/ssl/ioms/origin.key /etc/caddy/ioms/origin.key
    else
        warn "No 'caddy' group: Caddy may run as another user. caddy-site.sh checks it can read the key."
        install -d -m 755 /etc/caddy/ioms
        install -m 644 /etc/ssl/ioms/origin.pem /etc/caddy/ioms/origin.pem
        install -m 600 /etc/ssl/ioms/origin.key /etc/caddy/ioms/origin.key
    fi
    {
        echo "# IOMS -- generated by deploy/tencent/provision.sh. Activated by caddy-site.sh"
        echo "# through ONE 'import' line in the main Caddyfile; nothing else of Caddy's is changed."
        echo "$(printf '%s, ' "${DOMAINS[@]}" | sed 's/, $//') {"
        echo "    tls /etc/caddy/ioms/origin.pem /etc/caddy/ioms/origin.key"
        echo "    encode gzip"
        echo "    reverse_proxy 127.0.0.1:${BACKEND_PORT} {"
        echo "        header_up Host {host}"
        echo "    }"
        echo "    log {"
        echo "        output file /var/log/caddy/ioms-access.log"
        echo "    }"
        echo "}"
        # Explicit, because a main Caddyfile with `auto_https off` and a catch-all
        # HTTP site would otherwise answer http://<name> itself (caddy-site.sh --redirect).
        echo
        echo "# http -> https for the IOMS names (caddy-site.sh --redirect)"
        echo "$(printf 'http://%s, ' "${DOMAINS[@]}" | sed 's/, $//') {"
        echo "    redir https://{host}{uri} 308"
        echo "}"
    } > /etc/caddy/ioms.caddy
    chmod 644 /etc/caddy/ioms.caddy
    echo "  wrote /etc/caddy/ioms.caddy; review it, then: bash $here/caddy-site.sh            (read-only check)"
    echo "                                              bash $here/caddy-site.sh --apply    (validate, add import, graceful reload, verify, auto-undo)"
fi

# ---------------------------------------------------------------- worker, scheduler
log "Installing the queue worker and the scheduler"
cp "$here/supervisor-ioms-worker.conf" /etc/supervisor/conf.d/ioms-worker.conf
svc enable supervisor
svc enable cron
cp "$here/ioms.cron" /etc/cron.d/ioms && chmod 644 /etc/cron.d/ioms

# ---------------------------------------------------------------- backups, logs
install -m 750 "$here/backup.sh" /usr/local/bin/ioms-backup
install -m 750 "$here/restore-check.sh" /usr/local/bin/ioms-restore-check
cat > /etc/logrotate.d/ioms <<LR
${APP_ROOT}/app/storage/logs/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    su ${APP_USER} ${APP_USER}
}
LR

# ---------------------------------------------------------------- hardening
log "Enabling unattended security updates and fail2ban"
dpkg-reconfigure -f noninteractive unattended-upgrades
svc enable fail2ban || warn "fail2ban did not start (it needs a real syslog; fine in a container)."

if [[ $APPLY_FIREWALL -eq 1 ]]; then
    log "Applying firewall"
    if [[ -n "$SSH_FROM" ]]; then
        ufw allow from "$SSH_FROM" to any port 22 proto tcp
    else
        warn "SSH open to the world (no --ssh-from). Restrict it in the Lighthouse console at least."
        ufw allow OpenSSH
    fi
    # Web stays open until the Cloudflare cutover is verified; then
    # cloudflare-lockdown.sh restricts 80/443 to Cloudflare's ranges.
    if [[ "$FRONT" == caddy ]]; then ufw allow 80,443/tcp; else ufw allow 'Nginx Full'; fi
    ufw --force enable
    ufw status verbose
    echo "Mirror these rules in the Lighthouse console firewall (it sits in front of ufw)."
else
    echo "Firewall NOT changed (pass --apply-firewall). Current listening ports:"; ss -tlnp
fi

log "Provisioned ($FRONT mode). Next: $( [[ $FRONT == caddy ]] && echo "caddy-site.sh (check, then --apply), then ")migrate.sh app"
