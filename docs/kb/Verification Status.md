---
title: Verification Status
type: register
updated: 2026-10-19
tags: [kb/verification]
---

# Verification Status

What has actually been **run**, versus what has been carefully **reasoned about**. This distinction
is a first-class concept in IOMS, not documentation pedantry.

**Authoritative procedure: `LOCAL-VERIFICATION.md`.**

---

## v2.118.0 — the level this register was missing

Three releases were reported as delivered, with passing tests and clean builds, while PPE
Master went on refusing the action they had fixed. The reports were not dishonest; they were
**true statements about the wrong subject**. A test asserts the behaviour of the code it
loads. It cannot assert which code a different process, on a different machine, has compiled.

The message the owner saw exists in no file of the release they deployed — it was last
present in v2.106.0. Checking that commit out in isolation, provisioning it against MySQL 8
and driving the screen in a browser, the twelve-to-six month edit **saves and persists**.

> [!important] Browser-verified is not deploy-confirmed
> Every level above **Reasoned** describes code the verifier chose and loaded. None of them
> says anything about the binary serving production. A branch the server happens to be on, a
> `git pull` that refused because a committed asset was touched, or OPcache holding the
> previous release's classes will each defeat all four, silently, with a clean deploy log.

`ReleaseStatusService` and the hardened `app:deploy` exist to close that gap, and
`DeploymentIdentityTest` pins them. See `docs/ADR/057-a-release-must-be-able-to-prove-what-is-running.md`.

### What was actually run for the powerful-Hermes model (ADR 073)

Rehearsed in the Ubuntu 24.04 container: the `acli` audit (three cases), the dev workspace, autonomous
deploy (pass; failing tests refused; failed backup stops; site-breaking commit auto-rolled-back), the
approved migration deploy, artisan allow-list and refusals, redaction of tokens in nginx access logs.
**Not run anywhere:** restarting `user@1000.service` (no systemd in a container), and nothing on
`43.173.21.31`.

### What was actually run for the release automation (ADR 076)

- On a replica of the Tencent layout in this environment (`/srv/ioms/app`, MySQL 8, PHP-FPM, nginx
  behind Caddy with the Origin certificate, `ioms-backup`), via `sshd` and the forced command:
  `8621e15` deployed from GitHub with the server-side test gate (1654 passed), backup, health check,
  `IOMS_RESULT` matching; additive migration auto-deployed; `dropColumn` refused `needs_approval`;
  broken release auto-rolled back; same SHA a no-op; forced command refused shell, `id`, injection and
  forwarding. Two defects found and fixed (ADR 076).
- `tests/Unit/MigrationPolicyTest.php`, actionlint on both workflows, ESLint (0 errors after declaring
  `Notification`), Pint on changed files.
