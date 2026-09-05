# CLAUDE.md — IOMS Project Knowledge Base

This file is the entry point. Read this first, then follow links below as needed. Don't re-derive
what's already written here by re-scanning the repository — it's slower and more error-prone than
using this document, which is kept current specifically so that isn't necessary.

## What this project is

**IOMS — Industrial Operations Platform** — formerly "Shipyard Management System,"
formerly "SAFETY LOG." An enterprise operations platform for an industrial/HSE-heavy organization:
employee records, PPE (personal protective equipment) lifecycle, KPI tracking and reporting,
project manpower assignment, and an increasingly general-purpose workflow layer (Material Request
today, more modules planned) built on shared, reusable engines rather than per-module one-offs.

Current version: **2.36.0 Beta**. Check `config/ioms.php` (`version`, `stage`, `build`) for the
authoritative current number — this document doesn't restate it elsewhere to avoid it going stale
in two places.

## Stack

Laravel 12 (PHP) + Inertia.js + React 18 + Tailwind CSS + MySQL + Sanctum. `barryvdh/laravel-dompdf`
for PDF generation, `maatwebsite/excel` for Excel import/export. `spatie/laravel-permission` was
added in Milestone 2 (Tenancy Foundation) as RBAC infrastructure (roles/permissions exist, are
tenant-scoped, and are editable from Settings) — but no controller has been migrated from the
original `role` column + `isX()/canX()` methods to permission checks yet; that remains the live
authorization path. See `docs/ADR/008-tenancy-foundation.md` for the reasoning and
`docs/ARCHITECTURE.md` § Authorization for the current state.

## Where things actually are

- `app/Models/`, `app/Http/Controllers/` — standard Laravel locations, one file per model/controller.
- `app/Concerns/` — reusable traits models opt into (`HasApprovals`, `HasWorkflow`). This is where
  the "engine" pattern lives on the backend. See `docs/ARCHITECTURE.md`.
- `app/Services/` — stateless service classes (`PdfGeneratorService`, `MasterDataDetector`,
  `ReportTemplateResolver`, `TenantContext`).
- `app/Imports/`, `app/Exports/` — Maatwebsite Excel import/export classes.
- `resources/js/Pages/` — one Inertia page per route, grouped by module folder (`Employees/`,
  `MaterialRequests/`, `Ppe/`, etc.).
- `resources/js/Components/shared/` — the reusable frontend components every module is expected to
  use rather than reinvent: `StatusBadge`, `ApprovalActions`, `ActivityTimeline`, `ModuleTabNav`,
  `PageHeader`, `EmptyState`, `LoadingState`, `StatCard`, `EmployeeImportDialog`, and others.
- `resources/js/lib/workspaces.js` — the workspace navigation registry (Workspace → Item), the
  single source of truth for the top workspace switcher and dynamic sidebar. See
  `docs/ARCHITECTURE.md`'s Navigation Architecture section and
  `docs/ADR/007-workspace-navigation.md`.
- `config/ioms.php` — app version/branding/changelog-summary metadata (not Laravel framework config).
- `config/modules.php` — the module toggle registry (Settings → Modules).
- `config/workflow.php` — role-based permission lists for workflow actions (approve/process/
  override). See `docs/ARCHITECTURE.md` § Authorization.
- `docs/ADR/` — Architecture Decision Records for the larger, less-obvious design calls (see below).
- `CHANGELOG.md`, `ROADMAP.md`, `README.md` — existing project docs, not duplicated here. This file
  is about orientation and "how to think about the codebase," not a changelog.

## The five documents in this knowledge base, and when to read which

| Document | Read it when you need to know... |
|---|---|
| **CLAUDE.md** (this file) | Where to start, how the pieces fit together at a glance. |
| `docs/ARCHITECTURE.md` | How the reusable engines work (Approval, Workflow, Timeline, Import, PDF, Report Export), the multi-tenant/company-scoping model, and the authorization approach. Read before building anything that might duplicate an existing engine. |
| `docs/MODULES.md` | What a specific module (Employees, PPE, Material Request, Projects, KPI, Tasks, Settings) actually does, its key files, and its module-specific business rules. Read before touching a module you haven't worked in yet. |
| `docs/CONVENTIONS.md` | The house style: migration patterns, naming, status-enum conventions, verification habits, things that have caused real bugs before and how they were fixed. Read before writing a migration, adding a role check, or touching anything cache-related. |
| `docs/ADR/*.md` | The *reasoning* behind a handful of specific, larger decisions (why the Approval Engine is shaped the way it is, why "Pending Approval" isn't a stored status, why Tenancy/RBAC/Platform Super Admin are shaped the way they are in `008`). Read the relevant one before revisiting a decision it documents, so you don't re-litigate something that was already deliberately decided with tradeoffs in mind. |

## The single most important habit in this codebase: verify before building

Across this project's history, the instruction "verify first, don't assume a feature is missing"
has repeatedly caught real gaps that a fresh build would have duplicated (e.g. `ActivityLog` already
existed and was already used 32+ times before the Activity Timeline viewer was built — only the
*viewing* UI was actually missing). It has also caught two genuinely severe bugs that static
reading alone would have missed if the check had stopped at "the code looks right":

- Material Request and PPE Replacement Request routes were once accidentally nested inside a
  `role:super_admin` middleware group, silently locking out every non-Super-Admin user.
- A settings cache (`CompanySetting`) used `Cache::rememberForever()` in a way that could
  permanently hide a newly-registered module from the sidebar, and a first attempt to fix that
  introduced a *second* real bug (a cache-key mismatch between `get()` and `set()`).

Both are written up in `docs/CONVENTIONS.md` because they're the kind of mistake that's easy to
repeat in a slightly different shape. Before adding a new module, route group, or cached setting,
skim that document.

## What this knowledge base is *not*

- Not a substitute for reading actual source code when implementing something — it documents
  shape and reasoning, not line-by-line behavior.
- Not a runtime verification tool. Nothing in this repository's development history has ever been
  confirmed by actually running `php artisan migrate`, `npm run build`, or clicking through the app
  in a browser — every prior verification was static (balance/brace checks, route cross-references,
  prop-contract matching). Treat "documented as working" as "reasoned through carefully," not
  "tested." Say so plainly if you're in the same position.
- Not exhaustive. It covers what's architecturally significant, not every field on every model.

## Maintaining this knowledge base

Update these documents in the same session as a change that makes them stale — not as a separate
cleanup pass later, which tends not to happen. Concretely:

- New reusable engine or shared component → add it to `docs/ARCHITECTURE.md` and the table above.
- New module → add a section to `docs/MODULES.md`.
- A migration/caching/auth mistake gets made and fixed → add it to `docs/CONVENTIONS.md`'s "Known
  Pitfalls" list, the same way the two bugs above were captured, so the *next* person (human or AI)
  doesn't rediscover it the hard way.
- A genuinely significant, non-obvious design decision gets made (something a future reader would
  reasonably ask "why was it done this way?") → write an ADR in `docs/ADR/`, don't just fold the
  reasoning into a code comment where it's easy to miss.
- If a document starts duplicating another, merge or cut — the goal is one clear place per kind of
  question, not maximum coverage.
