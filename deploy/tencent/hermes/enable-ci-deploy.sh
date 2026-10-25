#!/usr/bin/env bash
#
# enable-ci-deploy.sh -- let GitHub Actions deploy IOMS to this host, and nothing more (ADR 076).
#
# Run ONCE as root (iomsadmin) on the Tencent instance. One line; it reads this script and the
# tools it installs from origin/main WITHOUT changing the running application:
#
#   cd /srv/ioms/app && sudo -u ioms git fetch -q origin main && git -c safe.directory=$PWD show origin/main:deploy/tencent/hermes/enable-ci-deploy.sh | sudo bash -s
#
# What it does:
#   1. installs the current ioms-ops, the migration policy, the health check and
#      ioms-ci-deploy (all root-owned, from this checkout);
#   2. creates the `iomsci` system account (no password, cannot log in by password);
#   3. generates an ed25519 key ON THIS HOST and authorises it ONLY as the forced command
#      /usr/local/sbin/ioms-ci-deploy with `restrict` (no shell, no forwarding, no pty);
#   4. lets iomsci run exactly `ioms-ops deploy <sha> | status | health | release-json` via sudo,
#      validated with visudo before it is installed;
#   5. prints the two values to store as GitHub repository secrets, once.
#
# It does NOT touch sshd_config, the firewall, DNS, Cloudflare, Duitku, the migration hold,
# Hermes or any existing account. It reports (and does not change) an sshd AllowUsers/AllowGroups
# rule that would keep iomsci out.
#
#   --host IP_OR_NAME   the address GitHub connects to (default 43.173.21.31)
#   --source DIR        install the tools from DIR (a deploy/tencent/hermes checkout) instead of origin/main
#   --rotate            replace the key (the old one stops working immediately)
#   --revoke            remove the key and the sudo rule; the account stays, locked
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
HOST="43.173.21.31" ROTATE=0 REVOKE=0 SRC="" APP_DIR=/srv/ioms/app APP_USER=ioms
while [[ $# -gt 0 ]]; do
    case "$1" in
        --host) HOST="$2"; shift 2 ;;
        --rotate) ROTATE=1; shift ;;
        --revoke) REVOKE=1; shift ;;
        --source) SRC="$2"; shift 2 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done
U=iomsci HOME_DIR=/var/lib/iomsci AK=/var/lib/iomsci/.ssh/authorized_keys SUDOERS=/etc/sudoers.d/ioms-ci

if [[ $REVOKE -eq 1 ]]; then
    rm -f "$AK" "$SUDOERS"; passwd -l "$U" >/dev/null 2>&1 || true
    echo "CI deploy access revoked: key and sudo rule removed. Remove the GitHub secrets too."; exit 0
fi

for t in ssh-keygen visudo jq git php; do command -v "$t" >/dev/null || { echo "missing: $t" >&2; exit 1; }; done

echo "==> tools"
work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
if [[ -n "$SRC" ]]; then
    for f in ioms-ops migration-policy.php ioms-ci-deploy; do cp "$SRC/$f" "$work/$f"; done
    cp "$SRC/../healthcheck.sh" "$work/healthcheck.sh"
else
    # From origin/main, read as blobs: the working tree (the RUNNING release) is not touched.
    sudo -u "$APP_USER" -H git -C "$APP_DIR" fetch -q origin main
    for f in hermes/ioms-ops hermes/migration-policy.php hermes/ioms-ci-deploy healthcheck.sh; do
        git -c safe.directory="$APP_DIR" -C "$APP_DIR" show "origin/main:deploy/tencent/$f" > "$work/$(basename "$f")"
    done
    echo "  from origin/main $(git -c safe.directory="$APP_DIR" -C "$APP_DIR" rev-parse --short origin/main)"
fi
bash -n "$work/ioms-ops" && bash -n "$work/ioms-ci-deploy" && php -l "$work/migration-policy.php" >/dev/null
install -m 755 -o root -g root "$work/ioms-ops" /usr/local/bin/ioms-ops
install -d -m 755 /usr/local/lib/ioms-ops
install -m 755 -o root -g root "$work/healthcheck.sh" /usr/local/lib/ioms-ops/healthcheck.sh
install -m 644 -o root -g root "$work/migration-policy.php" /usr/local/lib/ioms-ops/migration-policy.php
install -m 755 -o root -g root "$work/ioms-ci-deploy" /usr/local/sbin/ioms-ci-deploy
echo "  ioms-ops policy:"; /usr/local/bin/ioms-ops policy | sed 's/^/    /'

