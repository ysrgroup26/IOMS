#!/usr/bin/env bash
#
# test-staging-ops.sh -- checks for the staging CI path (ADR 084), run by CI and locally.
#
#  1. ioms-staging-ci (the forced command) maps each allowed request to EXACTLY one sudo call
#     and refuses everything else -- shells, chaining, substitution, other branch namespaces,
#     scp/rsync/git, bad counts -- WITHOUT calling sudo at all.
#  2. ioms-staging-ops validators, the staging guard (path and .env), redaction, and the
#     dispatcher's refusals.
#  3. Production isolation: none of the staging tools references the production checkout,
#     ioms-ops, the production secrets or a service manager.
#  4. The installer: one narrow sudo rule (validated with visudo when available), a restricted
#     forced-command key, no other account touched.
#  5. The workflow: manual only, read-only token, pinned host key, only IOMS_STAGING_* secrets,
#     claude/* branches only, deploy requires a green CI run and passes the exact commit.
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 1
fail=0
ok() { echo "ok    $*"; }
bad() { echo "FAIL  $*"; fail=1; }

H=deploy/tencent/hermes
CI="$H/ioms-staging-ci"
OPS="$H/ioms-staging-ops"
INSTALL="$H/enable-staging-ci.sh"
WF=.github/workflows/staging.yml
SHA=0123456789abcdef0123456789abcdef01234567

tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

# ------------------------------------------------------------ 1. forced command
mkdir -p "$tmp/bin"
cat > "$tmp/bin/sudo" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CALLS"
STUB
printf '#!/usr/bin/env bash\nexit 0\n' > "$tmp/bin/logger"
chmod +x "$tmp/bin/sudo" "$tmp/bin/logger"

run_ci() { # request -> sets out, rc, call
    : > "$tmp/calls"
    out="$(CALLS="$tmp/calls" PATH="$tmp/bin:$PATH" SSH_ORIGINAL_COMMAND="$1" bash "$CI" 2>&1)"; rc=$?
    call="$(cat "$tmp/calls")"
}

accept() { # request expected-sudo-args
    run_ci "$1"
    if [[ $rc -eq 0 && "$call" == "$2" ]]; then ok "accepts '$1' -> sudo $2"
    else bad "'$1': rc=$rc call='$call' out='$out' (want sudo $2)"; fi
}
refuse() { # request
    run_ci "$1"
    if [[ $rc -ne 0 && -z "$call" && "$out" == REFUSED* ]]; then ok "refuses $(printf '%q' "$1")"
    else bad "did not refuse $(printf '%q' "$1"): rc=$rc call='$call' out='$out'"; fi
}

P=/usr/local/bin/ioms-staging-ops
accept "status"                                     "-n $P status"
accept "flows"                                      "-n $P flows"
accept "activate-due"                               "-n $P activate-due"
accept "review-check"                               "-n $P review-check"
accept "deploy claude/eloquent-newton-pbxxdg $SHA"  "-n $P deploy claude/eloquent-newton-pbxxdg $SHA"
accept "deploy claude/a/b.c_d-e $SHA"               "-n $P deploy claude/a/b.c_d-e $SHA"
accept "logs"                                       "-n $P logs app 200"
accept "logs ops 50"                                "-n $P logs ops 50"

for r in "" "bash" "sh -c id" "/bin/bash -i" "status; id" "status && id" "status|id" 'status $(id)' \
         'status `id`' $'status\nid' "status extra" "flows now" "review-check x" \
         "deploy main $SHA" "deploy claude $SHA" "deploy claude/ $SHA" "deploy claude/../main $SHA" \
         "deploy claude/x.lock $SHA" "deploy claude/x deadbeef" "deploy claude/x ${SHA^^}" \
         "deploy claude/x" "deploy claude/x $SHA extra" "deploy feature/x $SHA" \
         "logs nginx" "logs app 9999" "logs app -1" "logs app 10 more" "logs ../../etc/shadow" \
         "scp -t /tmp" "rsync --server -e.LsfxC . /tmp" "git-upload-pack /srv/ioms/app" \
         "ioms-ops deploy $SHA" "sudo ioms-staging-ops status" "internal-sftp" \
         "status'" 'status"' "status *" "status ~" "$(printf 'x%.0s' {1..250})"; do
    refuse "$r"
done

# ------------------------------------------------------------ 2. ioms-staging-ops
# Sourced: defines the functions without running main.
# shellcheck source=/dev/null
source "$OPS"
# Read by the sourced functions (audit, main).
# shellcheck disable=SC2034
AUDIT="$tmp/audit.log"; STATE="$tmp/state"; mkdir -p "$STATE"

for b in claude/eloquent-newton-pbxxdg claude/a claude/a/b-c.d_e; do
    valid_branch "$b" && ok "valid branch $b" || bad "rejected valid branch $b"
done
for b in main claude claude/ claude/../x claude//x claude/x.lock claude/x/ 'claude/x;id' 'claude/$(id)' feature/claude/x; do
    valid_branch "$b" && bad "accepted invalid branch $b" || ok "rejects branch $(printf '%q' "$b")"
