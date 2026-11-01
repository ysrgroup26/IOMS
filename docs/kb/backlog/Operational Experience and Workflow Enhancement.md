---
title: Operational Experience and Workflow Enhancement
type: backlog
status: AUDIT COMPLETE — roadmap proposed, awaiting the owner's decisions (D1–D12) before any implementation
updated: 2026-11-01
tags: [kb/backlog, status/planned]
---

# Operational Experience and Workflow Enhancement

**Source:** owner directive, 2026-11-01 — "IOMS Major Product Update: Operational Experience &
Workflow Enhancement". **Baseline:** production `2.140.0`, commit `d73680c`.
**Direction:** *Easy for the Workforce. Efficient for Supervisors. Powerful for Management.*
**Board:** [[Project Board]] · **Register:** [[Requirements Register]] · **Decisions:** [[Decision Register]]

This is the ONE master note for the directive. Every requirement is a row with its own status; nothing
in the brief is tracked anywhere else. Status words follow [[Working with This Knowledge Base]]:
`#status/planned` → `#status/in-progress` → `#status/implemented` → `#status/verified`.

> [!important] Stage 1 is an audit, not a build
> The brief's first deliverable is this audit and roadmap. **Nothing below has been implemented.**
> Every finding names the file it was read from, so it can be re-checked rather than trusted.
> Items marked **OPEN** are product decisions the brief itself says must not be assumed approved.

---

## 1. Scope (from the brief)

| # | Area | The brief asks for |
|---|---|---|
| S1 | Role-based dashboards | Role- and department-aware, simple, actionable, consistent across modules |
| S2 | My Work | A **cross-department** personal workspace (assignments, tasks, responsibilities, status, priority, deadlines), permission-aware, mobile-first |
| S3 | Work Order | Clear lifecycle, assignment, urgency, authorization and approval; the bilingual, configurable WO format; integration with PTW |
| S4 | Global Custom Form Builder | Shared across PTW, HSE, HRD and Warehouse & Logistics; Company Admin or authorised users; validation and business rules preserved |
| S5 | Visual Approval Flow | Visual configuration; 3, 4 or more stages; an "Approval Creator" and a final **Verified** stage that activates the permit automatically; role-based authorization and history |
| S6 | PTW enhancement | WO authorization precedes PTW; validity, expiry and automatic closure; safety gates kept |
| S7 | SIMOPS | Detect simultaneous activities, flag conflicts, link WO and PTW, support supervisors |
| S8 | Notifications | In-app; web and mobile push as primary channels; sound where supported; WhatsApp optional; assignment, approval, deadline, status and operational events |
| S9 | Live PTW Dashboard | Actionable live permit monitoring |
| S10 | Audit, versioning, tenant isolation | Audit trail, versioning of forms and records, approval and change history, strict isolation |

Constraints carried from the brief:
- standard SaaS, not per-customer software;
- the four core modules are kept;
- entitlements and role permissions are preserved;
- no safety control is traded for UI simplicity;
- Light and Midnight themes both stay;
- no duplicate systems and no needless dependencies;
- the landing page comes **after** the product.

---

## 2. What already exists — the audit

Read from the code on `main` at `120141b`. "Reuse" means the thing a new feature must be built on,
not beside.

### S1 — Dashboards: department-aware, not role-aware
- **Exists:**
  - One overview per sold workspace: `/hse/dashboard`, `/hr/dashboard`, `/logistics/dashboard`, `/management`.
  - The Business-only Global Dashboard `/dashboard` (ADR [[045-five-spaces-and-the-global-dashboard\|045]]).
  - Landing chosen by `EntitlementService::landingWorkspaceKey()`, from workspace focus, then department.
  - Work Center: "what needs my attention" across departments.
