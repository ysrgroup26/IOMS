#!/usr/bin/env bash
#
# IOMS behind the Caddy that the Hermes image already runs on 80/443.
#
#   sudo bash caddy-site.sh                 READ-ONLY: what Caddy serves today, whether the
#                                           IOMS site would validate, what --apply would change
#   sudo bash caddy-site.sh --apply         add ONE line (`import /etc/caddy/ioms.caddy`) to the
#                                           main Caddyfile, validate, graceful reload, verify
#   sudo bash caddy-site.sh --undo DIR      put the backed-up Caddyfile back and reload
#   sudo bash caddy-site.sh --detach NAME   take NAME (e.g. staging.iomsuite.com) off the IOMS site
#                                           and answer it with 503 instead, so that name can never
#                                           reach the production app and database here
#   sudo bash caddy-site.sh --redirect      add an explicit http -> https redirect for the IOMS
#                                           names to /etc/caddy/ioms.caddy (only that file),
#                                           validate, reload, verify, auto-undo
#
# Why --redirect exists: a main Caddyfile with `auto_https off` and a catch-all
# HTTP site (the Hermes image) answers http://iomsuite.com itself -- 200 with
# ITS content -- because Caddy adds no automatic redirect when auto_https is
# off. An explicit `http://<name>` site is more specific than any catch-all,
# so it wins with or without auto_https (reproduced with a real Caddy).
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

MODE=check UNDO="" DETACH=""
while [[ $# -gt 0 ]]; do
    case "$1" in --apply) MODE=apply; shift ;; --redirect) MODE=redirect; shift ;; --detach) MODE=detach; DETACH="${2:-}"; shift 2 ;; --undo) MODE=undo; UNDO="$2"; shift 2 ;; *) die "Unknown option: $1" ;; esac
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

