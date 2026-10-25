#!/usr/bin/env bash
#
# IOMS -- hand production over from IDCloudHost (cPanel) to Tencent in ONE step.
#
# Run in cPanel Terminal. Get it WITHOUT changing the live code (`git pull`
# would update the running application's files without a deploy):
#
#   cd ~/ioms && git fetch -q origin main && git show origin/main:deploy/tencent/cpanel-handoff.sh > ~/ioms-handoff.sh
#   bash ~/ioms-handoff.sh              # rehearsal: production keeps serving
#   bash ~/ioms-handoff.sh --final      # cutover: maintenance mode first, so no write is lost
#
# What it does:
#   1. a consistent database snapshot (mysqldump --single-transaction), storage/app,
#      a per-table row count and checksums -- the same export the import proves parity against;
#   2. the production configuration Tencent needs (APP_KEY for comparison, Duitku, VAPID,
#      Google sign-in, IOMS_*), NOT database or mail credentials;
#   3. all of it as ONE file, encrypted with AES-256 under a random one-time key, published
#      under an unguessable name in the site's public directory;
#   4. prints ONE line -- the transfer code (URL, key, checksum) -- for migrate-all.sh on Tencent;
#   5. deletes the published file as soon as the download shows in the access log, or after
#      --wait minutes, or on Ctrl-C. The plain export stays in ~/ioms-export-<stamp>/ (mode 700)
#      as your own copy.
#
# Production is only READ, except with --final, which puts it in maintenance mode first
# (undo: cd ~/ioms && php artisan up). Nothing is deleted from the application or database.

set -euo pipefail
APP="${HOME}/ioms" PUBLIC_URL="" WAIT_MIN=45 FINAL=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --app) APP="$2"; shift 2 ;;
        --public-url) PUBLIC_URL="$2"; shift 2 ;;
        --wait) WAIT_MIN="$2"; shift 2 ;;
        --final) FINAL=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done
say() { printf '\n==> %s\n' "$*"; }
die() { printf 'xx  %s\n' "$*" >&2; exit 1; }

