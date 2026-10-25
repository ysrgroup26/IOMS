#!/usr/bin/env bash
#
# IOMS -- the whole move to Tencent in ONE command, after Stage 2.
#
#   sudo bash /opt/ioms-kit/deploy/tencent/migrate-all.sh            # rehearsal or final, idempotent
#   sudo bash /opt/ioms-kit/deploy/tencent/migrate-all.sh go-live    # AFTER the DNS switch you approved
#
# It asks for (hidden prompts, nothing echoed or logged):
#   1. the TRANSFER CODE that cpanel-handoff.sh printed on IDCloudHost (one line);
#   2. the Zoho app password, only if mail is not configured yet (Enter = later).
# It finds the Cloudflare Origin certificate at /root/origin.pem + /root/origin.key
# (or --origin-cert/--origin-key). Without a REAL one, IOMS is NOT published.
#
# Steps, each skipped when already done:
#   preflight   Stage 2 present, MySQL, PHP-FPM, nginx backend, Caddy, disk
#   fetch       download the encrypted bundle straight from IDCloudHost over HTTPS,
#               verify its checksum, decrypt, verify every file's checksum
#   config      apply production settings from the bundle (Duitku forced to SANDBOX,
#               VAPID, Google, IOMS_*); APP_KEY must MATCH, never overwritten
#   hold        hold the scheduler and the queue worker: a copy of production with
#               live mail must not email customers before the cutover
#   import      backup -> load -> per-table row-count parity -> storage -> migrations
#   mail        Zoho SMTP (one password prompt, once)
#   certificate validate the Origin certificate (issuer, key, names) and install it
#   publish     caddy-site.sh --apply: one import line, verified, auto-undo
#   verify      pages, security surface, e2e sign-up/sign-in, Duitku sandbox, mail,
#               backup + restore check
#   report      one table: what passed, what is blocked and why, ready for cutover or not
#
# Never: DNS, firewall, Caddy's own sites, Hermes, IDCloudHost, Duitku production.
# Recovery: migrate.sh restore --from /var/backups/ioms/<stamp> --yes-overwrite-database
#           caddy-site.sh --undo /root/caddy-backup-<stamp>

set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$here/lib.sh"

APP_DIR=/srv/ioms/app APP_USER=ioms
STATE=/var/lib/ioms-migrate LOG=/var/log/ioms-migration.log
CMD=run REPLACE=0 SKIP_MAIL=0 SKIP_PAYMENT=0 MAIL_TO="" CODE="" ORIGIN_CERT=/root/origin.pem ORIGIN_KEY=/root/origin.key
DOMAIN=iomsuite.com
[[ "${1:-}" == go-live ]] && { CMD=go-live; shift; }
while [[ $# -gt 0 ]]; do
    case "$1" in
        --replace) REPLACE=1; shift ;;
        --skip-mail) SKIP_MAIL=1; shift ;;
        --skip-payment) SKIP_PAYMENT=1; shift ;;
        --mail-to) MAIL_TO="$2"; shift 2 ;;
        --code-stdin) IFS= read -r CODE; shift ;;
        --origin-cert) ORIGIN_CERT="$2"; shift 2 ;;
        --origin-key) ORIGIN_KEY="$2"; shift 2 ;;
        *) die "Unknown option: $1" ;;
    esac
done
[[ $EUID -eq 0 ]] || die "Run as root (sudo, as iomsadmin)."
install -d -m 700 "$STATE"
touch "$LOG"; chmod 600 "$LOG"
exec > >(tee -a "$LOG") 2>&1        # nothing secret is ever printed, so the log is safe
echo; echo "===== migrate-all $CMD $(date -Is) ====="

declare -A GATE
gate() { GATE["$1"]="$2"; printf '  %-8s %s\n' "$2" "$1${3:+ -- $3}"; }
envget() { grep -E "^$1=" "$APP_DIR/.env" | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }
mig() { bash "$here/migrate.sh" "$@"; }
set_env() { printf '%s\n' "$2" | mig env-set "$1" >/dev/null; }   # value on stdin, never argv
HOLD="$STATE/hold"