done
valid_sha "$SHA" && ok "valid sha" || bad "rejected a valid sha"
for s in deadbeef "${SHA^^}" "${SHA}0" "g${SHA:1}"; do
    valid_sha "$s" && bad "accepted sha $s" || ok "rejects sha $s"
done
for n in 1 200 500; do valid_lines "$n" && ok "lines $n" || bad "rejected lines $n"; done
for n in 0 501 9999 -1 abc; do valid_lines "$n" && bad "accepted lines $n" || ok "rejects lines $n"; done

# The path guard: only /srv/ioms-staging/app, however it is spelt.
[[ -z "$(path_problem /srv/ioms-staging/app)" ]] && ok "path guard accepts the staging checkout" || bad "path guard refused staging"
for p in /srv/ioms/app /srv/ioms /srv/ioms-staging/app/../../ioms/app /srv/ioms-staging /tmp/app; do
    [[ -n "$(path_problem "$p")" ]] && ok "path guard refuses $p" || bad "path guard accepted $p"
done

# The .env guard: the staging database, a non-production environment, the staging account.
envf() { printf '%s\n' "$@" > "$tmp/.env"; echo "$tmp/.env"; }
[[ -z "$(env_problem "$(envf APP_ENV=staging DB_DATABASE=ioms_staging DB_USERNAME=iomsstg)")" ]] \
    && ok "env guard accepts the staging .env" || bad "env guard refused the staging .env"
[[ -z "$(env_problem "$(envf 'APP_ENV="staging"' 'DB_DATABASE="ioms_staging"' 'DB_USERNAME=iomsstg')")" ]] \
    && ok "env guard reads quoted values" || bad "env guard failed on quoted values"
for case in "APP_ENV=staging DB_DATABASE=ioms DB_USERNAME=iomsstg" \
            "APP_ENV=production DB_DATABASE=ioms_staging DB_USERNAME=iomsstg" \
            "DB_DATABASE=ioms_staging DB_USERNAME=iomsstg" \
            "APP_ENV=staging DB_DATABASE=ioms_staging DB_USERNAME=ioms" \
            "APP_ENV=staging DB_DATABASE=ioms_staging DB_DATABASE=ioms DB_USERNAME=iomsstg"; do
    read -ra kv <<<"$case"
    [[ -n "$(env_problem "$(envf "${kv[@]}")")" ]] && ok "env guard refuses: $case" || bad "env guard accepted: $case"
done
[[ -n "$(env_problem "$tmp/missing.env")" ]] && ok "env guard refuses a missing .env" || bad "env guard accepted a missing .env"

# Redaction.
red="$(printf '%s\n' 'password="hunter2"' 'DB_PASSWORD=s3cr3t' 'APP_KEY=base64:abc' 'Authorization: Bearer abc.def' \
    'mysql://u:pw@h/db' '/reset-password/tok123' | redact)"
for leak in hunter2 s3cr3t base64:abc abc.def ':pw@' tok123; do
    [[ "$red" == *"$leak"* ]] && bad "redaction leaks $leak" || ok "redacts $leak"
done

# The dispatcher refuses malformed requests before doing anything (as root or not).
for args in "" "nope" "status extra" "deploy claude/x" "deploy main $SHA" "flows x" "logs app 0" "logs nginx"; do
    read -ra a <<<"$args"
    o="$( (main "${a[@]}") 2>&1 )"; r=$?
    if [[ $EUID -ne 0 ]]; then
        [[ $r -ne 0 && "$o" == *"must run through sudo"* ]] && ok "dispatcher (non-root) refuses: '$args'" || bad "dispatcher (non-root) '$args': $o"
    else
        [[ $r -ne 0 && "$o" == *REFUSED* ]] && ok "dispatcher refuses: '$args'" || bad "dispatcher did not refuse '$args': $o"
    fi
done

# ------------------------------------------------------------ 3. production isolation
for f in "$CI" "$OPS" "$INSTALL" "$H/staging-review-check.php" "$H/staging-payment-check.php" "$WF"; do
    code="$(grep -vE '^\s*(#|\*|//)' "$f")"
    for forbidden in '/srv/ioms/app' '/srv/ioms/' '/etc/ioms-ops' 'IOMS_DEPLOY_' 'iomsci@' 'supervisorctl' 'systemctl' \
                     'service php' 'service nginx' 'DB_DATABASE=ioms$' '/var/backups/ioms/'; do
        if grep -qF -- "$forbidden" <<<"$code"; then
            # The installer's one reference to the production tool is the check that the new
            # account can NOT run it.
            if [[ "$f" == "$INSTALL" ]] && grep -F -- "$forbidden" <<<"$code" | grep -qv 'sudo -n -l /usr/local/bin/ioms-ops'; then
                bad "$f references $forbidden"
            elif [[ "$f" != "$INSTALL" ]]; then
                bad "$f references $forbidden"
            fi
        fi
    done
