# ADR 075: A release loop is bounded, and the server never edits code

- Status: accepted (v2.133.0). Server half rehearsed with stubs; not yet installed on Tencent.
- Related: ADR 057 (prove what is running), ADR 072 (migration, `ioms-ops`), ADR 073 (Hermes deploys main)

## Context

The owner asked for an autonomous loop: Claude Code develops, tests and pushes; Hermes deploys;
production is monitored; failures are diagnosed and fixed by Claude Code; and it redeploys, with
idempotent deploys, SHA tracking, concurrency protection, bounded retries, escalation and rollback.

The instruction also said: do not assume a Claude session can be called directly, verify the
supported invocation method.

What exists and was verified in this environment:

- GitHub Actions runs on push (`ci.yml`, check name `Tests and build`). Verified green on `82efedf`
  through the public API; the repository is public, so CI status is readable without a token.
- A Claude Code cloud session can create **scheduled tasks** that start a fresh session in this
  environment on a cron schedule, with the GitHub MCP tools available inside it.
- Nothing on the Tencent host can start a Claude session. There is no API credential for that, and
  putting one on a host an agent operates would hand that agent the ability to write code.

## Decision

The loop is four stages joined by **durable records**, not by direct calls:

```
push to main ──> GitHub Actions CI (Tests and build) ──> conclusion on the SHA
                                                            │
 ioms-release-watch (Tencent, root, every 5 min) <──────────┘ reads it
   └─ ioms-ops deploy <sha>: test gate on that SHA, backup, deploy, health, AUTO-ROLLBACK
   └─ health check every tick
   └─ on failure: redacted report on disk + GitHub issue (deploy-failure / production-health)
                                                            │
 Claude remediation (scheduled task, fresh session) <───────┘ reads open issues
   └─ diagnose, fix, full suite, push to main  ──> back to CI
   └─ or label needs-human and stop
```

1. **Deploy exactly what CI passed.** The watcher deploys a specific SHA (`ioms-ops deploy <sha>`,
   which must be on `origin/main`), never "whatever main is now", so main moving between the CI check
   and the deploy cannot deploy untested code. `ioms-ops` still runs the suite again on that SHA.
2. **Idempotent and serialised.** A SHA already running is a no-op. The watcher holds a `flock`;
   `ioms-ops` holds its own deploy lock and an hourly rate limit.
3. **Bounded.** A transient failure (rolled back after an unhealthy deploy, backup or fetch failure)
   is retried at most `MAX_ATTEMPTS` (2) times with exponential backoff (15 min, then 30). A
   deterministic failure (CI failed, tests failed on the server, migrations awaiting approval, not a
   fast-forward) **blocks the SHA at once**: retrying the same code cannot change the answer. Only a
   new commit, or a person running `unblock`, starts again.
4. **The server never edits code.** Repair happens only in a Claude session, through a commit that
   goes through CI and the server's own test gate like any other. So the "repair loop" is
   CI-gated and has no path that skips review by the suite.
5. **Remediation is bounded too.** Each issue gets at most three attempts, counted by labels
   `claude-attempt-1..3`; then `needs-human`. The session may push to `main` only when the full suite
   passes and the fix touches no migration, deploy script, authentication, permission or payment
   code; otherwise it opens a pull request and labels the issue `needs-human`.
6. **Escalation is a label a person sees.** `needs-human` is set for: a blocked transient failure,
   migrations awaiting approval, a failed rollback, a health outage lasting about 30 minutes, and any
   remediation the session is not allowed to make.
7. **No secrets travel.** Issue bodies and reports pass through the same redaction as `ioms-ops logs`,
   plus GitHub token patterns. The rehearsal asserted that a password, a `ghp_` token and a quoted
   secret printed by the deploy never reached an issue. The GitHub token lives only in
   `/etc/ioms-release-watch.conf` (root, 600) and is sent only as an Authorization header.

## What still needs a person

- Installing the watcher on Tencent and enabling its timer (`install-release-watch.sh --enable`):
  that is the moment production starts deploying main on its own.
- Creating a fine-grained GitHub token (Issues read/write, Checks read, Metadata read, this
  repository only) and storing it with `--token-stdin`. Without it, deploys still run and failures
  are recorded on the server, but nobody is told and remediation sees nothing.
- Migrations, rollbacks to anything but the last good deploy, and maintenance mode stay behind
  `ioms-ops request` and a person's approval (ADR 072/073). The loop does not change that.

## Consequences

- The latency from a failure to a remediation attempt is the scheduled task's interval, not seconds.
  That is the price of not putting a Claude credential on a host an agent operates.
- During the migration HOLD (before cutover) health failures are not filed: the host is a copy that
  is not serving customers.