hold_on() {
    [[ -f "$HOLD" ]] && return 0
    sed -i -E 's|^([^#].*schedule:run.*)$|# held by migrate-all until go-live: \1|' /etc/cron.d/ioms
    supervisorctl stop ioms-worker >/dev/null 2>&1 || true
    date -Is > "$HOLD"
}

# ================================================================== go-live
if [[ $CMD == go-live ]]; then
    log "Go-live: releasing the scheduler and the queue worker"
    [[ -f "$HOLD" ]] || { echo "  nothing is held."; exit 0; }
    if [[ -t 0 ]]; then read -r -p "  DNS for $DOMAIN already points at this server and you approved the cutover? Type GO-LIVE: " ok; [[ "$ok" == GO-LIVE ]] || die "Not released."; fi
    sed -i -E 's|^# held by migrate-all until go-live: ||' /etc/cron.d/ioms
    supervisorctl start ioms-worker >/dev/null 2>&1 || true
    rm -f "$HOLD"
    echo "  scheduler and worker running. Watch: sudo ioms-ops logs app 100"
    exit 0
fi

# ================================================================== preflight
log "Preflight"
[[ -f "$APP_DIR/.env" && -d "$APP_DIR/vendor" ]] || die "Stage 2 has not run (no $APP_DIR with .env and vendor)."
grep -qE '^APP_KEY=.+' "$APP_DIR/.env" || die "APP_KEY is empty: Stage 2's production APP_KEY step is missing."
mysqladmin --defaults-extra-file=/root/ioms-db.cnf ping >/dev/null 2>&1 || die "MySQL is not answering."
[[ -S /run/php/ioms-fpm.sock ]] || die "The ioms PHP-FPM pool is not running."
BACKEND="$(grep -oE 'listen 127\.0\.0\.1:[0-9]+' /etc/nginx/sites-available/ioms | awk -F: '{print $2}' || true)"
CADDY=0; [[ -n "$BACKEND" && -f /etc/caddy/ioms.caddy ]] && CADDY=1
echo "  mode: $([[ $CADDY -eq 1 ]] && echo "caddy (nginx on 127.0.0.1:$BACKEND)" || echo nginx)"
free_mb="$(df -Pm /var | awk 'NR==2 {print $4}')"; [[ "$free_mb" -gt 2048 ]] || die "Less than 2 GB free on /var."
gate preflight PASS

# ================================================================== fetch
log "Fetch the bundle from IDCloudHost"
if [[ -z "$CODE" ]]; then
    [[ -t 0 ]] || die "No transfer code (run interactively, or pipe it with --code-stdin)."
    read -r -s -p "  Paste the TRANSFER CODE from cpanel-handoff.sh (hidden): " CODE; echo
