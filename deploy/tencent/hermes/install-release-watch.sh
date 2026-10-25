#!/usr/bin/env bash
#
# Install the server half of the IOMS release loop (ADR 075). Run as root on
# the Tencent instance, from the repository checkout:
#
#   sudo bash /srv/ioms/app/deploy/tencent/hermes/install-release-watch.sh            # install, timer OFF
#   sudo bash .../install-release-watch.sh --token-stdin < token.txt                 # also store the GitHub token
#   sudo bash .../install-release-watch.sh --enable                                  # start the 5-minute timer
#   sudo bash .../install-release-watch.sh --disable                                 # stop it (nothing else changes)
#
# It installs: the updated ioms-ops (deploy <sha> + outcome file), the watcher,
# and a systemd timer. The timer is NOT enabled by default: enabling it is the
# moment production starts deploying main on its own, and that is the owner's
# call (ADR 073 already lets Hermes do the same by hand).
#
# The token is a fine-grained GitHub token for ONE repository with
#   Issues: read and write,  Checks: read,  Metadata: read  -- nothing else.
# It is read from stdin only (never an argument: arguments are visible in ps
# and shell history) and stored in /etc/ioms-release-watch.conf, root 600.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
[[ $EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
TOKEN_STDIN=0 ENABLE="" REPO="ysrgroup26/IOMS"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --token-stdin) TOKEN_STDIN=1; shift ;;
        --enable) ENABLE=on; shift ;;
        --disable) ENABLE=off; shift ;;
        --repo) REPO="$2"; shift 2 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done
for t in jq curl flock git; do command -v "$t" >/dev/null || { echo "missing: $t" >&2; exit 1; }; done

echo "==> ioms-ops (deploy <sha>, outcome file)"
install -m 755 -o root -g root "$here/ioms-ops" /usr/local/bin/ioms-ops
install -d -m 755 /usr/local/lib/ioms-ops
install -m 755 -o root -g root "$here/../healthcheck.sh" /usr/local/lib/ioms-ops/healthcheck.sh

echo "==> watcher"
install -m 750 -o root -g root "$here/ioms-release-watch" /usr/local/sbin/ioms-release-watch
install -m 644 -o root -g root "$here/ioms-release-watch.service" /etc/systemd/system/ioms-release-watch.service
install -m 644 -o root -g root "$here/ioms-release-watch.timer" /etc/systemd/system/ioms-release-watch.timer
install -d -m 750 /var/lib/ioms-ops/release
touch /var/log/ioms-release-watch.log; chmod 640 /var/log/ioms-release-watch.log

conf=/etc/ioms-release-watch.conf
if [[ ! -f "$conf" ]]; then
    printf 'GITHUB_REPO=%q\nGITHUB_TOKEN=""\nREQUIRE_CI=1\nMAX_ATTEMPTS=2\nBACKOFF_MIN=15\n' "$REPO" > "$conf"
fi
chmod 600 "$conf"; chown root:root "$conf"
if [[ $TOKEN_STDIN -eq 1 ]]; then
    IFS= read -r tok || true
    [[ "$tok" =~ ^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$ ]] || { echo "That does not look like a GitHub token; nothing stored." >&2; exit 1; }
    grep -v '^GITHUB_TOKEN=' "$conf" > "$conf.tmp"; printf 'GITHUB_TOKEN=%q\n' "$tok" >> "$conf.tmp"
    install -m 600 -o root -g root "$conf.tmp" "$conf"; rm -f "$conf.tmp"; unset tok
    code="$(. "$conf"; curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $GITHUB_TOKEN" "https://api.github.com/repos/$GITHUB_REPO/issues?per_page=1")"
    [[ "$code" == 200 ]] && echo "  token stored; it can read $REPO's issues" || echo "  token stored, but GitHub answered HTTP $code for $REPO -- check its repository and permissions"
fi

systemctl daemon-reload
case "$ENABLE" in
    on)  systemctl enable --now ioms-release-watch.timer; echo "  timer ENABLED: main is now deployed on its own once CI passes" ;;
    off) systemctl disable --now ioms-release-watch.timer; echo "  timer disabled" ;;
    *)   echo "  timer left as it was ($(systemctl is-enabled ioms-release-watch.timer 2>/dev/null || echo disabled)); --enable starts it" ;;
esac
echo
/usr/local/sbin/ioms-release-watch status || true
