#!/usr/bin/env bash
#
# IOMS -- export production data FROM IDCloudHost (cPanel Terminal), for the
# Tencent import. READ-ONLY for production: it reads the database and
# storage/app and writes four files to ~/ioms-export-<stamp>/. Nothing in
# ~/ioms is changed, the site stays up, nothing is deleted.
#
#   cd ~/ioms && bash deploy/tencent/export-cpanel.sh
#
# Outputs:
#   ioms.sql.gz          mysqldump --single-transaction (consistent, no lock)
#   storage-app.tar.gz   storage/app (uploads, private documents)
#   counts.tsv           row count per table, so the import can PROVE parity
#   SHA256SUMS           checksums of the three above
#
# The database password is read from .env into a temporary option file
# (mode 600) and never placed on a command line or printed.
set -euo pipefail
APP="${APP:-$HOME/ioms}"
cd "$APP"
envval() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }

STAMP="$(date +%Y%m%d-%H%M)"
OUT="$HOME/ioms-export-$STAMP"
mkdir -p "$OUT"; chmod 700 "$OUT"

CNF="$(mktemp)"; chmod 600 "$CNF"; trap 'rm -f "$CNF"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' \
    "$(envval DB_HOST)" "$(envval DB_PORT)" "$(envval DB_USERNAME)" "$(envval DB_PASSWORD)" > "$CNF"
DB="$(envval DB_DATABASE)"

echo "Dumping database $DB ..."
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers \
    --no-tablespaces --set-gtid-purged=OFF "$DB" 2>/dev/null | gzip -9 > "$OUT/ioms.sql.gz" \
  || mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers \
    --no-tablespaces "$DB" | gzip -9 > "$OUT/ioms.sql.gz"

echo "Counting rows ..."
mysql --defaults-extra-file="$CNF" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB' AND table_type='BASE TABLE' ORDER BY table_name" \
  | while read -r t; do
      printf '%s\t%s\n' "$t" "$(mysql --defaults-extra-file="$CNF" -N -e "SELECT COUNT(*) FROM \`$DB\`.\`$t\`")"
    done > "$OUT/counts.tsv"

echo "Archiving storage/app ..."
tar -C "$APP" -czf "$OUT/storage-app.tar.gz" storage/app

( cd "$OUT" && sha256sum ioms.sql.gz storage-app.tar.gz counts.tsv > SHA256SUMS )
echo
echo "Export complete: $OUT"
ls -lh "$OUT"
echo "Tables: $(wc -l < "$OUT/counts.tsv")   Release: $(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
echo "Copy the whole directory to the Tencent instance (scp -r), then: migrate.sh import --from <dir>"