fi
IFS='|' read -r ver URL KEY SUM <<<"$CODE"; unset CODE
[[ "$ver" == IOMS1 && "$URL" =~ ^https?:// && "$KEY" =~ ^[0-9a-f]{64}$ && "$SUM" =~ ^[0-9a-f]{64}$ ]] || die "That is not a transfer code (expected IOMS1|url|key|sum)."
WORK="/root/ioms-handoff/$SUM"; install -d -m 700 /root/ioms-handoff "$WORK"
if [[ -f "$WORK/export/SHA256SUMS" ]]; then
    echo "  bundle ${SUM:0:12} already fetched and decrypted"
else
    curl -fsS --retry 3 --max-time 1800 -o "$WORK/bundle.enc" "$URL" || die "Download failed (has cpanel-handoff.sh already removed it? run it again)."
    [[ "$(sha256sum "$WORK/bundle.enc" | cut -d' ' -f1)" == "$SUM" ]] || die "Checksum mismatch: the download is not the bundle that was published."
    install -d -m 700 "$WORK/export"
    IOMS_K="$KEY" openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:IOMS_K -in "$WORK/bundle.enc" | tar -C "$WORK/export" -xf - \
        || die "Decryption failed (wrong code?)."
    rm -f "$WORK/bundle.enc"
fi
unset KEY
( cd "$WORK/export" && sha256sum -c --quiet SHA256SUMS ) || die "A file inside the bundle does not match its checksum."
echo "  $(wc -l < "$WORK/export/counts.tsv") tables, $(tar -tzf "$WORK/export/storage-app.tar.gz" | grep -vc '/$') files, from release $(grep source_release "$WORK/export/manifest" | cut -d= -f2), exported $(grep exported_at "$WORK/export/manifest" | cut -d= -f2)"
gate fetch PASS "checksums verified"

# ================================================================== config
log "Production configuration"
src_key="$(grep -E '^APP_KEY=' "$WORK/export/env.transfer" | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/' || true)"
if [[ -n "$src_key" && "$src_key" != "$(envget APP_KEY)" ]]; then
    gate config FAIL "APP_KEY on Tencent differs from production"
    die "APP_KEY differs from production. Encrypted data and signed links would break. Fix it: migrate.sh env-set APP_KEY (paste production's)."
fi
unset src_key
applied=0
while IFS= read -r line || [[ -n "$line" ]]; do
    k="${line%%=*}"; v="${line#*=}"; v="${v#\"}"; v="${v%\"}"
    case "$k" in
        APP_KEY|"") continue ;;
        DUITKU_IS_PRODUCTION) v=false ;;                                  # sandbox until approved
        IOMS_HELLO_EMAIL) [[ "$v" == *hello@iomsuite.com* ]] && continue ;;  # no hello@ in Zoho (ADR 071)
    esac
    [[ "$(envget "$k")" == "$v" ]] && continue
    if [[ -z "$v" ]]; then   # empty in production = unset = the same default here
        [[ -n "$(envget "$k")" ]] && warn "$k is empty in production but set on Tencent; left as it is here."
        continue
    fi
    set_env "$k" "$v"; applied=$((applied + 1))
done < <(tac "$WORK/export/env.transfer" | awk -F= '!seen[$1]++' | tac)   # the LAST of a repeated key wins, as in Laravel
[[ "$(envget DUITKU_IS_PRODUCTION)" == true ]] && set_env DUITKU_IS_PRODUCTION false
echo "  $applied setting(s) applied; APP_KEY matches production; Duitku is SANDBOX"
gate config PASS

# ================================================================== hold + import
log "Hold the scheduler and the worker (a production copy must not email customers yet)"
hold_on; echo "  held since $(cat "$HOLD")"; gate hold PASS "release with: migrate-all.sh go-live"

log "Import"
tables="$(mysql --defaults-extra-file=/root/ioms-db.cnf -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='ioms'")"
if [[ "$(cat "$STATE/imported" 2>/dev/null)" == "$SUM" && $REPLACE -eq 0 ]]; then
    echo "  this bundle is already imported"
elif [[ "$tables" -gt 0 && $REPLACE -eq 0 ]]; then
    die "Tencent's database already holds $tables tables from an earlier import. Re-run with --replace (it is backed up first)."
else
    if [[ "$tables" -gt 0 ]]; then mig import --from "$WORK/export" --replace; else mig import --from "$WORK/export"; fi
    echo "$SUM" > "$STATE/imported"
    hold_on   # the import's deploy step restarts the worker; keep it held
    sed -i -E 's|^([^#].*schedule:run.*)$|# held by migrate-all until go-live: \1|' /etc/cron.d/ioms
    supervisorctl stop ioms-worker >/dev/null 2>&1 || true
fi
gate data PASS "every table's row count equals the source"
gate storage PASS "$(tar -tzf "$WORK/export/storage-app.tar.gz" | grep -vc '/$') files"

# ================================================================== mail
log "Mail (Zoho)"
if [[ -n "$(envget MAIL_PASSWORD)" && "$(envget MAIL_PASSWORD)" != null && "$(envget MAIL_MAILER)" == smtp ]]; then
    echo "  already configured"
