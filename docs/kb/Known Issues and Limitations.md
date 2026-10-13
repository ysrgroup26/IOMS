---
title: Known Issues and Limitations
type: register
updated: 2026-10-11
tags: [kb/issues]
---

# Known Issues and Limitations

What is currently wrong, bounded, or stale — stated plainly so nobody rediscovers it the hard way or
trusts a document further than it deserves.

Nothing here is a secret defect: each item is either accepted, tracked in [[Requirements Register]],
or waiting on something external.

---

## Documentation that is stale or partial

> [!warning] These are the highest-risk items in this list
> A stale document that *looks* current is worse than a missing one. Each entry says exactly which
> part to distrust.

| Document | State | What to do |
|---|---|---|
| **`CHANGELOG.md`** | **Stops at v2.0.0.** 71 releases exist only in `config/ioms.php`'s `version_history`. It is still authoritative for **v1.1.0 – v1.5.4**, which predate that array | Use [[Release History]], which indexes both |
| **`README.md`** | **Mixed.** Deployment, domain (v2.56.0) and mail (v2.57.0) sections are current. The **ERD and Roles sections are historical** — the ERD does not mention `tenants`, `packages`, `vendors`, `purchase_requisitions` or `employee_cases`, so it predates Milestone 2, Milestone 4 and v2.69.0 | Trust the install/deploy sections; for data relationships read the migrations and [[Data Ownership and Boundaries]] |
| **`ROADMAP.md`** | **Mixed.** Guiding principles are current and authoritative. The "Delivered" sections are historical, and several "Near-term" items have **already shipped** | Read [[Requirements Register]] first — it records the resolution |
| **`UX_ARCHITECTURE_DISCOVERY.md`** §22.5 | Item 5 names v2.68.0 as the latest release; v2.69.0 has since shipped | Cosmetic; the substance holds |

## Data quality

| Issue | Detail |
|---|---|
| **Release dates are unreliable** | **16 of 82** entries in `version_history` are dated *after* the current release date (2026-09-14), and there are **2 non-monotonic pairs** (v2.12.0 → v2.13.0, and v2.64.0 → v2.65.0 which goes backwards by 20 days). Version *ordering* is correct; the dates are not. A release-date test is `#status/planned` in [[Requirements Register]] |
| **ADR numbering has gaps and a collision** | 002, 003 and 005 do not exist, and **029 is used twice**. Recorded in [[Decision Register]]; next free number is 035 |

## Product limitations

| Limitation | Detail |
|---|---|
| **Finance workspace is a placeholder** | Registered in navigation, routes to a Coming Soon page. No capability behind it |
| **HR placeholder items** | Recruitment, Performance, HR KPI, Documents and Reports are `disabled: true` navigation entries |
| **Live payments need external configuration** | The integration is built and the activation boundary is pinned by tests, but going live requires provider credentials and configuration outside this repository. IOMS bills invoice-per-cycle; there is no automatic card charging, and Midtrans Subscription/Recurring would be a separate integration rather than a flag |
| **Renewal automation needs a cron entry** | `subscriptions:lifecycle` must be reached by `php artisan schedule:run` for renewal invoices and reminders to be issued. It does NOT gate access -- subscription standing is derived from the dates on every read -- so a stopped scheduler delays billing without locking anyone out. ADR [[033-subscription-lifecycle|033]] |
| **Settings is a 2,500-line monolith** | 17 tabs in one file. Editing it carries risk disproportionate to the change. Decomposition is `#status/deferred` |
| **Workspaces and route-prefix departments are synced by hand** | `workspaces.js` and `config/departments.php` describe overlapping things in two places. Adding a route prefix means touching both — a recorded pitfall. This is the thing most likely to break as the workspace count grows |
| **Field-level audit does not exist** | `ActivityLog` records a free-text description, not structured before/after values per field |
| **No generic attachment mechanism** | Each module has its own table (`VendorDocument`, `DailyReportPhoto`, …) |

## Frontend

