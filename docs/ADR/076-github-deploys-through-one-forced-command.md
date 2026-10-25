# ADR 076: GitHub deploys through one forced command, and only additive migrations skip a person

- Status: accepted (v2.133.0 follow-up). Rehearsed end to end on a replica; not yet enabled on Tencent.
- Related: ADR 072/073 (ioms-ops, Hermes), ADR 075 (bounded release loop), ADR 057 (prove what runs)

## Context

The owner wants IOMS released the way TerasAkademi is: push to `main`, and it is live. TerasAkademi's
`deploy.yml` (73 runs, latest green) SSHes from GitHub Actions to its VPS with a full-shell key, runs
`git reset --hard`, applies every migration, builds, and restarts. It has no test gate, health check,
backup or rollback, and the key can do anything the deploy user can.

IOMS already had the safe half on the server (`ioms-ops deploy`: test gate on the target commit,
backup, deploy, health check, automatic rollback) and a pull-based watcher (ADR 075). Two things
kept releases manual:

1. **Something had to start the deploy.** Nothing on GitHub could reach the server, and the watcher
   was never installed.
2. **Every migration needed a person.** Most IOMS migrations only add tables and nullable columns,
   so "routine" releases stopped for approval anyway.

## Decision

**Push model, with the safety on the server where a workflow cannot skip it.**

- `.github/workflows/deploy.yml` runs when CI (`Tests and build`, plus ESLint, Pint on changed PHP,
  deploy-script parsing and actionlint) concludes on a `main` push. It deploys **that exact SHA**, never
  "whatever main is now".
- It connects as `iomsci`, an account whose only SSH key is bound to
  `restrict,command="/usr/local/sbin/ioms-ci-deploy"`. That entrypoint accepts `deploy <40-hex sha>` or
  `status` and nothing else: no shell, no forwarding, no file copy. Its sudo rule allows
  `ioms-ops deploy|status|health|release-json` only. A leaked GitHub secret can therefore ask for a
  commit already on `main` to be deployed through every check, and nothing more.
- Everything else stays inside `ioms-ops`: the suite runs again on the server, a backup is taken,
  `deploy.sh` runs, the health check runs, and an unhealthy release is rolled back automatically.
- Each attempt is recorded as a GitHub **Deployment** (environment `tencent`) with the server's own
  machine-readable result (`IOMS_RESULT {...}`: running SHA, last good, outcome, migration hold). That is
  what makes "is it live?" answerable from GitHub, without access to the host.
- Retries are bounded and only for transient causes (SSH unreachable, another deploy holding the lock),
  at most 3 attempts with 60 s and 120 s backoff. A failure the code caused is never retried; it opens a
  redacted `deploy-failure` issue, and `needs-human` is added when a person must act (non-additive
  migrations, a failed rollback, an unreachable host).

**Scoped migration policy.** `ioms-ops` now deploys migrations without a person when **every** changed
migration is additive (`AUTO_MIGRATIONS=additive`, default; `off` restores the old rule). The judge is
`migration-policy.php`, installed root-owned and run **as the app user** on file contents piped in, so
repository code is tokenized, never executed, and never as root. Additive means `up()` only creates
tables, adds nullable or defaulted columns, indexes and foreign keys, behind optional `hasTable` or
`hasColumn` guards. Review means anything else: drop, rename, `change()`, any `DB::` call except
`getDriverName`, a model, `$this->helper()`, `new`, a NOT NULL column without a default on an existing
table, or an added migration file that was edited, deleted or renamed. Calibrated on the 209 existing
migrations: 153 additive, 56 review, and every review reason was genuine (data backfills, constraint
drops, column changes). Pinned by `tests/Unit/MigrationPolicyTest.php`. Because additive schema is
ignored by older code, automatic code rollback after such a deploy stays safe; the pre-deploy backup
still exists.

## What the rehearsal found

Run on a replica of the Tencent layout (the same `/srv/ioms/app`, MySQL, PHP-FPM, nginx behind Caddy
with the Cloudflare Origin certificate, `ioms-backup`), through `sshd` and the forced command exactly as
GitHub would connect. Two defects that reading had not found:

1. **`ioms-ops deploy` did not deploy the commit it tested.** It checked out the target and then ran
   `./deploy.sh`, whose first step is `git pull`: the checkout moved to whatever `origin/main` had
   become, so the code that went live was not the code the test gate passed, and a destructive
   migration ran that the policy never saw. This was already true before today for plain
   `ioms-ops deploy` whenever `main` moved during the test gate. `ioms-ops` now runs the steps after the
   pull itself (`composer install`, `php artisan app:deploy`) on exactly the target, the way its
   rollback always did, and treats a checkout that is not the target as unhealthy.
2. **The policy's verdict was read from the wrong pipeline element.** `PIPESTATUS[1]` was the
   `while` loop (always 0), so a `dropColumn` the classifier had correctly marked `review` was deployed.
   The decision is now made from the classifier's output, and fails closed: only one `additive` line
   per changed file passes.

After both fixes, on the replica: `8621e15` deployed with the test gate (1654 passed), backup, health
and a matching `IOMS_RESULT`; an additive migration deployed by itself and added its column; a
`dropColumn` was refused `needs_approval` with production left on the previous commit and the column
intact; a release that answered 500 everywhere rolled back by itself (`rolled_back`); redeploying the
running SHA was a no-op; and the forced command refused `id`, a shell, `deploy <sha>; id` and port
forwarding.

## Not done, deliberately

- TerasAkademi's `git reset --hard` and unconditional `migrate`: this design refuses both.
- No change to `sshd_config`, the firewall, DNS, Cloudflare, Duitku or the migration hold. If the
  Lighthouse firewall only admits SSH from known addresses, GitHub's runners cannot connect; the
  workflow reports `unreachable` with `needs-human`. Opening port 22 is the owner's decision.
- Pint is enforced on changed files only. The repository predates it, and a whole-tree gate would block
  every release on old style debt.

## Consequences

- One-time setup needs root on the host (`enable-ci-deploy.sh`) and two repository secrets. This
  session's GitHub access cannot write repository secrets (403), so the owner pastes them once.
- The pull watcher (ADR 075) stays available for a host that must not accept inbound SSH. Enable one
  of the two, not both.
