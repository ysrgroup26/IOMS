#!/usr/bin/env bash
#
# IOMS -- restrict web traffic (80/443) on the origin to Cloudflare's edge.
#
# Run ONLY AFTER the Cloudflare cutover is verified (TENCENT-MIGRATION.md
# section 8): once it is applied, the origin answers web requests from
# Cloudflare only, so the site is reachable solely through the proxied
# records. SSH rules are not touched.
#
#   sudo bash cloudflare-lockdown.sh --dry-run    print the ufw rules it would apply
#   sudo bash cloudflare-lockdown.sh              apply them
#   sudo bash cloudflare-lockdown.sh --undo       reopen 80/443 to everyone (rollback)
#
# Also mirror it in the Lighthouse console firewall: either the same ranges
# for 80/443, or leave 80/443 open there and let ufw decide -- but never
# leave SSH open to the world in either place.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$here/lib.sh"
[[ $EUID -eq 0 ]] || die "Run as root."

mode="${1:-apply}"
# Caddy mode (Hermes image): 80/443 also carry the Hermes dashboard and any
# other Caddy site. A port-level Cloudflare-only rule would cut those off, so it
# is refused here; restrict the IOMS site inside Caddy instead (runbook 3.7).
if ss -tlnpH '( sport = :443 )' 2>/dev/null | grep -q '"caddy"' && [[ "$mode" != --undo ]]; then
    die "Caddy owns 80/443 (it also serves Hermes). A port-level lockdown would cut Hermes off. See TENCENT-MIGRATION.md 3.7."
fi
ranges="$(cloudflare_ranges)"
[[ "$(wc -l <<<"$ranges")" -ge 10 ]] || die "Cloudflare range list looks wrong ($(wc -l <<<"$ranges") entries); refusing."

rules=()
while read -r r; do
    [[ -n "$r" ]] && rules+=("allow proto tcp from $r to any port 80,443 comment cloudflare")
done <<<"$ranges"

case "$mode" in
    --dry-run)
        echo "Would remove:  allow 'Nginx Full' / 80 / 443 from anywhere"
        printf 'Would add:     ufw %s\n' "${rules[@]}"
        ;;
    --undo)
        log "Reopening 80/443 to everyone"
        ufw allow 'Nginx Full'
        ufw status numbered | grep -E 'cloudflare' | awk -F'[][]' '{print $2}' | sort -rn | while read -r n; do ufw --force delete "$n"; done
        ufw status verbose
        ;;
    apply)
        command -v ufw >/dev/null || die "ufw is not installed (run provision.sh)."
        ufw status | grep -q 'Status: active' || die "ufw is not active; run provision.sh --apply-firewall --ssh-from <your-ip> first, so SSH stays reachable."
        log "Allowing 80/443 from $(wc -l <<<"$ranges") Cloudflare ranges"
        for rule in "${rules[@]}"; do
            # shellcheck disable=SC2086
            ufw $rule >/dev/null
        done
        log "Removing the open-to-everyone web rules"
        ufw --force delete allow 'Nginx Full' >/dev/null 2>&1 || true
        ufw --force delete allow 80/tcp >/dev/null 2>&1 || true
        ufw --force delete allow 443/tcp >/dev/null 2>&1 || true
        ufw status verbose
        echo
        echo "Now verify through Cloudflare:  bash healthcheck.sh https://iomsuite.com"
        echo "and that the bare origin no longer answers web traffic from elsewhere:"
        echo "  curl -sk --max-time 5 --resolve iomsuite.com:443:43.173.21.31 https://iomsuite.com/ (from a non-Cloudflare host: must time out)"
        echo "Rollback: sudo bash cloudflare-lockdown.sh --undo"
        ;;
    *) die "Usage: cloudflare-lockdown.sh [--dry-run|--undo]" ;;
esac
