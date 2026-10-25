# Migrating IOMS from IDCloudHost (cPanel) to Tencent Cloud Lighthouse

**Status (v2.131.0): prepared, not executed.** The scripts in `deploy/tencent/` and this runbook are
committed and syntax-checked, and `healthcheck.sh` passed against a local production-mode instance.
**Nothing has run on the Tencent instance:** this development environment can't reach
`43.173.21.31` (HTTP and SSH were refused by the network policy), and no SSH key, Lighthouse console
access, Cloudflare access or cPanel access was available. Every step below says who runs it and what
evidence counts as done.

Production stays on IDCloudHost until §8's cutover is approved **and** §9's checks pass.

| | |
|---|---|
| Instance | Tencent Cloud Lighthouse, Jakarta, `43.173.21.31` |
| Size | 2 vCPU, 4 GB RAM, 60 GB system disk |
| Bought via | the Hermes Agent page — OS, image and services **unverified** |
| Domain | `iomsuite.com`, DNS on Cloudflare, currently pointing at IDCloudHost |
| Deploy today | cPanel Terminal: `cd ~/ioms && git pull origin main && ./deploy.sh` |

---

## 1. Capacity assessment

IOMS is a Laravel/PHP-FPM app on MySQL with no Node at runtime (assets are prebuilt and committed) and
no long-running process today. For the current customer base that means:

| Consumer | Typical resident memory |
|---|---|
| MySQL 8 (`innodb_buffer_pool_size` 512M–1G) | 0.6–1.2 GB |
| PHP-FPM, 12 children × ~60–80 MB | 0.7–1.0 GB at peak |
| nginx, queue worker, cron | < 0.2 GB |
| OS | 0.3 GB |
| **IOMS total** | **~2–2.7 GB** |

**Verdict:** 2 vCPU / 4 GB / 60 GB is enough for IOMS on its own, with headroom. Dompdf renders and
Excel imports are the CPU spikes, and they're short. What changes the verdict is **Hermes Agent**
sharing the box (§3): a Python agent with its own runtime, optional Redis, and LLM tool calls can hold
0.5–1.5 GB and burst CPU. Both fit only if Hermes is idle most of the time, and the 2 GB swap file that
`provision.sh` adds is a safety net, not capacity. Disk: 60 GB is ample (the app plus 14 nightly
backups are well under 10 GB at current data volumes; check with `du` after the first backup).

## 2. Step 1–2: inspect before touching anything

Owner (or whoever holds SSH), on the instance:

```bash
git clone https://github.com/ysrgroup26/IOMS.git /tmp/ioms-src     # or scp deploy/tencent/
sudo bash /tmp/ioms-src/deploy/tencent/inspect-instance.sh > ~/inspection-$(date +%F).txt 2>&1
```

It is **read-only**. It reports the OS, capacity, listening ports, services, containers, Hermes
processes/units/directories (file **names** only, never contents), outbound connections, accounts,
SSH settings, cron, firewall, existing software and disk use. Read it before sharing it.

**Do not reinstall, reset or "Reinstall System" the instance** before this report has been read and
anything Hermes holds (§3) has been either kept or exported. Lighthouse snapshots are free for a
small number of copies: **take one now**, before anything else (console → Snapshots → Create).

## 3. Hermes Agent assessment

**What it is (public sources):** Hermes Agent is an MIT-licensed autonomous AI agent by Nous Research.
Tencent Lighthouse offers it as a one-click "Application Image" (AI Agent category), which
pre-installs its runtime; Tencent's guides recommend 2 vCPU / 4 GB and mention Redis and systemd being
configured. It connects to an LLM provider with an API key and is usually driven through a chat
channel (Telegram, WhatsApp, etc.) or a terminal. Its tools can include **running shell commands** on
the host.

**What it is on this instance:** unknown until `inspect-instance.sh` runs. The report's "HERMES
AGENT" section answers: which user it runs as, which units start it, what it listens on, how much
memory it holds, which directories hold its state, and whether a terminal/shell toolset is enabled.

**Is IOMS dependent on it?** No. Nothing in this repository calls, configures or needs Hermes.

**Risk of co-hosting it with production IOMS:**

| Concern | Why it matters here |
|---|---|
| Shell access | An agent that can run commands as a user with access to `/srv/ioms` can read `.env` (database password, Duitku API key, Zoho app password) and the database. A prompt-injected or misused agent becomes a path to customer data and payment credentials |
| Credentials on the box | Its own LLM API key and chat-bot tokens sit beside IOMS's secrets |
| Resource contention | Memory/CPU bursts during agent runs compete with PHP-FPM and MySQL (§1) |
| Exposed ports | A web UI or gateway port open to the internet is attack surface on the production IP |
| Image provenance | A marketplace image is built by someone else; its packages and defaults need the same review as anything else on a production host |

**Recommendation (decision for the owner):** run production IOMS on a host **without** Hermes. In
order of preference:

1. **Separate:** keep Hermes on this instance if it's wanted, and buy a second Lighthouse instance
   (clean Ubuntu 24.04 LTS image) for IOMS. Cleanest isolation, and the Hermes data is untouched.
