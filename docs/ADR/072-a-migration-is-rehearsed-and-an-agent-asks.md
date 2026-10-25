# ADR 072 — A migration is rehearsed before it is run, and an agent asks before it acts

**Status:** Accepted (v2.132.0, 2026-10-25)
**Related:** ADR 057 (prove what is running), ADR 071 (merchant review), `docs/runbooks/TENCENT-MIGRATION.md`,
`deploy/tencent/`.

## Context

The owner chose to migrate IOMS from IDCloudHost shared hosting to a Tencent Cloud Lighthouse instance in
Jakarta, behind Cloudflare. The instance was bought through a Hermes Agent offer, and the owner wants
Hermes **kept** as an operations agent (Git, deploys, logs, health checks). This development environment
can't reach the instance: the network policy denies `43.173.21.31`, and no SSH key exists.

## Decisions

### 1. The migration runs on the instance, from one orchestrator, and is rehearsed first

`deploy/tencent/migrate.sh` (preflight · app · env-set · import · verify) runs on the instance. Before
anyone runs it there, every phase was run for real in an Ubuntu 24.04 container, against a
production-shaped export and nginx over HTTPS. It found nine defects that careful reading had not,
including one that would have silently broken every nightly backup (`mysqldump` rejecting `database=` in
`[client]`), one that made every backup fail its own checksum, and one that hid every error (PHP-FPM as `www-data` couldn't write `storage/logs`). A script
that has only been read is a hypothesis.

**Rejected:** writing the runbook as prose commands for a person to adapt. Every adaptation is a new,
untested script.

### 2. Data parity is proven, not assumed

`export-cpanel.sh` writes per-table row counts beside the dump; `import` compares every table after
loading and stops on any difference. `--replace` takes a backup first. Secrets enter `.env` only through
a hidden prompt or stdin. A secret passed as an argument is refused, because arguments land in shell
history and process listings.

### 3. Cloudflare stays the DNS, proxy and security layer

Nameservers don't move, and Tencent's "Add DNS record" stays off. The origin presents a Cloudflare
Origin CA certificate (Full (strict)) and restores the visitor IP from `CF-Connecting-IP`. A request for
any other host name, including the bare IP, gets no response (`return 444`). After the cutover is
verified, `cloudflare-lockdown.sh` limits 80/443 to Cloudflare's ranges. Mail records are outside the
change entirely.

### 4. Hermes asks; a person approves; the agent executes exactly what was approved

Hermes runs as its own user, which can't read `/srv/ioms`, `.env` or backups. Its one privilege is
`sudo /usr/local/bin/ioms-ops`:

- read operations run immediately, with credentials redacted from any log output;
- deploy, restart and rollback are **requested**, approved by a person, expire in 30 minutes, are bound
  to the commit named at request time, and back up first;
- the agent's own account is refused at `approve`. sudo sets `SUDO_USER`, and the agent can't forge it;
- restore, `.env`, firewall, DNS, packages, users and reinstall aren't reachable through the tool at
  all;
- every call is a JSON line in an append-only audit log.

**Rejected:** giving Hermes broad sudo, or none. Broad sudo puts customer data and payment keys one
prompt-injection away. None throws away the operational help the owner asked to evaluate.

**Not decided here, deliberately:** whether Hermes can share the host. That depends on what the image
actually does (it may run as root, or be in `docker`), which only the on-instance inspection shows. The
installer audits it and fails loudly. The runbook gives the decision rule: co-host if contained,
otherwise a separate instance for IOMS.

## Consequences

- Rehearsed: provision, app, env-set, import with parity, nginx/HTTPS, end-to-end register/sign-in,
  an imported account using five modules and a PDF, backup/restore, the Hermes guard, the lockdown
  dry run.
- Not yet done, and only doable on the instance or in Cloudflare/Zoho/Duitku: everything in the runbook's
  §11 table.
