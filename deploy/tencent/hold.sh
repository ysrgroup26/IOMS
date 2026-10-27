#!/usr/bin/env bash
#
# The migration hold: a copy of production must not run customers' jobs or
# scheduled work before the cutover is approved.
#
#   sudo bash hold.sh status     READ-ONLY. Exit 0 only when everything is held.
#   sudo bash hold.sh on         Hold, or re-assert the hold. Idempotent.
#   sudo bash hold.sh release    Undo the hold (migrate-all.sh go-live calls this).
#
# A marker file alone is not a hold: the worker's supervisor program has
# autostart=true, so a supervisor restart or a reboot started it again
# (reproduced). `on` therefore makes the hold durable where it is decided:
#   - worker:    autostart=false and autorestart=false in its supervisor
#                program (the original is kept, byte for byte, in $STATE),
#                applied with reread/update, then stopped;
#   - scheduler: the cron line that runs schedule:run is commented out;
#   - marker:    /var/lib/ioms-migrate/hold, which migrate.sh, verify and
#                ioms-ops read (ioms-ops refuses to restart the worker or run
#                schedule:run while it exists).
# `release` restores the original program file and cron line, applies them
# (the worker starts because autostart is true again), and removes the marker.
#
# Never touched: the application, its .env, the database, the queue's jobs.

set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$here/lib.sh"

STATE=/var/lib/ioms-migrate
HOLD="$STATE/hold"
PROG=ioms-worker
CONF=/etc/supervisor/conf.d/ioms-worker.conf
ORIG="$STATE/ioms-worker.conf.orig"
CRON=/etc/cron.d/ioms
MARK='# held by migrate-all until go-live: '

[[ $EUID -eq 0 ]] || die "Run as root (sudo, as iomsadmin)."
cmd="${1:-status}"

worker_state() {
    pgrep -x supervisord >/dev/null || { echo "STOPPED (supervisord not running)"; return 0; }
    supervisorctl status "$PROG" 2>/dev/null | awk '{print $2}' || true
}
conf_value() { grep -E "^$1=" "$CONF" 2>/dev/null | tail -1 | cut -d= -f2 | tr -d ' '; }
schedule_active() { grep -qE '^[^#].*schedule:run' "$CRON" 2>/dev/null; }
apply_conf() { supervisorctl reread >/dev/null 2>&1 || true; supervisorctl update >/dev/null 2>&1 || true; }

# What is queued, so go-live is a decision about real rows (read-only).
queue_counts() {
    local cnf=/root/ioms-db.cnf
    [[ -r "$cnf" ]] || { echo "  queue      (no /root/ioms-db.cnf; not counted)"; return 0; }
    local jobs failed
    jobs="$(mysql --defaults-extra-file="$cnf" -N -e 'SELECT COUNT(*) FROM jobs' ioms 2>/dev/null || echo '?')"
    failed="$(mysql --defaults-extra-file="$cnf" -N -e 'SELECT COUNT(*) FROM failed_jobs' ioms 2>/dev/null || echo '?')"
    echo "  queue      $jobs pending job(s), $failed failed job(s) -- these run when the worker is released"
}

status() {
    local ok=0 w
    w="$(worker_state)"
    log "Migration hold"
    if [[ -f "$HOLD" ]]; then echo "  marker     present (since $(cat "$HOLD"))"; else echo "  marker     ABSENT"; ok=1; fi
    if [[ "$w" == RUNNING || "$w" == STARTING ]]; then echo "  worker     $w  <- NOT held"; ok=1
    else echo "  worker     ${w:-unknown to supervisor}"; fi
    if [[ "$(conf_value autostart)" == false && "$(conf_value autorestart)" == false ]]; then
        echo "  restart    autostart=false, autorestart=false (survives a supervisor restart and a reboot)"
    else
        echo "  restart    autostart=$(conf_value autostart), autorestart=$(conf_value autorestart)  <- a restart or reboot would start the worker"; ok=1
    fi
    if schedule_active; then echo "  scheduler  ACTIVE in $CRON  <- NOT held"; ok=1
    elif grep -qF "$MARK" "$CRON" 2>/dev/null; then echo "  scheduler  held (schedule:run commented out in $CRON)"
    else echo "  scheduler  no schedule:run line in $CRON"; fi
    queue_counts
    if [[ $ok -eq 0 ]]; then echo "  HELD"; else echo "  NOT FULLY HELD"; fi
    return $ok
}

case "$cmd" in
status)
    status
    ;;
on)
    install -d -m 700 "$STATE"
    [[ -f "$CONF" ]] || die "$CONF does not exist (provision.sh writes it)."
    log "Holding the queue worker and the scheduler"
    [[ -f "$HOLD" ]] || date -Is > "$HOLD"
    # Keep the ORIGINAL once; a second `on` must not overwrite it with a held copy.
    [[ -f "$ORIG" ]] || cp -a "$CONF" "$ORIG"
    sed -i -E 's/^autostart=.*/autostart=false/; s/^autorestart=.*/autorestart=false/' "$CONF"
    grep -qE '^autostart=' "$CONF" || printf 'autostart=false\n' >> "$CONF"
    grep -qE '^autorestart=' "$CONF" || printf 'autorestart=false\n' >> "$CONF"
    # update re-adds a changed program; with autostart=false it stays stopped.
    apply_conf
    supervisorctl stop "$PROG" >/dev/null 2>&1 || true
    sed -i -E "s|^([^#].*schedule:run.*)\$|$MARK\\1|" "$CRON"
    status || die "The hold is not complete -- see above."
    ;;
release)
    [[ -f "$HOLD" ]] || { echo "  nothing is held."; exit 0; }
    log "Releasing the scheduler and the queue worker"
    if [[ -f "$ORIG" ]]; then cp -a "$ORIG" "$CONF"; fi
    sed -i -E "s|^$MARK||" "$CRON"
    rm -f "$HOLD"
    apply_conf
    supervisorctl start "$PROG" >/dev/null 2>&1 || true
    rm -f "$ORIG"
    echo "  worker: $(worker_state); scheduler: $(schedule_active && echo active || echo 'NOT active')"
    echo "  watch: sudo ioms-ops logs app 100"
    ;;
*)
    die "Usage: hold.sh status|on|release"
    ;;
esac
