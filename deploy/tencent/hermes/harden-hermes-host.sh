#!/usr/bin/env bash
#
# Take root away from Hermes Agent WITHOUT moving or reinstalling it.
#
# On the Tencent instance Hermes runs as `ubuntu`, and `ubuntu` has
# passwordless sudo (cloud-init's /etc/sudoers.d/90-cloud-init-users). That
# makes Hermes root: no file mode, no ioms-ops allow-list, no 750 directory
# constrains it. This script must run BEFORE any IOMS secret or production
# data is placed on the host.
#
#   sudo bash harden-hermes-host.sh --admin-user iomsadmin          # run as ubuntu, once
#   sudo bash harden-hermes-host.sh --undo /root/hermes-hardening-<stamp>   # as iomsadmin
#
# ORDER, because the person running this is usually ALSO logged in as ubuntu:
#   1. create a separate HUMAN admin (password-protected sudo), copy ubuntu's
#      SSH keys to it, and VERIFY it before anything is removed;
#   2. back up every sudoers file and ubuntu's groups;
#   3. disable ubuntu's sudo rules and remove it from sudo/admin/wheel/lxd/docker,
#      validated by visudo (restored automatically if validation fails);
#   4. give ubuntu exactly one command, ioms-ops (install-ops-guard.sh);
#   5. restart Hermes so its running process drops the old groups, and prove
#      from /proc that it no longer carries them.
#
# What Hermes keeps: its user, home, configuration, keys and processes. What it
# loses: root. Its operational work goes through `sudo ioms-ops` (status,
# logs, health, backup; deploy/restart/rollback only after a person approves).

set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$here/../lib.sh"

AGENT="ubuntu"
ADMIN="iomsadmin"
UNDO=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --agent-user) AGENT="$2"; shift 2 ;;
        --admin-user) ADMIN="$2"; shift 2 ;;
        --undo) UNDO="$2"; shift 2 ;;
        *) die "Unknown option: $1" ;;
    esac
done
[[ $EUID -eq 0 ]] || die "Run as root (sudo)."
id -u "$AGENT" >/dev/null 2>&1 || die "No such user: $AGENT"

# adm reads /var/log, whose nginx access log carries order-status tokens.
PRIV_GROUPS=(sudo admin wheel lxd docker adm)

hermes_pids() { pgrep -u "$AGENT" -f -i hermes 2>/dev/null || true; }
groups_of_pid() { awk '/^Groups:/ {$1=""; print}' "/proc/$1/status" 2>/dev/null; }
gid_of() { getent group "$1" | cut -d: -f3; }

# ------------------------------------------------------------------ undo
if [[ -n "$UNDO" ]]; then
    [[ -d "$UNDO" ]] || die "No backup at $UNDO"
    log "Restoring sudoers and groups from $UNDO"
    cp -a "$UNDO/sudoers" /etc/sudoers
    rm -rf /etc/sudoers.d && cp -a "$UNDO/sudoers.d" /etc/sudoers.d
    while read -r g; do [[ -n "$g" ]] && usermod -aG "$g" "$AGENT"; done < "$UNDO/groups"
    visudo -c >/dev/null || die "visudo failed after restore -- inspect /etc/sudoers.d now."
    echo "Restored. $AGENT groups: $(id -nG "$AGENT"). Restart Hermes for its process to regain them."
    exit 0
fi