2. **Clean OS on this instance:** if Hermes isn't wanted, export anything it holds (its config
   directory and memory/state per the report; LLM keys are re-issuable at the provider, so rotate
   rather than copy them), take a snapshot, then "Reinstall System" with **Ubuntu 24.04 LTS**
   (not the Hermes application image). Needs explicit owner authorisation.
3. **Co-host (least preferred):** only if Hermes runs as its own unprivileged user with no
   shell/terminal toolset, no read access to `/srv/ioms` (`provision.sh` makes it mode 750, owned by
   `ioms`), no public port (Lighthouse firewall), and a memory cap (`MemoryMax=1G` in its systemd
   unit). Disable it entirely (`systemctl disable --now <unit>`) during the cutover window.

`provision.sh` does not stop, remove or reconfigure Hermes. It refuses to run if something other than
nginx holds ports 80/443, and it changes the firewall only with `--apply-firewall`.

## 4. Steps 3–5: prepare the server

Ubuntu 22.04 or 24.04 (the script refuses anything else):

```bash
sudo bash deploy/tencent/provision.sh --domain staging.iomsuite.com            # no firewall change yet
# read the listening-ports list it prints; then, once Hermes' needs are known:
sudo bash deploy/tencent/provision.sh --domain staging.iomsuite.com --apply-firewall --tls
```

Installs nginx, PHP-FPM 8.3 (+ gd, zip, intl, bcmath, gmp, mbstring, xml, curl, mysql), MySQL 8 bound
to 127.0.0.1, Composer (checksum-verified), supervisor (queue worker), the scheduler cron,
`/usr/local/bin/ioms-backup` at 01:30 daily, logrotate (14 days), unattended security upgrades,
fail2ban, a 2 GB swap file, and (with `--tls`) a Let's Encrypt certificate. The generated database
password goes to `/root/ioms-db.cnf` (mode 600) only.

Also in the **Lighthouse console firewall**, which sits in front of `ufw`: allow 22 (ideally your IP
only), 80, 443; nothing else. SSH: key-only (`PasswordAuthentication no`) once your key works.

**Staging DNS:** in Cloudflare add `staging.iomsuite.com  A  43.173.21.31`, **DNS only** (grey) until the
certificate is issued. This touches no production record.

## 5. Steps 6–8: deploy, then migrate data

As the `ioms` user:

```bash
sudo -u ioms git clone https://github.com/ysrgroup26/IOMS.git /srv/ioms/app
sudo -u ioms cp /srv/ioms/app/.env.example /srv/ioms/app/.env
sudo -u ioms nano /srv/ioms/app/.env      # see the table below; never paste secrets into chat or git
cd /srv/ioms/app && sudo -u ioms php artisan key:generate   # STAGING ONLY -- see note
sudo -u ioms ./deploy.sh --first
```

| `.env` key | Staging value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://staging.iomsuite.com` |
| `DB_*` | `127.0.0.1`, `ioms`, `ioms`, password from `/root/ioms-db.cnf` |
| `SESSION_SECURE_COOKIE` | `true` |
| `TRUSTED_PROXIES` | unset (no proxy in front) or Cloudflare ranges once proxied |
| `MAIL_*` | Zoho, per `docs/runbooks/EMAIL-ZOHO.md` |
| `PAYMENT_GATEWAY`, `DUITKU_*` | **Sandbox** credentials only; `DUITKU_IS_PRODUCTION=false` |
| `QUEUE_CONNECTION` | `database` (the supervisor worker drains it) |

> [!warning] APP_KEY
> For the **final** migration, copy `APP_KEY` from the IDCloudHost `.env`. Encrypted columns,
> signed URLs already in customers' inboxes, and existing sessions depend on it; a new key would
> make them unreadable or invalid. `key:generate` is for staging only.

**Data, on IDCloudHost (cPanel Terminal), read-only for production:**

```bash
cd ~/ioms
mysqldump --single-transaction --quick --routines --triggers --no-tablespaces \
  -u"$(grep ^DB_USERNAME .env | cut -d= -f2)" -p "$(grep ^DB_DATABASE .env | cut -d= -f2)" \
  | gzip > ~/ioms-$(date +%F).sql.gz                      # prompts for the password
tar -czf ~/ioms-storage-$(date +%F).tar.gz storage/app
sha256sum ~/ioms-*.gz > ~/ioms-transfer.sha256
```

Copy the three files to the instance (`scp`, or download them from cPanel File Manager and upload), then:

```bash
sha256sum -c ioms-transfer.sha256
gunzip -c ioms-YYYY-MM-DD.sql.gz | mysql --defaults-extra-file=/root/ioms-db.cnf ioms
sudo -u ioms tar -C /srv/ioms/app -xzf ioms-storage-YYYY-MM-DD.tar.gz
cd /srv/ioms/app && sudo -u ioms php artisan migrate --force && sudo -u ioms php artisan storage:link
```

A rehearsal copy may be loaded into staging. Treat it as **production data**: don't email customers
from staging. Set `MAIL_MAILER=log` on staging while it holds a copy of real data.

