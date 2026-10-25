# Migrating IOMS from IDCloudHost (cPanel) to Tencent Cloud Lighthouse

**Status (v2.132.0):** the whole migration, including deploy and rollback through Hermes, has been **rehearsed end to end** on Ubuntu 24.04 in a container,
using the same scripts, a production-shaped export and nginx over HTTPS (§10). **Nothing has run on the
Tencent instance yet.** This development environment can't reach `43.173.21.31` (its network policy
denies the host, and outbound SSH is unreachable), and no SSH key, Cloudflare, Zoho or Duitku credential
is available here. The commands below run **on the instance**, by you or by Hermes through `ioms-ops`
(§4).

Production stays on IDCloudHost until the cutover in §8 is approved **and** its checks pass.

| | |
|---|---|
| Instance | Tencent Cloud Lighthouse, Jakarta, `43.173.21.31`, 2 vCPU / 4 GB / 60 GB, Running |
| Domain | `iomsuite.com`, added in the Lighthouse console. **"Add DNS record" was left at Later, and must stay that way** |
| DNS / proxy / WAF | **Cloudflare**, which keeps its nameservers. Only the website A records change, at cutover |
| Mail | Zoho (MX, SPF, `zmail._domainkey`, DMARC), **untouched by this migration** |
| Today | IDCloudHost cPanel, `git pull origin main && ./deploy.sh` |

```
visitor ──HTTPS──▶ Cloudflare (proxy, WAF, edge cert) ──HTTPS, Full (strict)──▶ Tencent nginx (Origin CA cert)
                                                                                 └─ PHP-FPM pool "ioms" ─ MySQL 8 (127.0.0.1)
mail:   iomsuite.com MX/SPF/DKIM/DMARC ──▶ Zoho          (independent of where the website runs)
```

---

## 0. The kit (`deploy/tencent/`)

| File | Runs where | What it does |
|---|---|---|
| `inspect-instance.sh` | instance | Read-only report: OS, ports, services, Hermes, accounts, firewall, disk |
| `provision.sh` | instance | nginx, PHP-FPM 8.3 (own `ioms` pool), MySQL, supervisor worker, cron, Origin cert, Cloudflare real-IP, backups, optional firewall |
| `migrate.sh` | instance | `preflight` · `app` · `env-set` · `env-show` · `import` · `fresh` · `verify` · `status` |
| `export-cpanel.sh` | IDCloudHost | Dump + `storage/app` + per-table row counts + checksums. Read-only for production |
| `healthcheck.sh` | anywhere | Public pages, security surface, TLS, optional `--e2e` register/sign-in, `--resolve` to test the origin before DNS |
| `backup.sh` / `restore-check.sh` | instance (cron) | Nightly dump + files + `.env`, 14 days; the restore check proves the newest dump loads |
| `cloudflare-lockdown.sh` | instance, after cutover | 80/443 from Cloudflare only (`--dry-run`, `--undo`) |
| `hermes/install-ops-guard.sh`, `hermes/ioms-ops` | instance | Hermes' single, audited, approval-gated sudo command (§4) |

## 1. Get onto the instance: two stages, one command each

