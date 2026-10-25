#!/usr/bin/env bash
#
# IOMS behind the Caddy that the Hermes image already runs on 80/443.
#
#   sudo bash caddy-site.sh                 READ-ONLY: what Caddy serves today, whether the
#                                           IOMS site would validate, what --apply would change
#   sudo bash caddy-site.sh --apply         add ONE line (`import /etc/caddy/ioms.caddy`) to the
#                                           main Caddyfile, validate, graceful reload, verify
#   sudo bash caddy-site.sh --undo DIR      put the backed-up Caddyfile back and reload
#
# --apply is safe to re-run (the import line is added once), and it undoes
# itself when:
#   - `caddy validate` rejects the combined configuration (nothing reloaded);
#   - after the reload, any site Caddy served BEFORE answers differently
#     (the Hermes dashboard included) -- the backup is restored and reloaded;
#   - IOMS does not answer through Caddy.
# A reload is graceful: Caddy keeps serving the old configuration until the new
# one is loaded, so Hermes does not drop.
#
# Never touched: the existing site blocks, Caddy's service unit, its users,
# its certificates for other names, the firewall, DNS.

set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$here/lib.sh"
export LANG=C
# Local checks go to 127.0.0.1 directly, never through an HTTP(S) proxy from the
# environment (`--resolve` does not bypass one -- found in the rehearsal).
CURL=(curl -s --noproxy '*' --max-time 15)
code_of() { local c; c="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$@" 2>/dev/null)"; echo "${c:-000}"; }

MODE=check UNDO=""
while [[ $# -gt 0 ]]; do
    case "$1" in --apply) MODE=apply; shift ;; --undo) MODE=undo; UNDO="$2"; shift 2 ;; *) die "Unknown option: $1" ;; esac
done
[[ $EUID -eq 0 ]] || die "Run as root (sudo, as iomsadmin)."
command -v caddy >/dev/null || die "caddy is not installed here."
SNIPPET=/etc/caddy/ioms.caddy
IMPORT_LINE="import $SNIPPET"

# ------------------------------------------------------------------ where is Caddy's config?
unit_exec="$(systemctl cat caddy 2>/dev/null | grep -E '^\s*ExecStart=.+' | tail -1 || true)"
CADDYFILE="$(grep -oE -- '--config[= ]+[^ ]+' <<<"$unit_exec" | awk '{print $NF}' | sed 's/^--config=//' || true)"
[[ -n "$CADDYFILE" ]] || CADDYFILE=/etc/caddy/Caddyfile
ADAPTER="$(grep -oE -- '--adapter[= ]+[^ ]+' <<<"$unit_exec" | awk '{print $NF}' | sed 's/^--adapter=//' || true)"
caddy_user="$(systemctl show caddy -p User --value 2>/dev/null || true)"; caddy_user="${caddy_user:-caddy}"

reload_caddy() { if has_systemd; then systemctl reload caddy; else caddy reload --config "$CADDYFILE" --adapter caddyfile >/dev/null; fi; }

# Every host Caddy serves, from its own adapted config (not from parsing).
hosts_of() { # caddyfile
    caddy adapt --config "$1" --adapter caddyfile 2>/dev/null \
      | jq -r '.. | objects | select(has("host")) | .host[]?' 2>/dev/null | sort -u
}
listen_of() { caddy adapt --config "$1" --adapter caddyfile 2>/dev/null | jq -r '.apps.http.servers[]?.listen[]?' 2>/dev/null | sort -u; }

# What every site that existed BEFORE the change answers, from this host. The
# host list is fixed before the change, so newly added IOMS names are never
# mistaken for a change to an existing site.
snapshot() { # hostsfile -> lines "target code"
    local h
    while read -r h; do
        [[ -n "$h" ]] || continue
        printf 'https://%s %s\n' "$h" "$(code_of -k --resolve "$h:443:127.0.0.1" "https://$h/")"
        printf 'http://%s %s\n'  "$h" "$(code_of --resolve "$h:80:127.0.0.1" "http://$h/")"
    done < "$1"
    printf 'http://127.0.0.1 %s\n'  "$(code_of http://127.0.0.1/)"
    printf 'https://127.0.0.1 %s\n' "$(code_of -k https://127.0.0.1/)"
}