elif [[ $SKIP_MAIL -eq 0 && -t 0 ]]; then
    read -r -s -p "  Zoho app password for support@iomsuite.com (hidden; Enter = later): " zp; echo
    if [[ -n "$zp" ]]; then
        printf '%s\n' "$zp" | mig env-set MAIL_PASSWORD >/dev/null; unset zp
        set_env MAIL_MAILER smtp; set_env MAIL_HOST smtppro.zoho.com; set_env MAIL_PORT 465
        set_env MAIL_SCHEME smtps; set_env MAIL_USERNAME support@iomsuite.com; set_env MAIL_FROM_ADDRESS noreply@iomsuite.com
        echo "  configured"
    fi
fi
MAIL_READY=0; [[ "$(envget MAIL_MAILER)" == smtp && -n "$(envget MAIL_PASSWORD)" ]] && MAIL_READY=1

# ================================================================== certificate
log "Cloudflare Origin certificate"
CERT_OK=0
if [[ -f "$ORIGIN_CERT" && -f "$ORIGIN_KEY" ]]; then
    issuer="$(openssl x509 -in "$ORIGIN_CERT" -noout -issuer 2>/dev/null || true)"
    names="$(openssl x509 -in "$ORIGIN_CERT" -noout -ext subjectAltName 2>/dev/null | tr ',' '\n' | sed 's/ *DNS://' | grep -v Subject || true)"
    if ! grep -qi 'CloudFlare Origin' <<<"$issuer"; then
        gate certificate BLOCKED "$ORIGIN_CERT is not issued by Cloudflare Origin CA ($issuer)"
    elif [[ "$(openssl x509 -in "$ORIGIN_CERT" -noout -pubkey | sha256sum)" != "$(openssl pkey -in "$ORIGIN_KEY" -pubout 2>/dev/null | sha256sum)" ]]; then
        gate certificate FAIL "the key does not match the certificate"
    elif ! grep -qx "$DOMAIN" <<<"$names" || ! grep -qxE "\*\.$DOMAIN|www\.$DOMAIN" <<<"$names"; then
        gate certificate FAIL "it must cover $DOMAIN and *.$DOMAIN (has: $(tr '\n' ' ' <<<"$names"))"
    else
        install -d -m 750 -o root -g www-data /etc/ssl/ioms
        install -m 644 "$ORIGIN_CERT" /etc/ssl/ioms/origin.pem; install -m 640 -g www-data "$ORIGIN_KEY" /etc/ssl/ioms/origin.key
        if [[ $CADDY -eq 1 ]]; then
            g=caddy; getent group caddy >/dev/null || g=root
            install -d -m 750 -o root -g "$g" /etc/caddy/ioms
            install -m 644 "$ORIGIN_CERT" /etc/caddy/ioms/origin.pem; install -m 640 -g "$g" "$ORIGIN_KEY" /etc/caddy/ioms/origin.key
        fi
        CERT_OK=1
        gate certificate PASS "Cloudflare Origin, $(openssl x509 -in "$ORIGIN_CERT" -noout -enddate | cut -d= -f2)"
    fi
else
    gate certificate BLOCKED "no $ORIGIN_CERT / $ORIGIN_KEY yet (Cloudflare -> SSL/TLS -> Origin Server)"
fi

# ================================================================== publish
log "Publish IOMS behind Caddy"
PUBLISHED=0
if [[ $CADDY -eq 0 ]]; then
    PUBLISHED=1; gate publish PASS "nginx mode"
elif [[ $CERT_OK -eq 1 ]]; then
    if grep -qF 'import /etc/caddy/ioms.caddy' /etc/caddy/Caddyfile 2>/dev/null; then
        reload_ok=0; { systemctl reload caddy 2>/dev/null || caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; } && reload_ok=1
        [[ $reload_ok -eq 1 ]] && { PUBLISHED=1; gate publish PASS "already active; certificate reloaded"; } || gate publish FAIL "Caddy reload failed"
    elif bash "$here/caddy-site.sh" --apply; then
        PUBLISHED=1; gate publish PASS "one import line, existing sites verified unchanged"
    else
        gate publish FAIL "caddy-site.sh refused or rolled back (see above)"
    fi
