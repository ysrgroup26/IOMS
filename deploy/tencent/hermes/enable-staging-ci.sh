#!/usr/bin/env bash
#
# enable-staging-ci.sh -- let GitHub Actions operate the STAGING preview, and nothing more (ADR 084).
#
# Run ONCE as root on the Tencent instance. One line; it reads this script and the tools it
# installs from the branch, through the STAGING checkout's git objects, without changing the
# staging working tree and without touching production at all:
#
#   B=claude/eloquent-newton-pbxxdg; R=/srv/ioms-staging/app; sudo -u iomsstg git -C $R fetch -q origin $B && sudo -u iomsstg git -C $R show FETCH_HEAD:deploy/tencent/hermes/enable-staging-ci.sh | sudo bash -s -- --branch $B
#
# What it does:
#   1. installs ioms-staging-ops, ioms-staging-ci and the read-only review check (root-owned);
#   2. creates the `iomsstgci` system account (no password, cannot log in by password);
#   3. generates an ed25519 key ON THIS HOST and authorises it ONLY as the forced command
#      /usr/local/sbin/ioms-staging-ci with `restrict` (no shell, no forwarding, no pty);
#   4. lets iomsstgci run exactly the six ioms-staging-ops verbs through sudo, validated with
#      visudo before it is installed, and PROVES it cannot run a shell or ioms-ops;
#   5. prints the values to store as GitHub repository secrets, once.
#
# It does NOT touch: production (/srv/ioms, its database, ioms-ops, iomsci, its .env), sshd_config,
# the firewall, Caddy, nginx, php-fpm, DNS, Cloudflare, Duitku, Hermes, the `claude` account or
# any other existing account. It reports (and does not change) an sshd AllowUsers/AllowGroups rule.
#
#   --branch NAME   the claude/* branch to read the tools from (required unless --source)
#   --source DIR    install the tools from DIR (a deploy/tencent/hermes checkout) instead
#   --host ADDR     the address GitHub connects to (default 43.173.21.31)
#   --rotate        replace the key (the old one stops working immediately)
#   --revoke        remove the key and the sudo rule; the account stays, locked
#   --purge         --revoke, then remove the account and the three tools (audit log and
#                   staging backups are kept)
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
HOST="43.173.21.31" BRANCH="" SRC="" ROTATE=0 REVOKE=0 PURGE=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --branch) BRANCH="$2"; shift 2 ;;
        --source) SRC="$2"; shift 2 ;;
        --host) HOST="$2"; shift 2 ;;
        --rotate) ROTATE=1; shift ;;
        --revoke) REVOKE=1; shift ;;
        --purge) REVOKE=1; PURGE=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done
U=iomsstgci HOME_DIR=/var/lib/iomsstgci AK=/var/lib/iomsstgci/.ssh/authorized_keys
SUDOERS=/etc/sudoers.d/ioms-staging-ci
OPS=/usr/local/bin/ioms-staging-ops CI=/usr/local/sbin/ioms-staging-ci LIB=/usr/local/lib/ioms-staging-ops
STG_APP=/srv/ioms-staging/app STG_USER=iomsstg

if [[ $REVOKE -eq 1 ]]; then
    rm -f "$AK" "$SUDOERS"; passwd -l "$U" >/dev/null 2>&1 || true
    echo "Staging CI access revoked: key and sudo rule removed. Remove the GitHub secrets IOMS_STAGING_* too."
    if [[ $PURGE -eq 1 ]]; then
        id "$U" >/dev/null 2>&1 && userdel -r "$U" 2>/dev/null || true
        rm -f "$OPS" "$CI"; rm -rf "$LIB"
        echo "Purged: account $U and the staging tools removed (kept: /var/log/ioms-staging-ops.log, /var/backups/ioms-staging)."
    fi
    exit 0
fi

