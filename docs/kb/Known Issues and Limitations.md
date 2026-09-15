---
title: Known Issues and Limitations
type: register
updated: 2026-09-15
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
| **ADR numbering has gaps and a collision** | 002, 003 and 005 do not exist, and **029 is used twice**. Recorded in [[Decision Register]]; next free number is 034 |

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
