# IOMS — Industrial Operations Platform

**Industrial Operations Platform** — a clean, fast operations management app built for daily HSE
work across industrial sectors (Shipyard, Mining, Construction, Manufacturing, Oil & Gas, Energy).
Formerly **Shipyard Management System**, formerly **SAFETY LOG**. Replaces the Excel-based HSE KPI
sheet and adds multi-company support, projects, PPE management, and daily reporting — while
staying deliberately simple (not an ERP).

Stack: **Laravel 12** (backend) + **Inertia.js + React 18** (frontend, no separate API) + **TailwindCSS** + **ShadCN-style components** + **MySQL** + **Laravel Sanctum** (session auth) + **Chart.js** + **maatwebsite/excel** + **barryvdh/laravel-dompdf**.

> **Version 1.6.9.1 (Enterprise Edition)** — see `CHANGELOG.md` for what changed and `ROADMAP.md` for what's next.
> Designed & Developed by **YSR Systems**. © 2026.

---

## 1. Project Structure

```
ioms/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/AuthenticatedSessionController.php   # login/logout
│   │   │   ├── HomeController.php                        # welcome page
│   │   │   ├── DashboardController.php                   # KPI dashboard + charts + company filter
│   │   │   ├── EmployeeController.php                    # employee CRUD + profile (company-scoped)
│   │   │   ├── KpiInputController.php                    # core: single input + quick attendance
│   │   │   ├── ProjectController.php                     # projects + manpower + timeline
│   │   │   ├── PpeTypeController.php                     # PPE Master (Super Admin only)
│   │   │   ├── PpeController.php                         # PPE Distribution + History + Dashboard
│   │   │   ├── DailyReportController.php                 # Daily HSE Report + auto-writes Project Timeline
│   │   │   ├── ReportController.php                      # report view + Excel/PDF export
│   │   │   └── SettingsController.php                    # companies/dept/position/user/branding/backup
│   │   ├── Middleware/
│   │   │   ├── HandleInertiaRequests.php                 # shares auth user + role flags + companies
│   │   │   └── CheckRole.php                             # role:super_admin,hse route guard
│   │   └── Requests/                                     # form validation classes
│   ├── Models/                                           # Eloquent models (see ERD below)
│   ├── Policies/                                         # Employee, KpiRecord, User, Company, Project
│   ├── Services/
│   │   ├── KpiReportService.php                          # single source of truth for report data
│   │   └── DashboardStatsService.php                     # dashboard aggregations/leaderboards
│   ├── Exports/                                          # maatwebsite/excel export classes
│   └── Providers/
├── database/
│   ├── migrations/                                       # 11 base + 7 incremental v1.2 migrations
│   ├── seeders/                                          # roles, companies, departments, positions, KPI categories, users, demo data
│   └── factories/
├── resources/
│   ├── js/
│   │   ├── Pages/                                        # one folder per module (Inertia pages)
│   │   │   ├── Auth/Login.jsx                            # title + version/developer footer
│   │   │   ├── Home/Index.jsx
│   │   │   ├── Dashboard/Index.jsx                       # + company filter, headcount, projects, reminders
│   │   │   ├── Employees/{Index,Form,Profile}.jsx        # + Company field, Years of Service, projects
│   │   │   ├── KpiInput/Index.jsx                        # single input + quick attendance
│   │   │   ├── Projects/{Index,Form,Show}.jsx            # projects + manpower + timeline
│   │   │   ├── Ppe/{Master,Index,Dashboard}.jsx          # PPE Master, Distribution+History, Dashboard
│   │   │   ├── DailyReports/{Index,Form,Show}.jsx        # Daily HSE Report
│   │   │   ├── Reports/Index.jsx                         # + company filter
│   │   │   └── Settings/Index.jsx                        # + Companies tab, 4-role users
│   │   ├── Layouts/AuthenticatedLayout.jsx               # sidebar + topbar shell, role-aware nav, About
│   │   ├── Components/
│   │   │   ├── ui/                                       # reusable ShadCN-style primitives
│   │   │   └── shared/                                   # KpiSummaryCard, PeriodFilter, AboutDialog
│   │   ├── lib/                                          # utils, chart setup
│   │   └── app.jsx                                       # Inertia entry point
│   ├── css/app.css                                       # Tailwind + CSS variables (light/dark)
│   └── views/
│       ├── app.blade.php                                 # Inertia root view
│       └── exports/kpi-report-pdf.blade.php              # dompdf template
├── routes/web.php                                        # all routes, grouped by role
├── config/                                               # app, database, sanctum, inertia, excel, etc.
├── CHANGELOG.md
├── ROADMAP.md
├── composer.json
├── package.json
├── vite.config.js
└── tailwind.config.js
```

