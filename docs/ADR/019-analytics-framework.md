# 019 — Analytics Framework (Milestone 3, Task #64)

## Status

Accepted.

## Problem

Every dashboard built so far (Dashboard/Index.jsx, the four department dashboards) computed its own
chart data with bespoke controller queries. That's fine for one-off widgets, but the UAT brief asked
for a *reusable* framework any module can register a dataset into, so future dashboards (and Report
Center, Task #65) don't each reinvent aggregation.

## Decision

**`config/analytics.php`** is the dataset registry -- one array entry per chart, naming the model,
the `GROUP BY` column, a chart-type hint, and which module gates its visibility. Adding a chart for
a new module is a config entry, not a new controller/query.

**`App\Services\AnalyticsService`** is the only class that turns a registry entry into chart data
(`dataset($key)` for a snapshot breakdown, `trend($key)` for a 6-month line). Every dashboard,
Report Center export, or future widget goes through this service -- never queries a model directly
for chart data.

**Tenant safety by construction**: every dataset is scoped to `company_id IN (<this tenant's
companies>)`, sourced from `Company::pluck('id')` -- Company already carries `TenantScope`
(Milestone 2), so tenant isolation falls out of the existing scope with zero extra tenant-matching
code in this new service.

**`AnalyticsController@index`** renders `Analytics/Index.jsx` (every visible dataset as a chart, one
page). **`AnalyticsController@show`** returns a single dataset as JSON (`GET /analytics/{key}`) for
a dashboard to fetch one widget without loading the whole page -- not yet consumed by the existing
dashboards (out of scope for this task; they already have their own working chart queries and
weren't touched), but the endpoint is real and available for that follow-up.

Module gating mirrors the sidebar's own two-gate pattern (module enabled AND granted to the tenant)
-- `AnalyticsController::enabledModuleKeys()` duplicates the small "granted ∩ stored-enabled"
resolution `HandleInertiaRequests` already does, rather than sharing a helper, to avoid coupling a
new service to that middleware's internals for a three-line calculation.

## Bug caught during verification

`config/analytics.php`'s first draft assumed every dataset's model has a direct `company_id` column.
Live browser verification (not assumed) hit a real 500 on `/analytics`:
`SQLSTATE[42S22]: Column not found: 1054 Unknown column 'company_id' in 'where clause'` for
`milestones` -- `Milestone` and `GoodsReceipt` only carry `company_id` transitively through
`project`/`materialRequest`. Fixed with an optional `company_via` config key: when set,
`AnalyticsService` scopes with `whereHas($relation, fn ($q) => $q->whereIn('company_id', ...))`
instead of a column that doesn't exist. Re-verified with real data (`MaterialRequest::create()` in
tinker, reloaded `/analytics`, confirmed the pie chart stopped showing "No data yet" and
`/analytics/material_requests_by_status` returned the correct JSON), then the test rows were
deleted.

## Consequences

- Ten datasets registered at launch: Material Requests, Employees (x2), Incidents (x2), Tasks, Leave
  Requests, Milestones, Goods Receipts (trend), PPE Replacement Requests -- covering every module
  with a `status`/`severity`-like column today.
- `Incident`, `Task`, and `LeaveRequest` have no module-toggle gate in `workspaces.js` today (see
  that file's own note), so their datasets use `module_key => null` ("always visible") rather than a
  key that would never match and silently hide a real chart.
- Not yet wired into the four existing dashboards as inline widgets -- they already have working,
  independently-built chart queries (Task #17 and earlier) and weren't disturbed. `GET
  /analytics/{key}` exists specifically so that wiring is additive whenever it's prioritized.
