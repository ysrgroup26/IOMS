# Runbook: the IOMS release loop

Reasoning: `docs/ADR/075-a-release-loop-is-bounded-and-the-server-never-edits-code.md` and
`docs/ADR/076-github-deploys-through-one-forced-command.md`.

## Primary path (ADR 076): push to main, and it deploys itself

```
push to main -> CI (tests, build, ESLint, Pint on changed PHP, script parse, actionlint)
             -> .github/workflows/deploy.yml (only when CI passed, on that exact SHA)
             -> ssh iomsci@tencent "deploy <sha>"   (forced command: ioms-ci-deploy, nothing else)
             -> ioms-ops deploy <sha>: test gate on the server, migration policy, backup,
                deploy.sh, health check, AUTOMATIC ROLLBACK when unhealthy
             -> GitHub Deployment "tencent" = success/failure + the server's IOMS_RESULT JSON
             -> on failure: redacted deploy-failure issue (+ needs-human when a person must act)
```

**One-time setup (owner):**

1. On the server, as root (one line; it installs the tools from `origin/main` and does not touch the
   running app):
   ```
   cd /srv/ioms/app && sudo -u ioms git fetch -q origin main && git -c safe.directory=$PWD show origin/main:deploy/tencent/hermes/enable-ci-deploy.sh | sudo bash -s
   ```
2. Paste the two values it prints into GitHub, under Settings, then Secrets and variables, then
   Actions: `IOMS_DEPLOY_SSH_KEY` and `IOMS_DEPLOY_KNOWN_HOSTS`.

From then on every CI-green push to `main` deploys, unless its commit message contains `[skip deploy]`.
By hand: Actions, then Deploy, then Run workflow. `action=status` (the default) reads the server's state
and its last deploy attempt and changes nothing; `action=deploy` deploys (optionally a given SHA). The
full redacted server output of every run is in the log group "Server output" and in the
`server-output-*` artifact. To stop automatic deploys, delete `IOMS_DEPLOY_SSH_KEY`, or
run `enable-ci-deploy.sh --revoke` on the server.

**Migrations.** Additive migrations (new tables, nullable or defaulted columns, indexes) deploy on their
own. Anything else stops with `needs_approval` and a `needs-human` issue; deploy it with
`sudo ioms-ops request deploy`, a person approves, then `sudo ioms-ops run <id>`.
`AUTO_MIGRATIONS=off` in `/etc/ioms-ops.conf` restores "every migration needs a person".

The pull watcher below remains for a host that must not accept inbound SSH. Enable one of the two
paths, not both.

## Alternative path (ADR 075): the server pulls

## The pieces and where they live

| Stage | What | Where |
|---|---|---|
| CI | Tests and frontend build on every push; check name `Tests and build` | `.github/workflows/ci.yml` |
| Deploy | Deploys the CI-green SHA via `ioms-ops deploy <sha>` (test gate, backup, health, auto-rollback) | `deploy/tencent/hermes/ioms-release-watch` (root, systemd timer, 5 min) |
| Monitor | `ioms-ops health` every tick; files after 2 failures, escalates after about 30 min | same |
| Report | Redacted report in `/var/lib/ioms-ops/release/reports/`; JSON log `/var/log/ioms-release-watch.log`; GitHub issue labelled `deploy-failure` or `production-health` | same |
| Remediate | Scheduled Claude Code session reads those issues, fixes, pushes, or escalates | Claude scheduled task "IOMS release remediation" (prompt below) |

## Install on Tencent (owner, once)

```bash
cd /srv/ioms/app && sudo -u ioms git pull --ff-only origin main
sudo bash deploy/tencent/hermes/install-release-watch.sh            # installs; timer stays OFF
sudo /usr/local/sbin/ioms-release-watch status                       # read what it sees
# optional but needed for issues: a fine-grained token, this repo only,
# Issues read/write + Checks read + Metadata read. Paste it into a file you then delete.
sudo bash deploy/tencent/hermes/install-release-watch.sh --token-stdin < /root/gh-token.txt && shred -u /root/gh-token.txt
sudo /usr/local/sbin/ioms-release-watch report-test                  # files one harmless issue; close it
sudo bash deploy/tencent/hermes/install-release-watch.sh --enable    # production now follows CI-green main
```