### Key architectural decisions

- **Inertia.js, not a separate API** — one Laravel app serves server-rendered React pages directly. Simpler deploy, no CORS/token juggling; Sanctum just protects session cookies.
- **Four roles** (`super_admin`, `hse`, `hrd`, `manager`), enforced in three layers: route middleware (`role:super_admin,hse`), model policies, and the frontend hides unavailable UI. The middleware/policies are the real boundary — frontend hiding is just UX. `User::isAdmin()` is kept as a back-compat alias meaning "Super Admin **or** HSE".
- **Multi-Company** — `companies` (GAJ, Maintenance) own departments and employees. Department names are unique *per company*, so both companies can have "HSE", "Engineering", etc. KPI records resolve to a company through `departments.company_id`, so no redundant column was added to `kpi_records`.
- **KPI categories are data, not code** — stored in `kpi_categories`, so new categories can be added without a migration.
- **`KpiReportService` is the single source of truth** for report numbers — used identically by the Report page, Excel export, and PDF export.
- **Quick Attendance** creates one `kpi_records` row per checked employee in a DB transaction.
- **Project is a simple container** — not project management software. Any future module can reference `project_id` and append to the polymorphic `project_timeline_events` table without schema changes.
- **One piece of information, one module (v1.3).** PPE Distribution and PPE History are the same `employee_ppe` table, not two. The Project Timeline is not manually maintained — it's auto-populated from Daily Report submissions (activity summary + date only, never manpower or PPE) using the same polymorphic timeline table introduced in v1.2. Daily Report itself never asks for manpower or PPE, since both already exist in Project Manpower and PPE Distribution respectively.
- **PPE Master is fully table-driven.** No PPE type or replacement interval is hardcoded; Super Admin manages both from the PPE Master page. A `null` interval marks request-based equipment (e.g. Harness, Headlamp) — still fully tracked in history, just without an expiry date.
- **PPE status is fully automatic (v1.3.1).** `EmployeePpe::getEffectiveStatusAttribute()` computes Active/Expiring Soon/Expired from `expiry_date` on every access — nothing is cached or cron-updated, so it can never go stale. The raw `status` column still tracks genuine manual lifecycle events (Replaced/Returned); the "Mark As" control only ever touches that.
- **Display order is configurable, not hardcoded (v1.3.1).** Departments and Positions each have a `sort_order` column, editable via Settings. `Employee::scopeOrderedForDisplay()` is the single place this ordering is applied (joins departments+positions, orders by their `sort_order` then name) — every employee list in the app (Employees index, Reports grouping, Project Manpower, Excel export, PPE/KPI employee pickers) uses it, so the configured order is consistent everywhere without each screen re-implementing sorting.
- **Version info is centralized, not hardcoded (v1.3.2, moved to `config/ioms.php` in v1.6.0).** Single source of truth for the version number, edition, release date, developer/company credit, "What's New", and version history — shared to every page via `HandleInertiaRequests`. The About dialog, sidebar footer, login footer, and Home page's release announcement all read the same values; a release bump is a one-file edit.
- **KPI categories are per-company configurable, not hardcoded (v1.5.0).** `kpi_categories.company_id` (nullable) makes a category either Global (every company) or scoped to one company — managed via Settings > KPI Categories (Super Admin + HSE). `KpiCategory::scopeVisibleForCompany()` is the single place this filtering happens; the Dashboard and Reports pages both use it wherever they already have a Company filter.
- **Sidebar modules are toggleable, not hardcoded (v1.5.0).** `config/modules.php` is the registry of modules that exist; Settings > Modules (Super Admin) toggles them via an `enabled_modules` CompanySetting. This is the mechanism a *future* module (Fleet, Marine Operations, Procurement, etc. — see `ROADMAP.md`) would register into without changing the toggle system itself. It's a navigation-visibility toggle, not a hard access-control boundary.
- **The Task Engine is a reusable foundation, not a single-purpose feature (v1.6.4).** `related_module` + `related_record_id` on `tasks` are a lightweight polymorphic link any future module can use to attach tasks to its own records without `TaskService` or the `tasks` table needing to know about them. Deliberately minimal for now — see `ROADMAP.md` for the planned comments/attachments/history/notification extensions.
- **`PdfGeneratorService` is the single point every document type goes through (v1.6.7).** Material Request and PPE Replacement Request already use it; future Daily Report / Incident Report / Permit to Work PDFs should reuse the same service rather than each calling the PDF library directly. It's built around plain Blade views specifically because that's the fastest way to match traditional company paperwork layouts, and the same skill (writing a Blade view) is all any future document type needs.
- **`ReportTemplateResolver` is the plug-in point for company-specific Excel exports (v1.6.8), prepared ahead of any actual template existing.** `KpiReportExport` (the current generic export) implements `ReportExportInterface` as the default; a real company template later is one new class plus one line in the resolver, not a rewrite of `ReportController` or how report data is assembled.
- **The `HasApprovals` trait is the reuse mechanism for the Universal Approval Engine (v1.6.9).** Material Request is the first consumer. A future approvable module (PPE Replacement Request, Permit To Work, Purchase Request, Asset Request, Inspection) adds this one trait, extends its own status enum with `approved`/`rejected` constants, and gets the identical draft→submitted→approved/rejected workflow with zero new backend routes — see `docs/ADR/001-approval-engine.md` for the full reasoning, including what this deliberately is *not* (the larger, configurable multi-step Workflow Engine discussed separately).
- **`ActivityLog` already existed and was already used app-wide before v1.6.9** — verified rather than assumed missing. The real gap was a viewing UI, not the recording mechanism; `ActivityTimeline.jsx` is that reusable piece, and any future page eager-loads its own subject's activity rows the same way `MaterialRequestController::show()` now does. See `docs/ADR/004-timeline-engine.md`.
- **`HasWorkflow` (v1.6.9.1) complements `HasApprovals` rather than duplicating it.** `HasApprovals` is specifically the submit/approve/reject decision (one `Approval` record); `HasWorkflow` is the general state-machine guard around a model's whole `status` lifecycle, valid for every transition including ones with no approval decision at all. A future multi-step module defines its own `$transitions` map and gets the identical guard, throwing a descriptive error for any disallowed move rather than silently accepting it.
- **Role checks for workflow actions live in `config/workflow.php`, not scattered inline `if ($user->role === 'x')` checks.** Deliberate: no RBAC package (Spatie Permission or otherwise) exists in this codebase yet, and migrating to one now would be a breaking change for a problem (per-company customizable permissions) that doesn't exist yet. Centralizing the current role checks means a future Spatie migration has one well-defined place to update instead of dozens — see `docs/ADR/006-material-request-workflow.md` for the full evaluation and reasoning.
- **Branding is centralized, not hardcoded (v1.5.3).** `BrandWordmark`, `BrandIcon`, `BrandWatermark` are the only components that ever reference a brand image path — every page uses them instead of hardcoding `<img>` tags. `config/branding.php` holds static defaults (shipped asset paths); the actual effective values are resolved once per request into the `branding` shared prop (`HandleInertiaRequests`), with room already built in for a future admin override with zero other code changes.

