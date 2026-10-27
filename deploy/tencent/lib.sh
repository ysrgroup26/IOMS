# Shared helpers for deploy/tencent/*.sh. Sourced, never executed.

log()  { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mxx  %s\033[0m\n' "$*" >&2; exit 1; }

# systemd on the real instance; `service` in a container rehearsal (no PID-1
# systemd). Every service action goes through this, so the same script runs
# in both places and the rehearsal exercises the real code path.
has_systemd() { [[ -d /run/systemd/system ]]; }
svc() { # svc <action> <unit>
    local action="$1" unit="$2"
    if has_systemd; then
        case "$action" in
            enable) systemctl enable --now "$unit" ;;
            *) systemctl "$action" "$unit" ;;
        esac
    else
        case "$action" in
            # Leave a running service alone; `start || restart` killed a
            # running supervisord in the rehearsal and never brought it back.
            enable) service "$unit" status >/dev/null 2>&1 || service "$unit" start ;;
            reload) service "$unit" reload || service "$unit" restart ;;
            *) service "$unit" "$action" ;;
        esac
    fi
}

# Is <unit> running? The init system answers; without systemd, the process
# names those daemons really carry (PHP-FPM's is `php-fpm: master process`).
service_running() { # service_running <unit>
    if has_systemd; then systemctl is-active --quiet "$1"; return; fi
    case "$1" in
        php*-fpm) pgrep -f '^php-fpm: master process' >/dev/null ;;
        mysql)    pgrep -x mysqld >/dev/null ;;
        *)        pgrep -x "$1" >/dev/null ;;
    esac
}

# The live Cloudflare ranges, falling back to the shipped copy.
cloudflare_ranges() {
    local here; here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    local v4 v6
    v4="$(curl -fsS --max-time 10 https://www.cloudflare.com/ips-v4 2>/dev/null || true)"
    v6="$(curl -fsS --max-time 10 https://www.cloudflare.com/ips-v6 2>/dev/null || true)"
    if [[ -n "$v4" ]] && grep -qE '^[0-9]+\.' <<<"$v4"; then
        printf '%s\n%s\n' "$v4" "$v6" | grep -E '^[0-9a-f:.]+/[0-9]+$'
    else
        warn "Could not fetch Cloudflare's live ranges; using the shipped fallback list."
        grep -E '^[0-9a-f:.]+/[0-9]+$' "$here/cloudflare-ips.txt"
    fi
}