| Issue | Detail |
|---|---|
| **Single ~1.8 MB JS bundle** (~440 KB gzipped) | Vite warns on every build. Accepted for now — `#status/deferred` |
| **Two stat labels clip at 320px** | Where a single word (`OBSERVATIONS`) is wider than its column in a 2-up grid. **Clean from 375px up.** Recorded rather than fixed with a font-size special case |

## Verification gaps

See [[Verification Status]] for the full picture. The headline: **the v2.67.0 shell accessibility
work is contract-tested and reviewed, not interaction-verified** — signing in could not be automated
at the time, and the shell is React that never runs server-side, so an Inertia response is a prop bag
containing none of that markup.

---

## Findings recorded but NOT acted on (v2.108.1 MySQL verification)

Found by running the suite against a real MySQL 8.0 for the first time. **Not changed here** — the
first is in a module this task had no business touching, the other two are accepted behaviour worth
writing down.

| ID | Finding | Severity | Why it was left |
|---|---|---|---|
| QA-007 | ~~`/ptw/quota` returned 500 on MySQL~~ — **FIXED in v2.108.2.** `PtwQuotaController::show()` eager-loaded `permit:id,ptw_number,title`; `permits_to_work` has no `title` (the field is `work_description`), and nothing read it. The column was dropped from the select list. Reproduced on MySQL 8 before the fix and re-verified after; the regression test now reads the row back out of the page props, so the select list has to be one MySQL will actually serve. | ~~High~~ Fixed | — |
| QA-008 | ~~A soft-deleted employee's **PPE issue records become invisible**~~ — **RESOLVED in v2.115.0, by the product decision rather than by a scope change.** `EmployeePpe` reaches its owner via `whereHas('employee')`, which stopped matching once the employee was trashed: the rows were in the table and unreachable through the PPE module, which is the worst of the available outcomes. Employee deletion is now permanent (ADR 055), so the rows go with the person and no unreachable row is left. `EmployeeBulkDeletionDependenciesTest` now asserts the absence of orphans across every dependent table instead. | ~~Low~~ Resolved | — |
| QA-009 | Competency certificates uploaded **before v2.108.1** are still on the public disk. New ones are private. | Low | Moving existing files is an irreversible bulk operation on production data — the same reasoning `SecureDocumentController` already records for the wider migration (SEC-013). They stream correctly either way. |

---

## Findings recorded but NOT acted on (v2.115.0 permanent deletion)

| ID | Finding | Severity | Why it was left |
|---|---|---|---|
| QA-010 | **`2026_10_05_100290_complete_subscription_lifecycle::down()` cannot run on SQLite.** It drops `invoices.purpose`, which `up()` created with `->index()`, without dropping that index first; SQLite then refuses with `error in index invoices_purpose_index after drop column`. Observed while a test was briefly using `DatabaseMigrations`, whose rollback walks every `down()`. `up()` is unaffected, so no deploy and no fresh install is touched — only a `migrate:rollback` that reaches this far back, and `migrate:fresh` (which drops tables rather than rolling back) is fine too. | Low | Out of scope: it is in the subscription module, nothing in this task touches it, and fixing a rollback path blind is how the v2.105.0 half-applied-index problem happened. The fix is a `dropIndex` before the `dropColumn`, guarded on `Schema::hasIndex`. Recorded here so the next person does not spend the same hour finding it. |

---

## Findings recorded but NOT acted on (v2.108.0 inspection)

Found while reading the Employees module and its neighbours during the v2.108.0 work. **Nothing
below was changed**, deliberately: each sits in a module that work had no business touching, and
two of them are decisions for the product owner rather than fixes. Recorded here so they are not
rediscovered as news.