---

## 2. Database ERD

```
 companies                 departments                positions
 ┌────────────┐            ┌──────────────┐           ┌────────────┐
 │ id         │◄───────────┤ company_id   │◄──────────┤ department_id
 │ name       │            │ id           │           │ id
 │ code       │            │ name         │           │ name
 │ is_active  │            │ code         │           │ is_active
 └─────┬──────┘            │ is_active    │           └────────────┘
       │                   └──────┬───────┘  (name unique per company)
       │                          │
       │        ┌─────────────────▼───────────────────┐
       ├───────►│              employees               │
       │        │  id, employee_id, full_name,         │   (nik REMOVED in v1.2)
       │        │  company_id (FK), department_id (FK), │
       │        │  position_id (FK), status, photo,     │
       │        │  join_date, phone   (soft deletes)    │
       │        └───────┬───────────────────────────┬──┘
       │                │                           │
       │      ┌─────────▼────────┐        ┌─────────▼──────────┐
       │      │   kpi_records    │        │  project_manpower  │
       │      │ employee_id (FK) │        │ project_id (FK)    │
       │      │ department_id(FK)│        │ employee_id (FK)   │
       │      │ kpi_category_id  │        └─────────┬──────────┘
       │      │ record_date      │                  │
       │      │ month, year      │        ┌─────────▼──────────┐
       │      │ quantity (=1)    │        │     projects       │
       │      │ created_by (FK)  │        │ id, company_id(FK) │◄─┐
       │      └──────────────────┘        │ name, vessel_name  │  │
       │                                  │ start/end_date     │  │
       └─────────────────────────────────►│ status, description│  │
                                          └─────────┬──────────┘  │
 users                                              │             │
 ┌────────────────────┐              ┌──────────────▼──────────┐  │
 │ id, name, email,   │              │ project_timeline_events │  │
 │ password, role     │              │ project_id (FK)         │──┘
 │ (super_admin|hse|  │              │ event_type, title       │
 │  hrd|manager)      │              │ event_date              │
 │ is_active          │              │ subject_type/id (poly)  │
 └─────────┬──────────┘              └─────────────────────────┘
           │
   ┌───────▼────────┐     kpi_categories            company_settings
   │ activity_logs  │     ┌────────────────┐        ┌────────────────┐
   │ user_id (FK)   │     │ code, name,    │        │ key, value     │
   │ action         │     │ short_label,   │        │ (app name,     │
   │ subject_type/id│     │ is_negative,   │        │  subtitle,logo)│
   │ meta (json)    │     │ supports_quick │        └────────────────┘
   └────────────────┘     └────────────────┘
```

