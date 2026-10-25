#!/usr/bin/env bash
#
# Finish what stage 1 left open on the Tencent instance, as iomsadmin:
#
#   sudo bash /opt/ioms-kit/deploy/tencent/hermes/finish-hardening.sh            # audit + safe fixes
#   sudo bash ... finish-hardening.sh --acli disable|keep                         # decide acli explicitly
#
# 1. AUDIT every sudo rule that still reaches the agent account -- including
#    /etc/sudoers.d/acli -- BEFORE changing anything. A rule is disabled
#    automatically only when it is ROOT-EQUIVALENT (ALL, a shell, an
#    interpreter, an editor, a package or service manager, a file-writing
#    tool, a wildcard, or a binary the agent can modify). Anything else is
#    shown and left alone until you pass --acli disable|keep.
# 2. RESTART HERMES THE RIGHT WAY. hermes-gateway.service is a user unit;
#    a new process inherits the supplementary groups of the USER MANAGER
#    (user@UID.service), which was started while ubuntu was still in `sudo`.
#    So `systemctl --user restart` cannot clear them. This restarts the user
#    manager from the system manager, waits for hermes-gateway to come back,
#    proves the old PIDs are gone, and reads /proc to prove no process of
#    the new manager carries sudo/adm. Login sessions (OrcaTerm) live in
#    session-*.scope and are not touched.
# 3. Confirms iomsadmin still has working administrator access, and prints
#    the recovery paths.
# 4. Installs the current ioms-ops (autonomous routine operations).

set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$here/../lib.sh"

AGENT=ubuntu ADMIN=iomsadmin UNIT=hermes-gateway.service ACLI_DECISION="" AUDIT_ONLY=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --agent-user) AGENT="$2"; shift 2 ;; --admin-user) ADMIN="$2"; shift 2 ;;
        --unit) UNIT="$2"; shift 2 ;; --acli) ACLI_DECISION="$2"; shift 2 ;;
        --audit-only) AUDIT_ONLY=1; shift ;;
        *) die "Unknown option: $1" ;;
    esac
done
[[ $EUID -eq 0 ]] || die "Run as root (sudo, as $ADMIN)."
[[ "${SUDO_USER:-}" != "$AGENT" ]] || die "Run this as $ADMIN, not as the agent account."
[[ -z "$ACLI_DECISION" || "$ACLI_DECISION" =~ ^(disable|keep)$ ]] || die "--acli takes disable or keep"
UID_A="$(id -u "$AGENT")"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="/root/hermes-finish-$STAMP"; install -d -m 700 "$BK"

# ================================================================== 1. sudo audit
log "1. Every sudo rule that still reaches $AGENT"
sudo -l -U "$AGENT" | sed -n '/may run/,$p' | sed 's/^/   /'

# Root-equivalent if the command list matches any of these, or the binary is
# writable by the agent.
ROOTISH='(^|[ ,])(ALL|\*|/bin/(ba|da|z|k|c|tc|fi)?sh|/usr/bin/(ba|da|z|fi)?sh|.*/(python|python3|perl|ruby|php|node|lua|awk|gawk|vi|vim|nano|emacs|less|more|man|find|tee|cp|mv|dd|chmod|chown|install|rsync|tar|zip|unzip|env|sudo|su|systemctl|service|apt|apt-get|dpkg|snap|pip|pip3|npm|docker|git|crontab|mount|journalctl|nsenter|chroot|bash|sh|ssh|scp|curl|wget|openssl))( |,|$)'

agent_groups=" $(id -nG "$AGENT") "
applies_to_agent() { # first token of a user spec
    local who="$1"
    [[ "$who" == "$AGENT" || "$who" == ALL || "$who" == "#$UID_A" ]] && return 0
    [[ "$who" == %* && "$agent_groups" == *" ${who#%} "* ]] && return 0
    return 1
}

