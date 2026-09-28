---
title: Management Workspace and Admin Space
type: backlog
status: COMPLETED (v2.83.0) — shape corrected in v2.84.0
updated: 2026-09-28
tags: [kb/backlog, status/verified]
---

# Management Workspace and Admin Space

**Source:** owner decision, 2026-09-28 · **Board:** [[Project Board]] ·
**Decision:** ADR [[044-management-workspace-and-admin-space|044]]

## What this closes

ADR [[043-pricing-included-users-and-add-ons|043]] shipped the approved three-tier catalogue and
ended with one item the product could not honour: the Business scope reads *HSE + People/HRD +
Logistics/Warehouse + **Management***, and there was no such workspace. Nothing was invented at the
time, because granting a key that does not exist is the v2.58.0 empty-sidebar defect.

**The capability is now built.** `management` is a real department-tier workspace granted by
`config/plans.php` to Business and above, and `ApprovedCatalogue::SCOPE['business']` names it.

## Three questions, three places to ask them

| Surface | Question |
|---|---|
| Dashboard | What is happening in IOMS right now? |
| Department Overview | What is happening in this department? |
| **Management** | How is the company doing, and what needs management's attention? |

Management **aggregates and owns nothing**: no table, no cache, no write route. Every figure is read
from the module that holds it, so a management number cannot drift from the record it describes.

## What was built

| Area | Detail |
|---|---|
| Management routes | `management.overview` / `.kpi` / `.hse` / `.workforce` / `.logistics` / `.actions` — all GET |
| Data source | `ManagementInsightsService`, scoped through `DashboardStatsService::resolveCompanyIds()` |
| Entitlement | `management` in `config/plans.php` → `packages` grant → `EntitlementService` → route gate |
| Capability | ~~`User::canViewManagement()`~~ — **superseded in v2.84.0**. The role allow-list made Management the only workspace asking a question the others do not, and refused accounts the customer had paid for. Access is now `EntitlementService::userCanUseWorkspace()`: plan grant + department assignment, for all four workspaces alike. ADR [[045-five-spaces-and-the-global-dashboard\|045]] |
| Admin Space | `admin.index` (new) plus the existing Settings tabs, Activity Center and Billing, reframed |
| Admin capability | `User::isTenantAdmin()` (requires a tenant) and the wider `canAccessAdminSpace()` |
| Workspace focus | `users.workspace_focus`, nullable; null = All Workspaces; read only by navigation |
| Migration | Catalogue row, the focus column, and a **backfill** of `management` for existing Business tenants |

## Nothing is invented

Deliberately absent, and documented rather than estimated: **inventory value** (no unit cost on
`items`), **TRIR / safety index** (no reliable exposure denominator across contractors), and any
**compliance score** (IOMS knows which documents exist, not which are required). Each section
reports its own `available` flag so a page can say *"this module has never been used"* instead of
showing a confident zero, and `days_since_last_incident` is `null` rather than `0`.

## What is deliberately preserved

- **Master Admin and Admin Space stay separate.** Different route, controller, permission and owner.
  `isTenantAdmin()` requires a tenant, so an operator never becomes a customer's administrator.
- **HSE keeps Departments and Positions.** Reframing administration must not remove a capability an
  existing customer already uses.
- **Focus never grants or revokes.** No middleware, policy or capability method reads the column, a
  stale focus degrades to All Workspaces, and the switcher still lists only authorized workspaces.
- **Pricing is unchanged.** Management is inside Business; Admin Space is not sold at all.
- **One route added.** Every administrative form keeps its existing route and authorization.

## Related

ADR [[044-management-workspace-and-admin-space|044]] · ADR
[[043-pricing-included-users-and-add-ons|043]] · ADR [[007-workspace-navigation|007]] ·
[[Pricing Plans and Entitlements]] · [[Verification Status]]