**8 fixed KPI categories** (seeded, table-driven): Fatality, LTI, FAC, PPE Violation, BBS/Nearmiss, Drill, Campaign, TBM.
Every occurrence = **+1** row in `kpi_records` (`quantity` defaults to 1). No weighted scoring.

**Companies seeded:** GAJ (16 departments) and Maintenance (9 departments) — see `DepartmentSeeder`.

**v1.3 additions (not diagrammed above for space):**
- `ppe_types` (id, name, replacement_interval_months nullable, is_active) — PPE Master, Super-Admin-configurable.
- `employee_ppe` (employee_id FK, ppe_type_id FK, issued_date, expiry_date nullable, status, issued_by FK) — PPE Distribution *and* History, same table.
- `daily_reports` (project_id FK, hse_officer_id FK → employees, report_date, report_type, findings, notes, created_by FK) — a project may have multiple reports on the same date, each attributed to the HSE Officer who filed it (not the logged-in user account, which is kept only as an internal `created_by` audit field).
- `daily_report_activities` (daily_report_id FK, description, sort_order) — free-text activity lines.
- `daily_report_photos` (daily_report_id FK, photo_path, caption) — documentation uploads.
- Every `daily_reports` create/update writes one row to the existing `project_timeline_events` table (subject_type = `DailyReport`), which is how the Project Timeline stays populated without re-entering activities.

**Future-ready:** Incident, PTW, Gas Test, Confined Space, Inspection, Training,
Medical Checkup, Notification, Document Control can each be added as new tables referencing
`employees`/`departments`/`projects` — and can append to `project_timeline_events` — with no changes
to existing tables. This is also what makes the system usable by companies beyond GAJ/MTC: every
company-specific value (departments, PPE types, intervals) lives in the database, editable by
Super Admin, never in code.

---

## 3. Installation Steps

