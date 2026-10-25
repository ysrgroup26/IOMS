# ADR 073 — An agent is trusted with what it cannot silently break

**Status:** Accepted (2026-10-25). Refines ADR 072 §4.
**Related:** `deploy/tencent/hermes/`, `docs/runbooks/TENCENT-MIGRATION.md` §3.

## Context

ADR 072 put every change Hermes might make behind a person's approval. The owner rejected that as a
passive agent: Hermes should develop and operate IOMS on its own. On the real instance Hermes runs as
`ubuntu`, which had `NOPASSWD: ALL` (root). After stage 1 it still carried the `sudo` group through its
systemd **user manager**, and `/etc/sudoers.d/acli` granted it more.

## Decision

**Capability follows reversibility, not trust.** Hermes does on its own anything a guard rail can undo
or contain, and asks a person only for what a guard rail can't undo.

- **Autonomous:**
  - Code work in its own workspace (its own SQLite, no production secret).
  - Routine deploy of `main` (test gate on the target commit → backup → deploy → health check →
    **automatic rollback**).
  - Service restarts, an allow-listed set of artisan operations, redacted logs, counts-only DB
    statistics.
  - All of it rate-limited and audited.
- **Approved by a person** (the agent's account is refused at approve): migrations, which code rollback
  can't undo; rollback to an arbitrary commit; taking the site down.
- **Never:** a shell as root, `tinker`, raw SQL, `.env`, restore over live data, firewall, DNS, users,
  packages.
- A missing capability becomes a **new validated `ioms-ops` subcommand**, never a wider sudoers line.
- A sudo rule that reaches the agent is disabled automatically only if it's **root-equivalent**: `ALL`,
  wildcards, shells, interpreters, editors, managers, file writers, or a binary the agent can write.
  Anything else is shown and left for the owner.
- A user unit inherits its **manager's** groups, so privilege removal is completed by restarting
  `user@UID.service` and verified from `/proc`.

## Consequences

The rehearsal (runbook §10) showed the guard rails doing their job:

- a failing suite blocked a deploy, once the gate's `pipefail` defect was fixed;
- a failed backup stopped a deploy;
- a site-breaking commit was rolled back automatically;
- a migration needed an approval the agent couldn't give itself;
- the server's clean test gate caught a test that passed only on a developer `.env`.

The user-manager restart has not run anywhere yet: it runs for the first time on the instance, with its
own checks.