## 6. Step 9: checks on the staging URL

| Check | How | Pass |
|---|---|---|
| Public and security surface | `bash deploy/tencent/healthcheck.sh https://staging.iomsuite.com` | `ALL CHECKS PASSED` |
| Release identity (ADR 057) | `php artisan app:deploy` output vs Master Admin → Running Release | Same signature |
| Registration | Register a fresh test account in a browser | Lands on "Your account is ready", no 500 |
| Mail | `php artisan ioms:mail-check you@…` with Zoho configured | both `accepted`, headers pass |
| Sign-in | Existing (copied) account | Signs in, lands on its workspace |
| Duitku Sandbox | `docs/PAYMENTS-DUITKU.md` §6 checklist with **sandbox** credentials and the staging callback URL registered in the Duitku sandbox dashboard | Invoice `paid` only after the callback; replay settles nothing |
| PDF | Open any permit or invoice PDF | Renders with embedded fonts |
| Queue / scheduler | `supervisorctl status ioms-worker`; `php artisan schedule:list` | RUNNING; three jobs listed |
| Backup / restore | `sudo ioms-backup && sudo bash deploy/tencent/restore-check.sh` | Row counts match |
| Load | 20 concurrent `curl` of `/` and `/login` for 60 s | No 5xx, memory below 3.2 GB with Hermes in its final state |

## 7. Backups and monitoring

- Nightly: database dump, `storage/app`, `.env` → `/var/backups/ioms/<date>/`, 14 days, checksummed.
- **Off-site (owner decision):** a backup on the same disk doesn't survive losing the instance. Choose
  Tencent COS (or another host) and add the copy line in `backup.sh`. Also enable Lighthouse scheduled
  snapshots.
- Weekly: `restore-check.sh`.
- Monitoring, free tiers: Lighthouse built-in CPU/memory/disk alarms (console → Monitoring), plus an
  external uptime check on `https://iomsuite.com/login` and on the callback path's 403.

## 8. Steps 10–11: cutover and rollback, needing explicit owner approval

**Approval needed for one action only: changing the production `@`/`www` records in Cloudflare.**
Everything before it is reversible and doesn't touch production.

**T-24h:** lower the TTL of `iomsuite.com` and `www` A records to 300 s (Cloudflare "Auto" with proxy
on is already short; with proxy off set 5 min).

**T-0 (low-traffic window, e.g. 22:00 WIB):**

1. IDCloudHost: `php artisan down` (maintenance page). Writes stop. **Nothing is deleted.**
2. Final dump + `storage/app` tarball + checksums (§5); copy; load into the Tencent `ioms` database
   (drop and recreate it first if a rehearsal copy is there: `DROP DATABASE ioms; CREATE DATABASE ioms …`).
3. Tencent `.env`: `APP_URL=https://iomsuite.com`, **production `APP_KEY` copied from IDCloudHost**,
   Zoho mail, Duitku (still sandbox unless production approval and credentials exist).
4. `./deploy.sh`, then `certbot --nginx -d iomsuite.com -d www.iomsuite.com` (needs DNS pointing
   here, so with Cloudflare **proxied** use a Cloudflare Origin Certificate instead, SSL mode
   **Full (strict)**).
5. Cloudflare: change `@` and `www` A records to `43.173.21.31`. **This is the approved step.**
6. Duitku dashboard: callback URL stays `https://iomsuite.com/webhooks/payment/duitku`, no change.
7. §9 checks.

**Rollback (any §9 failure that can't be fixed in 30 minutes):**

1. Cloudflare: point `@` and `www` back to the IDCloudHost IP (write it down **before** step 5).
2. IDCloudHost: `php artisan up`.
3. Any orders or payments taken on Tencent during the window: export those rows
   (`invoices`, `payment_transactions`, `tenant_registrations`, `users` created after T-0) and
   re-apply them on IDCloudHost by hand. Duitku callbacks received at Tencent are in
   `payment_webhook_events` there.

## 9. Steps 12–13: verify live, then retire IDCloudHost later

Live checks: `healthcheck.sh https://iomsuite.com`; Master Admin → Running Release signature matches;
one registration; `ioms:mail-check`; one Duitku **sandbox** payment through staging, not production
money ("Never use a real production transaction to test the integration"); the first nightly backup
and a `restore-check.sh`.

Keep IDCloudHost **running and paid** for at least 14 days after a clean cutover. Before cancelling,
download a full cPanel backup (home directory, databases, mail) and keep it off-server.

## 10. What is blocked, and on whom

| Blocker | Needs |
|---|---|
| Running anything on the instance | SSH access (key) or Lighthouse console access |
| Hermes decision (§3) | Owner, after reading the inspection report |
| Reinstalling the instance, if chosen | Explicit owner authorisation, after a snapshot |
| Staging DNS record | Cloudflare access |
| Production DNS switch | Explicit owner approval at T-0 |
| Data export | cPanel access |
| Off-site backup target | Owner choice (Tencent COS bucket or other) |
