#!/usr/bin/env bash
#
# test-workflows.sh -- regression checks for the workflows' shell and jq (run by CI).
#
#  1. issue-by-marker.sh finds the right issues, including a null body and a marker
#     containing characters that are special to jq and to the shell.
#  2. No workflow builds a jq program inside a DOUBLE-quoted string (--jq "..."):
#     that is how `(.body // "")` became `(.body // )` and failed a deploy.
#  3. Every single-quoted --jq program in the workflows compiles.
set -euo pipefail
cd "$(dirname "$0")/../.."
fail=0
ok() { echo "ok    $*"; }
bad() { echo "FAIL  $*"; fail=1; }

s=.github/scripts/issue-by-marker.sh
fixture='[
  {"number": 7, "body": null},
  {"number": 8, "body": "unrelated"},
  {"number": 9, "body": "<!-- ioms-deploy:deploy:0ed5fe4c8e85a69418007d9ecfe47297a40228fa -->\n| | |"},
  {"number": 10, "body": "<!-- ioms-deploy:ci:abc -->\nCI failed"},
  {"number": 11, "body": "<!-- ioms-deploy:deploy:0ed5fe4c8e85a69418007d9ecfe47297a40228fa -->\nsecond"}
]'
got="$(bash "$s" '<!-- ioms-deploy:deploy:0ed5fe4c8e85a69418007d9ecfe47297a40228fa -->' <<<"$fixture")"
[[ "$got" == 9 ]] && ok "first issue by exact marker" || bad "first issue by marker: got '$got', want 9"
got="$(bash "$s" --all '<!-- ioms-deploy:' <<<"$fixture" | paste -sd, -)"
[[ "$got" == 9,10,11 ]] && ok "all issues by prefix" || bad "all by prefix: got '$got', want 9,10,11"
got="$(bash "$s" '<!-- none -->' <<<"$fixture")"
[[ -z "$got" ]] && ok "no match prints nothing" || bad "no match: got '$got'"
got="$(bash "$s" '"); .[] | ("' <<<"$fixture")"
[[ -z "$got" ]] && ok "a marker is data, not jq code" || bad "marker injection: got '$got'"

if grep -nE -- '--jq[[:space:]]+"' .github/workflows/*.yml; then
    bad "a workflow builds a jq program in a double-quoted string (see issue-by-marker.sh)"
else
    ok "no double-quoted --jq programs"
fi

while IFS= read -r prog; do
    # Compile without running: jq parses the whole program but never evaluates the branch.
    if jq -n "if false then ($prog) else empty end" >/dev/null 2>&1; then
        ok "jq compiles: ${prog:0:70}"
    else
        bad "jq does not compile: $prog"
    fi
done < <(grep -ohE -- "--jq '[^']+'" .github/workflows/*.yml | sed -E "s/^--jq '//; s/'$//")

exit $fail
