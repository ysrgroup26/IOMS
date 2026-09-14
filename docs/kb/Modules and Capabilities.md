---
title: Modules and Capabilities
type: index
updated: 2026-09-14
tags: [kb/modules]
---

# Modules and Capabilities

What IOMS actually does, grouped by the workspace a user reaches it through.

**Authoritative source: `MODULES.md`** — it holds each module's fields, behaviour and
module-specific business rules. This note exists to answer "what exists, where does it live, and is
it real?" without reading 2,300 lines. Read the `MODULES.md` section before touching a module you
have not worked in.

Status here means: **Built** = shipped and in use · **Partial** = foundation exists, named gaps
remain · **Placeholder** = advertised in navigation, no backing capability.

---

## Health, Safety & Environment (`hse`)

The deepest module set in the product, and most of what Starter sells.

| Module | Status | Notes |
|---|---|---|
| Incident Management + Investigation | Built | Feeds CAPA |
| Safety Observation | Built | One-click hazard / near-miss reporting |
| CAPA (Corrective Actions) | Built | **Polymorphic** — reusable from any source module |
| HIRADC / Risk Assessment | Built | Risk matrix; JSON line items |
| JSA (Job Safety Analysis) | Built | |
| Permit To Work | Built | The most complex workflow; has its own field experience and access permission |
| Gas Test | Built | Readings recorded against permits |
| LOTO | Built | |
| TBM (Toolbox Meeting) | Built | |
| HSE Inspection + Digital Checklist | Built | LSA / FFA / PPE categories |
| PPE Management | Built | Distribution, replacement requests, expiry |
| Waste Management | Built | B3 / non-B3, storage, disposal |
| Contractor Management | Built | Register, workers, documents |
| Visitor Management | Built | Site access register |
| Document Control | Built | Controlled documents with version history |
| Regulations & Standards Register | Built | Reference seeder ships **not wired into `DatabaseSeeder`** — a compliance register nobody reviewed that *looks* reviewed is worse than an empty one |
| Safety Equipment / HSE Materials / P3K | Built | |
| HSE KPI Input | Built | Fully data-driven from `KpiCategory` rows |
| Man-Hour | Built | Shared HR + HSE data, one table |

## Human Resources (`hr`)

| Module | Status | Notes |
|---|---|---|
| Employees | Built | Core master data. Import with a preview step and smart master-data detection |
| **Employee Cases** | Built (v2.69.0) | Employee relations and discipline. Standing is derived. ADR [[031-employee-cases\|031]] |
| Leave | Built | Uses the Approval Engine |
| Shift & Roster | Built | Patterns, assignments |
| Training & Competency | Built | Certificate expiry visible before it lapses |
| Man-Hour | Built | Shared with HSE |
| Intern / PKL detail | Built | One-to-one extension of Employee, not a second employee table |
| Recruitment · Performance · HR KPI · Documents · Reports | **Placeholder** | `disabled: true` navigation entries |

## Logistics / PPIC (`logistics`) and Warehouse (`warehouse`)

| Module | Status | Notes |
|---|---|---|
| Material Request | Built | The reference implementation of the engine pattern. Lifecycle extended v2.69.0 — see [[Operational Workflows]] |
| Item Master | Built | |
| Inventory / Stock | Built | Stock levels per warehouse |
| Goods Receipt | Built | Matched against Purchase Orders |
| Stock Out / Transfer / Adjustment | Built | |
| Stock Movement History | Built | Full audit trail |
| BAST / Serah Terima | Built | A handover document, **not** a second Goods Receipt |

## Procurement (`procurement`)

A genuine cross-department engine — not owned by any requesting department.

| Module | Status | Notes |
|---|---|---|
| Vendor / Supplier master | Built | Qualification status on the vendor row; **no accounting integration by design** |
| Purchase Requisition (FPB) | Built | Now sources **many** Material Requests. ADR [[030-material-request-lifecycle-and-demand-consolidation\|030]] |
| RFQ + Vendor Quotation | Built | Comparison is computed at request time, never a stored table. **The system never auto-picks the cheapest vendor** |
| Purchase Order | Built | Delivery status reached automatically from real received quantities |
| Vendor Performance | Built | Computed live; a blank rate means "nothing to measure yet", not zero |

## Project Management (`project-management`)

| Module | Status | Notes |
|---|---|---|
| Projects + Manpower + Timeline | Built | Projects act as an optional container for other modules |
| Milestones | Built | |
| Daily Reports | Built | |
| Tasks | Built | Reachable via Work Center; still no sidebar entry of its own |
| QC Inspection Request · NCR | Built | Quality Control workspace |

## Asset Management · Maintenance · Quality Control

| Module | Status | Notes |
|---|---|---|
| Asset register, assignment, lifecycle | Built | |
| Maintenance Requests + Work Orders (SPK) | Built | Includes spare-part consumption |
| Inspection Requests, NCR, Controlled Documents | Built | |

## Finance (`finance`)

**Placeholder.** The workspace exists in the registry and routes to a Coming Soon page. There is no
finance capability.

## Cross-cutting

| Capability | Status | Notes |
|---|---|---|
| Global Dashboard + department Overviews | Built | Ten department overviews |
| Work Center | Built | Cross-department action centre |
| Global Search | Built | ADR [[014-global-search-generalization\|014]] |
| Activity Center / audit log viewer | Built | ADR [[013-activity-center\|013]] |
| Notification Center | Built | Event-driven, not seeded. ADR [[011-notification-center\|011]] |
| Report Center + scheduled reports | Built | ADR [[020-report-center\|020]] |
| Analytics | Built | ADR [[019-analytics-framework\|019]] |
| Global Calendar | Built | One calendar engine, many consumers |
| Settings (17 tabs) | Built | A 2,500-line monolith — decomposition is [[Requirements Register\|deferred]] |
| Public marketing site + self-service onboarding | Built | Landing, pricing, checkout, sandbox |
| Subscription, invoicing, payments | Built | **Requires external gateway configuration to go live** — see [[Known Issues and Limitations]] |
| Platform Super Admin surface | Built | `/platform/*`, entirely separate from tenant surfaces |

---

See also: [[Operational Workflows]] · [[Architecture Map]] · [[Requirements Register]]
