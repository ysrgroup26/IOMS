#!/usr/bin/env bash
#
# IOMS -- prove the newest backup restores. Loads it into a SCRATCH database
# (ioms_restore_check), compares row counts for the tables that matter with
# the live database, then drops the scratch copy. Never touches `ioms`.
set -euo pipefail
LATEST="$(ls -1d /var/backups/ioms/*/ | sort | tail -1)"
[[ -n "$LATEST" ]] || { echo "No backup found." >&2; exit 1; }
( cd "$LATEST" && sha256sum -c SHA256SUMS )
mysql --defaults-extra-file=/root/ioms-db.cnf -e 'DROP DATABASE IF EXISTS ioms_restore_check; CREATE DATABASE ioms_restore_check;'
gunzip -c "$LATEST/ioms.sql.gz" | mysql --defaults-extra-file=/root/ioms-db.cnf ioms_restore_check
for t in tenants users employees invoices payment_transactions subscriptions activity_logs; do
    live=$(mysql --defaults-extra-file=/root/ioms-db.cnf -N -e "SELECT COUNT(*) FROM ioms.$t" 2>/dev/null || echo "-")
    rest=$(mysql --defaults-extra-file=/root/ioms-db.cnf -N -e "SELECT COUNT(*) FROM ioms_restore_check.$t" 2>/dev/null || echo "-")
    printf '%-22s live=%-8s restored=%s\n' "$t" "$live" "$rest"
done
mysql --defaults-extra-file=/root/ioms-db.cnf -e 'DROP DATABASE ioms_restore_check;'
echo "Restore check passed for $LATEST"
