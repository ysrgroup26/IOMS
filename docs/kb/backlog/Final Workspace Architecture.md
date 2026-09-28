---
title: Final Workspace Architecture
type: backlog
status: COMPLETED (v2.84.0)
updated: 2026-09-28
tags: [kb/backlog, status/verified]
---

# Final Workspace Architecture

**Source:** owner decision, 2026-09-28 · **Board:** [[Project Board]] ·
**Decision:** ADR [[045-five-spaces-and-the-global-dashboard|045]]

## The shape IOMS actually has now

```
IOMS
├── Global Company Dashboard        Business only — NOT a workspace
│
├── HSE                    → HSE Overview
├── People / HRD           → People Overview
├── Logistics / Warehouse  → Logistics Overview
├── Management             → Management Overview
│
└── Admin Space            → Administration Overview
```

One platform, five spaces, **shared connected data**. Separate experiences, not separate databases:
an Employee is owned by People and referenced by HSE as a permit holder, by Management as headcount
and by Logistics as a requester.

## The distinctions, written down once

| | |
|---|---|
| Global Dashboard ≠ Overview | company-wide vs one workspace |
| Global Dashboard ≠ Management | fast snapshot vs analysis and trends |
| Management ≠ Admin Space | the business vs the account |
| Management ≠ Project Management | company performance vs project execution |
| Admin Space ≠ Master Admin | the customer's administration vs the operator's console |
| Workspace ≠ Permission | a business area vs what you may reach |
| Workspace Focus ≠ Authorization | where you start vs what you may open |

## The plan boundary

| | Global Dashboard | HSE | People / HRD | Logistics / Warehouse | Management |
|---|---|---|---|---|---|
| **Starter** | — | ✓ | — | — | — |
| **Professional** | — | ✓ | ✓ | — | — |
| **Business** | ✓ | ✓ | ✓ | ✓ | ✓ |

**Admin Space is on every plan** — administration is global-tier chrome, never sold. A plan without
the Global Dashboard is **redirected** to its own workspace Overview rather than refused: `/dashboard`
is the post-login landing route, and a denial there would greet a paying customer on every sign-in.

## What was corrected

| Problem | Correction |
|---|---|
| Management alone required a ROLE on top of the plan — a 403 for accounts the customer paid for | `canViewManagement()` deleted; one method answers workspace access for all four |
| Admin Space's sidebar merged Reports + Administration into one application dashboard | Three named spaces, each with exactly one navigation |
| Eleven departments in the registry, four of them sold | Seven retired from navigation, plans and marketing copy — code and data untouched |
| Enterprise's `'*'` grant kept retired departments alive | Narrowed to the four; its difference is capacity, not scope |
| An unprovisioned tenant was offered unsold departments in the switcher | Navigation intersected with `config('plans.operational')` |
| The company dashboard carried Procurement, Assets, Maintenance, Projects | Removed, not emptied — a zero there describes the product, not the company |
| HSE was second in the catalogue, so Professional landed in People | Order is the product ladder; HSE first |

## What is deliberately preserved

- **Tenant isolation, RBAC, authentication, subscription lifecycle, entitlement and billing.** No gate
  was weakened to make navigation work; the department-scope rule still confines a Department User,
  and the plan boundary is still enforced server-side on every route.
- **Every retired module's code, routes and data.** They are not customer-facing; they are not gone.
- **Pricing.** v2.82.0's catalogue, prices and allowances are untouched.

## Fixed in passing

`SupportTicket::generateReference()` used `max(id) + 1`, which is not the next reference and could
issue a duplicate against a UNIQUE index. It now reads the highest reference issued, and creation
retries on collision.

## Related

ADR [[045-five-spaces-and-the-global-dashboard|045]] ·
ADR [[044-management-workspace-and-admin-space|044]] ·
[[Management Workspace and Admin Space]] · [[Pricing Plans and Entitlements]] ·
[[Verification Status]]