| ID | Finding | Severity | Why it was left |
|---|---|---|---|
| SEC-010 | ~~`/settings/backup` and `/settings/restore` were reachable by a tenant `super_admin`~~ — **FIXED in v2.108.2.** Both routes and their handlers were removed from the customer-facing Settings surface entirely and moved to `/platform/database` behind `role:platform_admin` (`PlatformDatabaseController`). A tenant administrator of any role now receives 403, and the dump is never invoked — asserted against a recording fake, not just by status code. Restore additionally requires a typed confirmation word, the target database named by hand, and a file that reads as a mysqldump. Credentials moved off the command line into a 0600 defaults file. See `PlatformDatabaseAccessTest` (17 tests, including a negative control proving they detect the old shape). | ~~High~~ Fixed | — |
| SEC-011 | ~~The DB password was passed to `mysqldump`/`mysql` on the command line~~ — **FIXED in v2.108.2** alongside SEC-010. `DatabaseBackupService` writes a 0600 `--defaults-extra-file` for the life of one command and removes it in a `finally`, so the password never appears in the host's process list. | ~~Medium~~ Fixed | — |
| SEC-012 | Branding accepts `svg` for `logo`, `favicon`, `wordmark` and `brand_icon` and stores it on the **public** disk, served from the app's own origin. An SVG carrying `<script>` executes there. No sanitisation step was found. | Medium | Settings/branding module. Fix is to drop `svg`, sanitise on upload, or serve branding with `Content-Disposition: attachment`. |
| SEC-013 | Most operational uploads still go to the **public** disk (`uploads/contractor-documents`, `uploads/competency`, `uploads/controlled-documents`, `uploads/inspection-evidence`, …) although the `private` disk exists for exactly them. Only `regulations` uses it. | High | **Already known and deliberately deferred** — `SecureDocumentController`'s own doc comment explains why (the file migration is irreversible on production data). The UI already routes through `secureDocumentUrl()`, so exposure needs a URL leak. Unchanged here. |
| QA-004 | `AnalyticsService` interpolates `{$def['group_by']}` and `{$def['date_field']}` into `selectRaw()`. Not injectable today — the keys resolve from `config/analytics.php` and `definition()` aborts on an unknown key — but it becomes one the moment a dataset definition is made dynamic. `DATE_FORMAT()` is also MySQL-only, so `trend()` cannot run on the test connection. | Low | Analytics module; no defect to fix today, only a shape worth not copying. |
| QA-005 | There is **no CI**: no `.github/workflows`. 1100 tests encoding tenant-isolation and authorization invariants are run only when a person remembers to. | Medium | Infrastructure decision. The fresh-clone blockers that would have stopped CI from working at all *were* fixed in v2.108.0 (see [[Verification Status]]), so adding a workflow is now a small change. |
| QA-006 | `CLAUDE.md` states "Current version: 2.71.0 Beta"; `config/ioms.php` says 2.108.0. [[Current State]] says 2.94.0. | Low | Documentation drift across three places; not touched because a correct fix is a re-measure sweep, not a number edit. |

---

## Environment notes for whoever runs this next

Not defects, but they cost time if unknown:

- **PHP is not on `PATH`.** It lives under Laragon; prepend
  `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64` before any `php`/`artisan`/`phpunit` command.
- **MySQL is not running by default** and has no registered service. Start `mysqld.exe` with
  `--datadir=C:/laragon/data/mysql-8.4` (note: **not** `C:\laragon\data\mysql`).
- **A stale `public/hot`** left by a crashed Vite dev server makes every asset URL point at a dead
  server and the app renders blank. Delete it.
- **Built assets are committed** — production has no npm. Always rebuild before verifying, or you
  will verify a stale bundle. ADR [[028-remove-nodejs-from-production|028]].

Full procedure: `LOCAL-VERIFICATION.md`.

---

See also: [[Requirements Register]] · [[Verification Status]] · [[Release History]]

---

## Public website, opened v2.85.0

| ID | Issue | Severity | Status |
|---|---|---|---|
| QA-002 | The Ziggy route manifest ships every internal route name, including the retired `procurement.*` routes, into the page source of public pages. Not visible copy; it is route metadata. | Low | Open, deliberately deferred. Filtering Ziggy changes the `route()` helper site-wide and a wrong allow-list breaks navigation silently rather than loudly, so it warrants its own change with its own verification. |
| QA-003 | `config/ioms.php` sets `edition` to "Enterprise Edition" and `license` to "Commercial Enterprise License" while `enterprise` is also a retired plan slug. The strings name the build, not the plan, and are serialized in props but never rendered. | Low | Open, owner decision. Renaming a product edition is product direction, not a redesign call. |

Full detail: `docs/IOMS Website Redesign/05 - QA & Verification.md`.
