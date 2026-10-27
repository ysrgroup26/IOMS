#!/usr/bin/env bash
#
# IOMS on Tencent Lighthouse -- the ONE command to start from, in OrcaTerm,
# logged in as `ubuntu` (which still has sudo the first time):
#
#   sudo git clone --depth 1 https://github.com/ysrgroup26/IOMS.git /opt/ioms-kit
#   sudo bash /opt/ioms-kit/deploy/tencent/bootstrap.sh stage1
#
# Not `curl ... | sudo bash`: stage 1 asks for a password on a terminal, and a
# piped script's stdin is the script itself.
#
# STAGE 1 (as ubuntu, interactive, ~10 minutes)
#   1. root-owned kit in /opt/ioms-kit (Hermes, as ubuntu, cannot edit it)
#   2. inspection report -> /root/inspection-<date>.txt
#   3. take root away from Hermes: create the human admin `iomsadmin`
#      (asks for a password), verify it, remove ubuntu's sudo, give ubuntu
#      only ioms-ops, restart Hermes and prove it lost the sudo group
#   STOPS. From here on you work as iomsadmin, never as ubuntu.
#
# STAGE 2 (as iomsadmin: `su - iomsadmin`, then sudo)
#   4. provision.sh (self-signed origin certificate until the Cloudflare one
#      is installed; no firewall change)
#   5. migrate.sh app (main), with mail and payment INERT
#   6. migrate.sh env-set APP_KEY (hidden prompt -- paste the PRODUCTION key)
#   STOPS, waiting for the export from IDCloudHost (runbook section 5).
#
# Every step is idempotent; re-running a stage is safe.

set -euo pipefail
KIT=/opt/ioms-kit
REPO="${IOMS_REPO:-https://github.com/ysrgroup26/IOMS.git}"   # overridable for a rehearsal
stage="${1:-}"

[[ $EUID -eq 0 ]] || { echo "Run with sudo." >&2; exit 1; }
say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

kit() {
    if [[ ! -d "$KIT/.git" ]]; then
        say "Fetching the migration kit into $KIT (root-owned)"
        git clone --depth 1 "$REPO" "$KIT"
    else
        git -c safe.directory="$KIT" -C "$KIT" pull --ff-only -q
    fi
    chown -R root:root "$KIT"; chmod -R go-w "$KIT"
}

case "$stage" in
    stage1)
        [[ "${SUDO_USER:-}" == ubuntu || "${FORCE:-}" == 1 ]] || { echo "Stage 1 is run by ubuntu (sudo bash ... stage1)." >&2; exit 1; }
        command -v git >/dev/null || apt-get install -y git
        kit
        say "Read-only inspection"
        bash "$KIT/deploy/tencent/inspect-instance.sh" > "/root/inspection-$(date +%F).txt" 2>&1 || true
        chmod 600 /root/inspection-*.txt
        echo "  report: /root/inspection-$(date +%F).txt"
        say "Taking root away from Hermes (it keeps running, as ubuntu)"
        bash "$KIT/deploy/tencent/hermes/harden-hermes-host.sh" --agent-user ubuntu --admin-user iomsadmin
        say "Stage 1 complete. Now:  su - iomsadmin   then   sudo bash $KIT/deploy/tencent/bootstrap.sh stage2"
        ;;
    stage1b)
        # Finishes stage 1 on an instance where it already ran: audits the
        # remaining sudo rules (acli), restarts Hermes through its user
        # manager, installs the current ioms-ops, and builds Hermes' dev
        # workspace. Run as iomsadmin.
        [[ "${SUDO_USER:-}" != ubuntu ]] || { echo "Run stage1b as iomsadmin: su - iomsadmin" >&2; exit 1; }
        kit
        bash "$KIT/deploy/tencent/hermes/finish-hardening.sh" "${@:2}"
        bash "$KIT/deploy/tencent/hermes/setup-dev-workspace.sh"
        say "Stage 1b complete. Next: sudo bash $KIT/deploy/tencent/bootstrap.sh stage2"
        ;;
    stage2)
        [[ "${SUDO_USER:-}" != ubuntu ]] || { echo "Stage 2 must not run as ubuntu (the Hermes account). Use: su - iomsadmin" >&2; exit 1; }
        kit
        say "Provisioning"
        bash "$KIT/deploy/tencent/provision.sh" --domain iomsuite.com --domain www.iomsuite.com
        say "Application (main)"
        bash "$KIT/deploy/tencent/migrate.sh" app
        if ! grep -qE '^APP_KEY=.+' /srv/ioms/app/.env; then
            say "Paste the PRODUCTION APP_KEY (from IDCloudHost: grep ^APP_KEY= ~/ioms/.env). Input is hidden."
            bash "$KIT/deploy/tencent/migrate.sh" env-set APP_KEY
        fi
        if [[ -f /etc/caddy/ioms.caddy ]]; then
            say "Stage 2 complete (CADDY MODE: Caddy keeps 80/443 for Hermes; IOMS is NOT yet published)."
            echo "Next: export on IDCloudHost -> migrate.sh import (runbook 5), then:"
            echo "  sudo bash $KIT/deploy/tencent/caddy-site.sh            # read-only"
            echo "  sudo bash $KIT/deploy/tencent/caddy-site.sh --apply    # one import line, verified, auto-undo"
        else
            say "Stage 2 complete. Next: export on IDCloudHost, then migrate.sh import (runbook section 5)."
        fi
        ;;
    *)
        sed -n '2,32p' "$0"; exit 2 ;;
esac
