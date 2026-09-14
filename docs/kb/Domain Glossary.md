---
title: Domain Glossary
type: reference
updated: 2026-09-14
tags: [kb/domain]
---

# Domain Glossary

What a word means *in IOMS*. Several of these are pairs that sound interchangeable and are not —
those are the ones that cause real bugs, so they come first.

---

## The distinctions that matter

### Organization → Operating Unit → Department

The product's organizational spine, settled in v2.54.0. **Authoritative: `ARCHITECTURE.md` §The
organizational model.**

| Product term | Class / table | What it is |
|---|---|---|
| **Organization** | `Tenant` / `tenants` | The subscribing customer. One organization = one subscription. |
| **Operating Unit** | `Company` / `companies` | A yard, site or division *inside* that organization. |
| **Department** | `Department` / `departments` | Belongs to exactly one operating unit. |

> [!warning] "Company" is the class, "Operating Unit" is the word users see
> The two real units in the production data — GAJ and MTC — are **two operating units of one
> organization, not two customers**. The class keeps the name `Company` because renaming it would
> touch every foreign key, scope and controller in the schema to buy a word.

**Legal entity is an attribute of an operating unit, not a level above it.** Ownership of any record
is `company_id` and only `company_id`, so there is exactly one answer to "who does this belong to".

### Workspace vs Department

Two overlapping concepts that are kept in sync **by hand**, and the thing most likely to break as
the product grows:

- **Workspace** — an entry in `resources/js/lib/workspaces.js`, keyed by e.g. `hse`. Drives the
  navigation rail and what a plan grants. Twelve exist.
- **Department** (routing sense) — a route-prefix grouping in `config/departments.php`, used to
  confine a Department User to their own area.

Adding a route prefix means touching **both**. Forgetting `config/departments.php` is a recorded
pitfall in `CONVENTIONS.md` (v1.11.2). See ADR [[007-workspace-navigation|007]].

### Dashboard vs Overview

- **Dashboard** — the company-wide landing page. Belongs to no department.
- **Overview** — a single department's own landing page (`hse.dashboard`, `hr.dashboard` …).

The labels were deliberately made different in v1.10.2 so the distinction is impossible to miss.

### My Work vs PTW Access

- **My Work** — a *workspace*: the field user's landing surface.
- **PTW Access** — a *permission*: who may reach Permit To Work.

One is a place, one is a right. Settled in v2.52.0.

### BAST vs Goods Receipt

Two documents, not two names for one (v2.52.0). **Goods Receipt** records materials arriving against
a Purchase Order. **BAST** (*Berita Acara Serah Terima*) is a formal handover record.

### Equipment Master vs Equipment Register

**Master** is the catalogue of equipment *types*; **Register** is the individual physical items.
Settled in v2.53.0.

### Implemented vs Verified

A project-wide distinction, not a documentation nicety: *implemented* means the code exists and the
suite passes; *verified* means somebody ran it. See [[Verification Status]].

---

## Roles

| Role | Who |
|---|---|
| `super_admin` | Company Admin — full authority inside one organization |
| `hse` | Health, Safety & Environment operator |
| `hrd` | Human Resources operator |
| `manager` | Read-mostly oversight across Dashboard, Reports, Employees, Projects |
| `warehouse` | Logistics / warehouse / procurement operator |
| `platform_admin` | **The IOMS operator, not a customer role.** `tenant_id` is null. |

A **Department User** is not a role: it is any user with `department_key` set, which confines them
to one department's navigation. See [[Data Ownership and Boundaries]].

---

## Operational terms

These are kept in the original language wherever that is what appears on the customer's own
paperwork — the rule in [[Product Identity and Principles]].

### HSE

| Term | Meaning |
|---|---|
| **PTW** | Permit To Work — authorisation for hazardous work (hot work, confined space) |
| **HIRADC** | Hazard Identification, Risk Assessment and Determining Control |
| **JSA** | Job Safety Analysis |
| **LOTO** | Lockout/Tagout — energy isolation |
| **CAPA** | Corrective And Preventive Action |
| **TBM** | Toolbox Meeting — pre-work safety briefing |
| **PPE** / **APD** | Personal Protective Equipment (*Alat Pelindung Diri*) |
| **P3K** | First aid (*Pertolongan Pertama Pada Kecelakaan*) |
| **Gas Test** | Atmospheric reading taken against a permit |
| **LTI / LTIFR / TRIR** | Lost Time Injury and its frequency/rate measures — **shown as "not available" rather than fabricated** when the underlying data does not exist |
| **Man-Hour** | Recorded worked hours; feeds safety KPI denominators. Shared HR + HSE data |

### Procurement, logistics and quality

| Term | Meaning |
|---|---|
| **MR** | Material Request — a department's demand |
| **PR / FPB** | Purchase Requisition (*Formulir Permintaan Barang*) — Procurement's internal document |
| **RFQ** | Request For Quotation, sent to invited vendors |
| **PO** | Purchase Order |
| **GR / GRN** | Goods Receipt (Note) |
| **NCR** | Non-Conformance Report |
| **PPIC** | Production Planning and Inventory Control |
| **SPK** | *Surat Perintah Kerja* — Work Order |
| **Consolidating** | Approved demand **deliberately retained** so it can be bought with related demand. Not "on hold". See [[Operational Workflows]] |

### HR

| Term | Meaning |
|---|---|
| **SP 1 / SP 2 / SP 3** | *Surat Peringatan* — formal warning letters, each valid for a fixed term (typically six months) |
| **Employee Case** | The record of a concern raised about an employee. Deliberately *not* "disciplinary record" — a case may be dismissed with no action |
| **Standing** | The most severe disciplinary action still in force. **Derived, never stored** |
| **PKWTT / PKWT** | Permanent / fixed-term employment contract |
| **PKL** | *Praktik Kerja Lapangan* — field work placement |

### Platform

| Term | Meaning |
|---|---|
| **Module** | A toggleable capability, registered in the `modules` table |
| **Workspace grant** | A tenant's entitlement to a whole department |
| **Numbering format** | Per-tenant document numbering pattern, e.g. `MR-{YEAR}-{00001}` |
| **IOMS Sandbox** | A real, bounded demo tenant — read-mostly, not a separate code path |

---

See also: [[Data Ownership and Boundaries]] · [[Operational Workflows]] · [[Modules and Capabilities]]