[[ -n "$BRANCH" || -n "$SRC" ]] || { echo "--branch claude/<name> (or --source DIR) is required" >&2; exit 2; }
[[ -z "$BRANCH" || "$BRANCH" =~ ^claude/[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$ ]] || { echo "--branch must be claude/<name>" >&2; exit 2; }
for t in ssh-keygen visudo jq git php mysqldump mysql flock curl logger; do
    command -v "$t" >/dev/null || { echo "missing: $t" >&2; exit 1; }
done
id "$STG_USER" >/dev/null 2>&1 && [[ -f "$STG_APP/.env" ]] || { echo "staging is not set up (run staging.sh setup first)" >&2; exit 1; }

echo "==> tools"
work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
if [[ -n "$SRC" ]]; then
    cp "$SRC/ioms-staging-ops" "$SRC/ioms-staging-ci" "$SRC/staging-review-check.php" "$work/"
else
    # Read as blobs from the branch: the staging working tree (what staging RUNS) is not touched.
    sudo -u "$STG_USER" -H git -C "$STG_APP" fetch -q origin "$BRANCH"
    for f in ioms-staging-ops ioms-staging-ci staging-review-check.php; do
        # The redirect is ROOT's, on purpose: the staging account reads the blob, root writes
        # it into root's own temporary directory.
        # shellcheck disable=SC2024
        sudo -u "$STG_USER" -H git -C "$STG_APP" show "FETCH_HEAD:deploy/tencent/hermes/$f" > "$work/$f"
    done
    echo "  from $BRANCH @ $(sudo -u "$STG_USER" -H git -C "$STG_APP" rev-parse --short FETCH_HEAD)"
fi
bash -n "$work/ioms-staging-ops" && bash -n "$work/ioms-staging-ci" && php -l "$work/staging-review-check.php" >/dev/null
install -m 755 -o root -g root "$work/ioms-staging-ops" "$OPS"
install -m 755 -o root -g root "$work/ioms-staging-ci" "$CI"
install -d -m 755 -o root -g root "$LIB"
install -m 644 -o root -g root "$work/staging-review-check.php" "$LIB/review-check.php"
echo "  installed $OPS, $CI, $LIB/review-check.php"

echo "==> account $U"
if ! id "$U" >/dev/null 2>&1; then
    # A real shell is required for sshd to run the forced command; the key can run nothing else.
    useradd --system --home-dir "$HOME_DIR" --create-home --shell /bin/bash --comment "IOMS staging CI (forced command only)" "$U"
fi
passwd -l "$U" >/dev/null 2>&1 || true             # no password login, ever
install -d -m 755 -o root -g root "$HOME_DIR/.ssh"   # root-owned: the account cannot edit its own keys

echo "==> sudo rule (validated before install)"
tmp="$(mktemp)"
cat > "$tmp" <<EOF
# Managed by enable-staging-ci.sh (ADR 084). The staging CI account may run these and nothing else.
$U ALL=(root) NOPASSWD: $OPS status, $OPS deploy *, $OPS flows, $OPS activate-due, $OPS review-check, $OPS logs *
EOF
visudo -cf "$tmp" >/dev/null || { rm -f "$tmp"; echo "sudoers validation failed; nothing installed" >&2; exit 1; }
install -m 440 -o root -g root "$tmp" "$SUDOERS"; rm -f "$tmp"

echo "==> key"
new_key=""
if [[ ! -s "$AK" || $ROTATE -eq 1 ]]; then
    kd="$(mktemp -d)"; chmod 700 "$kd"
    ssh-keygen -q -t ed25519 -N '' -C "ioms-staging-ci@$(hostname -s)" -f "$kd/key"
    printf 'restrict,command="%s" %s\n' "$CI" "$(cat "$kd/key.pub")" > "$AK.tmp"
    install -m 644 -o root -g root "$AK.tmp" "$AK"; rm -f "$AK.tmp"
    new_key="$(cat "$kd/key")"; rm -rf "$kd"
else
    echo "  a key is already authorised (use --rotate to replace it)"
fi

echo "==> checks"
fail=0
if grep -Eiq '^\s*(AllowUsers|AllowGroups)\b' /etc/ssh/sshd_config /etc/ssh/sshd_config.d/*.conf 2>/dev/null; then
    echo "  NOTE  sshd has an AllowUsers/AllowGroups rule. It was NOT changed. If it does not include $U,"
    echo "        the staging workflow will be refused at login; that is a decision for the owner."
fi
if sudo -u "$U" -n sudo -n "$OPS" status >/dev/null 2>&1; then echo "  ok    $U can run ioms-staging-ops status"; else echo "  FAIL  $U cannot run ioms-staging-ops status"; fail=1; fi
if sudo -u "$U" -n sudo -n -l /bin/bash >/dev/null 2>&1; then echo "  FAIL  $U can run a shell through sudo -- check other sudoers files"; fail=1; else echo "  ok    $U cannot run a shell through sudo"; fi
if sudo -u "$U" -n sudo -n -l /usr/local/bin/ioms-ops >/dev/null 2>&1; then echo "  FAIL  $U can run the PRODUCTION ioms-ops"; fail=1; else echo "  ok    $U cannot run the production ioms-ops"; fi
if id -nG "$U" | tr ' ' '\n' | grep -qxE 'sudo|admin|wheel|ioms|iomsstg|www-data'; then echo "  FAIL  $U is in a privileged or application group"; fail=1; else echo "  ok    $U is in no privileged or application group"; fi
grep -q '^restrict,command="/usr/local/sbin/ioms-staging-ci" ' "$AK" && echo "  ok    the key is a restricted forced command" || { echo "  FAIL  authorized_keys is not a restricted forced command"; fail=1; }
if [[ $fail -ne 0 ]]; then
    rm -f "$AK" "$SUDOERS"
    echo "A check failed: the key and the sudo rule were REMOVED again. Nothing is reachable from GitHub." >&2
    exit 1
fi

if [[ -n "$new_key" ]]; then
    hostkey="$(cut -d' ' -f1,2 /etc/ssh/ssh_host_ed25519_key.pub)"
    cat <<EOF

------------------------------------------------------------------------------------------
 Store these as GitHub repository secrets (ysrgroup26/IOMS -> Settings -> Secrets and
 variables -> Actions -> New repository secret). This private key is shown ONCE and is not
 stored on this host. It can only run ioms-staging-ci (the six staging actions).

 Name:  IOMS_STAGING_SSH_KEY
 Value: (everything between the lines, including BEGIN/END)
------------------------------------------------------------------------------------------
$new_key
------------------------------------------------------------------------------------------
 Name:  IOMS_STAGING_KNOWN_HOSTS
 Value: $HOST $hostkey
------------------------------------------------------------------------------------------
 Optional, only if GitHub must reach this host by another address:
 Name:  IOMS_STAGING_HOST     Value: $HOST
------------------------------------------------------------------------------------------
EOF
    unset new_key
fi
echo "Done. Run the Staging workflow (Actions -> Staging -> Run workflow) with action=status first."
