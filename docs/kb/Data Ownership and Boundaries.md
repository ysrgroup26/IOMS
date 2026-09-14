---
title: Data Ownership and Boundaries
type: reference
updated: 2026-09-14
tags: [kb/architecture, kb/security]
---

# Data Ownership and Boundaries

Who owns a row, who may reach it, and what actually enforces that. **Authoritative source:
`ARCHITECTURE.md` §Multi-tenant / company-scoping model and §Authorization.** This note is the map
and the reasoning; the mechanics live there.

---

## The one sentence version

Every record belongs to exactly one **Operating Unit** (`company_id`), every operating unit belongs
to exactly one **Organization** (`tenant_id`), and isolation is enforced by **global scopes on the
models** rather than by each query remembering to filter.

That last clause is the whole design. Convention failed; structure did not.

---

## The scopes

Five global scopes, in `app/Models/Scopes/`:

| Scope | Applied to | Effect |
|---|---|---|
| `TenantScope` | `Company` | Fail-closed. **With no resolved tenant it returns nothing**, not everything. |
| `CompanyOwnedScope` | Any model using `BelongsToCompany` (**39 models**) | Restricts to operating units inside the current tenant. |
| `BelongsToCompanyThrough` | Tables one join away from their owner (**26 models**) | Ownership resolved *through* a declared relation, so the parent's scope decides visibility and the rule is stated once. |
| `UserTenantScope` | `User` | A tenant never lists another customer's staff. |
| `CompanySettingScope` | `CompanySetting` | Two-tier: a tenant's own value, falling back to a shared default. |
| `CompanyAuthorizationScope` | Per-operating-unit authorization (v2.53.0) | Narrows within an organization where a user is granted only some units. |

### Adding a company-owned table

Put `company_id` on it **NOT NULL**, add `BelongsToCompany`, and stop thinking about isolation.

`TransitiveTenantIsolationTest` asserts that **every model is either isolated or explicitly declared
global reference data with a reason**. A new model cannot be added without that decision being made
— which is precisely the failure the test exists to prevent.

> [!warning] A null owner is not a shared row
> `companyScopeAllowsGlobalRows()` exists for exactly three tables (KPI categories, numbering
> formats, numbering sequences) where null means "the built-in default". Do not reach for it to make
> an ordinary table's orphan rows visible. **A row nobody owns must not become a row everybody
> sees.**

---

## Middleware order, and why it is load-bearing

The `web` group runs in this order, and two adjacencies are deliberate:

```
StartSession → ResolveTenant → SubstituteBindings → HandleInertiaRequests
             → IdentifyTenant → EnforceTenantEntitlement → RestrictDemoTenant
             → RestrictDepartmentAccess → AddLinkHeadersForPreloadedAssets
```

- **`ResolveTenant` must precede `SubstituteBindings`.** Route-model binding looks records up by id;
  if it runs before the tenant exists, every implicit binding in the application resolves with no
  tenant in context. This was a real defect fixed in v2.62.0 and is now asserted by a test in both
  directions.
- **Entitlement is checked before department scope.** A tenant-wide block should never depend on
  which department a route happens to belong to.

| Middleware | Question it answers |
|---|---|
| `ResolveTenant` | Which organization is this request for? |
| `EnforceTenantEntitlement` | Does this organization's plan include this workspace at all? |
| `RestrictDemoTenant` | Is this the Sandbox, where writes are limited? |
| `RestrictDepartmentAccess` | May this user reach this department's routes? |
| `RestrictPlatformAdminFromTenantRoutes` | Keeps the IOMS operator out of customer surfaces |

---

## Authorization: the live path is still the `role` column

This is the most commonly misread part of the codebase, so it is stated flatly:

- **`spatie/laravel-permission` is installed**, tenant-scoped, seeded, and editable from
  Settings → Roles & Permissions.
- **No controller uses it for authorization.** The live path is the `role` column plus
  `isX()` / `canX()` methods on `App\Models\User`.
- The UI says so rather than implying otherwise.

That is a deliberate, recorded position — ADR [[008-tenancy-foundation|008]] and ADR
[[017-dynamic-role-management|017]] — not an unfinished migration to tidy up opportunistically.
Migrating it is a real project; see [[Requirements Register]].

### Where permission decisions actually live

| Mechanism | Used for |
|---|---|
| `User::canManageX()` | Module-level operational rights |
| `config/workflow.php` → `approvers` / `processors` / `overriders` | Workflow authority, kept out of controllers so segregation of duties is reviewable in one file |
| Route middleware (`role:…`) | Coarse gating — **handle with care**, see below |
| `assertInCurrentTenant()` in controllers | Belt-and-braces re-check on route-bound records |

> [!danger] Route groups have caused a real outage
> Material Request and PPE Replacement routes were once accidentally nested inside a
> `role:super_admin` group, silently locking out every non-admin user. `CONVENTIONS.md` records it
> twice (v1.11.x, v2.14.0, v2.19.0). **Adding a route near an existing group is not a safe edit —
> check which group it lands in.**

### Segregation of duties

Authority is deliberately split so one person cannot both request and approve:

- A Procurement officer creates a PR but **never** automatically gains approval authority.
- A requester cannot approve their own Material Request, and (v2.69.0) cannot hold their own
  approved request for consolidation either — that is a buying decision, not an approval one.
- Override (`overriders`) is a deliberately shorter list than approval.

---

## Confidentiality inside a tenant

Isolation between customers is not the only boundary. **Employee Cases** (v2.69.0) are gated more
narrowly than the rest of HR — HR and Company Admin only, with `isManager()` explicitly absent —
because "can view the employee list" must not silently become "can read their disciplinary history".

The enforcement detail generalises: **an Inertia page ships its props to the browser whether or not
a component renders them**, so the authorization happens before serialization in the controller, not
by hiding a card in React. ADR [[031-employee-cases|031]].

---

See also: [[Security Decisions and Lessons]] · [[Pricing Plans and Entitlements]] · [[Architecture Map]]