ioms_hosts() { grep -E '^[a-z0-9].*\{\s*$' "$SNIPPET" | sed 's/[{,]/ /g'; }
# Caddy forwards correctly when IOMS answers THROUGH Caddy exactly what nginx
# answers DIRECTLY -- whatever the app's state (before the data import the
# app may well answer 500; that is the app, not the proxy).
backend_code() { code_of -H "Host: $1" "http://$upstream/login"; }
ioms_check() {
    local h direct via fail=0
    for h in $(ioms_hosts); do
        direct="$(backend_code "$h")"
        via="$(code_of -k --resolve "$h:443:127.0.0.1" "https://$h/login")"
        if [[ "$via" == "$direct" && "$via" != 000 ]]; then echo "  ok    https://$h/login through Caddy: $via (nginx directly: $direct)"
        else echo "  FAIL  https://$h/login through Caddy: $via, nginx directly: $direct"; fail=1; fi
    done
    return $fail
}

# ------------------------------------------------------------------ undo
if [[ $MODE == undo ]]; then
    [[ -f "$UNDO/Caddyfile" ]] || die "No Caddyfile backup in $UNDO"
    cp -a "$UNDO/Caddyfile" "$CADDYFILE"
    caddy validate --config "$CADDYFILE" --adapter caddyfile >/dev/null 2>&1 || die "The backup does not validate?! Left in place: $CADDYFILE"
    reload_caddy; echo "Restored $CADDYFILE from $UNDO and reloaded Caddy."; exit 0
fi

# ------------------------------------------------------------------ report (always)
log "Caddy as it is today"
echo "  service:   $(systemctl is-active caddy 2>/dev/null || echo '?'), user: $caddy_user, pid: $(pgrep -x caddy | head -1 || echo none)"
echo "  ExecStart: ${unit_exec#*=}"
echo "  config:    $CADDYFILE ${ADAPTER:+(adapter $ADAPTER)}"
if [[ "$CADDYFILE" == *.json || "$ADAPTER" == json ]] || grep -q -- '--resume' <<<"$unit_exec"; then
    die "Caddy is driven by JSON or --resume, not a Caddyfile. An import line would be ignored or overwritten. Stop here and decide by hand."
fi
[[ -r "$CADDYFILE" ]] || die "Cannot read $CADDYFILE."
echo "  listens:   $(listen_of "$CADDYFILE" | tr '\n' ' ')"
echo "  sites:     $(hosts_of "$CADDYFILE" | tr '\n' ' ')$(grep -qE '^\s*(:80|:443|http://|https://)?\s*(:[0-9]+)?\s*\{' "$CADDYFILE" && echo ' + a catch-all (no host name)')"
echo "  upstreams:"; caddy adapt --config "$CADDYFILE" --adapter caddyfile 2>/dev/null | jq -r '.. | objects | select(.handler?=="reverse_proxy") | .upstreams[]?.dial' | sort -u | sed 's/^/    /'
if caddy adapt --config "$CADDYFILE" --adapter caddyfile 2>/dev/null | jq -e '.. | objects | select(.handler?=="reverse_proxy") | .upstreams[]?.dial | select(test(":(9119|8642)$"))' >/dev/null; then
    if caddy adapt --config "$CADDYFILE" --adapter caddyfile 2>/dev/null | jq -e '.. | objects | select(.handler?=="authentication")' >/dev/null; then
        echo "  note  the Hermes dashboard/gateway is proxied WITH authentication configured"
    else
        warn "The Hermes dashboard/gateway (:9119/:8642) is published through Caddy WITHOUT authentication. Anyone who reaches it can drive the agent. This script does not change it -- see runbook section 3.7."
    fi
fi

log "The IOMS site"
[[ -f "$SNIPPET" ]] || die "$SNIPPET does not exist yet: run provision.sh first (it writes it in caddy mode)."
sed 's/^/  | /' "$SNIPPET"
for h in $(ioms_hosts); do
    hosts_of "$CADDYFILE" | grep -qx "$h" && ! grep -qF "$IMPORT_LINE" "$CADDYFILE" && die "Caddy ALREADY serves $h in its own config. Two definitions would conflict; decide by hand."