### Prerequisites
- PHP 8.2+ with extensions: `mbstring`, `pdo_mysql`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `gd`
- Composer 2.x
- Node.js 18+ and npm
- MySQL 8.0+ (or MariaDB 10.6+)
- `mysqldump` and `mysql` CLI binaries on PATH (used by Settings → Backup/Restore)

### Steps (fresh install)

```bash
# 1. Extract the project
cd ioms

# 2. Install PHP dependencies
composer install

# 3. Environment
cp .env.example .env
php artisan key:generate

# 4. Configure your database in .env
#    DB_DATABASE=ioms
#    DB_USERNAME=root
#    DB_PASSWORD=your_password

# 5. Create the database
mysql -u root -p -e "CREATE DATABASE ioms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 6. Run migrations + seed (roles, companies, departments, KPI categories, users, demo data)
php artisan migrate --seed

# 7. Storage symlink (employee photos / company logo)
php artisan storage:link

# 8. Frontend
npm install
npm run build
```

### Upgrading an existing install to v1.3 (from v1.2 or earlier)

Also additive and data-preserving:

```bash
composer install
npm install && npm run build
php artisan migrate      # adds ppe_types, employee_ppe, daily_reports + related tables,
                          # plus hse_officer_id and the multi-report-per-day revision
php artisan db:seed --class=PpeTypeSeeder   # seeds example PPE types (idempotent, safe to re-run)
```

New in v1.3: PPE Master starts with six example types (Safety Helmet, Safety Shoes, Coverall,
Safety Glasses, Headlamp, Harness) — all editable/removable via the PPE Master page; nothing is
hardcoded in application code. Daily HSE Report allows multiple reports per project per day, each
attributed to a chosen HSE Officer (an employee in the HSE department).

### Upgrading an existing SAFETY LOG (v1.1) install to v1.2

This release is **additive** and **preserves your data**. From your existing install:

```bash
# Pull the v1.2 code, then:
composer install
npm install && npm run build

# Run ONLY the new incremental migrations (do NOT use migrate:fresh):
php artisan migrate

# Seed the new reference data (companies + updated roles/departments).
# Seeders are idempotent (updateOrCreate) and safe to run on existing data:
php artisan db:seed
```

What the upgrade migrations do, safely:
- Create `companies` and seed **GAJ** + **Maintenance**.
- Add `company_id` to `departments` and `employees`, **backfilling existing rows to GAJ**.
- Widen `users.role`; migrate existing `admin` → `super_admin`, keep `hrd`.
- Create `projects`, `project_manpower`, `project_timeline_events`.
- **Drop `employees.nik`** (intentional; Employee ID is the employee number now).

> ⚠️ Never run `php artisan migrate:fresh` on a production database — it drops everything.
> The v1.2 upgrade path is `php artisan migrate` (incremental) only.

### Default login accounts (created by UserSeeder — change these passwords immediately)

| Role         | Email                    | Password |
|--------------|--------------------------|----------|
| Super Admin  | admin@ioms.local    | password |
| HSE          | hse@ioms.local      | password |
| HRD          | hrd@ioms.local      | password |
| Manager      | manager@ioms.local  | password |

> The original default Super Admin account (created back in v1.1, previously `admin@safetylog.local`) is preserved across every rebrand — its email is automatically migrated to `admin@ioms.local` by `UserSeeder` on upgrade, not recreated.

---

## 4. Run Commands

### Local development (two terminals)

```bash
# Terminal 1 — Laravel backend
php artisan serve            # → http://127.0.0.1:8000

# Terminal 2 — Vite dev server (hot reload for React)
npm run dev
```

### Local development with Laragon virtual host (recommended on Windows)

So you don't have to run `php artisan serve` every time and can use a clean URL:

1. Place the project in Laragon's `www` folder, e.g. `C:\laragon\www\ioms`.
2. In Laragon, **Menu → Preferences → General**, ensure "Auto virtual hosts" is enabled, and
   **Document Root** is set to the framework's `public/` (Laragon detects Laravel automatically and
   points the vhost at `public/`).
