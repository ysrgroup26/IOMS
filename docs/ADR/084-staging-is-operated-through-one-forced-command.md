# ADR 084 — Staging is operated from GitHub through one forced command

- **Status:** Accepted for preparation (owner, 2026-11-02): implemented on `claude/eloquent-newton-pbxxdg`
  and rehearsed in a disposable container. **Not installed on Tencent**; installation waits for the
  owner's review.
- **Builds on:** ADR 076 (GitHub deploys production through one forced command), ADR 073 (capability follows
  reversibility), ADR 072 (rehearse before trusting).

## Context

The owner authorised Claude to operate staging. Claude's cloud environment cannot open SSH (port 22):
outbound traffic goes through an HTTPS proxy, and the platform does not carry raw TCP. GitHub's runners do
reach the instance; production already deploys that way through `iomsci` and `ioms-ci-deploy` (ADR 076).

The owner set the constraints:

- staging only;
- least privilege;
- no unrestricted shell, forwarding or arbitrary commands;
- credentials only in GitHub secrets;
- backup, pre-checks, health checks, audit and rollback;
- automated tests;
- nothing installed before review.

## Decision

The production pattern is repeated for staging, with its own account, key, sudo rule and tool, sharing
nothing with production.

1. **`.github/workflows/staging.yml`**
   - Manual only (`workflow_dispatch`).
   - Token permissions are read-only (`contents`, `actions`, `checks`).
   - The host key is pinned (`StrictHostKeyChecking=yes` against `IOMS_STAGING_KNOWN_HOSTS`).
   - It reads only the `IOMS_STAGING_*` secrets.
   - Requests are serialised (`concurrency`, never cancelled midway).
   - Actions: `status`, `deploy`, `flows`, `activate-due`, `review-check`, `logs`.
   - `deploy` accepts only a `claude/*` branch whose head commit has a **green CI run**, and passes that
     exact 40-character commit to the server.
2. **`iomsstgci`**, a system account with no password. Its single key is generated **on the host** and
   authorised as `restrict,command="/usr/local/sbin/ioms-staging-ci"`. `restrict` means no pty and no
   port, agent or X11 forwarding. `authorized_keys` and its directory are root-owned.
3. **`ioms-staging-ci`**, the forced command.
   - It accepts one line of plain words (`[A-Za-z0-9._/-]`, at most three words, at most 200 characters).
   - It checks the verb and arguments against the allow-list and refuses everything else **before sudo is
     called**.
   - It then hands the request to `ioms-staging-ops` through one sudo rule naming exactly the six verbs,
     validated with `visudo`.
4. **`ioms-staging-ops`**, root-owned, knows only constants.
   - Constants: `/srv/ioms-staging/app`, `iomsstg`, `ioms_staging`, port 8082.
   - **Before any change it proves it is on staging:**
     - the path resolves to `/srv/ioms-staging/app`;
     - the `.env` names `ioms_staging` and the `iomsstg` user;
     - `APP_ENV` is set and is not `production`;
     - the origin is `ysrgroup26/IOMS`.
   - It never reads the production `.env`, never calls `ioms-ops`, and never restarts a service (php-fpm
     and nginx are shared with production).
   - **Deploy sequence:** rate limit; free-space check; fetch; refuse if the branch moved past the commit
     CI checked; backup of `ioms_staging`; checkout of exactly that commit; `composer`; `migrate`; caches;
     health check (`/up` and `/login` on the loopback port, artisan boots).
   - **When unhealthy:** automatic rollback of **code and database**. The restore first empties the
     staging database, then loads the backup.
   - **Other safeguards:**
     - one run at a time (`flock`);
     - every call is audited to `/var/log/ioms-staging-ops.log` (JSON lines, root-only) and to syslog;
     - logs leave the host redacted;
     - backups are root-only, and the last 10 are kept.
5. **`enable-staging-ci.sh`** installs it in one line, reading the tools from the branch through the
   **staging** checkout's git objects. It refuses to finish unless it can prove all of these, and removes
   the key and the rule again if any check fails:
   - the account can run the staging tool;
   - the account cannot run a shell or the production `ioms-ops`;
   - the account is in no privileged group;
   - the key is a restricted forced command.

   `--revoke` and `--purge` are the rollback.

## Alternatives rejected

- **SSH from Claude's environment** (the `claude` account): not reachable, and it would put a private key in
  an ephemeral container.
- **Giving `claude` sudo:** an interactive account with root cannot be bounded by an allow-list.
- **Reusing `iomsci`:** it would mix production and staging authority in one key.

## Consequences

- Claude can operate staging only through those six actions; nothing else on the host is reachable.
- **`workflow_dispatch` only works for a workflow that exists on the default branch.** Until `staging.yml`
  is on `main`, the workflow cannot be run, even though it is complete on the branch. Putting it on `main`
  is the owner's decision. A `[skip deploy]` commit (ADR 076) would add it without deploying production.
- The server must keep `UsePAM yes` (the Ubuntu default). With `UsePAM no`, OpenSSH refuses password-locked
  accounts such as `iomsstgci` and `iomsci` even with a valid key. This was seen in the rehearsal.

## Evidence

- `.github/scripts/test-staging-ops.sh`, run in CI:
  - over 110 checks covering the allow-list, injection attempts, validators, the path and `.env` guards,
    redaction, production isolation, the sudo rule (`visudo`) and the workflow's shape;
  - three deliberate regressions made it fail.
- **Rehearsal** in a disposable container laid out like Tencent, with OpenSSH 9.6 as on Ubuntu 24.04:
  - Installed by the real installer.
  - Every action ran through a real `sshd` with `restrict`.
  - A deploy from an old commit applied two pending migrations.
  - Two broken releases rolled back to a healthy state: one failed at composer, one migrated and then served
    500.
  - pty, scp, sftp, port forwarding, an interactive shell and command injection were refused.
  - `--revoke` and `--purge` worked as described.
  - The stand-in production tree was byte-for-byte unchanged.
  - The rehearsal found one defect, fixed before commit: a restore left a table the failed release had
    created.
