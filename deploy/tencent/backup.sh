#!/usr/bin/env bash
#
# IOMS -- nightly backup: database dump + uploaded files + .env, kept 14 days.
# Credentials come from /root/ioms-db.cnf (written by provision.sh, mode 600);
# nothing secret is passed on a command line or printed.
#
# A backup that has never been restored is a hope. restore-check.sh restores
# the newest dump into a scratch database and counts rows; run it weekly.
set -euo pipefail
STAMP="$(date +%F-%H%M)"
DEST="/var/backups/ioms/${STAMP}"
APP="/srv/ioms/app"
KEEP_DAYS="${KEEP_DAYS:-14}"
umask 077
mkdir -p "$DEST"
# A failed run must not leave a directory that looks like a backup.
trap 'rc=$?; [[ $rc -ne 0 ]] && { rm -rf "$DEST"; echo "$(date -Is) BACKUP FAILED ($rc)" >&2; }' EXIT

mysqldump --defaults-extra-file=/root/ioms-db.cnf --single-transaction --quick \
    --routines --triggers --no-tablespaces ioms | gzip -9 > "$DEST/ioms.sql.gz"
tar -C "$APP" -czf "$DEST/storage-app.tar.gz" storage/app
cp "$APP/.env" "$DEST/env.backup"
( cd "$DEST" && sha256sum ./* > SHA256SUMS )

find /var/backups/ioms -mindepth 1 -maxdepth 1 -type d -mtime +"$KEEP_DAYS" -exec rm -rf {} +
echo "$(date -Is) backup ok: $DEST ($(du -sh "$DEST" | cut -f1))"
# OFF-SITE: a backup on the same disk does not survive losing the instance.
# Copy $DEST to Tencent COS or another host here once a bucket is chosen
# (owner decision -- see TENCENT-MIGRATION.md section 7). Lighthouse
# snapshots are the second line, not a substitute.