# The HTTPS site's names only: the `http://...` redirect site is not one of them.
# The FIRST https site in the snippet is the IOMS site; a detached name's own
# block (--detach) comes later and is not one of its names.
ioms_hosts() { grep -E '^[a-z0-9].*\{\s*$' "$SNIPPET" | grep -v '^http://' | head -1 | sed 's/[{,]/ /g'; }
redirect_block() {
    printf '\n# http -> https for the IOMS names (caddy-site.sh --redirect)\n'
    printf '%s {\n    redir https://{host}{uri} 308\n}\n' "$(for h in $(ioms_hosts); do printf 'http://%s, ' "$h"; done | sed 's/, $//')"
}
# http://<name>/ must answer 301/308 to https://<name>/ for every IOMS name.
redirect_check() {
    local h out fail=0
    for h in $(ioms_hosts); do
        out="$("${CURL[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' --resolve "$h:80:127.0.0.1" "http://$h/login" 2>/dev/null)"
        if [[ "$out" =~ ^30[18]\ https://$h/login$ ]]; then echo "  ok    http://$h/login -> $out"
        else echo "  FAIL  http://$h/login -> ${out:-000}"; fail=1; fi
    done
    return $fail
}
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
    [[ -f "$UNDO/ioms.caddy" ]] && cp -a "$UNDO/ioms.caddy" "$SNIPPET"
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

# ------------------------------------------------------------------ detach
if [[ $MODE == detach ]]; then
    [[ "$DETACH" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || die "Usage: --detach <hostname>"
    grep -qF "$IMPORT_LINE" "$CADDYFILE" || die "IOMS is not published through Caddy yet."
    log "Detach $DETACH from the IOMS site"
    restore_both() { cp -a "$BK/Caddyfile" "$CADDYFILE"; cp -a "$BK/ioms.caddy" "$SNIPPET"; reload_caddy || true; }
    if grep -qxF "# detached: $DETACH" "$SNIPPET"; then
        echo "  $DETACH is already detached."
    else
        ioms_hosts | tr ' ' '\n' | grep -qxF "$DETACH" || die "$DETACH is not one of the IOMS site's names ($(ioms_hosts))."
        [[ "$(ioms_hosts | wc -w)" -gt 1 ]] || die "Refusing to detach the IOMS site's last name."
        tls_line="$(grep -m1 -E '^\s*tls ' "$SNIPPET" | sed 's/^\s*//')"
        [[ -n "$tls_line" ]] || die "No tls line in $SNIPPET to answer $DETACH over HTTPS."
        BK="/root/caddy-backup-$STAMP"; install -d -m 700 "$BK"
        cp -a "$CADDYFILE" "$BK/Caddyfile"; cp -a "$SNIPPET" "$BK/ioms.caddy"
        # Every OTHER name, production included, must answer exactly as before.
        hosts_of "$CADDYFILE" | grep -vxF "$DETACH" > "$BK/hosts-before.txt" || true
        snapshot "$BK/hosts-before.txt" > "$BK/before.txt"
        # Drop the name (and http://name) from every site-address line.
        awk -v h="$DETACH" '
            /^[^ \t#].*\{[ \t]*$/ {
                line = $0; sub(/[ \t]*\{[ \t]*$/, "", line)
                n = split(line, a, /,[ \t]*/); out = ""
                for (i = 1; i <= n; i++) if (a[i] != h && a[i] != "http://" h) out = out (out == "" ? "" : ", ") a[i]
                print out " {"; next
            }
            { print }' "$BK/ioms.caddy" > "$TMP/ioms.caddy"
        {
            printf '\n# detached: %s\n' "$DETACH"
            printf '# Not served by this server (caddy-site.sh --detach). This app is PRODUCTION;\n'
            printf '# a name detached here must never reach it. Undo: caddy-site.sh --undo %s\n' "$BK"
            printf '%s {\n    %s\n    respond "%s is not served here." 503\n}\n' "$DETACH" "$tls_line" "$DETACH"
            printf 'http://%s {\n    respond "%s is not served here." 503\n}\n' "$DETACH" "$DETACH"
        } >> "$TMP/ioms.caddy"
        cp "$TMP/ioms.caddy" "$SNIPPET"
        echo "  change to $SNIPPET:"; diff -u "$BK/ioms.caddy" "$SNIPPET" | tail -n +3 | sed 's/^/    /' || true
        if ! caddy validate --config "$CADDYFILE" --adapter caddyfile >"$TMP/validate.log" 2>&1; then
            sed 's/^/    /' "$TMP/validate.log" | tail -8
            cp -a "$BK/ioms.caddy" "$SNIPPET"; die "caddy validate rejects it. $SNIPPET restored; nothing was reloaded."
        fi
        reload_caddy || { restore_both; die "Caddy refused the reload; restored. Nothing remains changed."; }
        sleep 2
        snapshot "$BK/hosts-before.txt" > "$BK/after.txt"
        if ! diff -q "$BK/before.txt" "$BK/after.txt" >/dev/null; then
            diff "$BK/before.txt" "$BK/after.txt" | sed 's/^/    /' || true
            restore_both; die "Another site answered differently after the reload. Restored and reloaded; nothing remains changed."
        fi
        echo "  ok    every other site, production included, answers exactly as before ($(wc -l < "$BK/before.txt") checks)"
        echo "  undo: sudo bash $0 --undo $BK"
    fi
    log "Checks"
    s1="$(code_of -k --resolve "$DETACH:443:127.0.0.1" "https://$DETACH/login")"
    s2="$(code_of --resolve "$DETACH:80:127.0.0.1" "http://$DETACH/login")"
    dfail=0
    [[ "$s1" == 503 ]] && echo "  ok    https://$DETACH/login -> 503 (not production)" || { echo "  FAIL  https://$DETACH/login -> $s1"; dfail=1; }
    [[ "$s2" == 503 ]] && echo "  ok    http://$DETACH/login -> 503 (not production)" || { echo "  FAIL  http://$DETACH/login -> $s2"; dfail=1; }
    if [[ $dfail -ne 0 ]] || ! redirect_check || ! ioms_check; then
        [[ -n "${BK:-}" ]] && { restore_both; die "Checks failed; restored and reloaded. Nothing remains changed."; }
        die "Checks failed."
    fi
    echo "Done."
    exit 0
fi

# ------------------------------------------------------------------ redirect
if [[ $MODE == redirect ]]; then
    grep -qF "$IMPORT_LINE" "$CADDYFILE" || die "IOMS is not published through Caddy yet: run --apply first."
    log "http -> https for the IOMS names"
    echo "  before:"; redirect_check || true
    restore_both() { cp -a "$BK/Caddyfile" "$CADDYFILE"; cp -a "$BK/ioms.caddy" "$SNIPPET"; reload_caddy || true; }
    if grep -qE '^http://' "$SNIPPET"; then
        echo "  $SNIPPET already has its http site."
    else
        BK="/root/caddy-backup-$STAMP"; install -d -m 700 "$BK"
        cp -a "$CADDYFILE" "$BK/Caddyfile"; cp -a "$SNIPPET" "$BK/ioms.caddy"
        # Every site that is NOT IOMS must answer exactly as before.
        hosts_of "$CADDYFILE" | grep -vxF -f <(ioms_hosts | tr ' ' '\n' | sed '/^$/d') > "$BK/hosts-before.txt" || true
        snapshot "$BK/hosts-before.txt" > "$BK/before.txt"
        redirect_block >> "$SNIPPET"
        echo "  adds to $SNIPPET:"; redirect_block | sed 's/^/    | /'
        if ! caddy validate --config "$CADDYFILE" --adapter caddyfile >"$TMP/validate.log" 2>&1; then
            sed 's/^/    /' "$TMP/validate.log" | tail -8
            cp -a "$BK/ioms.caddy" "$SNIPPET"; die "caddy validate rejects it. $SNIPPET restored; nothing was reloaded."
        fi
        reload_caddy || { restore_both; die "Caddy refused the reload; restored. Nothing remains changed."; }
        sleep 2
        snapshot "$BK/hosts-before.txt" > "$BK/after.txt"
        if ! diff -q "$BK/before.txt" "$BK/after.txt" >/dev/null; then
            diff "$BK/before.txt" "$BK/after.txt" | sed 's/^/    /' || true
            restore_both; die "An EXISTING site answered differently after the reload. Restored and reloaded; nothing remains changed."
        fi
        echo "  ok    every other site answers exactly as before ($(wc -l < "$BK/before.txt") checks)"
        echo "  undo: sudo bash $0 --undo $BK"
    fi
    log "IOMS through Caddy"
    if ! redirect_check || ! ioms_check; then
        [[ -n "${BK:-}" ]] && { restore_both; die "IOMS did not answer as expected; restored and reloaded. Nothing remains changed."; }
        die "IOMS does not answer as expected."
    fi
    echo "Done."
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