3. Right-click Laragon tray → **Apache/Nginx → Reload**, or **Menu → Restart All**.
4. Laragon auto-creates the host `http://ioms.test`
   (folder name + `.test`). If you named the folder differently, the host matches that name.
5. Set `.env`:
   ```
   APP_URL=http://ioms.test
   SANCTUM_STATEFUL_DOMAINS=ioms.test
   ```
6. Build assets once (`npm run build`) or run `npm run dev` for hot reload while developing.
7. Visit **http://ioms.test** — no `php artisan serve` needed.

> If the `.test` domain doesn't resolve, run Laragon as Administrator once so it can update the
> hosts file, or use **Menu → Tools → Quick app**. Clear DNS with `ipconfig /flushdns` if needed.

### Building frontend assets for a release

Run this on your own machine (or CI) -- **not** on the production server, which has no Node.js.
See § 5 "Deployment Guide" for the full release flow (build here, commit, push, then the server
just `git pull`s the result).

```bash
npm run build
```

### Useful artisan commands

```bash
php artisan migrate                        # apply new incremental migrations (SAFE, keeps data)
php artisan db:seed                        # idempotent reference seeders (safe to re-run)
php artisan db:seed --class=EmployeeSeeder # demo employees + KPI history only
php artisan route:list                     # verify all routes registered correctly
php artisan tinker                         # inspect data in a REPL
```

---

## 5. Deployment Guide

**Production has no Node.js** (verified -- `node`/`npm` both "command not found" on the shared
host). Frontend assets are built on a machine that HAS Node (your own machine, or CI) and
**committed to git** -- `public/build/` is intentionally tracked, not gitignored (see the note on
`/public/build` in `.gitignore`). The server never runs `npm`; `git pull` alone is what brings the
already-built assets across. See `docs/ADR/028-remove-nodejs-from-production.md` for the full
reasoning.

**One flow, on the server**: `git pull` → `composer install` → `php artisan app:deploy` → ready.
`./deploy.sh` runs exactly that sequence. Every environment-specific difference (where `composer`
lives, shared hosting vs a VPS, MySQL vs MariaDB) is a one-time setup choice made *before* the first
deploy, never a step repeated on every deploy -- see
`docs/ADR/027-deployment-architecture-redesign.md` for that reasoning.

### Before every deploy that touches frontend code (on YOUR machine, which has Node)

```bash
npm run build
git add public/build
git commit -m "Build assets for <what changed>"
git push
```

Skip this entirely for a backend-only change (nothing under `resources/js`/`resources/css`
changed) -- the committed `public/build` from the last frontend build is still correct, and
`git pull` on the server won't touch it. If you're ever unsure whether the committed build is
current, running `npm run build` again is always safe -- it's a no-op (identical output, nothing to
commit) if nothing frontend-related changed since the last build.

### One-time setup (per environment, before the first deploy)

