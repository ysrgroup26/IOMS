#!/usr/bin/env bash
#
# check-build-fresh.sh -- no frontend change ships without its committed build (CI).
#
# IOMS serves public/build as committed; no server runs a JavaScript build. A commit
# that changes the frontend but not public/build deploys new PHP against the OLD
# screens. Vite's output is not byte-reproducible (minified names vary run to run),
# so this compares HISTORY instead of bytes: the latest commit that touched a
# frontend source must be the same as, or an ancestor of, the latest commit that
# touched public/build/manifest.json.
set -euo pipefail
cd "$(dirname "$0")/../.."
sources=(resources/js resources/css vite.config.js tailwind.config.js postcss.config.js package.json package-lock.json)
src="$(git rev-list -1 HEAD -- "${sources[@]}" 2>/dev/null || true)"
build="$(git rev-list -1 HEAD -- public/build/manifest.json 2>/dev/null || true)"
if [[ -z "$src" || -z "$build" ]]; then
    echo "history too shallow to compare (source: ${src:-?}, build: ${build:-?}); fetch more history"; exit 1
fi
if git merge-base --is-ancestor "$src" "$build"; then
    echo "ok    frontend sources last changed in ${src:0:7}; public/build committed at or after it (${build:0:7})"
else
    echo "FAIL  frontend sources changed in ${src:0:7} AFTER the last committed build (${build:0:7})."
    echo "      Run \`npm run build\` and commit public/build in the same change."
    exit 1
fi
