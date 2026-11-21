# Roadmap

**IOMS — Industrial Operations Platform** · updated 2026-11-21 (v2.144.0, **Stable**, running in production)

This file says what IOMS **is today**, what is **being built**, and what is **planned** — kept short
so it can be used for sales material without overstating the product. The historical v1–v2 roadmap
(delivered sections and old near-term lists) moved to `docs/ROADMAP-HISTORY-v1-v2.md`; release
history lives in `config/ioms.php` (`version_history`). `docs/kb/Requirements Register.md` remains
authoritative for the state of individual work items.

> [!IMPORTANT]
> **Only the "Available" section may be presented to customers as product capability.**
> "In development" and "Planned" are direction, not promises — never sold, never shown as if built.

---

## Guiding principles

1. **Additive only.** New capability arrives as new tables/columns and new pages, never as a rewrite.
2. **Preserve data.** Migrations never drop or reset existing data.
3. **Keep the UI lightweight.** Reuse the existing card/table/dialog patterns; no redesigns.
4. **One piece of information, one module.** Data is never re-entered across modules.
5. **No hardcoded master data.** Anything that could change is a configurable table, not a code constant.
6. **One standard product.** No per-customer development; every improvement ships to every customer.

---

## ✅ Available (sold today)

### Plans (public catalogue)

| Plan | Monthly | Yearly | Includes |
|---|---|---|---|
| Starter | Rp189.000 | Rp2.268.000 (pay 12, access 13 — **first year**) | HSE · 3 users · 1 operating unit · 50 PTW/month |
| Professional | Rp555.000 | Rp6.660.000 (pay 12, access 13 — **first year**) | + Warehouse Logistics · 10 users · 2 units · 200 PTW/month |
| Business | Rp1.249.000 | Rp14.988.000 (pay 12, access 14 — **first year**) | + People / HRD + Management · 25 users · 4 units · 500 PTW/month |

Add-ons: additional user Rp50.000/month; My Work users in packs of 10 (Rp100.000/month).
Annual benefits apply to the **first yearly payment only**; later years are 12 months paid for 12
months unless a promotion is granted deliberately. **Enterprise is not offered** (retired, inactive).

### Workspaces and modules

- **Health, Safety & Environment** — Incident Management, HSE Investigation, Safety Observation,
  Digital Checklist / HSE Inspection, TBM, CAPA; Permit to Work with Gas Test and LOTO; JSA; HIRADC;
  PPE Management; Contractor and Visitor Management; Waste Management; Man-Hour; Material Request;
  Safety Equipment & Compliance; Regulations & Standards; Document Control; HSE KPI.
- **Warehouse Logistics** — Material Request, Warehouse, Item Master, Inventory, Goods Receipt,
  Stock Out / Transfer / Adjustment, Stock Movement History.
- **People / HRD** — Employees (import from Excel, CSV, Word, PDF), Man-Hour, Leave, Employee Cases,
  Shift & Roster, Training & Competency.
- **Management** — Company KPI, HSE Performance, Workforce, Logistics & Inventory, Outstanding Actions.
- **Reports** — Analytics, Report Center, Document Library (PDF/Excel export).
- **Admin Space** — users and roles, operating units, approval flows, audit logs, branding, subscription.

### Platform

My Work (mobile field workspace for foremen/supervisors/operators) · configurable Approval Flow ·
Notification Center · global calendar and search · document numbering · installable web app (PWA,
online only by design) · light and dark theme · multi operating unit · self-service subscription with
Duitku payments (activation only from a verified provider callback) · read-only access to history
when a subscription lapses.

### Security and data (what is true today)

Tenant-isolated data access · private document storage · audit logs for tenant admins · server in
Jakarta (Tencent Cloud) · nightly backups kept 14 days with an automated restore check.

---

## 🛠️ In development / next

| Item | Why | Status |
|---|---|---|
| Dashboard enrichment for **Warehouse Logistics, Management, People / HRD** | Founder, 21 Nov 2026: these dashboards need more capability; competitor research first | Research (TICorp competitor crawler) |
| Off-site backup copy | Backups currently live only on the production server | Planned — bucket not yet chosen |
| Two-factor authentication | Account security | Not implemented (fields reserved in Settings) |

## 🧭 Planned (direction, not committed dates)

- Proactive reminders: PPE replacement, certificate/competency expiry, permit expiry.
- Field-level audit (before/after values) on top of today's activity log.
- HR items still disabled in navigation: Recruitment, Performance, HR KPI, HR Documents, HR Reports.
- Daily Report sharing (copy, PDF, WhatsApp link).

## 🧱 In the codebase, not offered

Projects, Procurement, Asset Management, Maintenance, Quality Control, Work Orders, Daily Reports and
the Finance placeholder exist as internal or legacy code paths. They are **not part of any plan** and
must not be presented as product capability until they are deliberately productised.

## Explicitly out of scope

- Full ERP features (payroll, accounting, general procurement suites).
- Offline mode (decision recorded in ADR 037).
- Per-customer custom development.
- Certifications (e.g. ISO 27001) are **not held** and must not be claimed.
