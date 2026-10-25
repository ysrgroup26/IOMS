#!/usr/bin/env bash
#
# Install the controlled-operations path for Hermes Agent on the IOMS host.
#
#   sudo bash install-ops-guard.sh --hermes-user <user-hermes-runs-as>
#
# Find <user> in inspect-instance.sh's "HERMES AGENT" section (the USER
# column of its processes, or User= in its systemd unit).
#
# What it does:
#   - installs /usr/local/bin/ioms-ops (root:root 755) and the health check;
#   - writes /etc/sudoers.d/ioms-ops granting the Hermes user that ONE
#     command, validated with visudo before it is put in place;
#   - creates the append-only audit log /var/log/ioms-ops.log;
#   - CHECKS the Hermes user's existing privileges and reports every one
#     that would defeat the guard (root, sudo/admin group, ioms group,
#     docker group, broad sudoers rules, read access to .env).
#
# What it does NOT do: change the Hermes user's groups, remove any sudo
# right it already has, or touch Hermes' own configuration. Those are
# reported for a person to decide, because removing them may break how the
# image runs Hermes.

set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$here/../lib.sh"

HUSER=""
while [[ $# -gt 0 ]]; do
    case "$1" in --hermes-user) HUSER="$2"; shift 2 ;; *) die "Unknown option: $1" ;; esac
done
[[ $EUID -eq 0 ]] || die "Run as root."
[[ -n "$HUSER" ]] || die "--hermes-user is required."
id -u "$HUSER" >/dev/null 2>&1 || die "No such user: $HUSER"

log "Installing ioms-ops"
install -m 755 -o root -g root "$here/ioms-ops" /usr/local/bin/ioms-ops
install -d -m 755 /usr/local/lib/ioms-ops
install -m 755 -o root -g root "$here/../healthcheck.sh" /usr/local/lib/ioms-ops/healthcheck.sh
printf 'HERMES_USER=%q\nDEFAULT_URL=%q\n' "$HUSER" "https://iomsuite.com" > /etc/ioms-ops.conf
chmod 644 /etc/ioms-ops.conf
install -d -m 750 /var/lib/ioms-ops /var/lib/ioms-ops/requests
touch /var/log/ioms-ops.log; chmod 640 /var/log/ioms-ops.log
if chattr +a /var/log/ioms-ops.log 2>/dev/null; then
    echo "  audit log is append-only (chattr +a): entries cannot be edited or removed, even through ioms-ops"
else
    warn "chattr +a not supported on this filesystem; the audit log is root-only but not append-only."
fi

log "Granting $HUSER exactly one sudo command"
tmp="$(mktemp)"
cat > "$tmp" <<SUDO
# Managed by deploy/tencent/hermes/install-ops-guard.sh. Hermes Agent may run
# ioms-ops as root and nothing else. Do not widen this line; add a subcommand
# to ioms-ops instead, where it is validated and audited.
Defaults:$HUSER !requiretty, env_reset, secure_path="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
$HUSER ALL=(root) NOPASSWD: /usr/local/bin/ioms-ops
SUDO
visudo -cf "$tmp" >/dev/null || die "The generated sudoers file failed visudo; nothing installed."
install -m 440 -o root -g root "$tmp" /etc/sudoers.d/ioms-ops; rm -f "$tmp"

log "Auditing what $HUSER can already do"
risk=0
flag() { echo "  RISK  $*"; risk=1; }
[[ "$(id -u "$HUSER")" -eq 0 ]] && flag "$HUSER IS ROOT. Nothing above constrains it. Run Hermes under its own unprivileged user."
for g in sudo admin wheel docker lxd "${IOMS_APP_USER:-ioms}" www-data adm; do
    id -nG "$HUSER" | tr ' ' '\n' | grep -qx "$g" && flag "$HUSER is in group '$g'$( [[ $g == docker || $g == lxd ]] && echo ' (equivalent to root)')"
done
other_rules="$(grep -rEl "^[^#]*\b$HUSER\b" /etc/sudoers /etc/sudoers.d 2>/dev/null | grep -v '/etc/sudoers.d/ioms-ops' || true)"
[[ -n "$other_rules" ]] && flag "$HUSER has other sudoers rules in: $other_rules"
if sudo -u "$HUSER" test -r /srv/ioms/app/.env 2>/dev/null; then flag "$HUSER can READ /srv/ioms/app/.env (database, mail and payment secrets)"; fi
if sudo -u "$HUSER" test -r /root/ioms-db.cnf 2>/dev/null; then flag "$HUSER can read /root/ioms-db.cnf"; fi
sudo -u "$HUSER" test -r /var/backups/ioms 2>/dev/null && flag "$HUSER can read /var/backups/ioms (database dumps)"
[[ $risk -eq 0 ]] && echo "  ok    $HUSER has no privilege beyond ioms-ops, and cannot read IOMS secrets or backups."

echo
echo "Hermes runs:   sudo ioms-ops status | logs app 200 | health | request deploy | run <id>"
echo "A person runs: sudo ioms-ops pending | approve <id> | deny <id>"
exit $risk