- **Gap:** the same overview is shown to an HSE officer, a supervisor and a manager. Role only gates
  the quick-action buttons (`HseDashboardController` lines ~305–320). Nothing on an overview is
  *personal* (mine, my team's, awaiting me).
- **Reuse:**
  - `WorkCenterService::pendingApprovalsFor()`, `myTasksFor()` and `actionAlertsFor()`, which are already per-person;
  - the KPI and StatCard system, with its zero-is-neutral rule (ADR [[081-one-meaning-one-colour-and-one-typeface-for-identifiers\|081]]);
  - live partial reload (ADR [[069-a-notification-arrives-without-being-asked-for\|069]]).

### S2 — My Work: an HSE tile launcher, not a work inbox
- **Exists:**
  - `/my-work`, served by `DashboardController::myWork()` ("Field Home");
  - the My Work account class: an employee's login (ADR [[062-a-my-work-account-is-an-employees-login\|062]]), quota-metered, bounded by `RestrictMyWorkUser::ALLOWED_PREFIXES` (`my-work`, `permits-to-work`, `tasks`, `employee-lookup`, …).
- **What it shows:** tiles. Create PTW, My PTW, Digital Checklist, Safety Observation and Report
  Incident: almost all HSE.
- **Gap:**
  - no list of *my* assignments, deadlines, approvals waiting on me, or my team's work;
  - nothing from HRD or Logistics;
  - no priority or deadline ordering.
- **Defect found:** "My PTW — *n* menunggu persetujuan" counts only `submitted`. A permit at
  `verified`, which is still awaiting approval (ADR 067), is not counted.
- **Blocker found — Tasks are unreachable for Department Users.** The `tasks` route prefix is in
  neither `config/departments.php` nor `RestrictDepartmentAccess::UNIVERSAL_PREFIXES`. Since v1.10.5,
  an unmapped prefix is **denied** to every Department User: an HSE officer gets 403 on `/tasks`, and
  observed it, while a My Work login is allowed through its allow-list. Tasks also have no sidebar
  entry (only Work Center reaches them). A cross-department My Work built on assignments needs this
  settled first — see **D1**.
- **Reuse:**
  - `Task`: priorities low to critical, statuses, `assignedTo`, `is_overdue`, `task_source`;
  - Work Center queries;
  - the Approval Engine's pending queue;
  - PTW `pendingStage()`;
  - Web Push.

### S3 — Work Order: exists, but in a department nobody can buy
- **Exists:**
  - `WorkOrder` (SPK): `draft → scheduled → in_progress → completed / cancelled`, an asset, a maintenance request, a technician, spare parts, and its number generator and preview.
  - A printable bilingual-titled document: `pdf/work-order.blade.php`, "Work Order / Surat Perintah Kerja".
- **Gap:**
  - **WO lives in the Maintenance department** (`config/departments.php`), which ADR 045 retired from sale. No plan grants it, so **no customer can reach Work Orders today**.
  - No priority/urgency field, no authorization or approval step, no assignment beyond one technician.
  - **No relation to PTW in either direction.** A permit has only a free-text `work_reference` and an optional project.
- **Not found in the repository:** the "previously discussed bilingual and configurable WO
  format". It is not in the docs, ADRs or config. **Input needed — D4.**

### S4 — Global Custom Form Builder: nothing general exists
- **Exists, and is the nearest precedent:**
  - `HseChecklistTemplate`: equipment-type checklists with typed items (Pass/Fail, reading with limits, date), a critical flag, required photos, and **server-side judging** (ADR [[078-one-motion-language-and-an-inspection-is-judged-from-its-checklist\|078]]). That is a small, proven form engine for one domain.
  - Also `DocumentTemplate` (letterheads) and `FieldMapping` (import mapping).
- **Gap:**
  - no shared form-definition model, no custom fields on any module, no versioned form definitions;
  - PTW types are a **hard-coded list of seven** (`PermitToWork::TYPES`), and its fields are fixed columns;
  - precautions are free text.
- **Risk:** Settings is a 2,500-line, 17-tab monolith whose split is deferred until "any substantial
  Settings work — do the split first" ([[Requirements Register]]). A form builder **is** that work.

### S5 — Approval: a real N-step engine exists, PTW does not use it
- **Exists:**
  - The **Approval Engine v2**: `approval_flows` and `approval_flow_steps` with `step_number`, `mode`, `approver_role` / `approver_user_id` and `escalate_after_hours`; `ApprovalEngine::start / decide / authorize`; hourly `approvals:escalate`; tenant-scoped flows; configured in Settings → Approval Flow (a form, not visual).
  - Used by Material Request, PPE Replacement Request and Leave.
  - PTW has its **own** two-stage service, `PtwWorkflowService` (`verify`, `approve`). The company configures who holds each stage, roles plus named people, with a job title per stage (ADR [[067-a-permit-is-verified-and-then-approved\|067]], [[068-a-job-title-is-configured-and-a-role-is-not-one\|068]]).
- **Conflict with the brief — OPEN, D2.** Today's PTW order is enforced by the state machine:
  `submitted → verified (HSE) → approved → active` (activation by hand). The brief describes approval
  stages **then** a final **Verified** that **activates automatically**. Reversing it changes a
  safety gate that ADR 067 made deliberately unskippable. That is the owner's call, with its
  consequences written down — never a silent change.
- **Gap:** no visual editor; PTW cannot have more than two stages; "Approval Creator" is not defined
  anywhere in the repository (**D2**).

### S6 — PTW: no automatic expiry or closure
- **Exists:**
  - the state machine (`PermitToWork::TRANSITIONS`);
  - start and end times;
  - personnel, PIC, PPE and controls;
  - the gas test module;
  - stamps (ADR 065);
  - live notifications;
  - a PTW quota.
- **Gap:**
  - **nothing expires or closes a permit**; `routes/console.php` schedules no PTW job. A permit past its end time is only *counted* ("past end time") on the HSE Overview;
  - no validity extension or revalidation;
  - no WO prerequisite.

### S7 — SIMOPS: greenfield
- **Exists:** nothing. "simops" or "simultaneous" appears nowhere in the product code.
- **Data constraint:** permit and WO **location is free text** (`permits_to_work.location`). Conflict
  detection needs a structured *where* — an Area/Location master within an operating unit — and
  that is new master data (**D6**). Time overlap alone is computable today.

### S8 — Notifications: most of it already exists
- **Exists:**
  - `NotificationService::notify / notifyRole`, after-commit delivery;
  - a cursor-based live delta while a tab is visible;
  - **self-hosted VAPID Web Push** with no payload (ADR 069);
  - the service worker, which stands down when a visible tab already announced the notification;
  - **in-page sound**, with a toggle primed on the user's gesture (v2.130.0);
  - notification URLs bound to routes (ADR 064).
- **Gap:**
  - no per-user notification preferences;
  - no event catalogue: each module calls `notify()` ad hoc, about 10 call sites;
  - no deadline or reminder events;
  - **no WhatsApp at all** (optional per brief — **D8**).
- **Platform fact:** "mobile push" here means Web Push to the installed PWA. iOS delivers it only to
  a Home-Screen-installed PWA (16.4+). There is no native app.

### S9 — Live PTW Dashboard: the parts exist, the board does not
- **Exists:**
  - the PTW register;
  - HSE Overview KPIs (active, past end time);
  - live partial reload and change markers (ADR 069);
  - the calendar.
- **Gap:** no board of permits by state and attention (awaiting my stage, starting soon, past end,
  gas test due), and no area view; the area view depends on D6.

### S10 — Audit, versioning, isolation: isolation strong, versioning narrow
- **Exists:**
  - `ActivityLog`, written at 84 call sites, snapshotting the actor's name and job title;
  - the Activity Center;
  - approval history in `approvals`;
  - Document Control versions (`document_versions`);
  - tenant isolation:
    - 94 models carry it through `BelongsToCompany` / `BelongsToCompanyThrough`;
    - `TenantScope` is fail-closed;
    - `TransitiveTenantIsolationTest` fails if a model is neither isolated nor explicitly exempt.
- **Gap:**
  - **no field-level audit** (before and after values); already `#status/planned` in the Register;
  - no record versioning outside Document Control;
  - form-definition versioning does not exist, because forms do not.

### Cross-cutting facts that shape everything
- **Plans:**
  - Starter = HSE;
  - Professional = HSE + HRD;
  - Business and Enterprise = HSE + HRD + Logistics + Management (`config/plans.php`);
  - Maintenance, Procurement, Project Management and Quality Control are **retired from sale** with their code intact.

  Any feature placed in a retired department is invisible to every customer.
- **Authorization:**
  - the live path is the `role` column plus `isX()` / `canX()`;
  - Spatie RBAC is installed but its migration is deferred;
  - My Work is bounded by an allow-list.

  New features must ask the same question as the gate that refuses them (ADR [[061-an-offer-is-bound-to-what-the-server-allows\|061]]).
- **Deferred items this initiative collides with:**
  - the Settings split;
  - the "Workflow form pass" (about 20 approval forms not yet on the Form Experience System, PTW and Work Orders among them);
  - the Universal Attachment Engine;
  - the field-level audit.

  Each becomes a prerequisite, not a side quest.

---

## 3. The integration map — one ecosystem, not ten features

```
Work Order ──authorizes──▶ PTW ──approval stages──▶ (final gate) ──▶ Active ──▶ Closed / Expired
   │  assignment                │  personnel, area, time               ▲
   ▼                            ▼                                      │ live board
 My Work ◀── tasks, approvals waiting on me, my permits, my WOs ───────┘
   ▲                            │
   │ notifications (in-app, push, sound; WhatsApp optional) ◀── every transition above
   │                            ▼
 Dashboards (role layer) ◀── counts of the same rows      SIMOPS ◀── overlapping area × time
 Form Builder ──extends──▶ PTW / WO / HSE / HRD / Logistics records (never replaces core columns)
 Audit + versions ◀── every write, approval and form-definition change
```

Rules that keep it one system:
1. **One approval engine.** The Approval Engine v2 grows a visual editor and stage semantics, and
   PTW's two stages either move onto it or are expressed through it (D2). Nothing builds a third.
2. **One assignment model.** My Work reads `Task`, the pending approvals, PTW stages and WO
   assignment. It owns no table of its own, the same way Management owns no data (ADR 044).
3. **One notification path.** Every new event goes through `NotificationService::notify()`, so it
   inherits live delivery, push, sound and route-bound URLs. WhatsApp, if chosen, is a channel
   adapter behind it, never a parallel sender.
4. **Counts are rows.** Every dashboard figure filters the register it summarises
   (ADR [[077-a-waste-figure-is-a-count-and-duration-is-not-capacity\|077]]).
5. **Form Builder extends, never replaces.** Core columns that carry business rules, such as PTW
   times, status, personnel and quota, stay real columns with server validation. Custom fields are
   additional, typed, versioned and validated on the server.

---

## 4. Open decisions — the owner's, not assumed

| # | Decision | Why it blocks | Options seen |
|---|---|---|---|
| **D1** | **Are Tasks a cross-department capability?** | Department Users get 403 on `/tasks` today, and My Work cannot be cross-department without it | (a) add `tasks` to `UNIVERSAL_PREFIXES`; tasks stay scoped to the assignee and creator. (b) map Tasks into each sold department. Either way it is a permission change and needs your yes |
| **D2** | **PTW approval order, and what "Approval Creator" and "Verified" mean** | The brief's "approvals → final Verified → auto-active" reverses ADR 067's enforced "verified (HSE) → approved → active (manual)" | Keep ADR 067's order and allow N approval stages *after* HSE verification; or adopt the brief's order and supersede ADR 067; plus whether activation is automatic at the final stage or still a person's act at the work site |
| **D3** | **Where Work Orders live and which plans get them** | WO sits in Maintenance, retired from sale | Move WO into HSE (as a work authorization for PTW), into Logistics/Warehouse, or make it cross-department, per plan |
| **D4** | **The bilingual, configurable WO format** | Not recorded anywhere in the repository | Please supply the format (fields, sections, languages, which parts a tenant may configure) |
| **D5** | **Expiry and closure rules** | Nothing expires today | At end time: auto-close; mark "expired, needs closing" for a person; or allow extension/revalidation (who, how often). Is a closure checklist required? |
| **D6** | **Structured work areas for SIMOPS and the live board** | Location is free text | Add an Area master per operating unit (new master data plus a migration), or start SIMOPS on time overlap plus operating unit only |
| **D7** | **Form Builder scope** | Very different sizes of work | (a) custom fields added to existing forms; (b) whole custom checklists per type, like the HSE checklist engine; (c) entirely custom forms or modules. Which plans get it, and who may edit (Company Admin only, or a delegated permission) |
| **D8** | **WhatsApp** | Needs a provider, a cost model and a data-privacy position | Not now; or a provider adapter (which provider, paid by whom, opt-in per user) |
| **D9** | **Role-dashboard audiences** | Decides what each overview shows | Proposed: Workforce (My Work), Supervisor (team and approvals), Manager (department KPIs) and Company Admin. Confirm, or name your own |
| **D10** | **Which plans get each new capability** | Entitlements must be preserved | E.g. SIMOPS and the Form Builder on Business+, the live board on all HSE plans |
| **D11** | **Record versioning scope** | Storage and complexity | Versions for form definitions only, or full snapshots of permits and WOs at every state change |
| **D12** | **Order of phases** (section 5) | Your stated priorities versus dependencies | Proposed order below |

---

## 5. Proposed implementation phases

Ordered by dependency and operational impact. Dashboard and My Work come first because they need
**no** decision except D1 and D9, carry no schema change, and pay off for every role at once.

| Phase | Content | Depends on | Schema | Status |
|---|---|---|---|---|
| **P0 — Foundations and quick fixes** | Fix the My PTW count (include `verified`); settle Tasks access (D1); split `Settings/Index.jsx` (the deferred prerequisite for approval and form editors); a notification event catalogue (a list, not code) | D1 | none | `#status/planned` |
| **P1 — My Work as the personal workspace** | One mobile-first page for every account class: *Waiting on me* (approvals, PTW stages), *Assigned to me* (tasks; WOs after P3), *My permits*, deadlines and overdue items ordered by urgency, and quick actions, all bounded by the existing gates. HRD and Logistics sources join as they qualify (leave approvals, material requests) | P0, D1 | none | `#status/planned` |
| **P2 — Role-aware dashboards** | A personal and team layer on each workspace overview ("my", "my team's", "awaiting me") on top of the existing company figures; same components and KPI rules in all four workspaces | P1, D9 | none | `#status/planned` |
| **P3 — Work Order in a sold workspace, linked to PTW** | WO lifecycle with priority, authorization (Approval Engine) and assignment; the agreed bilingual format; `permits_to_work.work_order_id`; **a PTW cannot be submitted, or cannot activate (D2), until its WO is authorized** | D3, D4, D2 | additive | `#status/planned` |
| **P4 — Approval flows, visual and N-stage** | A visual editor for Approval Engine flows; PTW stages expressed through it per D2, keeping the state-machine guarantee, row-lock decisions and commit-before-notify (ADR 067); full approval history on the record | P0 (Settings split), D2 | additive | `#status/planned` |
| **P5 — PTW lifecycle and the live board** | Scheduled expiry/closure per D5; extension or revalidation if chosen; a live PTW board (by state, awaiting my stage, starting soon, past end, gas test due), on live sync | D5, (D6 for an area view) | additive | `#status/planned` |
| **P6 — SIMOPS** | Overlap of active and approved permits and WOs by area × time window, a conflict matrix by permit type (e.g. hot work near confined space), supervisor acknowledgement recorded in audit | P3, P5, D6 | additive (areas, acknowledgements) | `#status/planned` |
| **P7 — Global Form Builder** | Versioned form definitions (typed fields, required, limits, options), server-side validation, values stored per record against the definition version; first consumer is PTW per-type checklists (generalising the HSE checklist engine), then HSE, HRD and Logistics forms per D7 | P0 (Settings split), D7, D10 | additive | `#status/planned` |
| **P8 — Notifications completion** | Per-user preferences per event category; deadline and reminder events (WO due, permit ending, approval escalation already hourly); push sound via the OS notification; WhatsApp adapter only if D8 says so | P1–P5 events, D8 | additive (preferences) | `#status/planned` |
| **P9 — Audit and versioning** | Field-level audit (before/after) for permits, WOs and form values; record snapshots per D11; one history view per record (approvals, transitions, changes) | D11 | additive | `#status/planned` |
| **P10 — Landing page update** | Screenshots and feature copy from the **production** product after P1–P9 ship and are verified | everything above | none | `#status/deferred` (by the brief) |

Phases P1 and P2 can ship in Light and Midnight with the existing design system. Every phase
re-runs the route-driven dark and light audit used for 2.140.0.

---

## 6. Risks

| Risk | Where | Mitigation |
|---|---|---|
| Weakening a PTW safety gate while "simplifying" | ADR 067 state machine, row-locked decisions, commit-before-notify | Any change to stage order or automatic activation needs D2 in writing plus a superseding ADR; the existing PTW tests stay and new ones pin the chosen order |
| Building a second approval system | `PtwWorkflowService` vs `ApprovalEngine` | Rule 1 in section 3: extend the engine; migrate PTW through it |
| Shipping features into a retired department | Maintenance (WO), Project Management (Tasks) | D1 and D3 before code; the offers-follow-the-plan crawl (ADR 061) on every release |
| A permission change disguised as UX | Tasks access, My Work allow-list | Each is a named decision (D1) with a test pinning who can and cannot reach it |
| Form Builder eroding data integrity | Core PTW, WO and HR columns | Extension-only (rule 5); server validation; versioned definitions; values bound to the version they were captured under |
| The Settings monolith | 2,500 lines, 17 tabs | Split first (P0), as the Register already requires |
| Push on iPhones | iOS Web Push needs an installed PWA | Say so in onboarding copy; in-app plus sound remains the floor |
| Bundle size | ~2.25 MB JS, deferred | Lazy-load the editors (form builder, flow designer), which are the first real reason to split |
| Tenant leakage in new tables | areas, form definitions, preferences | `BelongsToCompany` from day one; `TransitiveTenantIsolationTest` covers new models automatically |

---

## 7. QA and acceptance criteria (per phase)

Every phase is done only when all of these hold:
- the full suite is green;
- new tests pin the business rule and the permission boundary, including who must be **refused**;
- the route-driven crawl reports 0 × 403 offered and 0 × 5xx, for every role and plan touched;
- the dark and light audit finds 0 items;
- the views are checked at 390, 1024 and 1440 px;
- the knowledge base is updated in the same session;
- production signatures match after deploy (ADR [[057-a-release-must-be-able-to-prove-what-is-running\|057]]).

Phase-specific:
- **P1:** a Department User, a supervisor, a Company Admin and a My Work login each see only what
  they may act on. Every item links to a page that answers 200 for them. Counts equal the rows
  behind them.
- **P3:** a PTW cannot pass the WO gate chosen in D2 while its WO is unauthorized, by UI **or**
  direct request.
- **P4:** a flow of 3, 4 and 5 stages runs end to end. A stage cannot be skipped by holding two
  authorities. The history shows every decision with the actor's title at the time.
- **P5:** a permit past its end time reaches the D5 state on schedule, once, idempotently, and is
  audited.
- **P6:** two overlapping hot-work permits in one area are flagged. Non-overlapping ones are not.
- **P7:** a form-definition change never alters a record captured under the previous version.

---

## 8. Progress

| Item | Status | Evidence |
|---|---|---|
| Repository audit (S1–S10) | `#status/verified` | Section 2, read from `main` at `120141b`, 2026-11-01 |
| Roadmap and phases | `#status/implemented` | Section 5, proposed, awaiting D12 |
| Owner decisions D1–D12 | `#status/planned` | Section 4 |
| P0–P10 | `#status/planned` | Nothing implemented |
