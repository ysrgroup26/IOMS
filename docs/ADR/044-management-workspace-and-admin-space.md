---
title: 044 — Management workspace, Admin Space, and workspace focus
type: adr
status: accepted
decided: 2026-09-28
version: 2.83.0
tags: [adr, status/verified, navigation, entitlement, authorization, management, administration]
---

# ADR 044 — Three questions, three places to ask them

## Status

Accepted, implemented and verified in **v2.83.0**. It closes the open question ADR
[[043-pricing-included-users-and-add-ons|043]] left for the owner.

## The question 043 could not answer

The approved Business scope reads *HSE + People/HRD + Logistics/Warehouse + **Management***. The
first three named shipped department workspaces. The fourth named nothing. v2.82.0 refused to invent
a mapping — granting a workspace key that does not exist is the v2.58.0 empty-sidebar defect, and
pointing "Management" at the existing Reports layer would have sold every plan's own chrome as a
Business feature.

The answer was to **build the capability**, not to drop the word.

## Decision 1 — Management is a real department workspace, and it owns nothing

`management` is a department-tier row in the `workspaces` catalogue, granted by `config/plans.php` to
Business and above. Being an ordinary workspace is the point: entitlement, navigation, the route
gate and the operator's own grant UI all work on it with no second mechanism.

What is unusual is that it holds **no records of its own**. There is no `management_*` table, no
rollup cache and no write route in the entire workspace. `ManagementInsightsService` reads each
module's own table through that module's own model, so a management figure cannot drift from the
operational record it describes — it *is* that record, counted.

### The boundary that decides what belongs here

Three questions, deliberately kept apart:

| Surface | Question |
|---|---|
| **Dashboard** | What is happening in IOMS right now? |
| **Department Overview** | What is happening in this department? |
| **Management** | How is the company doing, and what needs management's attention? |

A count of today's open permits is operational and already lives on the HSE Overview. A twelve-month
incident trend set beside the departments it came from is not, and had nowhere to live at all.

### Nothing is invented, and that is enforced by shape

Where IOMS has no data for a metric a manager might reasonably expect, the metric is **absent**, not
estimated:

- **No inventory value.** `items` carries no unit cost. A number here would be fabricated.
- **No TRIR or safety index.** Both need exposure hours across contractors as well as employees, and
  a rate from a partial denominator is worse than no rate, because it gets quoted.
- **No compliance score.** IOMS records which documents exist and what state each is in; it does not
  know which documents a company is legally *required* to hold. There is no denominator.

Every section reports its own `available` flag, so a page can say **"this module has never been
used"** rather than showing a confident zero — the v2.39.0 lesson ("Great job!" on an empty
database) applied to a whole workspace. `days_since_last_incident` is `null`, never `0`: "there has
never been one" and "there was one today" must not be the same value.

### Two gates, both server-side, answering different questions

```
ENTITLEMENT   does this ORGANIZATION's plan include Management?
CAPABILITY    is this PERSON management?   (User::canViewManagement())
```

`canViewManagement()` is tenant administrator **or** Manager. HSE is deliberately excluded:
`isAdmin()` unions Super Admin with HSE for operational CRUD, which is a different question. An HSE
supervisor owning permits and incidents is not thereby entitled to company-wide workforce and
logistics performance — which is why this is its own predicate rather than a reuse of `isAdmin()`.

Hiding the navigation entry is a courtesy; `ManagementController` is the boundary, and the tests ask
for every URL directly.

## Decision 2 — Admin Space is the customer's administration, and is not Master Admin

Two administrative surfaces exist, and they belong to different companies:

| | Owner | Gate | Lives at |
|---|---|---|---|
| **Master Admin** | the IOMS operator | `role:platform_admin`, `tenant_id IS NULL` | `/platform` |
| **Admin Space** | the customer | `isTenantAdmin()` (requires a tenant) | `/admin` + existing Settings |

`isTenantAdmin()` is `isSuperAdmin() && tenant_id !== null` rather than the role check alone,
specifically so an operator account cannot become an administrator of every customer by holding an
elevated role string. `isPlatformAdmin()` is not unioned into any gate here.

**Why it exists.** An HSE supervisor should never have to scroll past subscription settings and user
administration to reach a permit. Administration is not a step in anybody's operational day.