- **Not run:** anything on Tencent (this environment is denied the host); a GitHub-hosted runner
  reaching Tencent over SSH (needs the owner's secrets and an open port 22).

### What was actually run for v2.133.0

- **Suite:** full `php artisan test` (results recorded in the release commit); 9 new tests in
  `SafetyEquipmentRegisterTest` (derivation, action required, back-dated inspections, ID generation,
  overview counts and filters, empty register, role boundaries, tenant isolation, old-tab redirect).
- **CI on GitHub:** run 40193048791 (`main`) and 40193049020 (branch) concluded `success` for
  `82efedf`, read through the public API. The GitHub MCP tools answered "invalid session" in this
  session, so CI was checked anonymously.
- **Browser:** MySQL 8 + `php artisan serve` + Playwright against seeded equipment, as HSE at 1440 px,
  HRD at 1440 px and HSE at 390 px: register, overdue filter, search, record page, inspection dialog,
  Equipment Types tab, and the `?tab=register` redirect. No page errors or console errors; HRD saw no
  write actions. Screenshots were reviewed by eye.
- **Release watcher:** run against a mock GitHub API, a stand-in `ioms-ops` and a real git remote
  (scenarios in `docs/runbooks/RELEASE-LOOP.md`). **Not run on Tencent**, and `ioms-ops deploy <sha>`
  was syntax-checked but not executed end to end.

### What was actually run for the Hermes hardening (v2.132.0, follow-up)

- `bootstrap.sh stage1` → `stage2` in the Ubuntu 24.04 container, reproducing the instance: `ubuntu`
  UID 1000 with cloud-init `NOPASSWD: ALL`, and a stand-in Hermes running as `ubuntu`. Verified: the
  agent account is denied root, `.env`, DB credentials, backups, MySQL and nginx logs; `iomsadmin` gets
  root with its password; `--undo` restores the previous state; an export in `/home/ubuntu` is refused.
  Two defects were found and fixed (runbook §10, items 10 and 11).
- **Not run on the instance:** this environment is still denied `43.173.21.31`.

### What was actually run for v2.132.0

- The full Tencent kit, **in an Ubuntu 24.04.5 container** (root, no systemd), with the real scripts:
  provision ×4, app from GitHub `main`, env-set, import with and without `--replace` (141/141 tables
  row-identical, 719 files), nginx + HTTPS, `healthcheck.sh --e2e` (all pass), Chromium as an imported
  account across five modules and a 418 KB permit PDF, backup + restore check, the Hermes guard with a
  stand-in user (bypass attempts refused, approval flow, redaction, append-only audit), and the lockdown
  dry run. Details: `docs/runbooks/TENCENT-MIGRATION.md` §10.
- **Not run:** anything on `43.173.21.31`, Cloudflare, Zoho, or a live Duitku sandbox request. `ufw`
  rules weren't applied (no netfilter in a container), and systemd unit handling was exercised only
  through the `service` fallback.

### What was actually run for v2.131.0

- Full SQLite suite (counts in the commit message), plus the new tests: `RegistrationSurvivesMailFailureTest`,
  `OpenOrderRepricingTest`, `MailCheckCommandTest`, and two added to `DuitkuPaymentFlowTest`.
- The 500 was **reproduced first** (a test with a throwing transport returned 500), and the repricing
  tests were run against the pre-fix controller and failed (Duitku asked for Rp299.000).
- Browser (Chromium, 390 px) against MySQL with `MAIL_HOST=127.0.0.1:1`: `/register` → 302 → "Your
  account is ready" with the not-sent notice; the error was logged and not shown.
- `deploy/tencent/healthcheck.sh` passed against a local production-mode server; no public page or
  built asset contains "Tahada".
- **Not run:** anything against `sandbox.duitku.com`, `iomsuite.com` DNS, Zoho, Cloudflare, cPanel or
  the Tencent instance. The development network policy denied all of them, and no credentials were
  available. The Duitku signature formulas were checked against Duitku's current documentation via
  web search, not by a live request.

### What was actually run for v2.130.0

**Level: two viewports in a real browser against MySQL 8, plus a rendered PDF inspected with
`pdffonts`. NOT deployed, and still no real device push.**

| Claim | How it was checked |
|---|---|
| The badge counts unread notifications only | Two unread → **2**; opened one → **1**; opened the second → **badge absent**; reload and navigate to another module → still absent |
| Unread looks unread | Row backgrounds read from the DOM: `rgb(239, 246, 255)` while unread, `rgba(0,0,0,0)` once read, with the other row's tint unchanged |
| Marking read actually persists | The `POST /notifications/{id}/read` response status was watched: **419 before the fix, 200 after** |
| Identifiers upper-case themselves | `emp-0042` → `EMP-0042`; a character inserted at position 4 left the caret at **5**, not at the end; a paste uppercased; `full_name` untouched. Both 1440 and 390 |
| The splash is on the path people start from | `/`, `/login`, `/dashboard` and `/my-work/login` all painted it with the bundle blocked, and it was gone after mount. `/` had previously been measured with an **empty** `body.innerText` |
| Typography reaches the screen | Computed styles: page title **Archivo 800 / 23px**, document type band **Archivo 800 at `font-stretch: 75%`**, KPI figure **Archivo 800 with tabular figures** |
| Typography reaches the PDF | `pdffonts` on a rendered permit: `ArchivoRoman-CondensedExtraBold`, `-ExtraBold`, `-Bold`, all embedded CID TrueType, Indonesian text intact |
| The issuer still outranks the type band | Re-rendered and looked at: the company name at 24px above "HOT WORK" at 22px condensed |

**Three defects found by driving it, not by reading it:**

1. **The 419.** Marking read failed for every freshly signed-in user, because `<meta csrf-token>` is
   stale after a session regeneration and Inertia never reloads the document. Invisible to the suite
   — Laravel disables CSRF verification in tests.
2. **Sending both tokens did not fix it.** Laravel prefers `X-CSRF-TOKEN`, so the stale value still
   won; only the cookie alone answered 200.
3. **The splash was on the wrong path.** `/` is where a phone lands, and it was blank.

**Automated:** `NotificationBadgeAndTypographyTest` — 20 tests over the badge arithmetic, the
count-down, unread-id reconciliation, per-user isolation, live config on non-dashboard pages,
identifier normalisation (and its exclusions), the embedded fonts and the weight rules. Full suite
green: **1625 tests, 8219 assertions** (`vendor/bin/phpunit`, 2026-10-20), `npm run build` clean.

**Still not verified: a real device push** (unchanged from v2.129.0, tracked as RT-001) and
production.

### What was actually run for v2.129.0

**Level: two concurrent browser sessions against MySQL 8, plus a stand-in push service. NOT
deployed, and NO real device push was ever received.**

| Claim | How it was checked |
|---|---|
| A badge changes while somebody watches it, with no navigation | An approver left on the dashboard, touching nothing: **4 → 5 unread** when HSE verified a permit in another session, `framenavigated` count **0** |
| The approver is not asked before a verification exists | Same session: the badge was unchanged through the permit's *submission*, and moved only after verification |
| A toast appears, and is readable on a phone | Screenshotted at 1440 (top-right) and 390 (above the tab bar), with the body and a *Lihat* action |
| An open detail page follows its own record | A permit's page, open in a second session, went to **Active** and **lost its Activate button** when a different session activated it — zero navigations, and no toast, because nothing was being asked of that person |
| A dropped connection loses nothing and doubles nothing | Offline for the length of two verifications: badge frozen at 5; on reconnect **7**, exactly **two** toasts, and the bell held **five unique** approval rows with no duplicates |
| The push sender is correct | Against a stand-in push service: `Authorization: vapid t=…, k=…`, `TTL`, `Urgency: high`, a per-record `Topic`, and a **zero-length body**; 201 recorded `last_pushed_at`, **410 deleted the subscription** |
| A real decision wakes a real device | A permit verified over **real HTTP** woke the approver's registered device **after the response** |
| The VAPID token would be accepted | Signed, then verified against **only the published public key** — the same check a push service performs |

**Two defects of my own, found by driving it rather than reading it:**

1. `useWatchedRecord()` did nothing on **every page in the product**. Every page wraps its own
   `<AuthenticatedLayout>`, so a page is the *parent* of anything the layout provides and cannot read
   its context. Nothing threw; found by watching the request query string.
2. The bell claimed *"this device receives IOMS notifications"* from `Notification.permission`
   alone, while `pushManager.subscribe()` was refusing.

**And one defect found by a test**, which is the one worth remembering: `absent` and `cursor=0` were
read as the same question, so **live delivery silently did not work for any account that had never
received a notification**.

**Automated:** `RealtimeNotificationTest` — 26 tests over the PTW chain, the cursor contract,
idempotent retries, per-user and per-tenant isolation, change-marker scoping, read state, the push
address book, and a failing push service proving it cannot undo a decision. Full suite green:
**1605 tests, 8148 assertions** (`vendor/bin/phpunit`, 2026-10-20), `npm run build` clean.

**NOT verified — a real device push.** Headless Chromium here has no push service connection
(`pushManager.subscribe()` answers *"Registration failed - permission denied"*), so the browser half
of push has been exercised only in code and against a stand-in service. Android Chrome, desktop
Chrome/Firefox/Edge and an installed iOS 16.4+ PWA are where it must be confirmed on a device. The
honest claim today: **the sender works, and the subscription flow refuses to claim more than it can
prove.**

### What was actually run for v2.128.0

**Level: the real UI and rendered artefacts, against MySQL 8 on this machine. NOT deployed, and NOT
checked on an iPhone.**

Five owner-reported UI faults, four of them invisible to every existing test.

| Claim | How it was checked |
|---|---|
| The boot splash is the first paint, on white, with the official stacked lockup | Loaded `/my-work/login` at 390x844 with the JS bundle **blocked** so the splash stayed up: white ground (`rgb(255,255,255)` on `body`), symbol above word, minimal bar, "Loading…". Then loaded it unblocked: the splash is gone after mount (`#ioms-boot` count 0) |
| The stacked lockup is the owner's artwork, not a redraw | Composed from `ioms-icon.svg`'s and `ioms-logo.svg`'s own paths, placed in nested `<svg>` elements with their source viewBoxes; both boxes measured with `getBBox()` in Chromium rather than read off the padded viewBoxes. A test matches a path from each source file **verbatim** against the composition |
| Remember Me works and can be revoked | Driven end to end in a browser: checked → `remember_web_*` cookie issued, session cookie dropped, reopening `/my-work/login` lands on `/my-work`; unchecked → no cookie, the form returns; signed out → cookie gone and `/my-work` refused |
| The PTW example fields no longer clip | At 390px the box grows to four lines and shows the whole example (`scrollHeight == clientHeight`); typing returns it to one line; Enter inserts nothing; both duplicated helper lines are gone. Same at 1440px |
| The permit header leads with the company | Re-rendered the PDF with `pdftoppm` and looked at it: "Galangan Nusantara Jaya" at 19px above a 16px "HOT WORK", legal name, address, contact and NPWP intact, no collision with the ACTIVE seal |
| Both seals name a job, not a permission | Configured `HSE Officer` / `Manager Shipyard` in Settings, drove a permit through both gates, read the activity rows back: the verifier had a position (`SPV HSE`) and it won; the approver had none and the configured stage title was snapshotted. The re-rendered seals print those, and no role |

**Two defects of my own were found by looking, not by the suite:**

1. A bare `svg` selector in the splash CSS overrode the `x`/`y`/`width`/`height` of the lockup's
   nested elements — the first render drew the symbol at full width, off centre, **with the word
   missing entirely**. Invisible in the markup.
2. `is_active` was checked only by the two sign-in forms, so a deactivated account kept working
   until its session expired. My own test for it failed, which is how it was found; Remember Me
   would have stretched that window to five years. Closed with `EnsureAccountIsActive`.

**Automated:** `AppShellAndBootSplashTest` (11), `MyWorkRememberMeTest` (8), `PtwStampJobTitleTest`
(10). Full suite green: **1579 tests, 8074 assertions** (`vendor/bin/phpunit`, 2026-10-20), and
`npm run build` clean. Restoring the `roleLabel()` fallback fails three of the stamp tests, so they
carry weight.

**Not verified:** iOS, and production. There is no Apple device on this machine — the boot splash
and the chrome metas are the iPhone implementation and are standards-based, but nobody has looked
at this release on one, and `apple-touch-startup-image` is deliberately not shipped (it needs
device-exact PNGs that cannot be checked here; without it iOS shows white, which is now exactly the
splash's own ground). Deployment remains the owner's, by hand.

### What was actually run for v2.127.0

**Level: the real UI and rendered artefacts, against MySQL 8 on this machine. NOT deployed.**

This release is a stated product requirement, not an audit: PTW must pass HSE verification before it
reaches final approval, each gate configured per company, each notified in turn.

| Claim | How it was checked |
|---|---|
| Settings → **Permit to Work Workflow** is usable, not just a column | Opened in a browser as a tenant administrator: Operating Unit select, two stage panels, role chips (Super Admin / HSE / Manager / HRD / Warehouse) and a checkbox list of that tenant's own five accounts. Saved stage 1 = `["hse"]`, stage 2 = `["manager"]`; read back from `ptw_workflow_settings` for that `company_id` and for no other |
| A submitted permit is **not** Active, and only the verifier is offered an action | Submitted as the requester, then signed in as both gate-holders: HSE saw **Verify**, the manager saw nothing, the badge read *Awaiting HSE Verification* |
| The approver is notified **after** verification, not with it | The manager's notification list was empty while the permit sat at `submitted`, and carried the permit after verification was recorded |
| Verify authority ≠ approve authority | The HSE account's `POST` to the `approved` transition answered **403** under the saved configuration; three pre-existing tests had to be given a `letHseHoldBothGates()` fixture for the same reason |
| Both stamps carry the real actor, their company job title and the time | Re-rendered the PDF with `pdftoppm` and looked at it: green **VERIFIED** (`Hse Audit-business / SPV HSE / 20 Oct 2026 07:09`) above blue **APPROVED** (`Manager Audit-business / MANAGER / 20 Oct 2026 07:10`) |
| A pending stage shows no stamp | A permit at `submitted` renders the badge and no seal; `StampRenderer` still cannot produce one without a server-side authorization record |

**Three defects of my own were found this way and not by the suite:**

1. `HasWorkflow::notifyStatusChange()` notified **inside** the caller's transaction, so a throwing
   notifier rolled the approval decision back — a 500 and a lost verification. Silently true for
   **every** workflow model in the product, not just PTW.
2. The APPROVED stamp hardcoded `'HSE'` as its kind label, so a manager-approved permit printed
   *APPROVED / HSE* above a Manager's name. Visible on the rendered sheet.
3. **Activate** was `canManageHse()` only, so a company routing approval to a manager ended up with
   an approved permit nobody on screen could activate. Found in the browser; widened to gate-holders.

**Automated:** `tests/Feature/PtwTwoStageWorkflowTest.php` — 29 tests covering all twelve scenarios
the requirement names, plus configuration, tenant isolation, notification-failure and activation.
Full suite green: **1550 tests, 7973 assertions** (`vendor/bin/phpunit`, 2026-10-20), and
`npm run build` clean.

**Not verified:** production. Deployment is the owner's, by hand — nothing in this release has been
run anywhere but this machine.

### What was actually run for v2.126.0

**Level: rendered artefacts and the real UI, against MySQL 8 on this machine. NOT deployed.**

This release exists because the owner checked v2.125.0 **by looking at the PDF and by using the
import**, and found three of its claims false. Two of the three were invisible to a green suite and
to code reading, and obvious within seconds of looking at the artefact:

| Claimed | Actually |
|---|---|
| "Dompdf can honour neither the rotation nor the ink texture" (ADR 065) | **It renders both.** Written from memory, never tested. The PDF stamp was a plain blue box. |
| "Branding is supported" | The header carried the company NAME; the logo was gated behind a template almost nobody creates, and `identity()['name']` defaulted to the product name. |
| "A company workbook with different column order is accepted" | True and useless — the mapping panel offered two rows. |

> [!important] The habit this adds to the list
> A renderer's limits are a thing to **MEASURE**, in five minutes, with a probe. A limitation
> asserted from memory becomes a design decision nobody revisits — and this one shipped, was
> documented in an ADR as a deliberate tradeoff, and had to be rejected by the owner before anybody
> rendered a test page.

**What was exercised:**

| Driven | Result |
|---|---|
| A probe PDF of `transform: rotate()` and of SVG | Both render. ADR 065 corrected. |
| The permit PDF, rendered and inspected at 150dpi | Customer logo, legal name, address, locality, contact line, NPWP; ACTIVE slanted green distressed stamp, no date; APPROVED in blue with `Sri Handayani / SPV HSE / 19 Oct 2026 21:34` |
| The document view at 1440px | The identical SVG, from the same data URI |
| The document view at 390px | Compact pill, **0px horizontal overflow** |
| The PPE import, through the real dialog | 4 employee rows from 12; 8 items to 3 people; `Baju Kerja` → Work Uniform and `Coverall` → Coverall as two rows from one file; sizes 42/41 on Safety Shoes; `FABRIKASI`/`PRODUKSI`/`Total` read as furniture; `ZZZ-9999` refused; one unreadable date refused by column and row |
| Pairing `Ukuran Baju` with Work Uniform by hand, then importing | Sizes **L** and **M** on the uniform issuances, read back from the database |
| The IOMS template on a clean slate | 2 rows, 6 items, 0 skipped — no regression |
| Both migrations on MySQL 8 | Applied, no failure |

**Four defects of my own were found by looking rather than by testing:** a `ReferenceError` in
`SignatureBlock` that blanked the whole document view (React unmounts the page; one line in the
console, no failed request, no server log — the exact failure `docs/CONVENTIONS.md` records for the
plan-change button), a `->format()` call on what is already a string, the stamp icon overlapping the
first letter of its word, and the ink wear striking through the approver's job title.

Suite: **1521 tests, 7797 assertions, green**. Build: clean.

> [!warning] Still not a statement about production
> Neither release has been deployed, so ADR 057's only honest post-deploy claim — "the two
> signatures match" — cannot be made for either.

---

### What was actually run for v2.125.0

**Level: browser-verified against MySQL 8 on this machine. NOT deployed, NOT production-verified.**

Environment: a fresh MySQL 8 database (`ioms_ui`), the full migration history applied with no
failure, four plan tenants seeded with role accounts, and a **My Work account created through the
real `MyWorkAccessService`** so the fixture matches what an administrator actually produces. Driven
with Chromium at **390px** (the phone the brief is about) and **1440px**.

What was exercised, end to end, in that browser:

| Driven | Result |
|---|---|
| My Work sign-in with an Employee ID and access code | lands on `/my-work` |
| `/employee-lookup` from that account | **200, 200, 200** — it answered **403** on every request before this release |
| Searching `Misrani`, `Saputra Misrani`, `Misrani  Saputra` | each finds "Misrani Saputra"; "Karyawan tidak ditemukan" no longer appears |
| Opening the create form | PIC arrives pre-filled with the creator ("Andi Pratama") |
| The form at 390px, long description, colleague selected | **0px horizontal overflow**; the textarea grew 72px → **190px** |
| Submitting the permit | `/permits-to-work/1`, personnel and PIC saved |
| HSE approving and activating it | `APPROVED` seal reading **SPV HSE** on the detail page **and** the document view; `ACTIVE` standing stamp on the document view |
| `activity_logs` read back | `actor_title = 'SPV HSE'` on the approving row, `'Welder'` on the requester's; new descriptions read "Permit to Work moved from…" |
| The PDF | 200, `application/pdf`, **8,053 bytes** |
| The notification, from the My Work account | `Permit to Work PTW-2026-00001 is now Approved · just now · Buka`; tapping it opens `/permits-to-work/1`; read state survives a reload |

**Two defects were found BY the browser and not by reading**, and both are fixed in this release:
the activity timeline rendered `PermitToWork moved from Draft to Submitted.`, and the first cut of
the compact approval seal dropped the job title — on the detail page, the most-visited surface,
which is the one place the title exists for.

Suite: **1476 tests, 7612 assertions, green** (SQLite). Build: clean, with both new arbitrary
Tailwind utilities confirmed in the emitted CSS.

> [!warning] What this does NOT say
> Nothing above describes production. The release has not been deployed, so the only honest claim
> ADR 057 permits — "the two signatures match" — cannot be made. See
> [[PTW and Field Access Master Revision]], whose rows are at *Menunggu deployment*.

---

### What was actually run for v2.124.0

The release is a SWEEP, so the evidence is the sweep itself: a MySQL 8 database seeded with five
tenants (Starter, Professional, Business, Enterprise and the IOMS Sandbox), twenty-five accounts
(five roles each) plus five My Work accounts, every account signed in, every link it is offered
opened, every create form submitted, and the database read back afterwards.

| Claim | Level | Evidence |
|---|---|---|
| A create page no longer takes a document number | **Browser-verified** | Before: three views of /incidents/create moved the sequence 2 → 5 with ONE incident in the table; `employee_cases` sat at 3 with zero rows. After: three views all show INC-2026-00006 and the sequence stays at 5 |
| The number shown is the number saved | **Test-covered** | `preview()` then `generate()` return the same string |
| A preview writes nothing at all | **Test-covered** | No sequence row is created for a module a fresh tenant has never used |
| The mistake cannot come back | **Test-covered** | A source scan fails if any `'...Number' => Model::generate...()` Inertia prop reappears |
| An incident with no Operating Unit reaches its own tenant | **Browser-verified** | Before: 404 for its own author, and absent from the register of all three tenants asked. After: both rows listed and openable for the filer's tenant, still absent for the other two |
| It reaches nobody else | **Test-covered** | Index and show, as a second tenant: not listed, 404 |
| The Sandbox stops offering what it refuses | **Browser-verified** | Crawled as the Sandbox administrator: 103 pages, 0 refusals (was 3, from the sidebar and the Administration Overview). The HSE account: 90 pages, 0 (was 1) |
| `tenants:sync-grants` runs | **Browser-verified** (CLI) | Was a LazyLoadingViolationException on the first tenant; now reports per tenant and `--dry-run` writes nothing |
| `db:seed --class=DemoTenantSeeder` runs | **Browser-verified** (CLI) | Was "Unknown column 'source_material_request_id'"; now completes, and the requisition is linked through the pivot |
| Saving works across modules and roles | **Browser-verified** | 14 create forms submitted as an Enterprise administrator, plus HSE, HRD and Warehouse accounts on their own modules: every record saved, and every one is visible in its register afterwards |
| The approval engine still completes | **Browser-verified** | Approve on a submitted material request: POST 302 /approvals/1/approve, status `approved` in MySQL, page reads Approved |
| My Work sign-in still works on every plan | **Browser-verified** | All five accounts: Employee ID + access code → /my-work, 3 links offered, 0 non-200 |
| The public surface is clean | **Browser-verified** | 16 signed-out pages crawled, no failures, no JS errors; sign-up POSTs 302 to /register/welcome |
| Nothing else is broken | **Browser-verified** | 25 accounts, 1,676 page loads: ZERO real failures. The only non-200 entries are file downloads, where the browser aborts the navigation and the server is serving a real PDF/workbook (checked directly: 200 with the right content types). One 500 in the run was mine -- `npm run build` deleted the Vite manifest mid-crawl, which the log names at the matching second -- and the page answers 200 on a re-check |
| Suite | **Test-covered** | 1445 tests / 7511 assertions on SQLite **and** MySQL 8 |
| Production itself | **NOT confirmed** | Deploy-confirmed remains the owner's step (ADR 057) |

> [!warning] Two of the 500s in the first crawl were mine
> Thirteen "failures" in an early pass were a half-finished edit and a running `npm run build`
> being served by `artisan serve` mid-crawl. `storage/logs/laravel.log` named both in one line each.
> Clear the log before a crawl, read it after, and stop editing while one is in flight — written up
> in `docs/CONVENTIONS.md`.

### What was actually run for v2.123.0

| Claim | Level | Evidence |
|---|---|---|
| An administrator picks an employee and the account is created | **Browser-verified** | MySQL 8, Business and Starter tenants: Add My Work User, searched the register, selected the worker, pressed Create. The dialog showed Employee ID, name, position, department, operating unit and employee status without any of them being typed |
| The account is linked to the right employee | **Browser-verified** | `users.employee_id` joins to the selected worker; `tenant_id` and `company_id` match that employee's own unit on both tenants |
| No employee is created by the flow | **Test-covered** | The register count is unchanged across a creation |
| A worker signs in with Employee ID and code | **Browser-verified** | Followed the Share Access Link itself, which prefilled the badge number, typed the code, landed on `/my-work` |
| A worker signs in with their full name | **Test-covered** | Lower-cased, hyphen-stripped code accepted; same guard, same session |
| The same name does not reach the wrong account | **Browser-verified** | Two workers both called Budi Santoso (EMP-0001, EMP-0002). Signing in as "Budi Santoso" with EMP-0002's code reached EMP-0002's account; EMP-0001's `last_login_at` stayed NULL |
| The same badge number across tenants is told apart | **Browser-verified** | Both tenants have an EMP-0003. The Business code logged into the Business account at 14:52:59 and the Starter code into the Starter account at 14:53:01 -- recorded in `last_login_at` on two different rows |
| The user lands in My Work, not the dashboard | **Browser-verified** | `/my-work` on every sign-in, with the three-item rail from v2.122.0 |
| A wrong code, and an unknown identity, are refused identically | **Browser-verified** | Both returned the same sentence; neither authenticated |
| A reset code replaces the one held | **Browser-verified** | Reset from the table issued a new code; the test proves the old one stops working and the new one works |
| A deactivated account cannot sign in, and can be restored | **Browser-verified** (table) / **Test-covered** (sign-in) | Row reads Deactivated with a Reactivate action; the test drives refuse-then-restore |
| Repeated wrong codes are rate limited | **Test-covered** | Six attempts; the sixth is the throttle message |
| A duplicate account is refused, and said before the button | **Browser-verified** | Selecting an employee who already has one shows the account's status and disables Create Access. Found and fixed a real defect here: the comparison was string-vs-number and the notice never appeared |
| Quota, tenant isolation and the allow-list survive the separation | **Test-covered** | Quota exhaustion and reactivation-at-full refused; another tenant's administrator gets 404; the account is still refused Settings, Employees and Work Center |
| Platform creation and sign-in are undisturbed | **Browser-verified** | Created a Platform User through the renamed dialog, signed in with email and password, landed on `/dashboard`. The two tables are cleanly separated: Platform Users lists only Full accounts, My Work Access lists the three My Work ones |
| The worker's own Account page is honest about this class | **Browser-verified** | It used to say "we sent a confirmation link to ." with a Resend button for an account with no address, offer Billing (403 for this class), offer a Workspace Focus it cannot use, and call the credential a password. It now states the Employee ID, names the access code, and offers neither |
| Suite | **Test-covered** | 1425 tests / 7430 assertions on SQLite **and** MySQL 8 |
| Production itself | **NOT confirmed** | Deploy-confirmed remains the owner's step (ADR 057) |

### What was actually run for v2.122.0

| Claim | Level | Evidence |
|---|---|---|
| A My Work account can be created through the app | **Browser-verified** | MySQL 8, Starter and Business tenants: Settings → Users & Access → Add User, POST 302, the account appears in the list |
| It can sign in with its own credentials | **Browser-verified** | Signed in on both tenants and landed on `/my-work`; also pinned as a test |
| It is stored with its operating unit | **Browser-verified** | `company_id` 1 and 2 on the two new accounts; the two created BEFORE the fix are still NULL in the same table, which is the defect side by side with its fix |
| An administrator-created account is marked verified | **Browser-verified** | `email_verified_at` set on creation; previously NULL forever with no way to clear it |
| Every link the account is offered actually opens | **Browser-verified** | Crawled every anchor on every reachable page: 3 links offered, 3 × 200, on both tenants. Before: 8 offered, 6 × 403 |
| With PTW Access it reaches the permit form | **Browser-verified** | 6 links, 0 × 403, `/permits-to-work/create` 200 |
| Full User creation is undisturbed | **Browser-verified** | Created through the same dialog on Business: `user_type` full, unit 2, signs in, lands on `/dashboard`, full rail |
| A Full User is offered nothing it is refused | **Browser-verified** | HRD Full User: 83 pages crawled, 0 × 403 (was 1). Starter administrator: 77, 0 × 403 (was 1). Business administrator: 105, 0 × 403 (was 3) |
| Quick actions follow the plan, not just the role | **Test-covered** | `PlanScopedActionOffersTest` over all four plans, asking the same prefix map the middleware enforces with |
| A full quota is explained on the field that caused it | **Test-covered** | `assertSessionHasErrors('user_type')`, message carrying used and permitted figures |
| Desktop and mobile hold up | **Browser-verified** | 16 pages at 1512×1000 and 390×844: no horizontal page overflow anywhere; mobile bottom-nav labels given a gutter after two long ones read as one run of text |
| Suite | **Test-covered** | 1402 tests / 7186 assertions on SQLite **and** MySQL 8 |
| Production itself | **NOT confirmed** | Deploy-confirmed remains the owner's step (ADR 057) |

### What was actually run for v2.121.0

| Claim | Level | Evidence |
|---|---|---|
| A wide file issues every dated column and nothing else | **Browser-verified** | MySQL 8, HSE account: 5-row Indonesian-headed CSV → 10 items across 3 employees, 18 blank cells reported, EMP-9999 refused by name |
| An empty cell produces no transaction | **Browser-verified** | The employee whose entire row was blank received 0 records and is reported as an absence, not an error |
| Nothing is created but the issuance record | **Browser-verified** | `employees` stayed 4; `ppe_types` went 6 → 7 only because a human added "Work Uniform" in PPE Master deliberately |
| Work Uniform + Shoes + Helmet = Compliant, with no Coverall | **Browser-verified** | Budi: Compliant. The database shows Work Uniform, Safety Shoes, Safety Helmet and no Coverall |
| All three held but out of date = Needs Replacement | **Browser-verified** | Siti: three items issued 430 days ago, all past their intervals |
| A missing clothing group = Incomplete | **Browser-verified** | Agus: Shoes + Helmet + Headlamp, no clothing |
| A renamed override still satisfies its requirement | **Test-covered** | The rule reads the declared role; renaming "Safety Shoes" does not break it |
| The filter agrees with the badges | **Browser-verified** | Filtering by Compliant returned exactly one row, and the total agreed (resolved before pagination) |
| The profile names which group is missing | **Browser-verified** | Group-by-group panel, naming the type that satisfied each one |
| The template matches what the importer accepts | **Measured** | Downloaded and read: wide, grouped by requirement (Coverall, Work Uniform, Shoes, Helmet, then additional), pre-filled with the 4 active employees, all PPE cells blank |
| The rules are load-bearing | **Measured** | Collapsing the expired distinction fails 3 tests; removing role inheritance fails 2 |
| Employee Import behaviour unchanged | **Test-covered** | Untouched this release; full suite unchanged |
| Suite | **Test-covered** | 1382 tests / 6983 assertions on SQLite **and** MySQL 8 |
| Production itself | **NOT confirmed** | Deploy-confirmed remains the owner's step (ADR 057) |

### What was actually run for v2.120.0

| Claim | Level | Evidence |
|---|---|---|
| PPE is issued in bulk to employees that already exist | **Browser-verified** | MySQL 8, HSE account: 8-row CSV with Indonesian headings → 6 issued, remarks carried, expiries derived per type interval (Coverall +6, Shoes/Helmet +12, Glasses +3) |
| It creates no employee and no PPE type | **Browser-verified** | `employees` stayed 4 and `ppe_types` stayed 6 across the whole run; the unknown Employee ID and unknown type were named on screen |
| Re-running the same file issues nothing | **Browser-verified** | Second pass: 8 rows read, 0 to be issued, 6 already held, confirm button disabled; `employee_ppe` still held exactly 6 rows |
| A built-in and its override count as one kind | **Test-covered** | Issued the template, then created the override, then imported — refused as already held |
| The fifth type works through the supported path | **Browser-verified** | "Seragam (Office)" refused and named; added once in PPE Master; same file then imported it as a company-owned type with a derived 12-month expiry |
| HSE (not only Super Admin) can reach it | **Browser-verified** | Driven throughout as an HSE account; a Manager account gets 403 (test-covered) |
| The guards are load-bearing | **Measured** | Duplicate guard removed → 2 of 24 fail; company filter removed → 2 isolation tests fail |
| Employee Import behaviour is unchanged | **Test-covered** | The trait extraction was proven neutral against 68 existing import tests before anything new was added; full suite unchanged otherwise |
| Suite | **Test-covered** | 1359 tests / 6935 assertions on SQLite **and** MySQL 8 |
| Production itself | **NOT confirmed** | Deploy-confirmed remains the owner's step (ADR 057) |

### What was actually run for v2.119.0

| Claim | Level | Evidence |
|---|---|---|
| A tenant can no longer rewrite or delete a shared KPI category | **Test-covered** | 12 tests; removing the guard fails 6 of them, including all 4 asserting the hole |
| Editing a built-in creates a company-owned override and leaves the template untouched | **Browser-verified** | MySQL 8: editing "Drill" left row 6 (`drill`, shared, `short_label` "Drill") unchanged and created row 9 owned by Unit Utama with "DRL-6" |
| Deleting a built-in deactivates this company's copy only | **Browser-verified** | "HSE Campaign": row 7 still shared and active, new row 10 inactive for company 1; the confirm dialog says deactivate, not delete |
| No surface shows a built-in beside its override | **Browser-verified** | Settings lists exactly one row per category after the override; also pinned for Quick Attendance and the per-company scope |
| `numbering_formats` / `numbering_sequences` have no equivalent hole | **Reasoned** | Both carry `tenant_id` and the write keys on it; read, not exercised |
| Suite | **Test-covered** | 1335 tests / 6847 assertions on SQLite **and** MySQL 8 |
| Production itself | **NOT confirmed** | Deploy-confirmed remains the owner's step |

### What was actually run for v2.118.0

| Claim | Level | Evidence |
|---|---|---|
| The deployed release's PPE edit is not refused | **Browser-verified** | `origin/main` checked out in isolation, migrated against MySQL 8, Safety Shoes 12 → 6 saved and persisted across a reload |
| The refusal the owner saw is absent from that release | **Measured** | `git grep` over the released commit returns no match; last present in v2.106.0 |
| Merged line: built-in PPE type editable, 12 → 6 persists, no duplicate row, other built-ins untouched | **Browser-verified** | 6 of 6 checks, MySQL 8, 1512px |
| Merged line: My Work account creation, class persisted, correct seat pool | **Browser-verified** | 6 of 6 checks; `My Work Users: 1 / 50` with `Full Users: 1 / 25` unchanged |
| Running Release panel reports version, branch, commit, signature from a web request | **Browser-verified** | Rendered at 1512px and 390px, no page overflow, no console errors |
| `app:deploy` fails loudly on missing schema and keeps maintenance mode | **Browser-verified (CLI)** | Column dropped by hand; deploy named `ppe_types.source_default_id` and exited non-zero |
| A fresh MySQL 8 database provisions end to end | **Browser-verified (CLI)** | `migrate` completes; previously died on `after('additional_users')` |
| Suite | **Test-covered** | 1323 tests / 6805 assertions pass on SQLite **and** MySQL 8 |
| Frontend | **Measured** | ESLint 0 errors (4 pre-existing warnings, unchanged); production build clean |
| Production itself | **NOT confirmed** | No access to production. Deploy-confirmed is the owner's step: compare the two signatures after deploying |

---

## Why this note exists

For most of this project's history, *nothing* was confirmed by running the application. MySQL was
unreachable in the development environment, so every claim about the UI came from reading source.
`CLAUDE.md` still says it plainly.

A single 30-minute browser session then found **four defects that months of code review had missed**,
including a wordmark showing the wrong product name on the login screen and a header that overflowed
on every phone width.

> [!important] The lesson is not "review harder"
> Some classes of defect are **only** visible when the application is running. Compilation is not
> verification: `npm run build` succeeding tells you the JavaScript parsed, nothing more.

---

## The levels

| Level | Means | Claim you may make |
|---|---|---|
| **Reasoned** | Read carefully; brace/balance checks, route cross-references, prop-contract matching | "The code looks correct" |
| **Test-covered** | A test exercises it and would fail on regression | "It behaves correctly under the suite" |
| **Browser-verified** | Exercised in a running application against realistic data | "It works" |
| **Measured** | A number was taken from the running app (overflow widths, query counts) | "It is *this* much better" |
| **Deploy-confirmed** | The release signature printed by `app:deploy` matches the one Master Admin → Running Release prints from a **web** request | "The code I verified is the code answering requests" |

`#status/verified` in the registers means **browser-verified or measured**, not merely test-covered.

---

## Current verification state

### Automated

| | |
|---|---|
| Suite | **1,030 tests, 5,415 assertions — all passing** (2026-10-13, source checkout `main` at v2.117.0) |
| Database | In-memory **SQLite**, as configured by `phpunit.xml`; no MySQL tests were run because no isolated MySQL test database is configured |
| Lint | `npm run lint` — 0 errors, 4 warnings (existing unused ESLint-disable directives in `GasTestRecords/Index.jsx` and `Settings/Index.jsx`) |
| Build | `npm run build` succeeded; generated assets use the current source. Existing large JavaScript chunk advisory remains (about 2,056 KB) |

### Browser verification (2026-10-13)

A separately seeded SQLite database in the Windows temp folder served the current built assets on a
local preview. In PPE Master, Safety Shoes changed from 12 to 6 months and saved with the success
message; a new company-owned PPE type was added and appeared in the list. In Users & Access, the Add
User dialog exposed Full User and My Work User, the table showed the account class, and the separate
Full/My Work meters appeared. The form was not submitted; backend tests created a My Work account,
asserted the persisted class, and enforced the corresponding pool.

The seeded Full User administrator's landing preference was enabled in that disposable database. The
My Work link appeared in both the HSE workspace and company-dashboard sidebars, and the administrator
opened the My Work page. The field preference remained separate from account class and PTW Access.
The PTW form showed distinct work-reference and physical-location fields with shipyard, mining, and
construction examples. No real tenant or configured MySQL database was used.

A browser click on the PPE deactivation control timed out before the confirmation outcome could be
read; no deactivation result is claimed. No UI deletion or account-creation submit was performed.
`SharedMasterDataOwnershipTest` covers company-only deactivation, safe deletion of an unused custom
type, and refusal to delete a type with issue history. There is no isolated MySQL test database, so
MySQL tests were not run.

The public production page was read without signing in: its banner reports **v2.116.0**, while the
starting `origin/main` source was v2.108.0. The public page exposes no commit SHA. The available
`origin/claude/eloquent-newton-pbxxdg` source has the reported default-PPE lock messages and lacks the
Add User account-class selector; that source comparison explains the reported behavior but does not
prove which SHA is deployed. This corrective release is based on `origin/main`; production was not
accessed beyond its public page and no deployment was performed.
| Suite | **1308 tests, 6678 assertions — all passing on SQLite** (2026-10-13, v2.116.0; was 1293/6611 at v2.115.0) |
| Suite on **MySQL 8.0** | **1308 of 1308 passing** (2026-10-13), on an isolated `ioms_mysql_test` database with synthetic data. The MySQL run earned its keep again this release: three new rate tests passed on SQLite and failed on MySQL, because the fixture put 100,000 hours in one `decimal(5,2)` column. SQLite accepted it silently. |
| Database | In-memory **SQLite**, so no MySQL or external service is needed |
| Lint | `npm run lint` — 0 errors (4 existing warnings in `GasTestRecords/Index.jsx` and `Settings/Index.jsx`) |
| Build | `npm run build` succeeded (2026-10-12): 2319 modules, 2,085 KB bundle, the existing size advisory unchanged |

> [!note] The suite now runs on a fresh clone (v2.108.0)
> It previously did not, and this was found the first time anyone tried. Two things blocked it:
> `bootstrap/cache/` was not tracked (no `.gitkeep`), so `composer install` failed at
> `package:discover`; and `phpunit.xml` pinned no `APP_KEY`, so **535 of 1022 tests** failed with
> `MissingAppKeyException` on any machine without a `.env`. Both are fixed, and the fix was checked
> by moving `.env` aside and running the suite. That matters mostly for CI, which **still does not
> exist** — there is no `.github/workflows`, so nothing runs this suite but a person.

> [!note] MySQL HAS now been run (v2.108.1)
> For the first time, the whole suite was executed against a real MySQL 8.0 in STRICT mode, on an
> isolated database with synthetic data. It found three things SQLite structurally cannot:
>
> 1. **A fresh MySQL database could not be provisioned at all** — a migration anchored a column with
>    `after()` on a column created two months later in migration order. SQLite ignores column
>    positioning, so the suite provisioned happily. Existing deployments were unaffected, which is
>    why nothing had ever surfaced it.
> 2. **`/ptw/quota` 500s on MySQL** — an eager load names a column that does not exist, and SQLite
>    reads an unresolvable double-quoted identifier as a string literal rather than failing.
> 3. **One test could not run on MySQL at all** — it queried `sqlite_master` directly.
>
> Both pitfalls are written up in `CONVENTIONS.md`. The lesson below stands and is now evidenced
> rather than asserted.

> [!warning] SQLite is not MySQL
> The suite deliberately runs on SQLite so it needs nothing external. That means it does **not**
> cover MySQL-specific behaviour — notably `lockForUpdate` row/gap locking, which SQLite serialises
> globally, so a "concurrency test" there proves nothing. It also does not cover real concurrency,
> production data volumes, or email/queue side effects.
>
> It also means **migrations must stay driver-aware**: avoid `ALTER TABLE … MODIFY COLUMN`,
> multi-table `UPDATE … JOIN` and anything reading `information_schema`, or guard it with
> `DB::getDriverName() === 'mysql'`. Six migrations had to be made portable before the suite could
> provision at all.

### Browser-verified — v2.116.0 The refined HSE Overview, driven rather than looked at (2026-10-13)

The redesign was checked two ways: how it LOOKS at three widths, and whether the controls on it
actually do anything. Against an isolated SQLite database seeded with synthetic HSE records —
classified incidents across all five categories, man-hours, a running permit and one past its end
time, equipment and P3K inspection dates, material requests, and real per-user notification rows.

**Layout, at three widths — no horizontal overflow, no console errors at any of them:**

| Width | Result |
|---|---|
| 1512 px | Header band, five KPI cards in one row, trend 5 / calendar 4 / notifications 3, then classification 5 / rates 3 / man-hour 4 |
| 1024 px | KPI cards in threes, trend and calendar side by side, notifications below |
| 390 px | Everything stacks; KPI cards in pairs rather than one per screen; the bottom nav is untouched |

**Behaviour — 9/9 checks, driven through the real UI:**

| Check | Result |
|---|---|
| `+ Add Schedule` | Opens, writes a real event, and the event comes back from the server on the next render |
| ...and it is the SAME store | The event appears on the full Calendar page too, scoped `department_key: hse` |
| An operational KPI card | Navigates to its register (`Active Permits` → `/permits-to-work`) |
| A Quick Access shortcut | Performs its action (`Report Incident` → `/incidents/create`) |
| Notifications panel | Lists the real per-user rows, with relative times, not a second feed |

**The numbers were read out of the page payload and checked by hand**, which is how the arithmetic
was verified rather than assumed: 6 classified cases (1 LTI, 1 RWC, 2 MTC, 2 FAC) + 1 unclassified
report surfaced separately; recordable 4, correctly excluding both first-aid cases; 1,280 man-hours;
TRIR = 4 × 200,000 / 1,280 = 625.00; LTIFR = 1 × 1,000,000 / 1,280 = 781.25. Both figures are
absurdly high because the fixture logs 1,280 hours, which is the point: the panel prints the
denominator it used, so an implausible rate is visibly a thin denominator rather than a mystery.

> [!important] What the browser pass changed
> Four things were wrong on screen and right in the tests: the Man-Hour labels truncated to single
> initials in a four-across grid, the classification labels truncated to "Lost Time I…", the hero
> carried a second clock duplicating the one the top bar already shows on every page, and the trend
> chart's smoothing was high enough to draw a curve dipping below zero between two months — a safety
> chart implying a negative number of incidents.

> [!note] Still not covered
> **Production was not reached from this session** — see the deployment note below. Everything above
> ran against an isolated SQLite database on `127.0.0.1:8188` with synthetic data only.

### Browser-verified — v2.115.0 Permanent deletion, and the import that follows it (2026-10-13)

The owner's decision replaced the v2.114.0 answer: deletion is permanent, so the question is no
longer "does a re-import restore" but "is the number actually free, and did the right things go with
the person". **28/28 end-to-end checks passed through the real UI**, zero JavaScript errors, against
an isolated SQLite database whose departments and positions existed before any import.

| Step | Result |
|---|---|
| Import 75 | `75 imported successfully`; all 75 on the Employees page; 15 distinct join dates on page 1; types `{pkwtt:15, pkwt:15, daily:15, intern:15, contractor:15}` |
| Delete all 75 | through the real `DELETE /employees/bulk` with its `HAPUS` confirmation; list shows nobody |
| Re-import the same file | `75 imported successfully`, **none skipped** — the numbers were genuinely free; all 75 back on the page |
| Mixed file (3 deleted + 2 live + 3 new) | `6 imported successfully · 2 skipped`, and the refusal names the real reason: `EMP-0040" (already used in this Operating Unit)` |
| The 3 new people | each findable by search; blank Workforce Type stayed **null**, not PKWTT; join date `2026-03-08` as written; leading-zero NIK `32010101019900001` intact |
| Single permanent delete, through the profile dialog | the dialog reports what would be removed from the server's own count, says `dihapus secara permanen`, and **no longer claims the history is preserved**; the roster drops by exactly one and the person cannot be found by search |
| The freed number, immediately | re-imported from the same file: `1 imported successfully · 7 skipped` |
| Consistency | list total equals the database; **5 departments, not duplicated**; other companies' 60 employees untouched |

Confirmed directly in the database afterwards: **zero orphans** across all twelve dependent tables
(`select count(*) ... where employee_id not in (select id from employees)` for each), the
`deleted_at` column gone, and the audit row for the erased employee carrying their full snapshot
(`{"employee_id":"NEW-0003","nik":"...","full_name":"Orang Baru C","department":"Logistik",...}`).

> [!important] Two harness faults this run exposed, both now written up
> The browser proof initially failed at the delete step because the paged `fetch` followed the
> controller's 302 **with the DELETE method**, so `DELETE /employees` answered 405 and a successful
> deletion looked like a failed one. And the atomicity test in
> `EmployeeBulkDeletionDependenciesTest` was simulating its failure through `Employee::deleting`,
> which **never fires** for the query-builder deletes the eraser uses — so the simulation did
> nothing and the test reported a symptom instead of a cause. Both are in `CONVENTIONS.md`; the
> second is the kind of green test that is worse than no test.

> [!note] Still not covered
> **Production was not reached from this session** — see the deployment note below; the container
> still has no `ssh`/`scp`/`sftp`/`rsync`/`lftp`, no key material, and its egress proxy still
> answers 403 to `iomsuite.com` (re-checked 2026-10-12). Everything above ran against an isolated
> SQLite database on `127.0.0.1:8188` with synthetic data only.

### Browser-verified — v2.114.0 The reported import failure, reproduced and then driven to work (2026-10-12)

This run exists because the defect was **reported from use**, not found by review: 75 rows refused as
duplicates by a page that said there were no employees at all. The sequence was reproduced before
anything was changed, and then the same sequence was driven through the real UI afterwards.

**Reproduced first, with counts, on an isolated SQLite database and a synthetic 75-person roster:**

| Before the fix | Observed |
|---|---|
| Re-import after deleting everyone | 75 rows read, **0 imported, 75 skipped**, 0 live employees — exactly the report |
| A file with no Workforce Type column | every row stored `pkwtt`, which nothing in the file said |
| 8 genuinely different join dates under an `mmm-yy` display format | collapsed to **5** distinct displayed values (the v2.113.0 raw-value read already prevents this; confirmed still correct) |

**Then 22/22 end-to-end checks passed through the browser**, zero JavaScript errors, against a tenant
whose departments and positions **already existed** before any import:

| Step | Result |
|---|---|
| Import 75 | `75 imported successfully`; all 75 on the Employees page |
| Accuracy | **15 distinct join dates on page 1 alone**; workforce types `{pkwtt:15, pkwt:15, daily:15, intern:15, contractor:15}` — read from the file, not invented |
| Delete all 75 | through the real `DELETE /employees/bulk` endpoint with its `HAPUS` confirmation; list shows nobody |
| Re-import the same file | preview announces the restorations in Indonesian; result `0 imported successfully · 75 restored`, **none skipped**; all 75 back on the page |
| Mixed file (3 deleted + 2 live + 3 new) | `3 imported successfully · 3 restored · 2 skipped`, and the refusal names the real reason: `EMP-0040" (already used in this Operating Unit)` |
| The 3 new people | each findable by search; a blank Workforce Type stayed **null**, not PKWTT; join date `2026-03-08` as written; a leading-zero NIK `32010101019900001` survived |
| Consistency | list total equals what the database holds; **5 departments, not duplicated**; **0 trashed rows left**; another tenant's 60 employees untouched |

Confirmed directly in the database afterwards: the restored `EMP-0001` kept **primary key 61** — the
id it was first imported with — so nothing that references `employees.id` was stranded. `EMP-0040`,
which was *live* when the mixed file named it, kept its original join date rather than being silently
overwritten by the file.

> [!important] What reproducing first bought
> The naive fix — dropping `withTrashed()` from the duplicate check — would have replaced a readable
> per-row skip with a `UniqueConstraintViolationException` that rolls the whole import back, because
> the unique indexes include soft-deleted rows. That was established by inspecting the index, not
> guessed at. See the v2.114.0 pitfall in `CONVENTIONS.md`.

> [!note] Still not covered
> **Production was not reached from this session.** The proof above ran against an isolated SQLite
> database on `127.0.0.1:8188` with synthetic data only; no production system, credential or dataset
> was touched.

### Browser-verified — v2.113.0 Full sweep of every promised import and export (2026-10-12)

This run did not test the new feature; it swept **every import and export entry point the UI
promises**, on a running application with a record of every printable document type seeded. That is
what surfaced four defects the feature's own tests could not see, three of which pre-date the
Document Platform entirely.

**29/29 end-to-end checks passed** after the fixes, zero JavaScript errors:

| Area | Result |
|---|---|
| **Import, through the dialog** | Indonesian semicolon CSV, legacy `.xls` (BIFF), `.xlsx` with a blank column, Word table and digital PDF — each detected, previewed, confirmed, and the roster **verified to grow by exactly the expected number** (6 → 8 → 10 → 11 → 14), with each new person found by searching the module. The PDF leg correctly added **0** because it carries the same people as the Word file. |
| **Exports** | All 11 library documents × 3 formats = **33 files**, each checked by magic bytes and size. |
| **Record documents** | All 8 entitled per-record PDFs render (PTW, HSE Inspection, Incident, Investigation, PPE Replacement, Material Request, Goods Receipt, Waste Manifest). |
| **Branding** | Tenant logo verified in the OUTPUT of all three formats. |
| **Authorization** | Anonymous export and record document → redirect; unknown key → 404; a document in a workspace the plan does not grant → **403, which is correct** (ADR 045 retired those departments). |
| **Profile filter** | Responds instead of 500ing, and its two halves partition the roster exactly. |

> [!important] What the sweep found that feature tests did not
> Four of the five defects fixed in v2.113.0 were invisible to tests written for the feature,
> because each test seeded its own tidy fixture. Three pre-date the Document Platform. The lesson is
> in `CONVENTIONS.md`: sweep what the UI PROMISES, against data shaped like a customer's.

> [!note] Still not covered
> **Scanned-PDF OCR remains unsupported** (root-only binaries; cloud OCR refused on confidentiality
> grounds). **Production was not reached from this session** — see the deployment note below.

### Browser-verified — v2.112.0 Release gate (2026-10-12)

The whole document platform driven through a running application (`php artisan serve`, isolated
SQLite file database, synthetic HSE/HR/logistics records, Playwright + Chromium). **27/27 checks
passed, zero JavaScript errors.** No production data of any kind was involved.

| Area | Result |
|---|---|
| **Word import** | A .docx roster chosen in the real dialog: table extracted (`Tabel 1`), the customer's Indonesian headings mapped, 3 rows previewed and validated, confirmed, and all three people found in the Employees module afterwards. |
| **Digital PDF import** | Extracted at a stated confidence of **75%** with its reconstruction note; re-importing the same people was correctly refused as `Duplicate Employee ID`, which is itself proof the Word rows had landed. |
| **Exports** | All **11** library documents fetched in all **3** formats — 33 files, each checked by magic bytes (`PK\x03\x04` / `%PDF`) and size, not by status code. |
| **Record documents** | Permit to Work, HSE Inspection, Incident Report and Investigation Report all returned real PDFs, and each page offers a Download PDF button. |
| **Branding** | The tenant logo verified **in the output**: an `/Subtype /Image` object in the PDFs, `xl/media/` in the XLSX, `word/media/` in the DOCX. |
| **Authorization** | Unauthenticated download → 302 to login (both a register and a record document); unknown document key → 404, not 500. |

> [!note] What this run did NOT cover
> **Scanned-PDF OCR remains unsupported and unexercised** — `tesseract`/`pdftoppm` need root, which
> the cPanel host does not provide, and cloud OCR is refused on confidentiality grounds. The product
> reports a scanned page as scanned rather than returning an empty table.
> **Production itself was not reached from this session** — see the deployment note below.

> [!warning] Production deployment was NOT performed from this session
> The build container has no `ssh`, `scp`, `sftp`, `rsync` or `lftp`, no `~/.ssh` key material, and
> its egress proxy refuses `iomsuite.com` (HTTP 403 on CONNECT). The release is pushed to
> `claude/eloquent-newton-pbxxdg`; the documented `./deploy.sh` flow (ADR 027) must be run **on the
> server** by the owner. Nothing here should be read as a claim that production is running this
> release.

### Browser-verified — v2.111.0 Employee import, end to end (2026-10-12)

The eight steps of the import flow, driven through the **real dialog** with Playwright + Chromium
against `php artisan serve` on an isolated SQLite file database. The fixture is a synthetic customer
workbook, deliberately awkward: a summary tab in front of the data, a company name and report title
above the table, the customer's own Indonesian column names, a row with no name, a missing join
date, and one genuine Excel date cell. No production data of any kind was involved.

| Step | Result |
|---|---|
| 1. Upload | **19/19 checks passed.** The picker offers `.xlsx,.xls,.csv,.docx,.pdf`; the button no longer names one format. |
| 2–3. Read and map | The **second worksheet** was chosen, the heading row **located on row 5**, and the customer's own headings (`No. Induk`, `Nama Lengkap`, `Tgl Masuk`…) mapped to IOMS fields. All of it stated on screen in a "Detected Source" panel. |
| 4. Preview | 5 rows found, 4 valid, 1 invalid; the nameless record listed as skipped **at row 9 — its position in the uploaded workbook**; nothing written. |
| 5–6. Confirm and write | New master data created and the import committed. |
| 7. In the module | `Hendra Gunawan` / `0090001` visible on `/employees`, the leading zero intact; the genuine Excel date cell survived the normalisation. |
| 8. Summary | "5 Rows Found · 4 imported successfully · 1 skipped", with the reason and the customer's own row number. Zero JavaScript errors. |

A **CSV** chosen through the same widened picker was separately driven end to end (6/6 checks): read,
previewed, imported, and visible in the module.

> [!note] What this run did NOT cover
> Word and PDF were exercised through the SERVER by the test suite, not dragged into the dialog by
> hand. Scanned-document OCR remains unexercised and disabled.

### Browser-verified — v2.110.0 Document Platform (2026-10-12)

Run against a **real running application**: `php artisan serve`, an isolated SQLite file database,
synthetic company/employee/inspection/stock/project data, driven with Playwright + the preinstalled
Chromium. No production data of any kind was involved.

| Flow | Result |
|---|---|
| Document Library page | **16/16 checks passed.** All four operational workspaces are grouped and labelled; all four documents listed; each offers Excel/Word/PDF; the supported INPUT formats are stated; OCR's unavailability is explained in Indonesian rather than hidden; no horizontal overflow at 390px; zero JavaScript errors. |
| Downloads | Six files fetched over HTTP and checked by **magic bytes and size**, not by status code alone: XLSX/DOCX begin `PK\x03\x04`, PDFs begin `%PDF`. |
| HSE Inspection report | `GET /hse-inspections/1/pdf` returned a **883 KB `%PDF-1.7`**, one page. Re-parsed with `smalot/pdfparser` and **rasterised with `pdftoppm` and read visually** — letterhead, checklist with per-line verdicts, the unanswered line printed as N/A, the overall result, notes, CAPA and signature boxes all present. |
| Read-only inspector | A CSV uploaded through the page's own control returned the format label and the located heading row, and **wrote nothing**. |

> [!note] What this run did NOT cover
> Word and PDF *import* were exercised by the test suite against generated fixtures, not by a human
> dragging a real customer file into the dialog. Scanned-document OCR was **not** exercised at all:
> it is disabled and the binaries are absent, which the product reports rather than hides.

### Browser-verified — v2.108.0 (2026-10-11)

Run against a **real running application**: `php artisan serve`, an isolated SQLite database,
synthetic employees, driven with Playwright + the preinstalled Chromium. No production data of any
kind was involved.

| Flow | Result |
|---|---|
| Employee bulk deletion, end to end | **16/16 checks passed.** Selection bar appears only once something is selected; header checkbox selects/clears the page; the confirm button stays disabled until `HAPUS` is typed exactly; deleting two of six leaves four; the unselected four remain; zero JavaScript errors. |
| Bulk deletion, in the database afterwards | Exactly the two selected rows soft-deleted, **all six rows still present**, 3 audit lines (2 per-record + 1 summary). |
| Employee import of a malformed file | **7/7 checks passed.** The preview names the skipped row *and* the unreadable date, with row numbers matching the spreadsheet exactly (Row 3, Row 5); the summary repeats both. |
| The dates that import produced | `15/01/2026` → `2026-01-15`, `01/02/2026` → `2026-02-01`, `belum tahu` → `NULL` + warning. Before v2.108.0 the first and third were **1970-01-01** and the second was 2 January. |

> [!note] What this run did *not* cover
> MySQL (the browser run used SQLite), real data volumes, email and queue side effects, mobile
> widths, and screen-reader behaviour. It also did not exercise any module outside Employees.

---

### Browser-verified — v2.108.1 (2026-10-11)

Same method as below: `php artisan serve`, isolated SQLite, synthetic employees, Playwright +
Chromium. No production data.

| Flow | Result |
|---|---|
| Smart Import of a customer's own file (their Indonesian column names, their order) | **6/6 checks passed.** The preview reports 2 valid rows (it reported 0 imported before the fix), the mapping panel names all nine of their columns, and both rows import. |
| What that import actually stored | `Kontrak` → `pkwt`, `Harian` → `daily`, `No. KTP` → 16-digit NIK intact, `15/01/2026` → 2026-01-15, `01/02/2026` → 2026-02-01, phone and email populated from their own column names. Every one of those was wrong or empty before v2.108.0–2.108.1. |

---

### Test-covered, not browser-reviewed

| Area | Version | What was verified |
|---|---|---|
| My Work account class, entitlements, PPE overrides and PTW copy | 2.117.0 | Full 1,030 / 5,415 SQLite suite; `UserClassAndPtwQuotaTest`, `MyWorkNavigationBoundaryTest`, `SharedMasterDataOwnershipTest`; local browser verified Add User class choices, account-class/seat display, My Work navigation on two sidebars, six-month PPE save/add, and PTW examples. Deactivation UI confirmation timed out; server behavior and safe-delete/history guards are test-covered. Build succeeded; lint 0 errors, 4 existing warnings. MySQL not run |
| Hero copy and mobile background | 2.92.0 | `LandingPositioningTest` pins the four supplied paragraphs and asserts that the mobile composition uses the management photograph without the field photograph. Full suite: 742 tests / 3986 assertions; isolated production build succeeded; lint 0 errors / 4 existing warnings. No browser visual review was performed |

### Browser-verified

Exercised against **MySQL with real tenant data** (3 tenants, 68 employees, 29 departments), which
matters — several defects only appear at realistic scale.

| Area | Version | What was verified |
|---|---|---|
| Complimentary access | 2.93.0 | `ComplimentaryAccessTest` (24): a platform admin granting; a tenant's **own Super Admin refused** and a guest redirected; the resulting subscription active, complimentary, `type = subscription` (not trial, not lifetime), with a real end date and no agreed price; the reason and operator recorded; **no payment transaction, no invoice raised, and no invoice marked paid**; an unpaid invoice **voided** rather than settled, with its payment date and reference still null; a complimentary Starter tenant granted `hse` and refused `hr`, `logistics` and `management` (asserted negatively, because the entitlement service fails open for an ungranted tenant); the plan being the operator's choice rather than the one ordered; an expired grant **lapsing to read-only** past its grace window while still reading; the nightly lifecycle job raising nothing for it; exactly one tenant, company, subscription and administrator, built from the registration's own identity; a second grant **erroring instead of duplicating**; unverified and already-paid registrations refused; an arbitrary duration, a missing or one-word reason, and an inactive plan all rejected; a request attempting to dictate `billing_mode`, `type`, `ends_at` and `tenant_id` ignored on every one; the audit line carrying organization, reference, operator, plan, duration, both dates and reason; conversion to paid Professional through the **ordinary** subscription edit recreating nothing (same tenant, subscription, admin and company ids) and moving the entitlements; two grants isolated from each other; and a regression test pinning the **paying** path, which was refactored to share the provisioning body. **No migration was needed — every column already existed.** **In a browser against MySQL**: a verified registration for PT. Galangan Aliran Jaya granted complimentary Starter for 3 months through the two-step dialog, with the duration list matching the config allow-list exactly and the end date recomputing as the duration changed; the review step reading back organization, reference, administrator, plan, duration, end date, billing and reason before anything was written; the result confirmed in the database as one tenant, one company, one subscription and one administrator with `billing_mode=complimentary`, `billable=no`, null agreed price, **zero invoices and zero payments**, and workspaces `hse, reports, administration`; the action button offered **only** on the grantable row and absent from the already-provisioned ones; and the tenant administrator then signing in, landing on the HSE Overview titled with the tenant name, and receiving a **403** on `/hr/dashboard` |
| Brand icon correction | 2.90.0 | `BrandIconsTest` + `PwaInstallabilityTest`; all 741 tests / 3978 assertions pass. Browser, Apple touch and PWA PNGs have transparent corners; the maskable mark stays within the 40%-radius safe circle; the SVG favicon is a 512px tight crop of the official source. Generated icons visually inspected over light and dark surfaces. Isolated production build succeeded with the known 2,031 KB chunk advisory; lint 0 errors / 4 existing warnings. No live browser tab or Apple device pass |
| Public website continuation | 2.89.0 | Local Chromium preview confirmed the landing hero, editorial domain sequence, Tahada Group identity and sign-in entry. Full automated suite passed. Narrow viewport only; 1440px desktop, real devices, Firefox/Safari and Lighthouse were not checked |
| Account → Subscription, end to end | 2.74.0 | Against MySQL: registered through `/register`; confirmed the account carried `role=account`, null tenant/company, **`isPlatformAdmin()` false**, and that no tenant, company or subscription row was created; read the verification email out of the mail log and followed its signed link; raised an order through the subscription setup page (identity taken from the account, never re-asked); drove checkout, which honestly parked at `awaiting_payment` with a real invoice because no gateway is configured; then activated through `TenantProvisioningService` — the same entry point the verified webhook uses — and confirmed the **user count did not change**, the account was promoted to `super_admin` on the new tenant, and an active subscription with agreed pricing was created |
| Admin context, workspace boundaries, billing | 2.84.1 | `AdminContextAndBillingFlowTest` (17): the two operational header links absent inside Admin Space; exactly one context carrying the active treatment and `aria-current`; `settings` owned by administration alone in `config/departments.php` **and enforced** — a department-scoped user refused Settings, Audit Logs and Admin Space alike; no operational workspace navigation containing an administrative route; Warehouse Logistics as one workspace with one name; the support queue reporting a **missing schema** instead of 500ing, asserted by dropping the tables; the schema service proven to report and not repair; an unconfigured deployment taking no payment; Duitku refusing to half-activate without credentials; the callback signature verified against the formula Duitku documents and **rejected** for a wrong signature, a changed amount, an empty signature and an unsigned payload; result codes mapped with an unknown code settling nothing; an unsigned callback settling nothing; a verified callback settling the invoice, recording **the provider that took it**, extending the period, and not extending twice on replay; and a lapsed tenant still reaching Admin Space, billing and renewal. **Both migrations applied against MySQL.** **In a browser**: the Support 500 reproduced from its actual root cause (tables renamed away — only that page failed, exception `42S02`); the full plan matrix by direct URL on all three plans; the header lighting exactly one context in each; HSE showing 26 operational items and **zero** administrative ones; every operational surface carrying no administrative link and no retired content; and the renewal flow reaching a **real** Duitku API call with deliberately invalid credentials, so the refusal surfaced as a refusal |
| The final workspace architecture | 2.84.0 | `WorkspaceArchitectureTest` (18): IOMS selling exactly four operational workspaces; the navigation registry containing none of the seven retired ones and no locked preview at all; no plan — including the retired one — granting a department the product does not sell; the plan matrix asserted **by direct URL** for Starter, Professional and Business in both directions; the Global Company Dashboard entitled only on Business and REDIRECTING rather than refusing on the others; the company snapshot carrying no retired metric; Dashboard, the four Overviews and Management proven to be five distinct pages; Admin Space open on the entry tier and closed to the operator console; Admin Space navigation proven not to be merged with reporting; All Workspaces exposing only authorized operational workspaces; a focus changing context while changing neither what is authorized nor what is reachable; Admin Space rejected as a focus; every sold workspace having a real Overview route; and workspace access proven tenant-scoped. **Migration applied, rolled back and re-applied against MySQL.** **In a browser**: all three plans signed in as real accounts — Starter reaching HSE only with no Dashboard link in the header or the mobile bar, Professional reaching HSE and People with Logistics and Management refused, Business reaching all four plus the company dashboard; each workspace rendering its own rail and header chip; Admin Space rendering administration only; Master Admin Support exercised through create, reply, status and association; and 375px mobile |
| Management workspace, Admin Space, workspace focus | 2.83.0 | `ManagementAndAdminSpaceTest` (24): Business granting `management` while Starter and Professional are refused it **by direct URL on every one of the six routes**; a Manager admitted and an HSE supervisor refused on the same Business tenant; the platform operator granted no tenant capability at all; aggregates read from real Employee / Incident / Department records and proven not to cross tenants (two tenants, different data, each reading only its own); an empty tenant reporting `available: false` and a NULL days-since-incident rather than a confident zero, with all six pages still rendering; Admin Space open to the tenant administrator on **every** plan and refused to HSE, HRD, Manager and Warehouse by direct URL; HSE keeping Settings while being refused the administrator Overview; Admin Space capacity figures asserted **equal to `stateSnapshot()`**; a focus chosen, proven not to change role, assignment or grants, and the account still reaching every other workspace; a focus refused for an unauthorized workspace; a stale focus after a downgrade degrading to All Workspaces with the preference kept. **Migration applied, rolled back and re-applied against MySQL**, including the backfill for existing Business tenants. **In a browser**: all six Management pages against seeded MySQL data, Admin Space showing real capacity, security posture and audit trail, the focus set from the Account page and the top bar collapsing to a static label, and 375px mobile |
| Pricing, included users and add-ons | 2.82.0 | `UserEntitlementAndAddOnTest` (28): every approved price and allowance per tier; the public catalogue being exactly the three sold tiers with Enterprise surviving as a row; one add-on price across all plans; the annual price being exactly N monthly payments and the service period matching (12/12, 11/12, 12/14); an annual renewal extending by the plan's **own** service months; the allowance enforced **server-side** on the user-create route; a deactivated account freeing its slot; an employee record and repeated sign-ins counting for nothing; purchased capacity raising the limit and charging monthly; annual add-ons billed for the months PAID; a renewal invoice carrying plan **and** add-on; a customer unable to set their own price or bypass authorization; capacity not releasable below active usage; a downgrade refused rather than deactivating anybody; purchased capacity surviving a plan change; customer and operator reading identical capacity; an existing subscription not repriced; a paid invoice not rewritten; and all four lifecycle states unchanged. Plus `PricingConsistencyTest` rewritten for the three distinct annual offers. **Migration applied, rolled back and re-applied against MySQL.** **In a browser**: pricing page copy for all three tiers, capacity purchased end to end (1/3 → 1/5, Rp100.000/month quoted before confirming), renewal amount Rp189.000 → Rp289.000, Master Admin showing the same figures |
| Invoice document | 2.81.0 | `InvoiceDocumentTest` (12): the brand stated once and as type, the mark used WITHOUT the wordmark, the shared tenant letterhead no longer rendered (asserted on its elements, not its class names — the stylesheet still defines them for every other document), both mark dimensions declared so dompdf cannot draw it oval, the mark file free of any rect or `#f7fafc`, every invoice fact still present, a paid invoice showing method and reference, an unpaid one never claiming settlement, no issuer block without a registered identity and one as soon as there is, still no tax line, and **the PDF actually rendering** through dompdf. **In a browser**: both states rendered from real invoices against MySQL and read at A4 measure |
| Canonical host redirect | 2.81.0 | `CanonicalHostRedirectTest` (10): 301 for GET and HEAD with path and query preserved; **a payment webhook POSTed to the www host is never redirected** and reaches signature verification instead; no unsafe method 301s; the canonical host served directly; an unrelated host (the legacy domain) left alone and still noindex; nothing redirected outside production; the whole thing switchable by config; and every page still declaring the canonical origin. **Not verified in production** — the hosting-level half is a manual step |
| Brand mark | 2.81.0 | Shipped `ioms-icon.svg` compared path-by-path against the designer master: six paths byte-identical, no rect, no `#f7fafc`. **In a browser** at desktop and 375px: navbar lockup 123×32, rendered ratio 3.853 vs the artwork's own 3.846 (not cropped, not stretched), no horizontal overflow. Canonical, OG, Twitter, structured-data logo, favicon set and manifest read from the live page — all on the canonical origin and the current assets. **Google's index is NOT verified and cannot be** from here; a stale logo in results is a crawl state |
| Subscription state parity, customer vs Master Admin | 2.80.0 | `SubscriptionStateParityTest` (10): the two **rendered payloads** compared key by key across active, grace, lapsed, suspended, cancelled and renewed; the grace boundary asserted at day 7 and day 8; an early renewal extending from the existing period end rather than from today; a long lapse deleting nothing. **In a browser against MySQL**: one tenant three days past its period end read *Masa tenggang · read-only mulai 1 Okt 2026* in Master Admin and *Grace · READ-ONLY FROM October 1, 2026* on the customer's own Billing page, the customer copy stating the 7-day window it reads from configuration |
| Billing modes (paid / manual / complimentary) | 2.80.0 | `BillingModeTest` (12): the default preserving pre-existing behaviour; an unrecognised value falling back to BILLABLE; a complimentary subscription never invoiced **with a same-dates billable control** proving the job ran; complimentary excluded from the work queue; the composition counted; and the two assertions that matter most — complimentary does **not** extend access past a period end, and does not withhold it inside one. Operator-only: a tenant administrator setting their own billing mode is refused. **Browser**: the composition card rendered 2 paid / 0 manual / 1 complimentary against MySQL |
| Support queue | 2.80.0 | `SupportTicketQueueTest` (16): identification by exact email and **not** by shared mail domain; manual association and re-identification; a support reply emailed from `support@` with the reference in the subject; a customer message reopening a resolved ticket onto the SAME thread; a closed conversation starting a new one; the default queue excluding tickets waiting on the customer; longest-waiting first ahead of a newer urgent one; age stopping when answered; the shared subscription snapshot in the ticket context; nothing written to the notification table; a tenant administrator refused the queue AND a single ticket; only a platform operator assignable; an unsent reply staying visibly unsent. **In a browser against MySQL**: a real reply sent, logged from `IOMS Support <support@iomsuite.com>` with `[TKT-000001]` in the subject, the ticket moving to *Menunggu pelanggan* |
| Payment provider abstraction | 2.80.0 | `PaymentProviderAbstractionTest` (6): no domain file names any provider (comments excluded); the shared verified-payment path resolving invoices through the contract; no controller branching on a concrete gateway class; both adapters implementing the whole contract including the three v2.80.0 calls; the null adapter answering rather than throwing for the three pre-payment questions while still refusing to fake a checkout. **Not verified against iPaymu** — no adapter exists, deliberately (BLOCKED) |
| Master Admin, whole console in Bahasa Indonesia | 2.80.0 | `LanguageHierarchyTest` (the JSX scan now excludes `/platform` per ADR 040, matching the rendered-props half that already did). **Browser**: every console page read in Indonesian against MySQL — Ringkasan, Tenant, tenant detail, Pembayaran, Dukungan, ticket detail |
| Master Admin operations + platform notifications | 2.79.0 | `PlatformOperationsTest` (8): lifecycle counts derived from dates not stored status, including a lapsed subscription that still stores `active`; the work queue ordered soonest-first and excluding healthy tenants; payment sums across invoices with a provider breakdown read from data; the demo tenant excluded from workload; the dashboard payload served to an operator; a verified renewal notifying platform admins **and not the tenant's own users**; grace and lapse each announced once; a failed payment notifying without changing subscription state; a tenant administrator refused the console. **In the browser against MySQL** as a temporary platform operator (deleted afterwards): the Indonesian operations section rendered real figures (3 active, Rp9.990.000 received, Rp799.000 outstanding) and a platform notification. **Responsive: partially verified** — the pane reported its own width (~720px, the tablet band) with no horizontal overflow; viewport emulation did not apply this session, so 375px and 1280px were **not** measured |
| Branding consumed everywhere | 2.78.2 | `BrandIconsTest` (head + manifest icons resolve to `config/branding.php` and the files exist; no component names a brand file directly; the config declares each asset once; the worker revalidates brand assets). **In the browser against the running app**: public home, login, authenticated desktop and mobile all drew `/branding/ioms-logo(-dark).svg` from the canonical source, tab icon `/favicon.ico`, manifest icons the four canonical PNGs, every asset 200 with the right content type, no horizontal overflow at 375px. **The service-worker fix was reproduced end to end**: with the worker active, changing a brand file on disk served the cached copy once (4122 bytes), then the refreshed bytes (4148) on the next request, and the file was restored byte-for-byte. Local DB holds no tenant wordmark override. **Not verified:** production, which needs a deploy |
| Payment-provider review readiness | 2.78.1 | `ReviewerJourneyTest`: new customer register → verify → setup → order → checkout stops at `awaiting_payment` with nothing provisioned; reviewer tenant created through the real `platform.tenants.store` form; its administrator is the ordinary per-organization role (`isPlatformAdmin()` false, `/platform` refused, another customer's invoice 404); renewal and upgrade reach an invoice and change nothing unpaid; plan-change preview equals the issued invoice. **In the browser against MySQL** with a temporary operator and reviewer tenant (both deleted afterwards): reviewer landed on Dashboard, `/platform` 403, HR module correctly refused on Starter, Billing → Renew now raised a next-period invoice, Plans dialog showed Upgrade Rp500.000 / Upgrade + yearly-from-date / Scheduled change with no charge, and a Business upgrade raised a Rp1.200.000 prorated invoice with the plan unchanged. **Not exercised:** the Midtrans payment window itself (no gateway configured locally) |
| Lifecycle emails | 2.78.0 | `SubscriptionLifecycleEmailTest`: nothing for an active subscription; grace emailed once across three nightly runs, to Super Admins only; lapse emailed once; a months-old lapse not announced; grace → lapse sends exactly one of each; a verified renewal sends one renewed email even when the webhook is replayed, and says recording is back; a forged notification sends nothing; a suspended subscription is not told it was renewed; each email states the state, the dates and one action linking to Billing, with the noreply/IOMS sender and the canonical logo; the renewal invoice no longer uses onboarding copy. Migration applied to MySQL; `subscriptions:lifecycle --dry-run` ran against real data. All three emails rendered and viewed in the browser. **Not verified:** delivery to a real inbox |
| Man-Hour calculation | 2.77.0 | `ManHourTest` (calculation, whole-period total across pages, inclusive bounds, empty period, project breakdown sums to total, 24h cap, replace-same-day, reversed period, tenant isolation). **Against MySQL in the browser** with a throwaway tenant (35 people, 45 records over two table pages): page showed exactly the expected 365.0 total / 35 headcount / 10.0 OT / 355.0 regular / 2 work days, breakdown Hull Block 12 170.0 + No Project 195.0. Mobile 375px: no overflow. Throwaway tenant deleted afterwards |
| Subscription lifecycle UX & read-only | 2.77.0 | `SubscriptionReadOnlyEnforcementTest`: every write route refused while lapsed with all table counts unchanged; real-record create/edit/delete refused; reads intact; own password change allowed; full renew → signed webhook → same subscription extended → writes restored, no duplicate tenant/company/subscription/account. **Against MySQL in the browser**: expiring, grace and lapsed banners and Billing notices with correct dates and a single Renew action; a real POST refused 403 while lapsed while reads stayed 200; Renew from lapsed raised a real invoice on the same tenant (bank-transfer copy, as no gateway is configured locally); settling it through `applyPaidInvoice()` extended the same row and a write then succeeded, with entity counts identical. Billing at 375px: no overflow, Renew on the first screen. **Not verified:** a live Midtrans payment (no production credentials here) |
| Landing positioning & brand icons | 2.76.0 | `LandingPositioningTest` + `BrandIconsTest`. In the browser after hydration: title *IOMS — Industrial Operations Platform*, exactly one H1 (*One platform for complex industrial operations.*), exactly **one** meta description and one `og:title` (the duplicates are gone), canonical https://iomsuite.com/, JSON-LD Organization + WebSite + SoftwareApplication, icon links /favicon.ico, 48px, SVG, 32px, apple-touch and manifest, `/favicon.ico` served as `image/vnd.microsoft.icon`, eight story `<h3>`s with working internal links. Layout measured rather than eyeballed: at 1440px the rows alternate visual-left/visual-right, and at 375px every row stacks visual-above-text; no horizontal overflow at either width. **Not verified:** how Google renders the result or favicon; that depends on deployment and crawling |
| Email logo | 2.75.0 | Rendered the real verification email with a **development** `APP_URL`: the image is `https://iomsuite.com/branding/ioms-logo-email.png` regardless, the verification link is untouched, alt text and descriptor remain. Viewed in a browser: identical design; with the header background stripped (the webmail failure) the logo still reads as a navy tile instead of an empty box. Asset served 200 `image/png`, opaque, background #0f2747. Sender still `noreply@iomsuite.com` / `IOMS` (EmailIdentityTest). **Not** verified in a real inbox — the PNG reaches production only when deployed |
| Public search identity | 2.75.0 | `PublicSearchIdentityTest` drives https://iomsuite.com in the production environment: robots.txt, a well-formed sitemap of exactly the ten public pages at canonical URLs (even when served from ioms.web.id), unique title/description/one canonical/OG/Twitter per page, valid JSON-LD with every URL on iomsuite.com, `lang=id` on legal pages, noindex on login/register/get-started/account/subscribe/dashboard/sandbox, noindex on the legacy host and in non-production. In the browser: tab titles correct through client-side navigation, local robots.txt `Disallow: /`. **Google Search Console not configured** — manual steps in ADR 039 |
| Get Started = account registration | 2.74.2 | **Browser-exercised against MySQL, starting from a plan card**: `/get-started?plan=professional&cycle=monthly` rendered ONLY the account form — full name, "Your email", password, confirm, consent — with no company field, no plan card, no price and no payment anywhere on the page; registering landed on the `register.welcome` fork; "Continue setup" opened subscription setup with **Professional and Monthly already selected**, proving the plan chosen before the account existed survived registration through the session; the account section stated the name and a Confirmed email with **zero password inputs**; submitting produced one order at Rp799.000 on the existing `register.status` page. Also confirmed: a signed-in org-less account opening `/get-started` is sent to `/account` (not `/dashboard`), `/dashboard` and `/incidents` both end at `/account`, and "Choose a plan" reopens the same setup page. Test data removed afterwards |
| One setup form, two entry points | 2.74.0 | **Browser-exercised against MySQL**: registered at `/register` (label now reads "Your email"), landed on the `register.welcome` fork rather than in either branch; confirmed `/subscribe` bounces an unverified account to `/account`; verified the address and reopened `/subscribe` — it rendered the **existing** Get Started page with its own hero, plan cards, billing toggle and company fields intact, with "Your account" **stating** the name and a Confirmed email and **no password fields**; submitted and landed on the existing `register.status` order page showing the account's own identity; confirmed in the database that exactly ONE order was created, carrying `contact_name`/`contact_email` from the account, `password = NULL`, `company_country` defaulted, and that **no tenant, company or subscription row** appeared; reopened `/subscribe` and confirmed the open order's plan and cycle were pre-selected rather than reset; confirmed Account → "Choose a plan" points at `/subscribe` and "Resume" at the existing order page. Test data removed afterwards |
| Unsubscribed access boundary | 2.74.0 | **Measured**: every one of `/dashboard`, `/incidents`, `/investigations`, `/permits-to-work`, `/employees`, `/settings`, `/my-work`, `/work-center`, `/subscription/billing` redirected to `/account`; `/platform` 404'd. No tenant data reachable |
| Incident → Investigation → CAPA | 2.73.0 | End to end against MySQL: a 5W1H initial report filed through the real endpoint (injury, treatment, facility, chronology, witnesses, claim reference all persisted; an empty witness row correctly dropped); an investigation opened from it, which moved the incident to `investigating`; the analysis, an interview and a corrective action saved; the workflow driven draft → in progress → under review → completed → closed, with an **illegal jump back to draft correctly refused** by the state machine; reviewer and closer stamps written server-side; closing the investigation closed the incident; the full audit trail read back |
| Record chain | 2.73.0 | Rendered from BOTH ends and confirmed to agree — the incident shows INC (current) → INV → CAPA, the investigation shows the same chain looking back |
| PTW required vs confirmed PPE | 2.73.0 | A permit raised with three required PPE types from the existing master plus one free-text item and two hazards; approved confirming only two, and the **Harness correctly surfaced as unconfirmed** on the Show page, the document view and the generated PDF alike |
| PWA installability | 2.73.0 | Manifest served and valid (name, short_name, standalone, start_url, 192+512 icons in both `any` and `maskable`); service worker registered and active at origin scope |
| **PWA cache safety** | 2.73.0 | **Measured**: after signing in and visiting /dashboard, /incidents, /investigations, a permit, /settings and downloading a PDF, the cache held **3 entries — 2 build assets and 1 brand asset, zero authenticated responses**. This is the assertion ADR 037 exists for |
| PDF weight | 2.73.0 | **Measured**: the PPE tick/box glyphs embedded a DejaVu subset and took the permit PDF from 8 KB to 886 KB. Replaced with CSS-drawn marks; back to 9 KB |
| Migrations | 2.73.0 | All four rolled back and re-applied against **MySQL**, not just the suite — which is how the over-length index name and an undroppable FK-backing index were both found |
| PTW approval stamp | 2.72.0 | End to end against MySQL: a permit raised through the real `store()` route showed **no** stamp while submitted; approving it as the HSE Officer through the real transition endpoint produced exactly one seal carrying the approver, their role and the moment, on the Show page, the Document view and the generated PDF alike. A seeded permit with status `approved` but no `hse_approver_id` correctly stamps nothing |
| Screen-vs-PDF agreement | 2.72.0 | **Measured**: stamp reads `17 Sep 2026 15:09` in both renderers, and the validity window `18 Sep 15:00 / 19 Sep 00:00` in both. Before the fix the screen said 16:09 — see the `display_timezone` pitfall in `CONVENTIONS.md` |
| Brand rollout | 2.72.0 | The official lockup renders and loads on every surface: login (dark variant on navy), the app rail (dark, 123x32), the public header and footer (light, on white and graphite-50), the About dialog (**both**, switched by the `dark` class). Favicon, apple-touch-icon, canonical, og:image and Organization JSON-LD read back correctly from the live `<head>`; `robots.txt` and `sitemap.xml` served with 11 correct absolute URLs |
| Operational form identity | 2.72.0 | `FormDocumentHeader` rendered on PTW (`PTW-2026-00001`, Draft, Requester, Permit Type, workflow line) and on Material Request (`MR-2026-00001`, Draft, Requested By, Items). `/ppe/master` confirmed to carry **no** document header — the master/operational distinction holds |
| Field & PTW Access | 2.72.0 | The PTW flow block and the per-account outcome sentence render; toggling My Work for an account left its PTW Access untouched in the database (`field=1 ptw=0`), which is the independence claim, exercised rather than asserted. Test state reverted afterwards |
| Responsive | 2.72.0 | **Measured** `scrollWidth` vs `clientWidth` at 375 / 768 / 1280 on the PTW form, PTW Show, PTW Document, Material Request form, Settings and the public home page: **no horizontal overflow anywhere**. The About dialog was found overflowing 406px inside a 334px panel at 375 and fixed |
| Capability reach | 2.71.0 | Material Request opened from the HSE rail without the sidebar jumping to Logistics; the HSE Administration row rendered on the HSE Overview; the My Work toggle, which previously 403'd for HSE, exercised end to end as the HSE Officer |
| Sidebar scroll memory | 2.71.0 | Offset restored exactly (240 -> 240) across a real navigation where it previously reset to 0; clamped to the maximum when the stored value exceeded a shorter list; an HSE offset did not leak into the global menu |
| Sidebar hierarchy | 2.71.0 | **Measured** from the compiled stylesheet: level-1 rows and group headers both 13px/500/navy-300, children 13px/400/navy-400, active 600/white; exactly one entry divider, after Overview |
| Master vs operational | 2.71.0 | The Master Data chip and its consequence sentence appear on switching to a reference-data tab and disappear on a register tab; chip contrast measured at 11.3:1; at 375px the tab strip scrolls inside its own wrapper and the page does not |
| Material Request lifecycle | 2.71.0 | The stage sentence rendered for submitted, consolidating and completed -- the last of which previously showed no Progress panel at all; the consolidating tone measured as steel, not a warning |
| Subscription lifecycle | 2.70.0 | Active, grace and lapsed states rendered and read; a renewal invoice raised at the customer's grandfathered price for the correct next period; a write refused with the real explanation while every read still worked; a downgrade scheduled rather than applied; no horizontal overflow at 375px |
| Demand consolidation | 2.69.0 | Hold with reason, consolidating two requests into one purchase, the automatic move to Processing, and the link back from the request — end to end |
| Material Request aging | 2.69.0 | Outstanding filter, aging emphasis, no overflow at 375px |
| Employee Cases | 2.69.0 | Case list, case record, derived standing, issued SP with validity window |
| Migrations | 2.69.0 | Rolled back and re-applied to confirm they reverse cleanly |
| Language hierarchy | 2.68.0 | Department Overviews and public pages, plus zero truncated labels at 375 / 430 / 768 / 1024 / 1440 |
| Error page | 2.68.0 | Full-page 404 renders the in-product page; framework messages suppressed |
| Dashboard charts and KPI labels | 2.68.0 | Zero truncation; ranked bars replace the 14-slice pie |
| Application shell layout | 2.67.0 | Overflow **measured** at 13 widths from 320 to 1920, before and after, against the real compiled stylesheet |

### Reasoned or contract-tested only — **not** interaction-verified

Stated plainly because the distinction matters:

| Area | Why it is not browser-verified |
|---|---|
| **Shell keyboard and screen-reader behaviour** (focus trap, `inert`, skip link, accessible names, `aria-current`) | Covered by contract tests over the shipped source and by review. Signing in could not be automated at the time, and the shell is React that never runs server-side — an Inertia response is a prop bag containing none of that markup. `FormExperienceSystemTest` records the same limit |
| **MySQL-specific concurrency** | See the SQLite note above |
| **Email and queue side effects** | Not exercised by the suite |
| **Live payment flow** | Requires external provider configuration — see [[Known Issues and Limitations]] |
| **Google OAuth (v2.74.0)** | Architecturally complete and **never executed**. No `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` exists in this environment, so the consent screen, the `state` check, the token exchange and the three linking branches have not been exercised against Google. What IS verified: the button is hidden and both routes 404 when unconfigured, and the linking logic's inputs (`sub`, `email_verified`) are read only from Socialite's server-to-server response. See `docs/ADR/038` for the exact configuration a deployment needs |
| **A real Midtrans notification** | Every webhook test signs its own payload with a test server key, so what is verified is that IOMS honours the contract *as documented*. Their actual signature over a live transaction, their full `transaction_status` vocabulary, and their retry timing have never been observed. This is the gap a sandbox transaction against real credentials would close, and it is the one remaining item in the payment path that carries genuine unknown risk — [[Requirements Register]] |
| **The scheduled run in production** | `subscriptions:lifecycle` is test-covered and was exercised by hand, but no deployment has yet run it from cron over a real period boundary |
| **The sidebar scroll WRITE, by a human hand** (v2.71.0) | The restore path was exercised fully (exact restore, clamping, per-department isolation). The write could not be driven by script: the embedded verification browser does not dispatch `scroll` for a programmatic `scrollTop` assignment while the window is not painting — a listener attached by hand in the console never fired either, so this is the tool, not the product. The handler is three lines, attached to the element the restore reads from, and pinned by a test that forbids the rAF regression that actually broke it |

---

## How to verify

The loop, from `LOCAL-VERIFICATION.md`:

```
code → tests → build → browser → responsive check → report
```

1. `php vendor/bin/phpunit` (PHP lives under Laragon — see [[Known Issues and Limitations]])
2. `npm run build` — **always**, or the browser serves a stale bundle and you "verify" code you did
   not change
3. Run the app; seed **realistic** data, including deliberate duplicate names — real industrial
   workforces are full of them, and they have exposed genuine defects
4. Exercise the behaviour, watch the network panel and the console
5. **Measure** responsive overflow at 320 / 375 / 390 / 430 — screenshots hide it
6. Report the boundary honestly: say what you ran and what you did not

---

See also: [[Known Issues and Limitations]] · [[Working with This Knowledge Base]] · [[Security Decisions and Lessons]]

---

## v2.85.0, 2026-09-30: the public website, verified by running it

The whole release was verified by execution rather than by reading, which is worth recording because
of what only execution found.

**Run:** full suite 692 passing / 3813 assertions with zero failures; `npm run build` clean; ESLint
zero errors; one data migration applied, rolled back and re-applied against MySQL; every public route
requested and checked for status and console errors; both 1440x900 and 375x812 exercised in a real
browser; rendered prices read off the page and compared to `tests/Support/ApprovedCatalogue.php`.

**What only running it found**

The em dash rule was being broken in the `packages` table, and a source-file search could not see it.
Plan descriptions are database rows rendered onto every pricing card, so they are website copy that
lives outside every file a copy review reads.

Worse, the **first** runtime sweep also missed them and reported a clean result. It searched the
rendered HTML for the literal character, and Inertia serializes props as JSON, where the character
appears as an escape sequence. The check was wrong in a way that produced a confident pass.

> [!important] A verification method can fail silently too
> "Verified by running it" is not automatically true. The first sweep ran against the real server,
> against real responses, and was still wrong, because it looked for one encoding of the thing it was
> searching for. The corrected check searches both forms and is pinned by a test that asserts against
> the rendered response rather than against source files.

**A test was holding a defect in place.** `LandingPositioningTest` asserted the hero names "projects"
and "procurement". It passed, because the hero did, through the entire release that retired both
departments. A green suite is evidence that the code matches the tests, which is only useful while
the tests still match the product.

**Payload, which no amount of reading would have surfaced as urgent.** Every public page carried
about 253 KB of internal changelog and the full workspace table. Visible only by measuring an actual
response.

**Not verified, and stated rather than implied:** no live payment was executed, no subscription
crossed a real period boundary, the authenticated UI was not re-reviewed visually, only Chromium was
tested, and mobile was viewport emulation rather than hardware.
