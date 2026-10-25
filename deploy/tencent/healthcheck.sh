#!/usr/bin/env bash
#
# IOMS -- functional and operational checks against a deployed host.
# Run from ANY machine against the staging host first, then against
# production after cutover:
#
#   bash healthcheck.sh https://staging.iomsuite.com
#
# Read-only: it GETs public pages and sends ONE unsigned callback, which a
# correct deployment refuses (403) without changing anything. It never
# registers, logs in, or pays.
set -uo pipefail
BASE="${1:?usage: healthcheck.sh https://host}"
BASE="${BASE%/}"
fail=0
check() { # label expected-status method path
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' -X "$3" --max-time 20 "$BASE$4")"
    if [[ ",$2," == *",$code,"* ]]; then printf '  ok    %-44s %s\n' "$1" "$code"
    else printf '  FAIL  %-44s got %s, want %s\n' "$1" "$code" "$2"; fail=1; fi
}
echo "IOMS health check: $BASE"
check "Landing page"                  200 GET  /
check "Pricing"                       200 GET  /pricing
check "Contact (support details)"     200 GET  /contact
check "Terms"                         200 GET  /terms
check "Privacy"                       200 GET  /privacy
check "Refund policy"                 200 GET  /refund-policy
check "Sign in"                       200 GET  /login
check "Registration form"             200 GET  /register
check "Unsigned Duitku callback"      403,503 POST /webhooks/payment/duitku
check ".env is not served"            403,404 GET  /.env
check ".git is not served"            403,404 GET  /.git/config
check "Unknown page"                  404 GET  /this-page-does-not-exist-$RANDOM

echo "TLS:"
if [[ "$BASE" == https://* ]]; then
    host="${BASE#https://}"; host="${host%%/*}"
    echo | openssl s_client -servername "$host" -connect "$host:443" 2>/dev/null | openssl x509 -noout -subject -enddate || { echo "  FAIL  certificate"; fail=1; }
    http_code="$(curl -s -o /dev/null -w '%{http_code}' "http://$host/")"
    [[ "$http_code" =~ ^30[18]$ ]] && echo "  ok    http redirects to https ($http_code)" || { echo "  FAIL  http -> https ($http_code)"; fail=1; }
fi

echo "Error pages must not leak internals:"
body="$(curl -s --max-time 20 "$BASE/this-page-does-not-exist")"
if grep -qiE 'stack trace|vendor/laravel|SQLSTATE|Whoops' <<<"$body"; then echo "  FAIL  debug output visible (APP_DEBUG must be false)"; fail=1; else echo "  ok    no debug output"; fi

[[ $fail -eq 0 ]] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit $fail
