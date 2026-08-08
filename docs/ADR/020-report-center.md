# 020 — Report Center (Milestone 3, Task #65)

## Status

Accepted.

## Problem

The existing `ReportController`/`Reports/Index.jsx` is a single-purpose KPI report exporter (Excel +
PDF, one hardcoded dataset). The UAT brief asked for a genuine Report Center: any report, Preview,
download in PDF/Excel/CSV, and Scheduled Report -- without duplicating a bespoke controller per
module.

## Decision

**Built entirely on top of the Analytics Framework (ADR-019)**, not a parallel system: Report
Center's "reports" ARE `config/analytics.php`'s registered datasets. A new module that registers an
Analytics dataset gets a Report Center entry automatically -- no code here.

- `ReportCenterController@preview` returns `AnalyticsService::dataset($key)` as JSON -- literally
  the same call a download uses, so Preview can never show something different from what downloads.
- `exportCsv`/`exportExcel`/`exportPdf` render that same dataset three ways: a hand-built CSV
  string, `App\Exports\AnalyticsDatasetExport` (generic `FromArray` Excel class, one class for every
  dataset), and `resources/views/exports/analytics-dataset-pdf.blade.php` (generic table PDF).
- **Scheduled Report** (`report_schedules` table, tenant_id required + company_id nullable,
  mirroring numbering_formats/approval_flows' tenant-scoping pattern from ADR-018): a user picks a
  dataset + format + frequency; `php artisan reports:dispatch-scheduled` (registered hourly in
  `routes/console.php`, same pattern as `approvals:escalate`) finds due schedules
  (`next_run_at <= now()`), generates the file into `storage/app/reports`, and notifies the owning
  user through the real Notification Center (`NotificationService::notify()`, category
  `information`) with a link back to Report Center.

**Deliberately not an email dispatcher.** No `Mail` usage or `config/mail.php` exists anywhere in
this codebase today -- building an email pipeline here would be exactly the "looks done, isn't"
trap CLAUDE.md warns about (unverifiable without real SMTP credentials, and nothing to test it
against). The stored file is a point-in-time snapshot; the same dataset can always be re-downloaded
live from Report Center at any time, so the schedule's value is "remind me it's ready," not "be my
only way to get it."

## Verified end-to-end, not just unit-level

Live browser walkthrough: opened Report Center, Preview on a populated dataset showed real grouped
counts (not placeholder rows), created a real `MaterialRequest` via tinker, confirmed both Analytics
and Report Center's Preview reflected it immediately, created a Scheduled Report via the actual UI
form, forced its `next_run_at` into the past via tinker, ran `reports:dispatch-scheduled` for real,
and confirmed: a genuine CSV file landed in `storage/app/reports`, a real `Notification` row was
created with the correct title/category/link, and `last_run_at`/`next_run_at` advanced correctly.
Test data was deleted afterward.

## Bug caught during this same pass

`employees_by_department`'s Preview/exports initially showed raw `department_id` integers instead
of department names (caught by literally reading Preview's output, not assumed correct). Fixed by
adding an optional `label_model` config key -- `AnalyticsService::dataset()` now resolves bucket
values through that model's `name` column when set. This is a general Analytics Framework
improvement (ADR-019), not Report-Center-specific, since Analytics' own chart labels had the exact
same gap.

## Consequences

- Report Center and Analytics necessarily share visibility rules (same `enabledModuleKeys()`
  resolution, duplicated in both controllers rather than extracted to a shared trait/service -- a
  three-line calculation, judged not worth the indirection of a shared helper for two call sites).
- No per-recipient sharing (a schedule always belongs to, and only notifies, the user who created
  it) -- "share this report with someone else" would need a `recipients` list, left for a future
  iteration since it wasn't explicitly requested.