cd "$APP" 2>/dev/null || die "No application at $APP (use --app)."
[[ -r .env ]] || die "Cannot read $APP/.env."
envval() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/'; }
for t in mysqldump mysql openssl tar gzip sha256sum php; do command -v "$t" >/dev/null || die "$t is not available here."; done
PHP_BIN="$(command -v php)"
for c in /opt/alt/php83/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php; do [[ -x "$c" ]] && PHP_BIN="$c" && break; done
PUBLIC_DIR="$APP/public"; [[ -d "$PUBLIC_DIR" ]] || die "No $PUBLIC_DIR."
[[ -n "$PUBLIC_URL" ]] || PUBLIC_URL="$(envval APP_URL)"
PUBLIC_URL="${PUBLIC_URL%/}"
[[ "$PUBLIC_URL" =~ ^https?:// ]] || die "Cannot tell the site's URL (APP_URL); pass --public-url https://iomsuite.com"

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$HOME/ioms-export-$STAMP"; mkdir -p "$OUT"; chmod 700 "$OUT"
CNF="$(mktemp)"; chmod 600 "$CNF"
PUBLISHED=""
cleanup() { rm -f "$CNF"; [[ -n "$PUBLISHED" && -f "$PUBLISHED" ]] && rm -f "$PUBLISHED" && echo "  removed the published bundle"; }
trap cleanup EXIT INT TERM

# ------------------------------------------------------------------ 0. final: stop writes
if [[ $FINAL -eq 1 ]]; then
    say "FINAL: maintenance mode first (no write can be lost after the snapshot)"
    "$PHP_BIN" artisan down --retry=60 >/dev/null && echo "  site is in maintenance. Rollback: cd $APP && $PHP_BIN artisan up"
fi

# ------------------------------------------------------------------ 1. export
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' \
    "$(envval DB_HOST)" "$(envval DB_PORT)" "$(envval DB_USERNAME)" "$(envval DB_PASSWORD)" > "$CNF"
DB="$(envval DB_DATABASE)"
say "Database $DB (consistent snapshot, no lock)"
if ! mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --no-tablespaces --set-gtid-purged=OFF "$DB" 2>/dev/null | gzip -9 > "$OUT/ioms.sql.gz" \
   || [[ "$(gzip -cd "$OUT/ioms.sql.gz" | head -c 100 | wc -c)" -lt 50 ]]; then
    mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --no-tablespaces "$DB" | gzip -9 > "$OUT/ioms.sql.gz"
fi
gzip -cd "$OUT/ioms.sql.gz" | tail -1 | grep -q 'Dump completed' || die "The dump did not complete."
mysql --defaults-extra-file="$CNF" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB' AND table_type='BASE TABLE' ORDER BY table_name" \
  | while read -r t; do printf '%s\t%s\n' "$t" "$(mysql --defaults-extra-file="$CNF" -N -e "SELECT COUNT(*) FROM \`$DB\`.\`$t\`")"; done > "$OUT/counts.tsv"
echo "  $(wc -l < "$OUT/counts.tsv") tables, $(du -h "$OUT/ioms.sql.gz" | cut -f1)"

say "Files (storage/app)"
tar -C "$APP" -czf "$OUT/storage-app.tar.gz" storage/app
echo "  $(tar -tzf "$OUT/storage-app.tar.gz" | grep -vc '/$') files, $(du -h "$OUT/storage-app.tar.gz" | cut -f1)"

say "Configuration Tencent needs (no DB or mail credentials)"
grep -E '^(APP_KEY|APP_NAME|APP_TIMEZONE|APP_LOCALE|IOMS_[A-Z0-9_]+|DUITKU_[A-Z_]+|PAYMENT_[A-Z_]+|MIDTRANS_[A-Z_]+|GOOGLE_[A-Z_]+|SAAS_[A-Z_]+|SESSION_LIFETIME|SESSION_DOMAIN|SANCTUM_STATEFUL_DOMAINS|MAIL_FROM_NAME)=' .env \
  | tac | awk -F= '!seen[$1]++' | tac > "$OUT/env.transfer" || true   # a repeated key: the LAST one is what Laravel uses
echo "  $(wc -l < "$OUT/env.transfer") keys: $(cut -d= -f1 "$OUT/env.transfer" | tr '\n' ' ')"
printf 'source_release=%s\nexported_at=%s\nfinal=%s\n' "$(git rev-parse --short HEAD 2>/dev/null || echo unknown)" "$STAMP" "$FINAL" > "$OUT/manifest"
( cd "$OUT" && sha256sum ioms.sql.gz storage-app.tar.gz counts.tsv env.transfer manifest > SHA256SUMS )

# ------------------------------------------------------------------ 2. encrypt + publish
say "Encrypting and publishing for Tencent"
KEY="$(openssl rand -hex 32)"; TOKEN="$(openssl rand -hex 16)"
PUBLISHED="$PUBLIC_DIR/ioms-handoff-$TOKEN.enc"
tar -C "$OUT" -cf - ioms.sql.gz storage-app.tar.gz counts.tsv env.transfer manifest SHA256SUMS \
  | IOMS_K="$KEY" openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass env:IOMS_K -out "$PUBLISHED"
chmod 644 "$PUBLISHED"
SUM="$(sha256sum "$PUBLISHED" | cut -d' ' -f1)"
URL="$PUBLIC_URL/ioms-handoff-$TOKEN.enc"
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -r 0-15 "$URL" || echo 000)"
[[ "$code" =~ ^20[06]$ ]] || die "The bundle is not reachable at its URL (HTTP $code). Nothing was sent anywhere; it has been removed."
echo "  $(du -h "$PUBLISHED" | cut -f1) encrypted, reachable over HTTPS"

echo
echo "------------------------------------------------------------------------------------"
echo " TRANSFER CODE -- paste it into migrate-all.sh on Tencent (it asks; input is hidden)."
echo " It is the only copy of the key. Do not paste it anywhere else."
echo
echo "   IOMS1|$URL|$KEY|$SUM"
echo "------------------------------------------------------------------------------------"
unset KEY

# ------------------------------------------------------------------ 3. wait, then remove
say "Waiting for the download (up to $WAIT_MIN min; Enter = remove now)"
logs="$(ls -1 "$HOME"/access-logs/* "$HOME"/logs/*access* 2>/dev/null | head -5 || true)"
deadline=$(( $(date +%s) + WAIT_MIN * 60 ))
while [[ $(date +%s) -lt $deadline ]]; do
    if [[ -n "$logs" ]] && grep -h "ioms-handoff-$TOKEN.enc" $logs 2>/dev/null | grep -qE '" 200 [0-9]{4,}'; then
        echo "  downloaded (access log)"; sleep 5; break
    fi
    if [[ -t 0 ]]; then
        if read -r -t 10 _; then echo "  removing on request"; break; fi
    else
        sleep 10
    fi
done
# cleanup() removes the published file on exit
echo "Done. Your plain copy: $OUT"