# ------------------------------------------------------------------ 0. look first
log "Current state"
echo "  $AGENT groups:        $(id -nG "$AGENT")"
echo "  $AGENT sudo rules:"; sudo -l -U "$AGENT" 2>/dev/null | sed -n '/may run/,$p' | sed 's/^/    /'
pids="$(hermes_pids)"
echo "  Hermes processes:    ${pids:-none found} $( [[ -n $pids ]] && echo "($(wc -w <<<"$pids"))")"
sys_units="$(grep -rlis hermes /etc/systemd/system /lib/systemd/system 2>/dev/null | grep -E '\.service$' || true)"
user_units="$(grep -rlis hermes "$(getent passwd "$AGENT" | cut -d: -f6)/.config/systemd/user" 2>/dev/null | grep -E '\.service$' || true)"
echo "  system units:        ${sys_units:-none}"
echo "  user units:          ${user_units:-none}"
for u in $sys_units $user_units; do
    if grep -qiE '^\s*ExecStart=.*\bsudo\b' "$u"; then
        die "$u starts Hermes THROUGH sudo. Removing sudo would stop Hermes from starting. Change that unit by hand first."
    fi
    if [[ "$u" == /etc/* || "$u" == /lib/* ]] && ! grep -qiE "^\s*User=$AGENT\b" "$u"; then
        warn "$u has no User=$AGENT: it may run Hermes as ROOT, which this script cannot constrain. Review it."
    fi
done

# ------------------------------------------------------------------ 1. a human admin, verified
log "Human administrator: $ADMIN"
if ! id -u "$ADMIN" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "IOMS administrator (human)" "$ADMIN" >/dev/null
fi
usermod -aG sudo "$ADMIN"
chmod 700 "$(getent passwd "$ADMIN" | cut -d: -f6)"
if [[ "$(passwd -S "$ADMIN" | awk '{print $2}')" != "P" ]]; then
    echo "Set a password for $ADMIN. sudo will ask for it; it is what stands between Hermes and root."
    if [[ -t 0 ]]; then
        passwd "$ADMIN"
    else
        IFS= read -r p1; IFS= read -r p2
        [[ -n "$p1" && "$p1" == "$p2" ]] || die "Passwords empty or different."
        printf '%s:%s\n' "$ADMIN" "$p1" | chpasswd; unset p1 p2
    fi
fi
akeys="$(getent passwd "$AGENT" | cut -d: -f6)/.ssh/authorized_keys"
if [[ -s "$akeys" ]]; then
    install -d -m 700 -o "$ADMIN" -g "$ADMIN" "$(getent passwd "$ADMIN" | cut -d: -f6)/.ssh"
    install -m 600 -o "$ADMIN" -g "$ADMIN" "$akeys" "$(getent passwd "$ADMIN" | cut -d: -f6)/.ssh/authorized_keys"
    echo "  copied $AGENT's SSH keys to $ADMIN"
fi
[[ "$(passwd -S "$ADMIN" | awk '{print $2}')" == "P" ]] || die "$ADMIN has no usable password; refusing to remove $AGENT's sudo."
id -nG "$ADMIN" | tr ' ' '\n' | grep -qx sudo || die "$ADMIN is not in the sudo group."
sudo -l -U "$ADMIN" | grep -qE '\(ALL( : ALL)?\) ALL' || die "$ADMIN does not get sudo (ALL) from the sudo group; refusing."
echo "  ok    $ADMIN has a password and full sudo (password required)"
echo "  BEFORE CONTINUING, keep this session open, and in a SECOND OrcaTerm tab run:"
echo "        su - $ADMIN -c 'sudo -v && echo ADMIN-OK'"
if [[ -t 0 ]]; then
    read -r -p "  Type YES once that printed ADMIN-OK: " ok
    [[ "$ok" == YES ]] || die "Stopped before changing anything for $AGENT."
fi

# ------------------------------------------------------------------ 2. back up
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="/root/hermes-hardening-$STAMP"
install -d -m 700 "$BK"
cp -a /etc/sudoers "$BK/sudoers"
cp -a /etc/sudoers.d "$BK/sudoers.d"
id -nG "$AGENT" | tr ' ' '\n' | grep -xE "$(IFS='|'; echo "${PRIV_GROUPS[*]}")" > "$BK/groups" || true
log "Backed up sudoers and $AGENT's privileged groups to $BK"

# ------------------------------------------------------------------ 3. remove root
log "Removing $AGENT's sudo"
for f in /etc/sudoers /etc/sudoers.d/*; do
    [[ -f "$f" && "$f" != /etc/sudoers.d/ioms-ops ]] || continue
    if grep -qE "^\s*$AGENT\s" "$f"; then
        sed -i -E "s/^(\s*$AGENT\s.*)$/# disabled by harden-hermes-host $STAMP: \1/" "$f"
        echo "  disabled $AGENT rule(s) in $f"
    fi
done
for g in "${PRIV_GROUPS[@]}"; do
    if id -nG "$AGENT" | tr ' ' '\n' | grep -qx "$g"; then gpasswd -d "$AGENT" "$g" >/dev/null && echo "  removed from group $g"; fi
done
rm -rf "/run/sudo/ts/$AGENT" "/var/run/sudo/ts/$AGENT" 2>/dev/null || true
if ! visudo -c >/dev/null; then
    cp -a "$BK/sudoers" /etc/sudoers; rm -rf /etc/sudoers.d; cp -a "$BK/sudoers.d" /etc/sudoers.d
    die "visudo rejected the result; the backup was restored and nothing changed."
fi

# ------------------------------------------------------------------ 4. one command
log "Granting $AGENT exactly ioms-ops"
bash "$here/install-ops-guard.sh" --hermes-user "$AGENT" || true

# ------------------------------------------------------------------ 5. restart Hermes, prove it
log "Restarting Hermes so its process drops the old groups"
restarted=0
for u in $sys_units; do systemctl restart "$(basename "$u")" && restarted=1; done
if [[ -n "$user_units" ]]; then
    uid="$(id -u "$AGENT")"
    for u in $user_units; do
        sudo -u "$AGENT" XDG_RUNTIME_DIR="/run/user/$uid" systemctl --user restart "$(basename "$u")" && restarted=1
    done
fi
[[ $restarted -eq 1 ]] && sleep 5
sudo_gid="$(gid_of sudo)"; stale=0
for p in $(hermes_pids); do
    if [[ -n "$sudo_gid" ]] && groups_of_pid "$p" | tr ' ' '\n' | grep -qx "$sudo_gid"; then
        echo "  STALE  pid $p ($(ps -o cmd= -p "$p" | cut -c1-60)) still carries the sudo group"; stale=1
    fi
done
# A process started before this keeps the sudo group until it restarts. That
# matters only if the account has a password sudo could accept.
pwstate="$(passwd -S "$AGENT" | awk '{print $2}')"
if [[ $stale -eq 1 && "$pwstate" == P ]]; then
    warn "$AGENT HAS A PASSWORD: a Hermes process still carrying the sudo group could use it until restarted. Restart Hermes NOW."
elif [[ $stale -eq 1 ]]; then
    echo "  note  $AGENT's password is locked ($pwstate), so a stale sudo group cannot be used; restart Hermes anyway to clear it"
fi
if [[ -z "$(hermes_pids)" ]]; then
    warn "No Hermes process is running now. Start it the way it was started before (it keeps all its files)."
elif [[ $stale -eq 1 ]]; then
    warn "Restart those processes (they were not started by a unit this script can see). Until then they keep the old groups -- though with the NOPASSWD rule gone, sudo still asks a password $AGENT does not have."
else
    echo "  ok    Hermes is running and its processes no longer carry the sudo group"
fi

log "Result"
echo "  $AGENT may run:"; sudo -l -U "$AGENT" 2>/dev/null | sed -n '/may run/,$p' | sed 's/^/    /'
echo "  Undo (as $ADMIN):  sudo bash $0 --undo $BK"
echo "  From now on, administer as $ADMIN:  su - $ADMIN   (then sudo ...)"
