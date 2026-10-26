#!/usr/bin/env bash
#
# issue-by-marker.sh -- find deploy issues by the marker their body starts with.
#
#   gh api "repos/$R/issues?state=open&labels=deploy-failure&per_page=100" | issue-by-marker.sh MARKER
#       -> the number of the first issue whose body starts with MARKER (or nothing)
#   ... | issue-by-marker.sh --all PREFIX
#       -> every such number, one per line
#
# The marker reaches jq as DATA (--arg), never spliced into the jq program. Building
# the program inside a double-quoted shell string broke the Deploy workflow once:
# `"(.body // "")"` closes and reopens the shell quotes, so jq received `(.body // )`.
# Tested by .github/scripts/test-workflows.sh.
set -euo pipefail
all=0
if [[ "${1:-}" == --all ]]; then all=1; shift; fi
marker="${1:?usage: issue-by-marker.sh [--all] MARKER < issues.json}"
if [[ $all -eq 1 ]]; then
    jq -r --arg m "$marker" '.[] | select((.body // "") | startswith($m)) | .number'
else
    jq -r --arg m "$marker" '[.[] | select((.body // "") | startswith($m)) | .number] | first // empty'
fi