done
sudo -u "$caddy_user" test -r /etc/caddy/ioms/origin.key || die "Caddy's user ($caddy_user) cannot read /etc/caddy/ioms/origin.key."
upstream="$(grep -oE 'reverse_proxy [0-9.:]+' "$SNIPPET" | awk '{print $2}')"
code="$(backend_code "$(ioms_hosts | awk '{print $1}')")"
[[ "$code" != 000 ]] || die "nothing answers on the nginx backend $upstream -- run provision.sh (caddy mode) first."
echo "  ok    nginx backend $upstream answers /login: $code$( [[ $code != 200 ]] && echo '  (the app itself; e.g. 500 before the data import is expected)')"

STAMP="$(date +%Y%m%d-%H%M%S)"; TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
cp "$CADDYFILE" "$TMP/Caddyfile"
grep -qF "$IMPORT_LINE" "$TMP/Caddyfile" || printf '\n# IOMS (deploy/tencent/caddy-site.sh %s)\n%s\n' "$STAMP" "$IMPORT_LINE" >> "$TMP/Caddyfile"
if caddy validate --config "$TMP/Caddyfile" --adapter caddyfile >"$TMP/validate.log" 2>&1; then
    echo "  ok    the combined configuration validates"
else
    sed 's/^/    /' "$TMP/validate.log" | tail -8; die "caddy validate rejects the combined configuration. Nothing was changed."
fi
echo "  change --apply would make to $CADDYFILE:"
diff -u "$CADDYFILE" "$TMP/Caddyfile" | tail -n +3 | sed 's/^/    /' || true

if [[ $MODE == check ]]; then
    echo; echo "Read-only check complete. Nothing was changed."
    exit 0
fi

# ------------------------------------------------------------------ apply
if grep -qF "$IMPORT_LINE" "$CADDYFILE"; then
    echo "  The import line is already present."
else
    log "Applying (backup, one line, validate, graceful reload)"
    BK="/root/caddy-backup-$STAMP"; install -d -m 700 "$BK"; cp -a "$CADDYFILE" "$BK/Caddyfile"
    hosts_of "$CADDYFILE" > "$BK/hosts-before.txt"
    snapshot "$BK/hosts-before.txt" > "$BK/before.txt"
    cp "$TMP/Caddyfile" "$CADDYFILE"
    if ! reload_caddy; then
        cp -a "$BK/Caddyfile" "$CADDYFILE"; reload_caddy || true
        die "Caddy refused the reload; restored $CADDYFILE. Nothing remains changed."
    fi
    sleep 2
    snapshot "$BK/hosts-before.txt" > "$BK/after.txt"
    if ! diff -q "$BK/before.txt" "$BK/after.txt" >/dev/null; then
        # `|| true`: diff exits 1 when it FINDS a difference, and under
        # set -e/pipefail that killed the script right here -- before the
        # restore below (found in the rehearsal: the rollback never ran).
        diff "$BK/before.txt" "$BK/after.txt" | sed 's/^/    /' || true
        cp -a "$BK/Caddyfile" "$CADDYFILE"; reload_caddy || true
        die "An EXISTING site answered differently after the reload. Restored $CADDYFILE and reloaded; nothing remains changed."
    fi
    echo "  ok    every existing site answers exactly as before ($(wc -l < "$BK/before.txt") checks)"
    echo "  undo: sudo bash $0 --undo $BK"
fi

log "IOMS through Caddy"
if ! ioms_check; then
    [[ -n "${BK:-}" ]] && { cp -a "$BK/Caddyfile" "$CADDYFILE"; reload_caddy || true; die "IOMS did not answer through Caddy; restored and reloaded. Nothing remains changed."; }
    die "IOMS does not answer through Caddy."
fi
echo "Done. Next: migrate.sh verify --url https://iomsuite.com --resolve 127.0.0.1 --e2e"
