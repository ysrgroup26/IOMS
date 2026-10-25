#!/usr/bin/env bash
#
# IOMS -- provision a Tencent Cloud Lighthouse instance (Ubuntu 22.04 / 24.04)
# to run IOMS under nginx + PHP-FPM 8.3 + MySQL 8, with a queue worker, the
# scheduler, HTTPS, a firewall and nightly backups.
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
#   sudo bash provision.sh --domain staging.iomsuite.com [--apply-firewall] [--tls]
#
# Idempotent: safe to re-run.

set -euo pipefail

DOMAIN=""
APPLY_FIREWALL=0
WANT_TLS=0
APP_USER="ioms"
APP_ROOT="/srv/ioms"
DB_NAME="ioms"
DB_USER="ioms"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain) DOMAIN="$2"; shift 2 ;;
        --apply-firewall) APPLY_FIREWALL=1; shift ;;
        --tls) WANT_TLS=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
[[ -n "$DOMAIN" ]] || { echo "--domain is required (use the STAGING host first)." >&2; exit 2; }

. /etc/os-release
if [[ "${ID:-}" != "ubuntu" || ! "${VERSION_ID:-}" =~ ^(22\.04|24\.04)$ ]]; then
    echo "This script supports Ubuntu 22.04 and 24.04; found ${PRETTY_NAME:-unknown}." >&2
    echo "Inspect the instance (inspect-instance.sh) and decide before adapting it." >&2
    exit 1
fi

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

# ---------------------------------------------------------------- safety
log "Checking that ports 80/443 are free or already nginx"
holders="$(ss -tlnpH '( sport = :80 or sport = :443 )' 2>/dev/null | grep -v nginx || true)"
if [[ -n "$holders" ]]; then
    echo "Ports 80/443 are held by something other than nginx:" >&2
    echo "$holders" >&2
    echo "Refusing to continue. See TENCENT-MIGRATION.md section 3 (Hermes) before changing anything." >&2
    exit 1
fi

# ---------------------------------------------------------------- packages
log "Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common ca-certificates curl unzip git acl \
    nginx mysql-server supervisor ufw fail2ban unattended-upgrades logrotate

if [[ "$VERSION_ID" == "22.04" ]] && ! apt-cache policy php8.3-fpm | grep -qE 'Candidate: [0-9]'; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -y
fi

apt-get install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-zip \
    php8.3-gd php8.3-curl php8.3-intl php8.3-bcmath php8.3-opcache php8.3-gmp

if ! command -v composer >/dev/null 2>&1; then
    log "Installing Composer (checksum-verified)"
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [[ "$expected" == "$actual" ]] || { echo "Composer installer checksum mismatch." >&2; exit 1; }
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

# ---------------------------------------------------------------- memory
# 4 GB is enough for IOMS + MySQL; a swap file keeps a composer install or a
# large PDF render from being OOM-killed while Hermes shares the box.
if ! swapon --show | grep -q .; then
    log "Adding a 2 GB swap file"
    fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# ---------------------------------------------------------------- user, dirs
log "Creating the ${APP_USER} user and ${APP_ROOT}"
id -u "$APP_USER" >/dev/null 2>&1 || adduser --system --group --home "$APP_ROOT" --shell /bin/bash "$APP_USER"
usermod -aG "$APP_USER" www-data
mkdir -p "$APP_ROOT" /var/backups/ioms
chown "$APP_USER:$APP_USER" "$APP_ROOT"
chmod 750 /var/backups/ioms

# ---------------------------------------------------------------- database
log "Creating database ${DB_NAME} and user ${DB_USER} (password goes to /root/ioms-db.cnf only)"
if [[ ! -f /root/ioms-db.cnf ]]; then
    pass="$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-32)"
    umask 077
    printf '[client]\nuser=%s\npassword=%s\ndatabase=%s\n' "$DB_USER" "$pass" "$DB_NAME" > /root/ioms-db.cnf
    mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${pass}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
-- the scratch database restore-check.sh proves backups against
GRANT ALL PRIVILEGES ON \`ioms_restore_check\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
    unset pass
fi
# MySQL listens on localhost only. Never expose 3306.
sed -i 's/^\s*bind-address\s*=.*/bind-address = 127.0.0.1/' /etc/mysql/mysql.conf.d/mysqld.cnf || true

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
pool=/etc/php/8.3/fpm/pool.d/www.conf
sed -i -E 's/^pm.max_children = .*/pm.max_children = 12/; s/^pm.start_servers = .*/pm.start_servers = 3/; s/^pm.min_spare_servers = .*/pm.min_spare_servers = 2/; s/^pm.max_spare_servers = .*/pm.max_spare_servers = 5/' "$pool"
systemctl restart php8.3-fpm

# ---------------------------------------------------------------- nginx
log "Configuring nginx for ${DOMAIN}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
sed "s/__DOMAIN__/${DOMAIN}/g; s#__ROOT__#${APP_ROOT}/app/public#g" "$here/nginx-ioms.conf" > /etc/nginx/sites-available/ioms
ln -sf /etc/nginx/sites-available/ioms /etc/nginx/sites-enabled/ioms
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# ---------------------------------------------------------------- worker, scheduler
log "Installing the queue worker and the scheduler"
cp "$here/supervisor-ioms-worker.conf" /etc/supervisor/conf.d/ioms-worker.conf
supervisorctl reread && supervisorctl update || true
cp "$here/ioms.cron" /etc/cron.d/ioms && chmod 644 /etc/cron.d/ioms

# ---------------------------------------------------------------- backups, logs
install -m 750 "$here/backup.sh" /usr/local/bin/ioms-backup
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
systemctl enable --now fail2ban

if [[ $APPLY_FIREWALL -eq 1 ]]; then
    log "Applying firewall: SSH, HTTP, HTTPS only"
    ufw allow OpenSSH
    ufw allow 'Nginx Full'
    ufw --force enable
    ufw status verbose
    echo "Also mirror these rules in the Lighthouse console firewall (it sits in front of ufw)."
else
    echo "Firewall NOT changed (pass --apply-firewall). Current listening ports:"; ss -tlnp
fi

if [[ $WANT_TLS -eq 1 ]]; then
    log "Requesting a Let's Encrypt certificate for ${DOMAIN}"
    apt-get install -y certbot python3-certbot-nginx
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect -m "support@iomsuite.com"
fi

log "Provisioned. Next: clone into '${APP_ROOT}/app' as ${APP_USER}, write .env, run ./deploy.sh --first, then the checks in TENCENT-MIGRATION.md section 6."