**What it added: one route.** Users, Roles, Operating Units, Departments, Positions, Modules, Audit
Log and Billing are reached through the routes that already serve them, with their controllers,
validation and authorization untouched. Reframing administration into a space is a navigation and
orientation change, not a re-implementation of seven forms. No bookmark broke.

**The `administration` workspace key did not move**, only its label. Every grant row, prefix map and
entitlement check is keyed on it; renaming the key would be a migration of the authorization model
dressed up as a copy edit — the same reasoning that keeps `hr` as `hr` while its label reads "Human
Resources".

### HSE keeps what it already had

`canAccessAdminSpace()` is deliberately wider than `isTenantAdmin()`: it is *"holds some genuine
administration capability today"*. HSE has managed Departments and Positions from Settings since
v1.x, and gating the space on `isTenantAdmin()` alone would have **removed a capability an existing
customer uses**. What HSE can *do* inside is unchanged — the Administration Overview itself requires
`isTenantAdmin()`, and every other area keeps the gate it already had. Entering a space is not a
permission; each page inside still asks its own question.

That distinction needed a fourth navigation gate: `adminOnly` means `is_admin`, which is Super Admin
**or** HSE, so the rows about capacity, billing and every account's security posture carry
`tenantAdminOnly` instead.

## Decision 3 — Workspace focus is a preference, and must never be able to act as a permission

Three concepts that had been two:

```
ROLE / PERMISSION   what the user is ALLOWED to access
WORKSPACE           a business area
FOCUS               where they START
```

`users.workspace_focus` is nullable and **null means All Workspaces** — what every account had
before the column existed. Nothing in the authorization chain reads it: no middleware, no policy, no
capability method. The switcher appears only when there is more than one authorized workspace *and*
no focus is set; a focused or single-workspace account gets a plain label, because a control that
opens onto one option implies there is somewhere else to go.

It is still **validated against the account's real authorization**, for a reason that is not
security: a focus naming a workspace the person cannot open would strand them on a 403 at every
sign-in. And `effectiveWorkspaceFocus()` degrades a stale focus to All Workspaces rather than serving
it, so a downgrade, a deactivated workspace or a reassignment cannot lock anybody out. **A focus can
only fail in the safe direction.** The stored value is deliberately kept through a downgrade, so an
upgrade restores the preference.

It lives on the person's own Account page rather than in user administration, deliberately: put it in
an administrator's screen and it reads as an access control, and somebody will use it as one.

## What was deliberately not done

- **No new permission system.** Focus is one nullable column; Management and Admin Space use the
  existing role predicates and the existing entitlement chain.
- **No pricing change.** Management is inside Business, not an add-on. Admin Space is not sold at
  all — administration is global tier, so no plan may withhold it.
- **No duplicated dashboards.** Management links to the department surfaces; it does not restate
  them.
- **No Management department assignment for people.** `management` is a workspace, not a
  `department_key` anybody is expected to hold — the capability is a role question.

## Consequences

- A Business tenant provisioned **before** this release holds an explicit grant list, which
  `grantedWorkspaceKeys()` reads as exhaustive. The migration therefore backfills `management` for
  every tenant whose own package grants it, and leaves tenants with no grant rows alone (an empty
  list means "not yet restricted", and writing one row would turn such a tenant into an explicitly
  restricted one holding a single workspace).
- `ApprovedCatalogue::SCOPE['business']` gains `management`, and the public plan ladder now reads
  *Logistics / PPIC, Warehouse, Management*.
- A test fixture that synced `Workspace::pluck('id')` against an **empty** catalogue used to grant
  nothing — which reads as "unrestricted" — and started failing the moment a migration put one row
  in the table. It now seeds the catalogue, which is what its own comment always claimed.

## Related

ADR [[043-pricing-included-users-and-add-ons|043]] (the open question this closes) ·
ADR [[007-workspace-navigation|007]] (the navigation model this extends) ·
ADR [[038-account-organization-subscription|038]] (account vs organization vs subscription) ·
ADR [[040-master-admin-is-an-operations-console|040]] (Master Admin, which Admin Space is not) ·
[[Project Board]] · [[Verification Status]]