done
ok "no staging tool references production paths, secrets, accounts or service managers"
if grep -vE '^\s*#' "$OPS" "$CI" | grep -qE '(^|[^-])ioms-ops( |$)'; then bad "a staging tool calls ioms-ops"; else ok "no staging tool calls ioms-ops"; fi

# ------------------------------------------------------------ 4. installer
rule="$(sed -n '/^\$U ALL=(root) NOPASSWD:/p' "$INSTALL")"
want='$U ALL=(root) NOPASSWD: $OPS status, $OPS deploy *, $OPS flows, $OPS activate-due, $OPS review-check, $OPS logs *'
[[ "$rule" == "$want" ]] && ok "sudo rule is exactly the six staging verbs" || bad "sudo rule: $rule"
if command -v visudo >/dev/null; then
    printf '%s\n' "${rule//\$U/iomsstgci}" | sed "s#\$OPS#/usr/local/bin/ioms-staging-ops#g" > "$tmp/sudoers"
    visudo -cf "$tmp/sudoers" >/dev/null 2>&1 && ok "sudo rule passes visudo" || bad "sudo rule fails visudo"
fi
grep -q "printf 'restrict,command=\"%s\" %s\\\\n' \"\$CI\"" "$INSTALL" && ok "key is installed as a restricted forced command" || bad "key is not restrict,command="
grep -qE 'install -d -m 755 -o root -g root "\$HOME_DIR/\.ssh"' "$INSTALL" && ok "authorized_keys directory is root-owned" || bad "authorized_keys directory ownership"
grep -q 'passwd -l "$U"' "$INSTALL" && ok "the account has no password" || bad "account password not locked"
if grep -vE '^\s*#' "$INSTALL" | grep -qE 'usermod|(^|[^0-9&])>>? */etc/ssh|tee +(-a +)?/etc/ssh|ufw |iptables|/home/claude'; then
    bad "installer changes another account, sshd or the firewall"
else ok "installer touches no other account, sshd or firewall"; fi
grep -q 'cannot run the production ioms-ops' "$INSTALL" && ok "installer proves the account cannot run production ioms-ops" || bad "no production ioms-ops check"
grep -q 'rm -f "$AK" "$SUDOERS"' "$INSTALL" && ok "installer withdraws access when a check fails, and on --revoke" || bad "no withdrawal on failure"

# ------------------------------------------------------------ 5. workflow
if python3 - "$WF" <<'PY'
import re, sys, yaml
wf = yaml.safe_load(open(sys.argv[1]))
on = wf.get(True, wf.get('on'))          # YAML 1.1 reads the key `on` as True
errors = []
if set(on) != {'workflow_dispatch'}: errors.append(f"triggers: {set(on)} (manual only)")
perms = wf.get('permissions', {})
if any(v != 'read' for v in perms.values()): errors.append(f"permissions not read-only: {perms}")
if set(perms) - {'contents', 'actions', 'checks'}: errors.append(f"unexpected permissions: {perms}")
if wf.get('concurrency', {}).get('cancel-in-progress') is not False: errors.append("concurrency must not cancel a running staging operation")
text = open(sys.argv[1]).read()
code = "\n".join(l for l in text.splitlines() if not l.lstrip().startswith('#'))
secrets = set(re.findall(r'secrets\.([A-Z0-9_]+)', text))
if secrets - {'IOMS_STAGING_SSH_KEY', 'IOMS_STAGING_KNOWN_HOSTS', 'IOMS_STAGING_HOST'}: errors.append(f"secrets: {secrets}")
for must in ['StrictHostKeyChecking=yes', 'BatchMode=yes', 'IdentitiesOnly=yes', 'ClearAllForwardings=yes',
             'ForwardAgent=no', 'iomsstgci@', '^claude/', 'workflows/ci.yml/runs?head_sha=', 'deploy $BRANCH $sha', 'umask 077']:
    if must not in text: errors.append(f"missing: {must}")
for never in ['set -x', 'StrictHostKeyChecking=no', 'accept-new', 'IOMS_DEPLOY_', 'echo "$KEY', 'cat "$d/key"']:
    if never in code: errors.append(f"forbidden: {never}")
opts = wf['on' if 'on' in wf else True]['workflow_dispatch']['inputs']['action']['options']
if opts != ['status', 'deploy', 'flows', 'activate-due', 'review-check', 'logs']: errors.append(f"actions: {opts}")
for step in wf['jobs']['staging']['steps']:
    for k, v in (step.get('env') or {}).items():
        if 'secrets.' in str(v) and 'run' in step and '${{ secrets' in step['run']:
            errors.append(f"secret interpolated into a script in step {step['name']}")
if errors:
    print("\n".join(errors)); sys.exit(1)
PY
then ok "workflow: manual only, read-only token, pinned host key, staging secrets only, claude/* only, CI-green deploy"
else bad "workflow checks failed (above)"; fi

exit $fail