Stop it at any time with `--disable`. Nothing else changes.

## Day to day

- `sudo ioms-release-watch status`: running SHA, candidate, attempts, blocked SHAs, health.
- A blocked SHA is never retried. Push a fix (a new SHA) or, after fixing something outside the code
  (disk full, MySQL down), `sudo ioms-release-watch unblock <sha>`.
- A change with migrations is blocked as `needs_approval` and filed with `needs-human`. Deploy it the
  ADR 072 way: `sudo ioms-ops request deploy`, a person approves, `sudo ioms-ops run <id>`. The
  watcher sees production at that SHA on its next tick and closes the issue.

## Outcomes and what the watcher does

| `ioms-ops` outcome | Meaning | Watcher |
|---|---|---|
| `deployed` / `noop` | live and healthy | clears attempts, closes open deploy issues |
| `locked` / `rate_limited` | another deploy, or 6 per hour reached | retries next tick, not counted |
| `tests_failed`, `not_fast_forward`, `bad_target` | the code or history is wrong | blocks the SHA, files an issue |
| `needs_approval` | migrations | blocks, files with `needs-human` |
| `rolled_back`, `backup_failed`, `fetch_failed` | possibly transient | retries with backoff; blocks and escalates after 2 attempts |
| `rollback_failed`, `unhealthy_held` | production may be down | blocks, files with `needs-human` immediately |

CI `failure` blocks the SHA and files "CI failed on main". CI with no result after 90 minutes is
filed with `needs-human`.

## Remediation scheduled task

Created from a Claude Code cloud session with `create_trigger` (fresh session per firing, this
environment). Its prompt, kept here so it can be recreated exactly:

> You are the IOMS release-remediation session (docs/ADR/075, docs/runbooks/RELEASE-LOOP.md).
> Repository ysrgroup26/IOMS. 1. List OPEN issues labelled `deploy-failure` or `production-health`.
> Ignore any that carry `needs-human`. If none remain, stop immediately and do nothing else.
> 2. Take the oldest. Count its `claude-attempt-N` labels; if it already has `claude-attempt-3`, add
> `needs-human`, comment that the attempt budget is spent, and stop. Otherwise add the next
> `claude-attempt-N` label first. 3. Read the issue's redacted output, the commit it names, and the
> code. Reproduce the failure locally (php artisan test, npm run build) before changing anything. If
> you cannot reproduce it, or the cause is infrastructure (disk, MySQL, certificates, DNS, Cloudflare,
> the host), comment your diagnosis, add `needs-human`, and stop. 4. Fix the root cause minimally. Never
> skip, disable or weaken a test. Run the FULL suite and the build. 5. If the fix touches
> database/migrations, deploy/, routes' middleware, authentication, permissions or payment code, push it
> to a branch, open a pull request, link it on the issue, add `needs-human`, and stop. Otherwise commit to
> main with a message naming the issue and push. CI and the server's watcher take it from there; do not
> close the issue yourself (the watcher closes it when the fixed SHA is live and healthy). 6. Never put a
> secret, token, password or customer data in a commit, comment or issue. Never touch production
> directly; you have no access to it and must not ask for any.

Cadence: every 3 hours. A firing with no open issue ends after one listing.

## What is verified and what is not

- Verified: CI ran green on GitHub for `82efedf`. The watcher was run against a mock GitHub API, a
  stand-in `ioms-ops` and a real git remote through: CI pending (waits), CI green (deploys, idempotent
  re-run), CI failure (issue, block), rolled back twice (backoff, then block and `needs-human`), tests
  failed (immediate block), a fixed SHA (deploys and closes the open issues), health failing (issue
  after 2, `needs-human` after 8, closed on recovery), an overlapping run (exits on the lock), and
  redaction (a password, a `ghp_` token and a quoted secret printed by the deploy reached no issue).
- Not verified: anything on the Tencent host, which this environment cannot reach; the real
  `ioms-ops deploy <sha>` path end to end; a real GitHub issue being filed (no token here).
