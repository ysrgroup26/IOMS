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
#       --domain iomsuite.com --domain www.iomsuite.com --domain staging.iomsuite.com \
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
log "Checking that ports 80/443 are free or already nginx"
holders="$(ss -tlnpH '( sport = :80 or sport = :443 )' 2>/dev/null | grep -v nginx || true)"
if [[ -n "$holders" ]]; then
    echo "$holders" >&2
    die "Ports 80/443 are held by something other than nginx. See TENCENT-MIGRATION.md section 3 (Hermes) before changing anything."
fi

# ---------------------------------------------------------------- packages
log "Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common ca-certificates curl unzip git acl openssl \
    nginx mysql-server supervisor ufw fail2ban unattended-upgrades logrotate cron

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
log "Configuring nginx for: ${DOMAINS[*]}"
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
ln -sf /etc/nginx/sites-available/ioms /etc/nginx/sites-enabled/ioms
rm -f /etc/nginx/sites-enabled/default
nginx -t
svc enable nginx
svc reload nginx

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
    ufw allow 'Nginx Full'
    ufw --force enable
    ufw status verbose
    echo "Mirror these rules in the Lighthouse console firewall (it sits in front of ufw)."
else
    echo "Firewall NOT changed (pass --apply-firewall). Current listening ports:"; ss -tlnp
fi

log "Provisioned. Next: migrate.sh app"