1. **Web root points at `public/`, nothing is copied there.** This is the part every past incident
   traced back to -- see the ADR for the full story. Two equivalent ways to satisfy it:
   - **Preferred, if your host allows it**: set the domain's document root directly to
     `~/ioms/public` (cPanel: Domains → your domain → Document Root).
   - **Universal fallback** (works on any shared host, including ones that pin the primary domain's
     document root to `~/public_html` and won't let you change it): replace `public_html` with a
     symlink to `ioms/public`. Rename the old directory out of the way rather than deleting it --
     safe to remove for good later, once you've confirmed the symlink works, but there's no reason
     to make that irreversible as part of this step:
     ```bash
     mv ~/public_html ~/public_html.bak-$(date +%Y%m%d)
     ln -s ~/ioms/public ~/public_html
     ```
   Either way, `~/ioms/public/build` (committed to git, built on a machine with Node -- see below)
   and the web-served `build/` directory become the *same folder* -- there is no second copy to
   fall out of sync, ever, on any future deploy. Do this once; never repeat it.
2. **`.env`**: copy `.env.example` to `.env` and fill in real values -- `APP_ENV=production`,
   `APP_DEBUG=false`, real `APP_URL` (https), `SESSION_SECURE_COOKIE=true`, `SANCTUM_STATEFUL_DOMAINS`
   = your domain, production DB credentials. `php artisan key:generate` if `APP_KEY` is blank.
3. **Locate `composer`.** Some shared hosts don't put it on `PATH` (e.g. it may only exist at
   `/opt/alt/php83/usr/bin/composer` or similar). Find it once (`find / -iname composer 2>/dev/null`
   or ask your host's docs), then add it to your shell profile so every future deploy picks it up
   automatically without editing any committed file:
   ```bash
   echo 'export COMPOSER_BIN=/opt/alt/php83/usr/bin/composer' >> ~/.bashrc
   ```
   `deploy.sh` reads `COMPOSER_BIN` (falling back to plain `composer` if unset).
4. **Cron, for the scheduler** (approval escalation, scheduled reports -- see `routes/console.php`):
   ```
   * * * * * cd ~/ioms && php artisan schedule:run >> /dev/null 2>&1
   ```
5. Make `deploy.sh` executable once: `chmod +x deploy.sh`.

### Every deploy, from then on

```bash
./deploy.sh            # standard deploy: migrate, no seed
./deploy.sh --seed     # also (re-)run seeders -- safe every time, every seeder is idempotent
./deploy.sh --first    # first-ever deploy on a brand-new install: seeds + skips maintenance mode
```

That's the entire server-side flow -- no manual `composer` invocation, no manual cache clearing, no
manual asset copying or building, no manual migration recovery, and **no Node.js/npm anywhere on
the server**. `deploy.sh` calls `composer install`, then hands off to `php artisan app:deploy`
(`app/Console/Commands/DeployCommand.php`), which clears stale config cache, migrates, optionally
seeds, links storage (only if not already linked), and rebuilds every cache -- wrapped in
maintenance mode, which only lifts if every step succeeded. If any step fails, the deploy exits
non-zero and the app stays in maintenance mode (a safe 503) instead of silently serving whatever
broken state the failure left behind; fix the reported error, then re-run `./deploy.sh`.

### VPS / Nginx specifics

Same `deploy.sh` flow. Point Nginx's document root at `~/ioms/public` directly (no symlink needed --
a VPS's Nginx config can reference any path), standard Laravel rewrite to `index.php`, `storage/` and
`bootstrap/cache/` writable by the web server user, and a cron entry for `schedule:run` as above.

### Docker (Laravel Sail) — local development only

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan app:deploy --seed
```

### Backups in production

Settings → Backup Database shells out to `mysqldump`; ensure the binary is installed and the DB user
has sufficient privileges. For unattended backups, also schedule `mysqldump` via cron directly.

### Security checklist before going live

- [ ] Change all four default seeded passwords
- [ ] `APP_DEBUG=false`
- [ ] HTTPS enforced, `SESSION_SECURE_COOKIE=true`
- [ ] `SANCTUM_STATEFUL_DOMAINS` matches your production domain
- [ ] Database backups scheduled outside manual button-clicks

---

## 6. Roles & Permissions (v1.2)

| Capability                        | Super Admin | HSE | HRD | Manager |
|-----------------------------------|:-----------:|:---:|:---:|:-------:|
| View Dashboard / Employees / Reports | ✅ | ✅ | ✅ | ✅ |
| View Projects                     | ✅ | ✅ | ✅ | ✅ |
| View PPE (Distribution/History/Dashboard/Master) | ✅ | ✅ | ✅ | ✅ |
| View Daily Reports                | ✅ | ✅ | ✅ | ✅ |
| Input KPI (single + quick attendance) | ✅ | ✅ | — | — |
| Create/Edit/Delete Employees      | ✅ | ✅ | — | — |
| Create/Edit/Delete Projects + Manpower | ✅ | ✅ | — | — |
| Issue/update PPE (Distribution)   | ✅ | ✅ | — | — |
| Create/Edit/Delete Daily Reports  | ✅ | ✅ | — | — |
| Manage Departments / Positions    | ✅ | ✅ | — | — |
| Manage PPE Master (types/intervals) | ✅ | — | — | — |
| Manage Companies                  | ✅ | — | — | — |
| User Management                   | ✅ | — | — | — |
| Branding + Backup/Restore         | ✅ | — | — | — |

See `CHANGELOG.md` for the full v1.2 change list and `ROADMAP.md` for planned modules.

---

## 7. Troubleshooting

**`SQLSTATE[42S22]: Unknown column 'company_id' in 'kpi_categories'` (or similar "Unknown column"
errors after upgrading):**

This means the database schema hasn't caught up to the code yet — some migration hasn't run. The
fix is always the same, in this order:

```bash
php artisan migrate:status   # confirm which migrations are still "Pending"
php artisan migrate          # (add --force if APP_ENV=production)
php artisan db:seed          # only after migrate completes -- never before
```

Never run `php artisan db:seed` before `php artisan migrate` completes — seeders assume the
current schema already exists. If you deploy via a script, make sure `migrate --force` runs and
exits successfully *before* any seed step, and never run `migrate:fresh` on a database with real
data (it drops every table).

**`Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.`
during `php artisan migrate` (any package's config, not just `permission`):**

The file is there and is fine — this is a stale `bootstrap/cache/config.php` left over from an
*earlier* deploy, before this package's own config file existed in the codebase. Laravel's config
cache is a hard, all-or-nothing snapshot: once `php artisan config:cache` has run, every
`config()` call reads only that frozen file, never `config/*.php` and never `.env`, until it's
explicitly cleared. `git pull` cannot fix or remove it — `bootstrap/cache/*.php` is gitignored
(intentionally: see the note on `/bootstrap/cache/*.php` in `.gitignore`), so it's a server-local
file that silently outlives every deploy that doesn't touch it. If `php artisan about` also shows
`Environment: local` / `Debug: ENABLED` / a `localhost` URL on a server whose `.env` is clearly
correct, that's the exact same stale cache, not a separate bug — those values got frozen in
alongside the missing config key.

Fix (one-time recovery for a server already in this state):

```bash
php artisan config:clear
php artisan migrate --force
php artisan config:cache   # rebuild it fresh, now that migrate has succeeded
```

This is exactly why `php artisan app:deploy` (what `./deploy.sh` runs on every deploy, see § 5)
always clears config before migrating, every time, not only the first deploy -- this specific
failure should not be able to recur from here on.

**White screen in production / browser console shows 404 for `build/assets/app-*.js` or
`app-*.css`, even though the file exists in the repo's `public/build`:**

Two separate copies of the built assets existed -- one at `~/ioms/public/build` (what `npm run
build` actually writes, matching the current `manifest.json`) and a second, stale one at
`~/public_html/build` (from an earlier deploy that copied files there manually) that Apache/Nginx
was actually serving. This can only happen when the web root is a *separate directory* that
something copies into, rather than `public/` itself. Root cause and permanent fix (not "copy the
new files over," which just recreates the same failure mode on the next deploy) are in
`docs/ADR/027-deployment-architecture-redesign.md` § "One-time setup" -- point the web root at
`~/ioms/public` directly, or symlink `public_html` to it, once. After that there is only ever one
`build/` directory and this class of bug cannot happen again.

**`SQLSTATE[42S01]: Base table or view already exists` for `ppe_replacement_request_items`
specifically:**

This means an earlier version of the `2026_08_06_100029` migration (since fixed) ran partway
against your database, created the table, then failed on an invalid foreign key reference before
Laravel could mark the migration as completed. The migration itself is fixed now and is
intentionally *not* written to detect or recover from this automatically — a production migration
silently dropping a table as routine logic would be dangerous if this table ever holds real data
in the future. This is a one-time, manual recovery for a database affected by that earlier bug,
not something to run normally:

```bash
php artisan tinker
>>> \Illuminate\Support\Facades\Schema::dropIfExists('ppe_replacement_request_items');
>>> exit
php artisan migrate
```

Only run the `dropIfExists` line if you're actually hitting this specific error — it's safe here
specifically because a table left behind by a failed migration that was never marked complete
cannot contain any real data (nothing in the application could have written to a table Laravel
doesn't think exists yet). Do not run this against a database where this migration completed
successfully and the table has since accumulated real Replacement Request records.