else
    gate publish BLOCKED "waits for the Origin certificate -- IOMS is NOT reachable through Caddy"
fi

# ================================================================== verify
log "Verify"
if [[ $PUBLISHED -eq 1 ]]; then
    hc=(https://$DOMAIN --resolve 127.0.0.1 --insecure --e2e)
else
    hc=(http://$DOMAIN:$BACKEND --resolve 127.0.0.1)
fi
# Gates come from what the check PRINTED, section by section -- never from
# whether a step ran (a published site once reported PASS for a failed e2e).
hc_out="$(mktemp)"; bash "$here/healthcheck.sh" "${hc[@]}" | tee "$hc_out" || true
if sed '/^End to end/,$d' "$hc_out" | grep -q 'FAIL'; then gate pages FAIL "see the check lines above"; else gate pages PASS; fi
if [[ $PUBLISHED -ne 1 ]]; then gate "sign-up/sign-in" BLOCKED "runs once IOMS is published (secure cookies need HTTPS)"
elif sed -n '/^End to end/,$p' "$hc_out" | grep -q 'FAIL'; then gate "sign-up/sign-in" FAIL "$(sed -n '/^End to end/,$p' "$hc_out" | grep FAIL | head -1 | sed 's/^ *FAIL *//')"
elif sed -n '/^End to end/,$p' "$hc_out" | grep -q 'ok    account area renders'; then gate "sign-up/sign-in" PASS "register, sign out, sign in, account area"
else gate "sign-up/sign-in" FAIL "the e2e did not run"; fi
rm -f "$hc_out"

A() { sudo -u "$APP_USER" -H bash -c "cd '$APP_DIR' && $*"; }
if [[ $SKIP_PAYMENT -eq 1 ]]; then gate "duitku sandbox" BLOCKED "skipped by --skip-payment"
elif A "php artisan ioms:duitku-check" >/tmp/ioms-duitku.$$ 2>&1; then gate "duitku sandbox" PASS "$(tail -1 /tmp/ioms-duitku.$$)"
elif [[ "$(envget PAYMENT_GATEWAY)" != duitku ]]; then gate "duitku sandbox" BLOCKED "no Duitku credentials in production's .env (set PAYMENT_GATEWAY/DUITKU_* with migrate.sh env-set)"
else gate "duitku sandbox" FAIL "$(grep -m1 -E 'returned no|refuse|error' /tmp/ioms-duitku.$$ || tail -1 /tmp/ioms-duitku.$$)"; fi
rm -f /tmp/ioms-duitku.$$

if [[ $MAIL_READY -eq 1 ]]; then
    to="${MAIL_TO:-support@iomsuite.com}"
    if A "php artisan ioms:mail-check '$to'" >/dev/null 2>&1; then gate mail PASS "2 test messages accepted by Zoho for $to -- check spf/dkim/dmarc in their headers"
    else gate mail FAIL "Zoho refused (php artisan ioms:mail-check $to shows why)"; fi
else
    gate mail BLOCKED "Zoho app password not set yet (re-run; it asks once)"
fi

if /usr/local/bin/ioms-backup >/dev/null && /usr/local/bin/ioms-restore-check >/dev/null 2>&1; then gate "backup+restore" PASS
else gate "backup+restore" FAIL "ioms-backup / ioms-restore-check"; fi

# ================================================================== report
log "Report"
ready=1
for k in preflight fetch config hold data storage certificate publish pages "sign-up/sign-in" "duitku sandbox" mail "backup+restore"; do
    printf '  %-16s %s\n' "$k" "${GATE[$k]:-?}"
    [[ "${GATE[$k]:-?}" == PASS ]] || ready=0
done
echo
if [[ $ready -eq 1 ]]; then
    echo "  READY FOR CUTOVER. Nothing outside this server was changed. DNS waits for your approval"
    echo "  (runbook section 8); after the switch: sudo bash $0 go-live"
else
    echo "  NOT READY: fix the FAIL/BLOCKED lines above and run this again (it skips what is done)."
fi
