---
title: 045 — Five spaces, and the Global Company Dashboard
type: adr
status: accepted
decided: 2026-09-28
version: 2.84.0
tags: [adr, status/verified, navigation, entitlement, authorization, workspace, dashboard]
---

# ADR 045 — One platform, five spaces, shared data

## Status

Accepted, implemented and verified in **v2.84.0**. It corrects ADR
[[044-management-workspace-and-admin-space|044]], which built the right two capabilities on the wrong
shape.

## The shape

```
IOMS
├── Global Company Dashboard        Business only — not a workspace
│
├── HSE                    → HSE Overview
├── People / HRD           → People Overview
├── Logistics / Warehouse  → Logistics Overview
├── Management             → Management Overview
│
└── Admin Space            → Administration Overview
```

**Four operational workspaces, one administrative space, one company dashboard.** Separate
experiences over *shared, connected data* — separate navigation, not separate databases. An Employee
is owned by People and referenced by HSE as a permit holder, by Management as headcount and by
Logistics as a requester. Nothing is duplicated to make a workspace independent.

## What was wrong

ADR 044 shipped Management and Admin Space, and both were right to exist. The architecture around
them was not.

### 1. Management asked a question no other workspace asked

```
HSE         plan grant + department assignment
People      plan grant + department assignment
Logistics   plan grant + department assignment
Management  plan grant + department assignment + A ROLE ALLOW-LIST
```

`canViewManagement()` (tenant administrator or Manager) looked defensible in isolation and was wrong
for this product: it made one workspace behave unlike the other three, and it **returned 403 to
accounts the customer had paid for**. Diagnosed against real data — an HRD account on a tenant whose
plan includes Management was refused, and an HSE-assigned account was refused twice over (the role
gate here, and `RestrictDepartmentAccess` denying the `management` prefix before the controller ever
ran).

The predicate was **deleted, not relaxed**, so nothing can start depending on a second, weaker answer
to a question that now has one: `EntitlementService::userCanUseWorkspace()`.

### 2. The sidebar had a catch-all instead of a space

There were two states — "a department is active" and a fallback that merged **Reports +
Administration** into one list. So entering Admin Space showed Reports, Analytics and Report Center
beside Users, Roles and Billing: administration and cross-module reporting presented as one
undifferentiated application dashboard, which is precisely what Admin Space exists not to be.

There are now three named spaces (`admin`, `workspace`, `company`), each with exactly one navigation.

### 3. Eleven departments, four of them sold

Project Management, Procurement, Asset Management, Maintenance, Quality Control, Finance and a
standalone Warehouse shell were in the registry, in Enterprise's `'*'` grant, on the landing page and
in the public showcase. A product that lists eleven departments and opens four reads as unfinished
rather than focused.

## Decision 1 — The Global Company Dashboard is a Business capability, and it is not a workspace

It is the one thing IOMS sells by plan that is not a workspace, so it cannot be a workspace grant. It
lives in `config('plans.global_dashboard')` beside the scope lists it belongs with.

**Why Business.** A cross-workspace view is only meaningful with more than one operational area *and*
the tier that sells cross-functional visibility. Starter has one workspace, so a "company overview"
would be the HSE Overview with a different title — exactly the Dashboard-is-not-an-Overview confusion
this ADR exists to end. Professional has two, and summarising two areas is what their two Overviews
already do.

**A redirect, not a 403, and not an upsell page.** `/dashboard` is the post-login landing route and
the one pinned link in the header; refusing it would greet a paying Starter customer with a denial
every time they signed in, and a locked teaser page would do the same thing more slowly. They go to
the Overview of the workspace they actually work in. This is UX: nothing on that page is secret, and
every figure it renders is tenant-scoped and separately reachable from the workspace that owns it.

**It never shows what a plan does not have.** Procurement, Assets and Maintenance left the attention
strip with their workspaces — the queries were real, the departments are not customer-facing, and a
company snapshot carrying a number the customer cannot open is the clearest way to make a focused
product look unfinished. Project Portfolio, Upcoming Milestones and Recent Daily Reports were
**removed rather than emptied**: "No active projects" on a customer with no projects module describes
the product while appearing to describe the company.

