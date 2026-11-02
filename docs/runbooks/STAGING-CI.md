# Runbook — Staging operated from GitHub (ADR 084)

**Status:** prepared and rehearsed; **not installed** on Tencent. Do not install until the owner approves.

## What it gives

From GitHub (Actions → **Staging** → Run workflow), six actions on staging, and nothing else:

| Action | Changes | What it does |
|---|---|---|
| `status` | nothing | branch, commit, pending migrations, health, last outcome, newest backups, free disk |
| `deploy` | staging code + DB | `claude/*` branch whose head has a green CI run → backup → exact commit → composer → migrate → caches → health → automatic rollback of code **and** DB if unhealthy |
| `flows` | staging DB | backup → (re)seed the Approval Flow review tenant with the staging app password |
| `activate-due` | staging DB | one pass of `ptw:activate-due` (staging has no cron) |
| `review-check` | nothing | read-only report of the review tenant (transaction rolled back) |
| `logs` | nothing | redacted tail of the staging Laravel log (`app`) or the ops audit log (`ops`), 1–500 lines |

Nothing here can reach production: separate account (`iomsstgci`), key, sudo rule and tool; the tool knows
only `/srv/ioms-staging/app`, `iomsstg`, `ioms_staging` and port 8082.

## Prerequisite: the workflow must be on `main`

GitHub runs `workflow_dispatch` only for workflows on the default branch. Until
`.github/workflows/staging.yml` is on `main`, the Staging workflow does not appear. Adding it there is the
owner's decision; a commit marked `[skip deploy]` adds it without deploying production (ADR 076).

## Install (once, as root on Tencent)

One line. It reads the installer and the tools from the branch through the **staging** checkout's git
objects; it changes neither the staging working tree nor anything in production.

```bash
B=claude/eloquent-newton-pbxxdg; R=/srv/ioms-staging/app; sudo -u iomsstg git -C $R fetch -q origin $B && sudo -u iomsstg git -C $R show FETCH_HEAD:deploy/tencent/hermes/enable-staging-ci.sh | sudo bash -s -- --branch $B
```

Expected output, all `ok`:

```
  ok    iomsstgci can run ioms-staging-ops status
  ok    iomsstgci cannot run a shell through sudo
  ok    iomsstgci cannot run the production ioms-ops
  ok    iomsstgci is in no privileged or application group
  ok    the key is a restricted forced command
```

If any check fails, the installer **removes the key and the sudo rule again** and stops; nothing is reachable.

The installer then prints, **once**, three values for GitHub (ysrgroup26/IOMS → Settings → Secrets and
variables → Actions → New repository secret):

| Secret | Value |
|---|---|
| `IOMS_STAGING_SSH_KEY` | the private key block, BEGIN to END (not stored on the host) |
| `IOMS_STAGING_KNOWN_HOSTS` | `43.173.21.31 ssh-ed25519 AAAA…` (the host's own key: pinned) |
| `IOMS_STAGING_HOST` | optional; only if GitHub must use another address |

Do not paste them anywhere else. Clear the terminal scrollback after copying the key.

Then run the workflow with `action=status`. Until the secrets exist it reports "not configured" and does
nothing.

## Installed files

| Path | Owner / mode | Purpose |
|---|---|---|
| `/usr/local/bin/ioms-staging-ops` | root 755 | the work; staging constants only |
| `/usr/local/sbin/ioms-staging-ci` | root 755 | forced command: allow-list |
| `/usr/local/lib/ioms-staging-ops/review-check.php` | root 644 | read-only report |
| `/etc/sudoers.d/ioms-staging-ci` | root 440 | the six verbs, nothing else |
| `/var/lib/iomsstgci/.ssh/authorized_keys` | root 644 (dir root 755) | `restrict,command=…` key |
| `/var/log/ioms-staging-ops.log` | root 640 | audit (JSON lines); also in syslog, tag `ioms-staging-ops` |
| `/var/backups/ioms-staging/` | root 700, files 600 | staging DB backups, newest 10 kept |
| `/var/lib/ioms-staging-ops/` | root 750 | lock, rate limit, last outcome |

## Rotate, revoke, uninstall (rollback)

Run the one-line command above with a different flag at the end:

| Goal | Flag | Effect |
|---|---|---|
| new key | `--rotate` | the old key stops working at once; update `IOMS_STAGING_SSH_KEY` |
| stop all access | `--revoke` | key and sudo rule removed; account locked; tools stay |
| remove everything | `--purge` | revoke + remove the account and the three tools; **keeps** the audit log and the staging backups |

`--revoke`/`--purge` do not need the branch; they can also be run from any copy of the script:
`sudo bash enable-staging-ci.sh --purge`. Afterwards delete the three `IOMS_STAGING_*` GitHub secrets.

Staging itself keeps running throughout; production is never involved.

## Restoring a staging backup by hand

A failed deploy restores automatically. Restoring a different backup is deliberately NOT one of the six
actions; a person does it as root, on the staging database only:

```bash
ls -1t /var/backups/ioms-staging/                  # choose a file
F=/var/backups/ioms-staging/<file>.sql.gz
gzip -t "$F" || exit 1
# Empty ioms_staging first: a dump does not remove tables created after it (Known Pitfall).
mysql -N -B -e "SELECT CONCAT('DROP ', IF(table_type='VIEW','VIEW','TABLE'), ' IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema='ioms_staging'" \
  | { echo 'SET FOREIGN_KEY_CHECKS=0;'; cat; echo 'SET FOREIGN_KEY_CHECKS=1;'; } | mysql ioms_staging
gunzip -c "$F" | mysql ioms_staging
```

Check the database name in every line: it must be `ioms_staging`, never `ioms`.

## Server requirement

Keep `UsePAM yes` in `sshd_config` (Ubuntu default). With `UsePAM no`, OpenSSH refuses password-locked
accounts like `iomsstgci` (and `iomsci`) even with a valid key.
