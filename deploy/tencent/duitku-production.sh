#!/usr/bin/env bash
#
# duitku-production.sh -- switch iomsuite.com from Duitku Sandbox to Duitku Production, safely,
# from a phone (Tencent Cloud console -> instance -> Login, the browser terminal) or any root shell.
# Implements the "Switch" and first "Verify" steps of docs/runbooks/DUITKU-PRODUCTION-ACTIVATION.md §8.
#
# Run ONCE as root on the Tencent instance. One line; it reads this script from origin/main without
# touching the running application:
#
#   cd /srv/ioms/app && sudo -u ioms git fetch -q origin main && git -c safe.directory=$PWD show origin/main:deploy/tencent/duitku-production.sh | sudo bash -s
#
# Undo (restores the .env saved by the last run and re-caches the config):
#
#   cd /srv/ioms/app && git -c safe.directory=$PWD show origin/main:deploy/tencent/duitku-production.sh | sudo bash -s -- --rollback
#
# What it does:
#   1. backs up .env (mode 600) next to it;
#   2. ASKS for the production Merchant Code and API Key on the terminal (the key is not echoed).
#      They never pass through chat, Git, CI or logs;
#   3. sets PAYMENT_GATEWAY=duitku, DUITKU_MERCHANT_CODE, DUITKU_API_KEY, DUITKU_IS_PRODUCTION=true,
#      leaving no Sandbox value in the file, then `config:cache`;
#   4. proves the credentials against passport.duitku.com by listing payment methods for Rp10.000
#      (no transaction is created). If that fails, it puts the previous .env back by itself.
# It prints the merchant code and the method count, never the key. It does not touch DNS,
# Cloudflare, the database, payments already made, or anything else in .env.
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Jalankan sebagai root (sudo)." >&2; exit 1; }
APP_DIR="${IOMS_APP_DIR:-/srv/ioms/app}"
APP_USER="${IOMS_APP_USER:-ioms}"
ENV="$APP_DIR/.env"
[[ -f "$ENV" ]] || { echo "Tidak menemukan $ENV" >&2; exit 1; }

as_app() { sudo -u "$APP_USER" bash -lc "cd '$APP_DIR' && $1"; }
cache() { as_app "php artisan config:cache" >/dev/null; }

latest_backup() { ls -1t "$ENV".bak-duitku-* 2>/dev/null | head -1; }

if [[ "${1:-}" == "--rollback" ]]; then
    b="$(latest_backup)"
    [[ -n "$b" ]] || { echo "Tidak ada cadangan .env dari skrip ini." >&2; exit 1; }
    cp -p "$b" "$ENV"
    cache
    echo "Dikembalikan dari $b dan config:cache dijalankan."
    exit 0
fi

state() {
    as_app "php artisan tinker --execute='\$d=config(\"payment.duitku\"); echo json_encode([\"gateway\"=>config(\"payment.gateway\"),\"merchant_code\"=>\$d[\"merchant_code\"]??null,\"is_production\"=>(bool)(\$d[\"is_production\"]??false)]);'" 2>/dev/null | tail -1
}

echo "== Sebelum: $(state)"

backup="$ENV.bak-duitku-$(date +%Y%m%d%H%M%S)"
cp -p "$ENV" "$backup"
chmod 600 "$backup"
echo "== Cadangan: $backup"

read -r -p "Merchant Code PRODUCTION (contoh D12345): " MC </dev/tty
MC="$(echo "$MC" | tr -d '[:space:]')"
[[ "$MC" =~ ^D[0-9]{3,}$ ]] || { echo "Merchant Code tidak sesuai format (D + angka). Dibatalkan, .env tidak diubah." >&2; exit 1; }
[[ "$MC" != "DS36132" ]] || { echo "Itu Merchant Code SANDBOX. Dibatalkan." >&2; exit 1; }
read -r -s -p "API Key PRODUCTION (tidak ditampilkan): " AK </dev/tty; echo
AK="$(echo "$AK" | tr -d '[:space:]')"
[[ ${#AK} -ge 20 ]] || { echo "API Key terlalu pendek. Dibatalkan, .env tidak diubah." >&2; exit 1; }

set_env() { # KEY VALUE -- replace the line, or append it; values are written literally
    local key="$1" val="$2" tmp
    tmp="$(mktemp)"
    KEY="$key" VAL="$val" awk 'BEGIN{k=ENVIRON["KEY"]; v=ENVIRON["VAL"]; done=0}
        $0 ~ "^"k"=" {print k"="v; done=1; next} {print}
        END{if(!done) print k"="v}' "$ENV" > "$tmp"
    cat "$tmp" > "$ENV"   # keeps the file's owner and mode
    rm -f "$tmp"
}
set_env PAYMENT_GATEWAY duitku
set_env DUITKU_MERCHANT_CODE "$MC"
set_env DUITKU_API_KEY "$AK"
set_env DUITKU_IS_PRODUCTION true
unset AK
cache

echo "== Sesudah: $(state)"
echo "== Menguji kredensial ke passport.duitku.com (tanpa membuat transaksi)..."
check="$(as_app "php artisan tinker --execute='try { \$m = app(\App\Services\Payments\DuitkuGateway::class)->getPaymentMethods(10000); echo \"OK \".count(\$m); } catch (\Throwable \$e) { echo \"GAGAL \".class_basename(\$e).\": \".substr(\$e->getMessage(),0,160); }'" 2>&1 | tail -1)"
echo "   $check"

if [[ "$check" != OK* || "$check" == "OK 0" ]]; then
    echo "!! Uji gagal: mengembalikan .env sebelumnya." >&2
    cp -p "$backup" "$ENV"
    cache
    echo "   Dikembalikan. Produksi tetap memakai konfigurasi lama. Periksa Merchant Code / API Key lalu ulangi." >&2
    exit 1
fi

echo
echo "SELESAI. IOMS kini memakai Duitku PRODUCTION ($MC). Langkah berikutnya (runbook §8):"
echo "  - Deploy workflow action=status: ALL CHECKS PASSED, callback tanpa tanda tangan 403."
echo "  - Satu pembayaran nyata nominal terkecil dengan kartu/VA pemilik."
echo "  - Kabari Claude Code untuk verifikasi, pensiun Sandbox, dan laporan."