echo "==> account $U"
if ! id "$U" >/dev/null 2>&1; then
    useradd --system --home-dir "$HOME_DIR" --create-home --shell /bin/bash --comment "IOMS CI deploy (forced command only)" "$U"
fi
passwd -l "$U" >/dev/null 2>&1 || true   # no password login, ever
install -d -m 755 -o root -g root "$HOME_DIR/.ssh"   # root-owned: the account cannot edit its own keys

echo "==> sudo rule (validated before install)"
tmp="$(mktemp)"
cat > "$tmp" <<EOF
# Managed by enable-ci-deploy.sh (ADR 076). The CI deploy account may run these and nothing else.
$U ALL=(root) NOPASSWD: /usr/local/bin/ioms-ops deploy *, /usr/local/bin/ioms-ops status, /usr/local/bin/ioms-ops health, /usr/local/bin/ioms-ops release-json
EOF
visudo -cf "$tmp" >/dev/null || { rm -f "$tmp"; echo "sudoers validation failed; nothing installed" >&2; exit 1; }
install -m 440 -o root -g root "$tmp" "$SUDOERS"; rm -f "$tmp"

echo "==> key"
new_key=""
if [[ ! -s "$AK" || $ROTATE -eq 1 ]]; then
    kd="$(mktemp -d)"; chmod 700 "$kd"
    ssh-keygen -q -t ed25519 -N '' -C "ioms-ci@$(hostname -s)" -f "$kd/key"
    printf 'restrict,command="/usr/local/sbin/ioms-ci-deploy" %s\n' "$(cat "$kd/key.pub")" > "$AK.tmp"
    install -m 644 -o root -g root "$AK.tmp" "$AK"; rm -f "$AK.tmp"
    new_key="$(cat "$kd/key")"; rm -rf "$kd"
else
    echo "  a key is already authorised (use --rotate to replace it)"
fi

echo "==> checks"
if grep -Eiq '^\s*(AllowUsers|AllowGroups)\b' /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null; then
    echo "  NOTE  sshd has an AllowUsers/AllowGroups rule. It was NOT changed. If it does not include $U,"
    echo "        GitHub's deploy will be refused at login; that is a decision for the owner."
fi
sudo -u "$U" -n sudo -n /usr/local/bin/ioms-ops release-json >/dev/null && echo "  ok    $U can run ioms-ops release-json through sudo"
sudo -u "$U" -n sudo -n -l /bin/bash >/dev/null 2>&1 && { echo "  FAIL  $U can run a shell through sudo -- check other sudoers files" >&2; exit 1; } || echo "  ok    $U cannot run a shell through sudo"

if [[ -n "$new_key" ]]; then
    hostkey="$(cut -d' ' -f1,2 /etc/ssh/ssh_host_ed25519_key.pub)"
    cat <<EOF

------------------------------------------------------------------------------------------
 Store these as GitHub repository secrets (ysrgroup26/IOMS -> Settings -> Secrets and
 variables -> Actions -> New repository secret). This private key is shown ONCE and is not
 stored on this host. It can only run ioms-ci-deploy (deploy <sha> | status).

 Name:  IOMS_DEPLOY_SSH_KEY
 Value: (everything between the lines, including BEGIN/END)
------------------------------------------------------------------------------------------
$new_key
------------------------------------------------------------------------------------------
 Name:  IOMS_DEPLOY_KNOWN_HOSTS
 Value: $HOST $hostkey
------------------------------------------------------------------------------------------
 Optional, only if GitHub must reach this host by another address:
 Name:  IOMS_DEPLOY_HOST     Value: $HOST
------------------------------------------------------------------------------------------
EOF
    unset new_key
fi
echo "Done. The next push to main that passes CI deploys itself (.github/workflows/deploy.yml)."