## Decision 2 — Three questions, three surfaces, and Management is the analytical one

| Surface | Question | Shape |
|---|---|---|
| **Global Dashboard** | What is happening in the company right now? | Fast, concise, cross-workspace |
| **Workspace Overview** | What is happening in this workspace? | Operational, one domain |
| **Management** | How is the company doing, and what needs attention? | Trends, comparison, analysis |

Management still owns no data (ADR 044's rule, unchanged) and still invents no metric.

## Decision 3 — Focus is context; authorization is authorization

```
ROLE / PERMISSION   what the user is ALLOWED to reach
WORKSPACE           a business area
FOCUS               where they START
```

`users.workspace_focus` is read by the navigation layer and by nothing else. **A single authorized
workspace is now an implicit focus** — a Starter customer has one, so "no focus chosen" and "focused
on HSE" describe the same situation, and treating them differently dropped that customer into an
empty company-navigation state the moment they opened Reports. Admin Space can never be a focus: it
is not an operational workspace, and the validator rejects it.

`authorizedDepartmentKeys()` is now intersected with `config('plans.operational')`. The reason is
subtle and was a live defect: `grantedWorkspaceKeys()` deliberately treats "no grant rows recorded"
as UNRESTRICTED — the v2.13.0 safety net that makes enforcement safe to switch on, and still correct
for ACCESS. It is wrong for NAVIGATION, and an unprovisioned tenant was offered Procurement,
Maintenance and Quality Control in the switcher.

## Decision 4 — Seven workspaces retired from the customer, and nothing was deleted

What was removed is **navigation entries and entitlements**. Routes, controllers, models, migrations
and records are untouched, and `config/departments.php` still maps every retired prefix to its owning
department, so `RestrictDepartmentAccess` and `EnforceTenantEntitlement` gate them exactly as before.

**Enterprise stopped being `'*'`.** A retired plan must not be the one place a department nobody sells
can still be granted — that is how those seven stayed reachable after the catalogue narrowed. It now
grants what Business grants; its remaining difference is capacity, not scope.

**Existing tenants are migrated.** A grant row is what the entitlement layer reads, so a customer
provisioned before this release would have kept reaching withdrawn departments while a new one would
not — two customers on one plan with different products. The migration withdraws those grants, and
`down()` restores each tenant's from its own package rather than re-granting the retired list
wholesale.

Warehouse is the one that did not retire: its real capability (Item Master, Inventory, Goods Receipt,
Stock Movement) always lived inside Logistics, and the tier is now sold and labelled as the one
domain it always was — **Logistics / Warehouse**.

## What was deliberately not done

- **No new permission system.** One method answers workspace access, and it composes the two gates
  that already existed.
- **No pricing change.** The catalogue, prices and allowances of v2.82.0 are untouched. Management
  stays inside Business; Admin Space is not sold at all.
- **No second navigation registry.** `config/workspaces.php` holds one route per workspace — the
  server has to answer "where does this person land" long before any JavaScript exists, and
  hardcoding `hse.dashboard` in two controllers is how halves drift.
- **No website redesign.** The landing page and showcase stopped *naming* retired domains, which is
  a correction, not a redesign.

## Consequences

- Five test files carried `route('dashboard')` as a stand-in for "any authenticated page". They now
  use Work Center, which is what they meant.
- The catalogue's `sort_order` is load-bearing: it decides where an account lands with several
  workspaces and no focus. HSE was behind People, so a Professional customer was sent to the wrong
  half of their product on every sign-in. HSE is now first, which is also the plan ladder.
- `SupportTicket::generateReference()` was fixed in passing: `max(id) + 1` is not the next reference,
  and after one rolled-back insert every ticket carried a reference lower than its own id. It now
  reads the highest reference issued, and creation retries on collision.

## Related

ADR [[044-management-workspace-and-admin-space|044]] (what this corrects) ·
ADR [[043-pricing-included-users-and-add-ons|043]] (the catalogue this bounds) ·
ADR [[007-workspace-navigation|007]] (the navigation model) ·
ADR [[040-master-admin-is-an-operations-console|040]] (Master Admin, which Admin Space is not) ·
[[Project Board]] · [[Verification Status]]
