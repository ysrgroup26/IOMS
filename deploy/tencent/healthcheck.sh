#!/usr/bin/env bash
#
# IOMS -- functional and operational checks against a deployed host.
#
#   bash healthcheck.sh https://staging.iomsuite.com
#   bash healthcheck.sh https://iomsuite.com --resolve 43.173.21.31 --insecure --e2e
#
# --resolve IP   send the request to IP while keeping the real host name in
#                SNI and Host -- tests Tencent BEFORE DNS points at it, and
#                bypasses Cloudflare to test the origin itself.
# --insecure     accept the origin certificate (a Cloudflare Origin CA
#                certificate is valid only to Cloudflare, not to curl).
# --e2e          create a throwaway account (e2e-<time>@example.com),
#                sign out, sign in again. WRITES ONE INERT ACCOUNT -- no
#                organization, no subscription, no invoice -- and nothing
#                else. Never pays.
#
# Otherwise read-only: it GETs public pages and sends ONE unsigned payment
# callback, which a correct deployment refuses without changing anything.
set -uo pipefail
BASE="${1:?usage: healthcheck.sh https://host [--resolve IP] [--insecure] [--e2e]}"; shift
BASE="${BASE%/}"
RESOLVE="" INSECURE=0 E2E=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --resolve) RESOLVE="$2"; shift 2 ;; --insecure) INSECURE=1; shift ;; --e2e) E2E=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done
HOST="${BASE#*://}"; HOST="${HOST%%/*}"; HOST="${HOST%%:*}"
SCHEME="${BASE%%://*}"
CURL=(curl -s --max-time 20)
[[ -n "$RESOLVE" ]] && CURL+=(--resolve "$HOST:443:$RESOLVE" --resolve "$HOST:80:$RESOLVE" --noproxy "$HOST")
[[ $INSECURE -eq 1 ]] && CURL+=(-k)
fail=0

check() { # label expected-statuses method path
    local code
    code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' -X "$3" "$BASE$4")"
    if [[ ",$2," == *",$code,"* ]]; then printf '  ok    %-44s %s\n' "$1" "$code"
    else printf '  FAIL  %-44s got %s, want %s\n' "$1" "$code" "$2"; fail=1; fi
}

echo "IOMS health check: $BASE${RESOLVE:+ (resolved to $RESOLVE)}"
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

if [[ "$SCHEME" == https ]]; then
    echo "TLS:"
    target="${RESOLVE:-$HOST}"
    if cert="$(echo | openssl s_client -servername "$HOST" -connect "$target:443" 2>/dev/null | openssl x509 -noout -subject -issuer -enddate 2>/dev/null)" && [[ -n "$cert" ]]; then
        sed 's/^/  /' <<<"$cert"
    else
        echo "  FAIL  no certificate presented"; fail=1
    fi
    code="$("${CURL[@]}" -o /dev/null -w '%{http_code}' "http://$HOST/")"
    [[ "$code" =~ ^30[18]$ ]] && echo "  ok    http redirects to https ($code)" || { echo "  FAIL  http -> https ($code)"; fail=1; }
fi

echo "Error pages must not leak internals:"
body="$("${CURL[@]}" "$BASE/this-page-does-not-exist")"
if grep -qiE 'stack trace|vendor/laravel|SQLSTATE|Whoops' <<<"$body"; then echo "  FAIL  debug output visible (APP_DEBUG must be false)"; fail=1; else echo "  ok    no debug output"; fi

if [[ $E2E -eq 1 ]]; then
    echo "End to end (one throwaway account):"
    jar="$(mktemp)"; trap 'rm -f "$jar"' EXIT
    email="e2e-$(date +%s)@example.com"
    pass="E2e$(openssl rand -hex 8)x9"
    xsrf() { awk '$6=="XSRF-TOKEN" {print $7}' "$jar" | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))'; }
    post() { # path data -> prints "status location"
        "${CURL[@]}" -b "$jar" -c "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' \
            -H "X-XSRF-TOKEN: $(xsrf)" -H 'Accept: text/html' --data "$2" "$BASE$1"
    }
    page() { "${CURL[@]}" -b "$jar" -c "$jar" "$BASE$1"; }

    page /register >/dev/null
    r="$(post /register "name=E2E+Check&email=$email&password=$pass&password_confirmation=$pass&terms=1")"
    [[ "$r" == "302 "*"/register/welcome" ]] && echo "  ok    register -> welcome" || { echo "  FAIL  register answered: $r"; fail=1; }
    page /register/welcome | grep -qE 'Auth(\\)?/AccountCreated' && echo "  ok    welcome page renders for the new account" || { echo "  FAIL  welcome page"; fail=1; }

    r="$(post /logout "")"
    [[ "$r" == 302* ]] && echo "  ok    sign out" || { echo "  FAIL  sign out: $r"; fail=1; }

    page /login >/dev/null
    r="$(post /login "email=$email&password=$pass")"
    [[ "$r" == "302 "* && "$r" != *"/login" ]] && echo "  ok    sign in -> ${r#302 }" || { echo "  FAIL  sign in answered: $r"; fail=1; }
    page /account | grep -qE 'Account(\\)?/Overview' && echo "  ok    account area renders" || { echo "  FAIL  account area"; fail=1; }
    echo "  note  throwaway account: $email (inert: no organization, no subscription)"
fi

[[ $fail -eq 0 ]] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit $fail
