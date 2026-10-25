#!/usr/bin/env bash
#
# IOMS -- READ-ONLY inspection of the Tencent Cloud Lighthouse instance.
#
# Run this FIRST, before provision.sh, and before anything is installed,
# removed, reset or reinstalled. It changes nothing: no package is
# installed, no service is started or stopped, no file outside the report
# is written. It answers the questions docs/runbooks/TENCENT-MIGRATION.md
# section 2 asks -- what OS, what is running, what Hermes Agent actually is
# on THIS machine, what it listens on and what it holds -- so a decision
# about the instance is made from evidence rather than from the purchase
# page.
#
#   sudo bash inspect-instance.sh > inspection-$(hostname)-$(date +%F).txt 2>&1
#
# SECRETS: the report lists file NAMES and permissions for .env / config /
# credential files and never prints their contents. Read the report before
# sharing it anyway; a process command line can carry a token.

set -u
section() { printf '\n==================== %s ====================\n' "$1"; }
try() { echo "\$ $*"; "$@" 2>&1 || echo "(exit $?)"; }

section "IDENTITY"
try date -u
try hostnamectl
try cat /etc/os-release
try uname -a
try uptime

section "CAPACITY"
try nproc
try free -h
try df -hT -x tmpfs -x devtmpfs
try swapon --show
try lsblk

section "LISTENING PORTS (who answers on the public IP)"
try ss -tulpn

section "SERVICES"
try systemctl list-units --type=service --state=running --no-pager
try systemctl list-unit-files --state=enabled --no-pager
try systemctl list-timers --all --no-pager

section "CONTAINERS"
if command -v docker >/dev/null 2>&1; then
    try docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'
    try docker images
    try docker volume ls
    try docker system df
else
    echo "docker: not installed"
fi

section "HERMES AGENT -- WHERE IT IS AND WHAT IT RUNS AS"
try ps -eo user,pid,ppid,%cpu,%mem,rss,etime,cmd --sort=-rss | head -40
echo "--- processes whose command line mentions hermes"
ps -eo user,pid,etime,cmd | grep -i '[h]ermes' || echo "(none)"
echo "--- systemd units mentioning hermes"
systemctl list-units --all --no-pager 2>/dev/null | grep -i hermes || echo "(none)"
for unit in $(systemctl list-unit-files --no-pager 2>/dev/null | awk '/hermes/ {print $1}'); do
    try systemctl cat "$unit"
done
echo "--- directories named *hermes* (names and sizes only)"
find / -xdev -maxdepth 4 -iname '*hermes*' -not -path '/proc/*' 2>/dev/null | while read -r p; do
    du -sh "$p" 2>/dev/null
done
echo "--- credential-looking files near hermes (NAMES ONLY, never contents)"
find / -xdev -maxdepth 5 \( -path '*hermes*' \) \( -name '.env' -o -name '*.env' -o -name 'config.yaml' -o -name '*.json' -o -name 'auth*' \) 2>/dev/null \
    | head -50 | xargs -r ls -l 2>/dev/null
echo "--- does hermes have a shell / terminal tool enabled? (grep for tool names, no values)"
find / -xdev -maxdepth 5 -path '*hermes*' -name 'config.yaml' 2>/dev/null | while read -r f; do
    echo "## $f"; grep -nE '^\s*(toolsets?|terminal|shell|backend|enabled_tools|model|provider)\b' "$f" 2>/dev/null | sed -E 's/(key|token|secret)(\s*[:=]\s*).*/\1\2[REDACTED]/I'
done

section "OUTBOUND CONNECTIONS (what this box talks to right now)"
try ss -tnp state established

section "ACCOUNTS AND ACCESS"
try getent passwd | awk -F: '$3 >= 1000 || $1 == "root" {print $1, $3, $6, $7}'
try ls -la /root/.ssh /home/*/.ssh
try grep -E '^(PermitRootLogin|PasswordAuthentication|PubkeyAuthentication|Port)' /etc/ssh/sshd_config
try last -n 20

section "SCHEDULED WORK"
try crontab -l
for u in $(getent passwd | awk -F: '$3 >= 1000 {print $1}'); do echo "## crontab $u"; crontab -l -u "$u" 2>&1; done
try ls -la /etc/cron.d

section "FIREWALL"
try ufw status verbose
try iptables -S
try nft list ruleset

section "SOFTWARE ALREADY PRESENT"
for b in nginx apache2 httpd php php-fpm8.3 mysql mysqld mariadb redis-server node npm python3 pip3 composer certbot supervisorctl; do
    printf '%-14s ' "$b"; command -v "$b" >/dev/null 2>&1 && "$b" --version 2>&1 | head -1 || echo "absent"
done

section "LARGEST DIRECTORIES (what holds the disk)"
du -xh --max-depth=2 / 2>/dev/null | sort -rh | head -25

echo
echo "Inspection complete. Nothing was changed."