**Known facts (owner's OrcaTerm check, 2026-10-25):** Ubuntu 24.04 LTS; Hermes runs as `ubuntu` (UID 1000);
`ubuntu` has **full passwordless sudo**; no Docker; snapshot `ioms-before-migration-20261025` exists.

**Stage 1, in OrcaTerm as `ubuntu`** (the only time `ubuntu` is used for administration):

```bash
sudo git clone --depth 1 https://github.com/ysrgroup26/IOMS.git /opt/ioms-kit
sudo bash /opt/ioms-kit/deploy/tencent/bootstrap.sh stage1
```

It takes a root-owned copy of the kit, writes the inspection report to `/root/`, then runs
`hermes/harden-hermes-host.sh` (§3). It asks you for a password for the new human admin `iomsadmin`,
and asks you to confirm in a **second** OrcaTerm tab that `su - iomsadmin -c 'sudo -v'` works, before it
removes anything from `ubuntu`.

**Stage 1b, as `iomsadmin`** (closes §3.1's two open items, installs the current `ioms-ops`, builds
Hermes' workspace):

```bash
su - iomsadmin
sudo bash -c 'git -C /opt/ioms-kit pull --ff-only'
sudo bash /opt/ioms-kit/deploy/tencent/bootstrap.sh stage1b            # add: --acli disable|keep after reviewing
```

**Stage 2, as `iomsadmin`** (from now on, always):

```bash
su - iomsadmin
sudo bash /opt/ioms-kit/deploy/tencent/bootstrap.sh stage2
```

It runs `provision.sh` (self-signed origin certificate until the Cloudflare one is installed, firewall
unchanged), `migrate.sh app` (`main`, mail and payment inert), and a hidden prompt for the **production**
`APP_KEY`. Then it stops, waiting for the export (§5).

## 2. Capacity

IOMS alone at current volumes is roughly 2–2.7 GB resident: MySQL 0.6–1.2 GB, PHP-FPM up to 12 children
at 60–80 MB, plus the worker and the OS. **2 vCPU / 4 GB / 60 GB is enough for IOMS**, with a 2 GB swap
file as a safety net. What decides the margin is Hermes (§3): an agent runtime plus optional Redis can
hold 0.5–1.5 GB. Measure it in the inspection report (`ps … --sort=-rss`) and again under load in §6. If
free memory falls under ~600 MB during the load check, move Hermes to its own instance (§3, option B).

## 3. Hermes Agent: a powerful operator, not root

The goal is a Hermes that **develops and operates IOMS on its own**: code, tests, logs, routine deploys,
restarts, caches, queues. What it gives up is the one thing that makes a compromised or confused agent
catastrophic: **unbounded root**, which means reading every customer's data and every payment and mail
secret, or destroying them without a trace.

### 3.1 State on the instance (owner's checks, 2026-10-25)

- Hermes runs as `ubuntu` (UID 1000) via the **user** unit `~/.config/systemd/user/hermes-gateway.service`.
- `ubuntu` had `NOPASSWD: ALL`, which makes Hermes root.
- `bootstrap.sh stage1` has run: `iomsadmin` exists, `ubuntu`'s cloud-init rule is disabled, and `ubuntu`
  is out of `sudo`/`adm` and holds `ioms-ops`.
- **Two items left open:**
  1. Hermes' process still carries the old `sudo` group.
  2. `/etc/sudoers.d/acli` grants `ubuntu` additional rules.

### 3.2 Why Hermes kept the `sudo` group, and the correct restart

A new process inherits the supplementary groups of its parent. A user unit's parent is the **user
manager** (`user@1000.service`), which was started while `ubuntu` was still in `sudo`. So
`systemctl --user restart hermes-gateway` produces a fresh process **with the old groups**, every time.

The correct method is `systemctl restart user@1000.service`, run from the system manager:

1. It stops every `ubuntu` user unit cleanly, including Hermes. Nothing is killed by name.
2. It starts a new user manager with groups read fresh from `/etc/group`.
3. `hermes-gateway` comes back because it's enabled. The script starts it explicitly if not.

Login sessions (OrcaTerm) live in `session-*.scope`, outside the user manager, and aren't touched.
`finish-hardening.sh` checks that the old PIDs are gone and the new ones differ, and reads
`/proc/<pid>/status` for every process of the new manager to confirm none carries `sudo` or `adm`.

### 3.3 `/etc/sudoers.d/acli`: audited before it is changed

`finish-hardening.sh` prints the whole file (sudoers files hold no secrets), with each binary's owner,
mode, package and type, and classifies every rule that reaches `ubuntu`:

| Verdict | Rule | Action |
|---|---|---|
| **Root-equivalent** | `ALL`; a wildcard; a shell, interpreter, editor or pager; a package/service/container manager; a file-writing tool (`tee`, `cp`, `dd`, `chmod`, `install`, `rsync`, `tar`…); `git`, `curl`, `find`, `env`, `sudo`; or **any binary or directory the agent can write to** | Disabled automatically, with a backup and `visudo` validation (automatic restore on failure). Keeping it would make every other boundary decorative |
| **Needs decision** | a specific, root-owned binary that isn't in the list above (for example a vendor CLI) | **Left in place**. Re-run with `--acli disable` or `--acli keep` after reading the printed details |

**Correction to `a0182e5`:** that version could finish **silently** with an `acli` rule still in force. A
one-rule file without a trailing newline was never read (`read` drops an unterminated last line), and
nothing compared the result with what `sudo` actually grants. Now every line is read (continuations
joined), every line gets a verdict, and `sudo -l -U ubuntu` is the deciding check: if anything besides
`ioms-ops` remains, the script **stops with an error** unless you chose `--acli disable` or
`--acli keep` (keep is recorded in `/root/ioms-sudo-review.log`). `--audit-only` changes nothing and exits
0 only when `ioms-ops` is all that reaches `ubuntu`.

**Disabling a root rule doesn't cost Hermes its tools or channels.** Its gateway is a *user* service; its
channel configuration and keys live in `~/.hermes`; it restarts itself with `systemctl --user restart
hermes-gateway`, which needs no sudo. Root is only needed for things `ioms-ops` already provides. If a
vendor tool turns out to be needed as root for a specific job, that job becomes a validated `ioms-ops`
subcommand rather than a blanket rule.

Rehearsed both ways (§10): `systemctl restart hermes-gateway` → root-equivalent (systemctl); a vendor
binary → needs decision; the same binary made writable by `ubuntu` → root-equivalent.

### 3.4 What Hermes can do afterwards

| Area | Hermes, autonomously | How |
|---|---|---|
| **Code** | read, change, branch, commit; with a GitHub token, push | its own clone `~/IOMS` (`setup-dev-workspace.sh`): its own SQLite database, mail to log, no production secret |
| **Tests and tooling** | `php artisan test`, `composer`, `npm run build/lint` | the same workspace; PHP 8.3, Composer, Node installed system-wide |
| **Routine deploy** | `sudo ioms-ops deploy`: fast-forward to `origin/main` | test gate on the **target** commit (in-memory SQLite) → backup → deploy → health check → **automatic rollback** if unhealthy. 6/hour |
| **Services** | `sudo ioms-ops restart nginx|php8.3-fpm|ioms-worker|mysql` | config-tested first; reload-or-restart; 12/hour |
| **Laravel ops** | `sudo ioms-ops artisan` `about`, `migrate:status`, `route:list`, `schedule:list/run`, `queue:failed/retry/restart`, `config/route/view/event:cache/clear`, `optimize(:clear)`, `storage:link`, `ioms:mail-check`, `ioms:duitku-check` | arguments validated; output redacted |
| **Logs** | `sudo ioms-ops logs app|worker|nginx|nginx-access|php|backup|migration|ops [N]` | passwords, keys, bearer tokens, signed-URL parameters and **order-status tokens in URLs** redacted |
| **Diagnostics** | `status`, `health`, `git-status`, `disk`, `db-stats` (counts and sizes, never rows), `backup`, `backups`, `policy` | |
| **Recovery** | `maintenance off`; restart a down service; rollback happens automatically after a failed routine deploy | |

**A person approves** (`ioms-ops request …`, approved by `iomsadmin`; the agent's own account is refused):
a deploy that contains **database migrations**, a rollback to an arbitrary commit (code can't un-apply a
migration), and putting the site **into** maintenance. Approvals expire in 30 minutes and are bound to
the exact commit. The approved deploy still goes through the test gate and backup.

**Not available to Hermes, and why:**

| Withheld | Reason |
|---|---|
| A root shell, `sudo` for anything but `ioms-ops` | root reads every tenant's data and every secret, and can erase the audit trail |
| `tinker`, raw SQL | arbitrary reads of personal data and password hashes, arbitrary writes without the app's rules |
| Production `.env` | DB password, Duitku key, Zoho password, `APP_KEY` (which forges signed links and decrypts data) |
| Restore over the live database, dropping data | irreversible; a person does it from a root shell |
| Firewall, DNS, users, packages, reboot, reinstall | infrastructure changes with blast radius beyond IOMS |

If Hermes needs something that isn't there, the answer is a new **validated subcommand** in `ioms-ops`
(rehearsed, audited, rate-limited), not a wider sudoers line.

**Toolchain (fix after the instance's first run):** `setup-dev-workspace.sh` failed with APT's "held broken
packages". The Hermes image's own Node.js conflicts with Ubuntu's `npm`, and the single install command
took the PHP packages down with it. Now the script installs only missing packages, simulates each group
first, refuses removals and runtime upgrades, and **uses the existing Node.js rather than installing
one**. Read-only diagnosis: §3.6.

### 3.6 Read-only APT diagnosis

```bash
export LANG=C
dpkg-query -W -f='${Package} ${Version} ${Status}\n' nodejs npm php8.3-cli mysql-server 2>/dev/null
command -v node npm; node -v; npm -v
grep -rl nodesource /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null
apt-mark showhold
apt-get check
apt-get install -s --no-remove nodejs npm 2>&1 | tail -12
apt-get install -s --no-remove git unzip sqlite3 php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-zip php8.3-gd php8.3-curl php8.3-intl php8.3-bcmath php8.3-gmp 2>&1 | tail -4
```

`-s` only simulates, so none of these changes anything.

### 3.5 Administrator access and recovery

`iomsadmin` is the human administrator (password plus sudo). If it's ever locked out, any one of these
works:

- (a) Lighthouse console → **Reset password**.
- (b) Lighthouse **Automation Tools (TAT)**, which runs as root: `usermod -aG sudo iomsadmin && passwd -u iomsadmin`.
- (c) Restore snapshot `ioms-before-migration-20261025`.
- (d) Restore the backups `harden-hermes-host.sh` and `finish-hardening.sh` write to
  `/root/hermes-hardening-*` and `/root/hermes-finish-*`.

### 3.7 Caddy holds 80/443: IOMS goes *behind* it, not in its place

**Finding (stage 2, 2026-10-25):** port 80 is held by **Caddy** (PID 1010), not nginx. On the Hermes
template Caddy is very likely the front door of the **Hermes dashboard**. The dashboard binds
`127.0.0.1:9119`, has no login of its own, and is normally published through Caddy (Hermes docs;
dashboard guides). Stopping Caddy or taking its ports would break Hermes. `provision.sh` stopped before
installing anything, as designed.

**The migration doesn't need nginx on 80/443.** It needs something to terminate TLS for `iomsuite.com`
and hand requests to PHP. Options:

| Option | Hermes impact | Verdict |
|---|---|---|
| Stop/replace Caddy with nginx | breaks the dashboard; rewrites Hermes' config | rejected |
| nginx on 80/443 alongside Caddy | impossible (same ports) | rejected |
| Serve IOMS directly from Caddy (`php_fastcgi`) | one site in Caddy | workable, but discards the rehearsed nginx layer |
| **Caddy keeps 80/443; nginx on `127.0.0.1:8081`; one IOMS site in Caddy** | **one `import` line added, verified** | **chosen** |
| A second instance for IOMS | none | cleanest isolation; costs a second instance |

**How it's done (rehearsed with a real Caddy and a stand-in dashboard, §10):**

1. `provision.sh` detects Caddy on 80/443 and switches to **caddy mode** by itself (`--front auto`):
   - nginx listens on `127.0.0.1:8081` only.
   - Its install runs under `policy-rc.d`, because Ubuntu's nginx starts itself on port 80 and would
     fail half-configured.
   - The visitor IP is trusted from `CF-Connecting-IP` only when it arrives from 127.0.0.1.
   - It writes `/etc/caddy/ioms.caddy` (IOMS hostnames, the Origin certificate, `reverse_proxy
     127.0.0.1:8081`) **without activating it**.
2. `caddy-site.sh` (read-only) shows:
   - Caddy's config source and every site and upstream it serves;
   - whether the combined config passes `caddy validate`;
   - the exact one-line diff it would apply.

   It refuses if Caddy is driven by JSON/`--resume`, if Caddy can't read the key, or if `iomsuite.com`
   is already defined.
3. `caddy-site.sh --apply`:
   - backs up the Caddyfile, adds `import /etc/caddy/ioms.caddy`, validates, and **reloads
     gracefully** (no drop);
   - then checks every site that existed **before** (each name on 80/443, plus the bare IP). If any of
     them answers differently, it restores the backup and reloads;
   - then checks that IOMS answers through Caddy exactly what nginx answers directly;
   - re-running is idempotent, and `--undo <backup>` reverses it.

**Security finding to decide (not changed by any script):** `caddy-site.sh` reports whether Caddy proxies
`:9119`/`:8642` **without authentication**. If it does, anyone who reaches the instance's IP can drive
Hermes. Hermes now has no root, but it still holds its LLM keys and channels. Fix it in Caddy with
`basic_auth` on that site, or restrict it to your IP. That's a change to Hermes' own site, so it's yours
to make. **In Caddy mode `cloudflare-lockdown.sh` refuses to run**, because a port-level Cloudflare-only
rule would also cut off the dashboard. Restrict IOMS inside its own Caddy site after cutover instead.

**Order on this instance:**

1. `bootstrap.sh stage2` (caddy mode; nothing is published).
2. Export and `migrate.sh import`.
3. `caddy-site.sh`, then `caddy-site.sh --apply`.
4. `migrate.sh verify --url https://iomsuite.com --resolve 127.0.0.1 --e2e`.

## 4. Provision (on the instance)

**Origin certificate first (Cloudflare dashboard):** SSL/TLS → Origin Server → Create Certificate → RSA,
hostnames `iomsuite.com, *.iomsuite.com`, 15 years. Save the certificate and private key **on the
instance** as `/root/origin.pem` and `/root/origin.key` (paste them in an editor there; don't send them
through chat or email).

```bash
cd /opt/ioms-kit/deploy/tencent
bash provision.sh --domain iomsuite.com --domain www.iomsuite.com --domain staging.iomsuite.com \
     --origin-cert /root/origin.pem --origin-key /root/origin.key
# after confirming what Hermes needs to listen on:
bash provision.sh --domain iomsuite.com --domain www.iomsuite.com --domain staging.iomsuite.com \
     --apply-firewall --ssh-from <your-public-ip>/32
```

Lighthouse console → Firewall: allow **22 from your IP only** (OrcaTerm works without public 22), and
80/443. Remove any other inbound rule the image added unless the inspection showed Hermes needs it.

## 5. Application and data: the fast path (one command per side)

**Method:** an encrypted **server-to-server pull over HTTPS**. Tencent downloads the bundle straight from
the existing IDCloudHost website, so there's no File Manager download and no OrcaTerm upload. SSH to
IDCloudHost isn't required: cPanel Terminal is enough.

| Considered | Verdict |
|---|---|
| SSH from Tencent to IDCloudHost (rsync/stream) | fastest if it exists, but not guaranteed on shared hosting |
| Remote MySQL (cPanel → Remote MySQL → allow `43.173.21.31`) | opens 3306 on shared hosting; storage still needs another route |
| cPanel File Manager download + OrcaTerm upload | works, but slow and manual, and the dump passes through your PC |
| **Encrypted bundle, published briefly under a random name, pulled by Tencent** | **chosen**: needs only cPanel Terminal and the site that already runs |

**Why it's safe:**

- AES-256 with a one-time random key that exists only in the transfer code.
- The file name is 128 random bits.
- Tencent checks the SHA-256 of the encrypted file before decrypting, and of every file after.
- The published file is removed as soon as the access log shows the download, after `--wait` minutes,
  or on Ctrl-C.
- The plain export stays only in your cPanel home (mode 700) and in `/root` on Tencent.

**IDCloudHost (cPanel Terminal).** The script is fetched with `git fetch`/`git show`, which doesn't touch
the live code; `git pull` would:

```bash
cd ~/ioms && git fetch -q origin main && git show origin/main:deploy/tencent/cpanel-handoff.sh > ~/ioms-handoff.sh
bash ~/ioms-handoff.sh            # rehearsal: production keeps serving
bash ~/ioms-handoff.sh --final    # cutover day: maintenance mode first, so no write is lost
```

It prints one line, the **transfer code**, and waits.

**Tencent (as `iomsadmin`):**

```bash
sudo git -C /opt/ioms-kit pull --ff-only
sudo bash /opt/ioms-kit/deploy/tencent/migrate-all.sh            # add --replace on later runs
```

It asks for the transfer code (hidden) and, only the first time, the Zoho app password (hidden; Enter =
later). Everything else is automatic and skipped when already done:

1. Download, checksum and decrypt the bundle.
2. Apply production's configuration from the bundle:
   - `APP_KEY` must **match**, and is never overwritten;
   - Duitku is forced to **sandbox**;
   - VAPID keys carry over, so existing push subscriptions keep working;
   - Google sign-in and `IOMS_*` carry over;
   - neither database nor mail credentials travel.
3. **Hold** the scheduler and the queue worker. A copy of production with live mail must not email
   customers before cutover. `ioms-ops` respects the hold.
4. Import: backup, then load, then **per-table row-count parity**, then storage, then migrations.
5. Validate the Origin certificate at `/root/origin.pem` + `/root/origin.key`: Cloudflare Origin CA,
   key matches, covers `iomsuite.com` and `*.iomsuite.com`. Without one, IOMS is **not published**.
6. Publish (`caddy-site.sh --apply`).
7. Verify: pages and security surface, e2e register/sign-out/sign-in, Duitku sandbox, Zoho, backup and
   restore check.
8. Print one table: PASS / BLOCKED / FAIL per gate, and **READY FOR CUTOVER** only if everything passes.

**After the DNS switch you approve:** `sudo bash /opt/ioms-kit/deploy/tencent/migrate-all.sh go-live`
releases the scheduler and worker.
**Recovery:** `migrate.sh restore --from /var/backups/ioms/<stamp> --yes-overwrite-database` (takes a
backup of the current state first), and `caddy-site.sh --undo /root/caddy-backup-<stamp>`.

### 5.1 Manual steps (fallback, as before)

```bash
bash migrate.sh app                                    # clones main into /srv/ioms/app, .env: mail=log, payment=none
# APP_KEY must be the PRODUCTION key: encrypted columns, signed links and sessions depend on it.
# On IDCloudHost:  grep ^APP_KEY= ~/ioms/.env   -> copy the value to the clipboard, then here:
bash migrate.sh env-set APP_KEY                        # hidden prompt; a secret given as an argument is refused
bash migrate.sh env-set APP_URL https://iomsuite.com
```

**Export on IDCloudHost** (cPanel Terminal; production keeps serving):

```bash
cd ~/ioms && git pull origin main            # brings export-cpanel.sh only; see note
bash deploy/tencent/export-cpanel.sh         # -> ~/ioms-export-<stamp>/
```

> `git pull` on production also brings v2.131/2.132 code without running `deploy.sh`. Code files change
> but no migration or cache rebuild runs. If you'd rather not touch production code at all, upload
> `export-cpanel.sh` alone through File Manager and run it from your home directory with
> `APP=~/ioms bash export-cpanel.sh`.

Move the directory to the instance: download it from cPanel File Manager, then upload it through
OrcaTerm **while logged in as `iomsadmin`**, whose home is mode 700. An upload made as `ubuntu` lands
where Hermes can read every tenant's data, and `import` refuses an export inside `/home/ubuntu`. Then:

```bash
bash migrate.sh import --from /root/ioms-export-<stamp>
```

It verifies checksums, refuses a non-empty database unless `--replace` (which backs up first), loads the
dump, **proves the row count of every table equals the source**, restores `storage/app`, and runs
`deploy.sh` (migrations to this release, caches, storage link, release signature).

**Secrets and services** (each prompt hidden):

```bash
bash migrate.sh env-set MAIL_MAILER smtp
bash migrate.sh env-set MAIL_HOST smtppro.zoho.com
bash migrate.sh env-set MAIL_PORT 465
bash migrate.sh env-set MAIL_SCHEME smtps
bash migrate.sh env-set MAIL_USERNAME support@iomsuite.com
bash migrate.sh env-set MAIL_PASSWORD                  # Zoho app-specific password
bash migrate.sh env-set PAYMENT_GATEWAY duitku
bash migrate.sh env-set DUITKU_MERCHANT_CODE D25218    # the SANDBOX project code
bash migrate.sh env-set DUITKU_API_KEY                 # SANDBOX key
bash migrate.sh env-set DUITKU_IS_PRODUCTION false
bash migrate.sh env-show                               # secrets shown as (set)
sudo -u ioms bash -c 'cd /srv/ioms/app && php artisan config:cache'
```

> [!warning] A copy of production data with live mail
> Turning on SMTP before cutover means the Tencent copy can email real customers: the scheduler's
> subscription notices, approval escalations. Either set mail last, right before the checks, or stop the
> scheduler until cutover by commenting out the `schedule:run` line in `/etc/cron.d/ioms`.

## 6. Verify on Tencent before DNS changes

DNS still points at IDCloudHost, so tests go **directly to the origin** with `--resolve`, keeping the
real host name for TLS SNI and routing:

```bash
bash migrate.sh verify --url https://iomsuite.com --resolve 127.0.0.1 --e2e --mail-to <your-own-address>
```

| Check | Pass |
|---|---|
| Public pages, `.env`/`.git` not served, unsigned callback refused, no debug output | `ALL CHECKS PASSED` |
| `--e2e` register → welcome → sign out → sign in → account area | five `ok` lines |
| Release identity | commit and signature printed; compare after cutover with Master Admin → Running Release |
| Services | nginx, PHP-FPM, MySQL, cron, worker `RUNNING`, three scheduled jobs |
| Permissions | `.env` 640 `ioms:ioms`, `/srv/ioms` 750, `APP_DEBUG=false` |
| `ioms:duitku-check` | "sandbox accepted the signature and offered N method(s)" |
| `ioms:mail-check` | both `accepted`; the headers of the received message show spf/dkim/dmarc `pass` |
| Backup + restore check | row counts match |

Then in a browser, before DNS: add `43.173.21.31 iomsuite.com www.iomsuite.com` to your own computer's
`hosts` file (and accept the Origin certificate warning, since only Cloudflare trusts it), and:

1. Sign in with a real account; open Employees, PTW, PPE, Incidents; open one permit PDF.
2. **Duitku sandbox end to end:** register a test account, verify its email, Continue setup, Starter
   monthly, choose a method, pay on the **sandbox** page. Duitku's callback only reaches Tencent after
   cutover (it targets `https://iomsuite.com/webhooks/payment/duitku`), so before cutover this proves
   inquiry and redirect. Settlement is proven in §9, again with sandbox money.
3. Remove the `hosts` line.

Load: `for i in $(seq 200); do curl -sk --resolve iomsuite.com:443:127.0.0.1 -o /dev/null https://iomsuite.com/login & done; wait; free -m`.
No 5xx in `ioms-ops logs nginx`, and free memory stays above ~600 MB with Hermes in its final state.

## 7. Cloudflare settings to prepare (no DNS change yet)

| Setting | Value | Why |
|---|---|---|
| SSL/TLS mode | **Full (strict)**, applied to the new origin only (see note) | Cloudflare validates the Origin CA certificate |
| Always Use HTTPS | On | |
| Minimum TLS | 1.2 | |
| Origin certificate | from §4 | |
| Proxy status of `@`, `www` | **Proxied** (orange) | Traffic keeps flowing through Cloudflare's WAF |
| Mail records | **unchanged**, DNS only (grey) | MX, `@` TXT SPF, `zmail._domainkey`, `_dmarc` |

> [!note] Full (strict) is zone-wide
> If IDCloudHost's certificate isn't publicly valid, switching the zone to Full (strict) **before**
> cutover would break the current site. Either switch it **at** cutover (step 4 below), or first confirm
> IDCloudHost presents a valid certificate (`openssl s_client -connect <idcloudhost-ip>:443 -servername iomsuite.com`).

Write these down before cutover, because rollback restores them:

| Record | Type | Current value (fill in from Cloudflare) | Proxy |
|---|---|---|---|
| `iomsuite.com` | A | `<IDCloudHost IP>` | |
| `www` | A/CNAME | | |
| SSL/TLS mode | — | | — |

## 8. Cutover (needs your explicit approval) and rollback

Approval is needed for **one action**: changing the `@` and `www` records in Cloudflare.

**T-1 day:** run `export-cpanel.sh` and `import --replace` once more as a rehearsal, then `verify`.
Lower nothing: proxied records switch immediately at Cloudflare's edge, so TTL isn't a factor.

**T-0, a quiet window (e.g. 22:00 WIB):**

1. IDCloudHost: `php artisan down --retry=60`. Writes stop; **nothing is deleted.**
2. IDCloudHost: `bash deploy/tencent/export-cpanel.sh`.
3. Tencent: `bash migrate.sh import --from <dir> --replace`. Parity must pass.
4. Cloudflare: SSL/TLS **Full (strict)**; then `@` → `43.173.21.31` (proxied) and `www` → `43.173.21.31`
   or a CNAME to `@` (proxied). **Change nothing else.**
5. `bash healthcheck.sh https://iomsuite.com --e2e` from any machine, now **through Cloudflare**.
6. One Duitku **sandbox** payment through to settlement: the callback now reaches Tencent. Invoice
   `paid`, organization provisioned, a replayed callback settles nothing.
7. Master Admin → Running Release signature equals the one `migrate.sh verify` printed.
8. Leave IDCloudHost in maintenance mode, running. Don't cancel it.

**Rollback (any failure in 5–7 that can't be fixed in 30 minutes):**

1. Cloudflare: `@` and `www` back to the values recorded in §7; SSL mode back to its recorded value.
2. IDCloudHost: `php artisan up`.
3. Anything written on Tencent after step 3 (registrations, invoices, payment callbacks): `migrate.sh
   status` shows the time window. Export those rows from Tencent and re-enter them on IDCloudHost by hand.
   Duitku callbacks that reached Tencent are in its `payment_webhook_events`.

**After 24 h stable:** `bash cloudflare-lockdown.sh --dry-run`, then apply it, so the origin answers
web traffic from Cloudflare only. Its own rollback is `--undo`.

## 9. After cutover

- Daily: backups run at 01:30. Weekly: `ioms-ops backups` and `ioms-restore-check`.
- **Off-site copy (your decision):** a Tencent COS bucket or another host. Add the copy line in
  `backup.sh`; also enable Lighthouse scheduled snapshots.
- Monitoring: Lighthouse alarms for CPU/memory/disk, and an external uptime check on
  `https://iomsuite.com/login`.
- Keep IDCloudHost paid and untouched for 14 days. Before cancelling, download a full cPanel backup and
  confirm cPanel mail was exported (`docs/runbooks/EMAIL-ZOHO.md` §5).
- Duitku production stays off until merchant approval and production credentials exist.

## 10. Rehearsal record (container, Ubuntu 24.04.5, 2026-10-25)

Run with the real scripts. The source was the local MySQL `ioms_ui` copy (141 tables), exported by
`export-cpanel.sh`.

| Step | Result |
|---|---|
| `provision.sh` ×4 (idempotent) | pass after fixes 1, 2, 5, 6 below |
| `migrate.sh app` from GitHub `main` | pass after fix 3 |
| `env-set APP_KEY` via stdin | value identical to source; secret as argument refused; `env-show` masks |
| `import` without `--replace` on a full database | refused |
| `import --replace` | backup taken, checksums OK, **141/141 tables row-count identical**, 719 files, migrations "Nothing to migrate", signature printed (after fix 4) |
| `healthcheck.sh --resolve 127.0.0.1 --insecure --e2e` via nginx/HTTPS | **all pass**, including register/sign-out/sign-in |
| Browser (Chromium) as an **imported** HSE account | sign-in, Employees, PTW list + detail, PPE, Incidents 200; permit PDF 418 KB; no 5xx |
| Backup + restore check | pass (after fixes 5 and 9): 7 key tables restored with identical counts |
| Hermes: `request deploy` → self-approve refused → person approves → `run` | deployed `main` 2.131.0 → 2.132.0, backup taken first, signature `988bfd11b4e2` |
| Hermes: `request rollback e6a5852` → approve → `run`, then deploy forward | 2.132.0 → 2.131.0 (`4504f078bccf`) → 2.132.0; health check passes after each |
| `migrate.sh verify --resolve 127.0.0.1 --e2e --allow-no-payment` | **VERIFY PASSED** (payment skipped: no sandbox key here) |
| Hermes guard (stand-in user) | 14 checks, all as designed after fix 7 (see §3) |
| `cloudflare-lockdown.sh --dry-run` | 22 rules; **not applied** (no netfilter in a container) |
| `bootstrap.sh stage1` as an `ubuntu` with `NOPASSWD: ALL` and a stand-in Hermes running as it | after fixes 10 and 11: ubuntu's rule disabled, removed from `sudo` and `adm`, left with only `ioms-ops`; `iomsadmin` reaches root with its password |
| A process still carrying the old `sudo` group | can't escalate (`ubuntu`'s password is locked); the script says so and asks for a Hermes restart |
| After the restart, from the agent's account | **denied:** root, IOMS `.env`, `/root/ioms-db.cnf`, backups, MySQL, nginx logs, writing to the kit. **Allowed:** its own `~/.hermes`, `sudo ioms-ops status` |
| `harden-hermes-host.sh --undo` | sudoers and groups restored, `ubuntu` back to root |
| `bootstrap.sh stage2` as `iomsadmin` (and as `ubuntu`) | passes (refused) |
| `import --from /home/ubuntu/...` (and from `iomsadmin`'s home) | refused (passes with 141/141 parity; export left root-owned, mode 700) |

Defects the rehearsal found that reading had not:

1. nginx 1.24 (Ubuntu 24.04) rejects `http2 on;`, so the config now uses `listen 443 ssl http2`.
2. A `[::]` listener stops nginx on a host without IPv6, so IPv6 listeners are emitted only when the
   host has IPv6.
3. Root `git` refused the `ioms`-owned repository ("dubious ownership"). Every root-side git call now
   names it as a safe directory.
4. The `ioms` user couldn't read an export in root's home, so extraction runs as root without the
   archive's owners, then the files are handed to `ioms`.
5. `mysqldump` rejected `database=` under `[client]` in `/root/ioms-db.cnf`, which would have broken
   **every nightly backup**. The key moved to `[mysql]`, and an existing file is repaired.
6. PHP-FPM ran as `www-data`, which couldn't write `storage/logs`, so the first 500 couldn't even be
   logged. IOMS now has its own pool running as `ioms`.
7. Log redaction left a Bearer token visible after "Authorization:", and part of a quoted password. Both
   rules are fixed and re-tested.
8. `svc enable` restarted an already-running supervisord in no-systemd mode and never brought it back. It
   now leaves running services alone (systemd path unaffected).
9. `backup.sh` wrote `SHA256SUMS` from `sha256sum ./*`, which checksummed the half-written file itself,
   so **no backup could ever pass verification**. It now names the three files.
10. Re-running `install-ops-guard.sh` aborted at `chmod` on the already append-only audit log, leaving the
    agent with **no** `ioms-ops` rule. The mode is now set only on a new log.
11. The agent stayed in `adm`, which reads nginx access logs, and those carry order-status tokens in URLs.
    `adm` is now removed too, and logs reach Hermes only through `ioms-ops logs`, which redacts.
12. **The test gate let a failing suite through.** It took its exit status from `tail`, not from
    `php artisan test`. Now under `pipefail`, and it names the failing tests.
13. That gate then caught a real repository defect: `ProductRevisionV252Test` still required four
    distinct mailboxes after v2.131.0 made `hello` share `support@`. It passed on the developer machine
    only because a local `.env` still set `hello@`.
14. `ioms-ops artisan` passed an empty argument (`printf '%q '` with no arguments), so every bare
    command failed.
15. `ioms-ops restart nginx` reloaded an nginx that was down, which does nothing. It is now
    reload-or-restart. `mysql` was added to the restart list after Hermes saw it down and couldn't act.

**Powerful-Hermes rehearsal (same container, stand-in Hermes as `ubuntu`):**

| Case | Result |
|---|---|
| `acli` with a `systemctl` rule and a vendor binary | `systemctl` disabled (root-equivalent), vendor binary left for decision; same binary made writable by `ubuntu` → disabled |
| `setup-dev-workspace.sh` | clone, Composer, SQLite workspace, a test file passes as `ubuntu`; production `.env` unreadable |
| A: autonomous `deploy` of a normal change | test gate 1,645 passed → backup → deploy → healthy, no approval |
| A, with the test still broken | **refused, nothing deployed** (after fix 12) |
| A, with MySQL down | backup failed → **deploy stopped, nothing changed**; Hermes then restarted MySQL, PHP-FPM, nginx and the worker itself |
| B: change containing a migration | autonomous deploy refused → `request deploy` → `ubuntu` cannot approve → `iomsadmin` approves → test gate, backup, deploy, migration applied, healthy |
| C: change that breaks the site (500s) | health check failed → **automatic rollback** to the last good commit, healthy again |
| artisan allow-list, `tinker`/`db:wipe`/`--env`/`$(id)` | allowed ones run; the others refused |
| `logs nginx-access` | order-status token and signed-URL parameters redacted |
| User-manager restart of Hermes | **not rehearsable** here (no systemd in a container); runs for the first time on the instance, with its own checks |


## 11. What is blocked, and on whom

| Blocker | Needs |
|---|---|
| Running the kit on Tencent | A shell on the instance (OrcaTerm or SSH). This environment is denied `43.173.21.31` |
| Hermes user / risk decision | The inspection report |
| Origin certificate | Cloudflare dashboard (§4) |
| Data export | cPanel access (§5) |
| Zoho SMTP password, Duitku sandbox key | Entered on the instance with `env-set`, never in chat |
| DNS cutover | Your explicit approval at T-0 (§8) |
| Off-site backup target | Your choice (§9) |
