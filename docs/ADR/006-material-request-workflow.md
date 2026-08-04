# 006 — Material Request Workflow (Complete Lifecycle)

## Status

Accepted (v1.6.9.1).

## Problem

Material Request needed to become a complete operational workflow (Draft through Completed/
Cancelled) rather than a CRUD module with a bolted-on Approval Engine. This requires: a full state
machine with enforced valid transitions, role-based permissions for each transition, and a detail
page that shows only the actions actually available in the current state.

## Decision

**"Pending Approval" is not a separate stored status.** The spec's lifecycle diagram lists
`Submitted -> Pending Approval` as if they were two distinct states, but the Action Buttons section
of the same spec shows only one row for both ("Submitted / Waiting Approval"), never listing
"Pending Approval" as its own status with its own button set. Storing it as a literal seventh
database value would mean two states (`submitted`, `pending_approval`) that are always identical in
practice -- created together, always transition together -- which is duplication, not a real
distinction. "Pending Approval" is how `submitted` is *labeled* in the UI while its associated
`Approval` record's own status is `pending` (which already exists as a separate, correct concept on
the `Approval` model itself, from ADR 001). The Material Request's own `status` column only needs:
`draft, submitted, approved, rejected, processing, completed, cancelled`.

**A new `HasWorkflow` trait**, complementing `HasApprovals` (ADR 001) rather than replacing it.
`HasApprovals` is specifically the submit/approve/reject decision (one `Approval` record).
`HasWorkflow` is the more general state-machine guard around a model's own `status` column, valid
for its whole lifecycle including states with no approval decision involved at all (`approved ->
processing`, `processing -> completed`). A consuming model defines a `$transitions` array (from-
state => allowed-to-states); `transitionTo()` throws a descriptive `ValidationException` naming
the actual problem for any disallowed move, and logs a single `ActivityLog` entry for any allowed
one.

**Role-based authorization via `config/workflow.php`**, not hardcoded checks. The spec's example
roles (Employee/Supervisor/Warehouse/Company Admin) are mapped onto real roles in this app:
Supervisor -> `manager` (a genuine capability expansion -- Manager was previously read-only),
Warehouse -> a new `warehouse` role (added with zero migration, since `role` is a plain `VARCHAR`
widened from a real enum in an earlier session specifically for this kind of additive change),
Company Admin -> `super_admin`. The config has three lists (`approvers`, `processors`,
`overriders`) any future workflow-driven module reads from the same file, rather than each module
hardcoding its own role check.

**RBAC recommendation, evaluated before implementing anything custom.** Confirmed no RBAC package
(Spatie Laravel Permission or otherwise) exists in this codebase -- `composer.json` has no such
dependency, and `User` has no `hasPermissionTo()`/`assignRole()` methods, just a plain `role` string
column with hardcoded `isX()`/`canX()` helper methods.

**Recommendation: adopt Spatie Laravel Permission when real multi-tenant SaaS permission
complexity actually arrives** (per-company custom roles, granular per-action permissions beyond
the current ~5 fixed roles) -- not this version. Reasoning:
- It is the de facto standard for Laravel RBAC: mature, well-maintained, handles the
  role-to-permission-to-user graph, caching, and Blade/policy integration that a custom system
  would otherwise reinvent.
- **However, migrating now would be a genuinely breaking change** this session's own instruction
  explicitly rules out ("do not introduce breaking changes"). Every `isSuperAdmin()`/`isHse()`/etc.
  call site (dozens across this codebase) and every `canManageX()` permission check would need
  rewriting to Spatie's `hasRole()`/`can()` API, plus a real data migration moving the plain `role`
  string into Spatie's `roles`/`model_has_roles` tables.
- The current plain-string-role system, while simple, is not yet actually limiting anything real:
  five roles with fixed capabilities is genuinely adequate for what this application does today.
  Adopting Spatie now would be solving a problem (per-company customizable permissions) that
  doesn't exist yet, at real migration risk, for a benefit not yet needed.
- **What this version does instead**: keeps the role check surface small and centralized
  (`config/workflow.php`'s three lists, rather than scattered `if ($user->role === 'x')` checks
  inline in controllers) specifically so that a *future* Spatie migration has a small, well-defined
  set of places to update, not dozens of ad-hoc checks spread through the codebase. This is
  explicitly preparation for that migration being *easier later*, not an attempt to avoid it
  forever.

## Alternatives Considered

**A literal `pending_approval` database status** -- rejected, per the "Pending Approval" reasoning
above; would have been visible duplication with `Approval.status`.

**Hardcoding `if ($user->role === 'manager')` inline in `ApprovalController`** -- rejected in favor
of `config('workflow.approvers')`. The config file is the one place a future module or a future
Spatie migration needs to change, not N call sites.

**Implementing Spatie Laravel Permission this session** -- rejected for now; see the reasoning
above. Explicitly not "never," just "not while it would be a breaking change with no immediate
benefit."

## Consequences

- `$approval->approvable::STATUS_APPROVED`-style static-constant access (from ADR 001) means every
  workflow-driven model needs matching constants; `HasWorkflow`'s `$transitions` array adds a
  second, parallel contract every such model must also define correctly, with no interface
  currently enforcing either. A small `Workflowable` interface is a reasonable near-term follow-up
  once a second real consumer exists.
- Reopening a Rejected request to Draft is real but deliberately narrow: gated to `overriders`
  only, and the UI never shows it as a standard action (matching the Action Buttons spec's
  "Rejected: read-only, View Rejection Reason" for everyone else) -- it exists as an explicit
  override path, reachable but not advertised.
- The three config lists (`approvers`/`processors`/`overriders`) are currently global, not
  per-module -- if a future module needs genuinely different approvers than Material Request, this
  file's shape will need a `per_module` override key added, which is a compatible extension, not a
  rewrite.