declare -a ROOTISH_LINES=() OTHER_LINES=()
for f in /etc/sudoers /etc/sudoers.d/*; do
    [[ -f "$f" && "$f" != /etc/sudoers.d/ioms-ops ]] || continue
    # User_Alias names that contain the agent
    aliases="$(grep -E '^\s*User_Alias' "$f" 2>/dev/null | grep -wE "$AGENT" | sed -E 's/^\s*User_Alias\s+([A-Z0-9_]+).*/\1/' || true)"
    # Every line, including an UNTERMINATED last line (`read` alone drops it --
    # which is how a one-rule vendor file like acli got no verdict at all),
    # with backslash continuations joined.
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*($|#|Defaults|User_Alias|Cmnd_Alias|Host_Alias|Runas_Alias|@include|#include) ]] && continue
        who="$(awk '{print $1}' <<<"$line")"
        if ! { applies_to_agent "$who" || grep -qwx "$who" <<<"$aliases"; }; then
            echo "   does not reach $AGENT  ${f}:  $line   (granted to '$who')"
            continue
        fi
        if true; then
            cmds="${line#*:}"; [[ "$line" == *:* ]] || cmds="$(cut -d= -f2- <<<"$line")"
            verdict=other
            if grep -qE "$ROOTISH" <<<" $cmds"; then verdict=rootish; fi
            for bin in $(grep -oE '/[^ ,]+' <<<"$cmds"); do
                [[ -e "$bin" ]] && sudo -u "$AGENT" test -w "$bin" && verdict=rootish
                [[ -e "$bin" ]] && sudo -u "$AGENT" test -w "$(dirname "$bin")" && verdict=rootish
            done
            if [[ $verdict == rootish ]]; then ROOTISH_LINES+=("$f|$line"); else OTHER_LINES+=("$f|$line"); fi
        fi
    done < <(sed -e ':a' -e '/\\$/N; s/\\\n//; ta' "$f")
done

if [[ -f /etc/sudoers.d/acli ]]; then
    echo; echo "   /etc/sudoers.d/acli (full content -- sudoers files hold no secrets):"
    sed 's/^/     | /' /etc/sudoers.d/acli; [[ -z "$(tail -c1 /etc/sudoers.d/acli)" ]] || echo "     (the last line has no trailing newline)"
    for bin in $(grep -vE '^\s*#' /etc/sudoers.d/acli | grep -oE '/[^ ,]+' | sort -u); do
        [[ -e "$bin" ]] || continue
        echo "     binary $bin: $(stat -c '%U:%G %a' "$bin"), package: $(dpkg -S "$bin" 2>/dev/null | cut -d: -f1 || echo none), type: $(file -b "$bin" | cut -c1-50)"
    done
fi

for e in "${ROOTISH_LINES[@]}"; do echo "   ROOT-EQUIVALENT  ${e%%|*}:  ${e#*|}"; done
for e in "${OTHER_LINES[@]}"; do echo "   NEEDS DECISION   ${e%%|*}:  ${e#*|}"; done

# The ground truth is what sudo itself grants, not what this parser understood.
granted_beyond_ops() { sudo -l -U "$AGENT" | sed -n '/may run/,$p' | tail -n +2 | grep -v '/usr/local/bin/ioms-ops' | grep -vE '^\s*$' || true; }

if [[ $AUDIT_ONLY -eq 1 ]]; then
    extra="$(granted_beyond_ops)"
    if [[ -z "$extra" ]]; then echo; echo "   VERDICT: only ioms-ops reaches $AGENT. No change needed."; exit 0; fi
    echo; echo "   VERDICT: sudo still grants $AGENT beyond ioms-ops:"; sed 's/^/     /' <<<"$extra"
    exit 3
fi

disable_line() { # file line
    local f="$1" line="$2"
    cp -a "$f" "$BK/$(basename "$f").bak"
    LINE="$line" TAG="# disabled by finish-hardening $STAMP: " awk '$0==ENVIRON["LINE"] {print ENVIRON["TAG"] $0; next} {print}' "$f" > "$f.new"
    chmod --reference="$f" "$f.new"; chown --reference="$f" "$f.new"; mv "$f.new" "$f"
}

if [[ ${#ROOTISH_LINES[@]} -gt 0 || ( ${#OTHER_LINES[@]} -gt 0 && "$ACLI_DECISION" == disable ) ]]; then
    cp -a /etc/sudoers "$BK/sudoers"; cp -a /etc/sudoers.d "$BK/sudoers.d"
    for e in "${ROOTISH_LINES[@]}"; do disable_line "${e%%|*}" "${e#*|}"; echo "   disabled (root-equivalent): ${e#*|}"; done
    if [[ "$ACLI_DECISION" == disable ]]; then
        for e in "${OTHER_LINES[@]}"; do disable_line "${e%%|*}" "${e#*|}"; echo "   disabled (your decision): ${e#*|}"; done
    fi
    if ! visudo -c >/dev/null; then
        rm -rf /etc/sudoers.d; cp -a "$BK/sudoers.d" /etc/sudoers.d; cp -a "$BK/sudoers" /etc/sudoers
        die "visudo rejected the change; restored from $BK. Nothing changed."
    fi
    rm -rf "/run/sudo/ts/$AGENT" 2>/dev/null || true
fi
if [[ ${#OTHER_LINES[@]} -gt 0 && -z "$ACLI_DECISION" ]]; then
    warn "Rules above marked NEEDS DECISION are not root-equivalent by inspection and were LEFT IN PLACE."
    warn "Re-run with --acli disable (remove them) or --acli keep (record that you reviewed them)."
fi

echo; echo "   $AGENT may now run:"; sudo -l -U "$AGENT" | sed -n '/may run/,$p' | sed 's/^/     /'
extra="$(granted_beyond_ops)"
if [[ -n "$extra" ]]; then
    if [[ "$ACLI_DECISION" == keep ]]; then
        sha="$(cat /etc/sudoers.d/* 2>/dev/null | sha256sum | cut -c1-16)"
        echo "$(date -Is) $SUDO_USER kept for $AGENT (sudoers.d sha $sha): $(tr '\n' ';' <<<"$extra")" >> /root/ioms-sudo-review.log
        warn "Kept by your decision, recorded in /root/ioms-sudo-review.log: $extra"
    else
        echo "$extra" | sed 's/^/   STILL GRANTED  /'
        die "$AGENT can still run the above as root. Decide: re-run with --acli disable or --acli keep. Nothing after this step was run."
    fi
fi

# ================================================================== 2. restart Hermes properly
log "2. Restarting Hermes through its user manager"
sudo_gid="$(getent group sudo | cut -d: -f3)"; adm_gid="$(getent group adm | cut -d: -f3)"
has_priv_group() { awk '/^Groups:/' "/proc/$1/status" 2>/dev/null | tr ' \t' '\n\n' | grep -qxE "${sudo_gid}|${adm_gid}"; }
uctl() { systemctl --user -M "$AGENT@" "$@"; }

if ! has_systemd; then
    warn "No systemd here (container rehearsal): skipping the user-manager restart."
else
    mgr_old="$(systemctl show "user@$UID_A.service" -p MainPID --value)"
    svc_old="$(uctl show "$UNIT" -p MainPID --value 2>/dev/null || echo 0)"
    linger="$(loginctl show-user "$AGENT" -p Linger --value 2>/dev/null || echo unknown)"
    enabled="$(uctl is-enabled "$UNIT" 2>/dev/null || echo unknown)"
    echo "   user manager pid $mgr_old $(has_priv_group "$mgr_old" && echo '(carries sudo/adm)' || echo '(clean)')"
    echo "   $UNIT pid $svc_old, enabled: $enabled, linger: $linger"
    [[ "$enabled" == enabled ]] || warn "$UNIT is not enabled; it will be started explicitly after the manager restart."

    if [[ "$mgr_old" != 0 ]] && has_priv_group "$mgr_old"; then
        systemctl restart "user@$UID_A.service"
        for _ in $(seq 1 30); do uctl is-active "$UNIT" >/dev/null 2>&1 && break; sleep 2; done
        uctl is-active "$UNIT" >/dev/null 2>&1 || uctl start "$UNIT" || true
        for _ in $(seq 1 15); do uctl is-active "$UNIT" >/dev/null 2>&1 && break; sleep 2; done
    fi

    mgr_new="$(systemctl show "user@$UID_A.service" -p MainPID --value)"
    svc_new="$(uctl show "$UNIT" -p MainPID --value 2>/dev/null || echo 0)"
    echo "   user manager pid $mgr_old -> $mgr_new, $UNIT pid $svc_old -> $svc_new ($(uctl is-active "$UNIT" 2>/dev/null))"
    for p in $mgr_old $svc_old; do [[ "$p" != 0 && "$p" != "$mgr_new" && "$p" != "$svc_new" ]] && kill -0 "$p" 2>/dev/null && warn "old pid $p is still alive"; done
    bad=0
    for p in $(systemd-cgls --no-pager -u "user@$UID_A.service" 2>/dev/null | grep -oE '[0-9]{2,}' | sort -u); do
        [[ -d /proc/$p ]] && has_priv_group "$p" && { echo "   STILL PRIVILEGED pid $p: $(ps -o cmd= -p "$p" | cut -c1-60)"; bad=1; }
    done
    [[ $bad -eq 0 && "$(uctl is-active "$UNIT" 2>/dev/null)" == active ]] \
        && echo "   ok    Hermes is running under a fresh user manager; no process in it carries sudo/adm" \
        || warn "Hermes is not active, or a process still carries sudo/adm -- see above. Journal: journalctl --user -M $AGENT@ -u $UNIT -n 50"
    others="$(pgrep -u "$AGENT" | while read -r p; do has_priv_group "$p" && echo "$p"; done | tr '\n' ' ')"
    [[ -n "$others" ]] && echo "   note  pids $others (login sessions, outside the user manager) still carry the old groups until they log out; ubuntu's password is $(passwd -S "$AGENT" | awk '{print $2}') so sudo cannot be used from them."
fi

# ================================================================== 3. admin access
log "3. Administrator access"
[[ "$(passwd -S "$ADMIN" | awk '{print $2}')" == P ]] && id -nG "$ADMIN" | tr ' ' '\n' | grep -qx sudo \
    && echo "   ok    $ADMIN has a password and is in sudo (you are using it right now)" \
    || die "$ADMIN is not a working administrator -- STOP and fix before anything else."
cat <<EOF
   Recovery if $ADMIN is ever locked out (any one is enough):
     a. Lighthouse console -> instance -> Reset password (resets the image's default login).
     b. Lighthouse console -> Automation Tools (TAT) -> run as root:
          usermod -aG sudo $ADMIN && passwd -u $ADMIN
     c. Restore snapshot ioms-before-migration-20261025 (loses changes since).
     d. Undo this script: sudo cp -a $BK/sudoers.d/. /etc/sudoers.d/ (backup at $BK)
EOF

# ================================================================== 4. ioms-ops
log "4. Installing the current ioms-ops for $AGENT"
bash "$here/install-ops-guard.sh" --hermes-user "$AGENT" | tail -4 || true
sudo -u "$AGENT" sudo -n /usr/local/bin/ioms-ops policy | head -3
log "Done. Next: sudo bash /opt/ioms-kit/deploy/tencent/hermes/setup-dev-workspace.sh   then   bootstrap.sh stage2"
