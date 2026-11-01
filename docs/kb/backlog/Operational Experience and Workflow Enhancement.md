---
title: Operational Experience and Workflow Enhancement
type: backlog
status: IN PROGRESS — v2 roadmap; P0 quick wins on branch; architecture and product decisions A1–A14 awaiting the owner
updated: 2026-11-01
tags: [kb/backlog, status/in-progress]
---

# Operational Experience and Workflow Enhancement

**Source:**
- the owner directive of 2026-11-01, "IOMS Major Product Update";
- the follow-up the same day, which adds performance, database and scalability, the Work Order
  format and its PTW flow, the Form Builder, the Visual Approval Flow, PTW, SIMOPS, notifications,
  the live board, audit and UX, and the working agreement.

**Baseline:** production `2.140.0` (`d73680c`).
**Direction:** *Easy for the Workforce. Efficient for Supervisors. Powerful for Management.*
**Board:** [[Project Board]] · **Register:** [[Requirements Register]] · **Decisions:** [[Decision Register]]

> [!important] The working agreement (owner, 2026-11-01)
> The **owner** decides product direction, requirements, system behaviour, user experience and the
> expected result. **Engineering** decides the technical approach, architecture, ordering and
> routine implementation. Anything that significantly changes a library or framework, open-source
> adoption, database architecture, a service, core technology, cost, security or **safety
> behaviour** is first proposed (the recommendation, why, the alternatives, pros and cons, risk,
> long-term consequences, fit with IOMS, and its effect on cost, maintenance, scale, security and
> UX) and **waits for approval**. Production deploys follow QA and approval. Historical production
> data — PTW, Work Orders, operational activity, audit history — is **never** deleted for being
> "unused".

The audit in section 2 of v1 (S1–S10) still stands and is not repeated. This version adds the
performance audit, the owner's new specifications, the decision pack and the delivery plan.

---

## 1. Performance, database and scalability — measured 2026-11-01

Measured in-process against the local copy (MySQL 8, the same 141-table schema production migrates
to) and from the production deploy reports. **What was measured is labelled; what was not is said.**

### What actually makes IOMS feel slow

| # | Finding | Evidence | Effect | Status |
|---|---|---|---|---|
| F1 | **Every page response carried the whole changelog.** `version.history`, 151 releases of summaries, was a shared Inertia prop | 426 KB of the ~430 KB **every** page response transferred, even `/tasks` | Each click downloaded ~430 KB of JSON and the browser parsed it; on a phone or weak network that alone is the lag | **Fixed on branch** (P0): history fetched by the About dialog when opened (`account.version-history`). Navigation now **5.7 KB** (measured in the browser). `OperationalFoundationsTest` pins < 20 KB |
| F2 | **One JavaScript bundle for all 186 pages** | `app-*.js` 2.30 MB (577 KB gzipped); `import.meta.glob(..., { eager: true })` in `app.jsx` | The first load downloads and parses every page before showing one. Eager loading was chosen in v1.6.5 to avoid a white flash between pages | P0, routine: lazy per-page chunks plus prefetch of the likely next pages, so the flash does not return |
| F3 | **A render-blocking Google Fonts stylesheet on every page** | `fonts.googleapis.com/css2?family=Archivo…IBM+Plex+Mono` in `app.blade.php` | Blocks first paint on every load. In the signed-in app those faces now serve only the document type band and machine IDs (ADR 081) | P0, routine: self-host the two faces from the static files already in `resources/fonts` (used by the PDF), or scope them to the public site |
| F4 | **A query floor of 60–70 per request, half of them cache reads** | Every page: 70–191 queries. **32** are `select * from cache`: company settings (logo, name, favicon …) read one at a time, because `CACHE_STORE=database`. The same entitlement questions are asked 4–5 times per request | Each request pays ~25–60 ms of DB round-trips before the page does any work | P0, routine: per-request memoisation of settings and entitlements. A1 (Redis) removes the cache-in-DB cost entirely |
| F5 | **The live notification poll costs a full request** | `/notifications/live`: 103-byte answer, **60 queries**, every 5 s per visible tab | ~0.2 req/s per open tab; 200 people ≈ 40 req/s against 12 PHP-FPM workers, each also reading the DB session | P0, routine: a lean route with only the middleware it needs. A3 (WebSockets) removes the polling |
| F6 | Server time is **not** the bottleneck at today's size | 35–155 ms per page locally (warm) | The slowness people feel is mostly F1–F3 (bytes and parse), then F4 and F5 under load | — |

**Not measured, and why:**
- production database size and growth;
- slow-query log;
- CPU and connection counts under real traffic.

This container may not connect to the server (standing instruction). The deploy status shows
1.8 GB free memory, 43 GB free disk and a load of 0.87. **Proposed:** a read-only `ioms-ops`
report (table sizes, row counts, slow-query summary, FPM and DB connection stats) so the next
measurement is real. It is an ops change, so it is in the decision pack (A5).

### Database structure

| Item | Finding |
|---|---|
| Tables | 141 |
| Tenant columns | All 91 `company_id` / `tenant_id` columns are indexed |
| Foreign keys | All indexed; polymorphic pairs covered by composite `(type, id)` indexes |
| Largest local tables | `permits_to_work`, `purchase_orders`, `kpi_records`, `sessions` |
| Unused objects | Local data is too small to rank waste. The retired departments' tables (Procurement, Maintenance, QC, Projects) are **not** dead: Work Order now returns to use (section 3), and historical rows must be kept |
| Temporary and housekeeping data | **Sessions, cache and the queue share the main database** (`SESSION_DRIVER`, `CACHE_STORE` and `QUEUE_CONNECTION` = database). Expired sessions and cache rows only go when the framework's lottery prunes them, and failed jobs accumulate. Proposed: scheduled `session:gc` and `cache:prune-stale-tags` / `queue:prune-failed`, routine, with **no operational data touched** |

### Tenancy and isolation

- **Today:** a shared database with row-level isolation.
  - 94 models carry it through `BelongsToCompany` / `BelongsToCompanyThrough`;
  - `TenantScope` is fail-closed;
  - `TransitiveTenantIsolationTest` fails on any unclassified model.

  This is sound and tested. See A2 for the long-term architecture.
- **Files:** stored on the server's local disk through 44 separate upload call sites. No
  per-tenant prefix, no registry, no size accounting, so a storage add-on has nothing to count.
- **Backups:** `deploy/tencent/backup.sh` still says "copy to COS or another host once a bucket is
  chosen". **Every backup lives on the same machine as production.** One disk or instance failure
  loses the backups with the data. This is the highest operational risk found. Fixing it is A4.

---

## 2. The owner's specifications (2026-11-01), recorded as given

### Work Order — the owner's format and flow (supersedes D3 and D4 of v1)
- **Name:** shown in the product as **Work Order**. Never "Maintenance Work Order", and never "WO"
  as the feature name.
- **Six sections, bilingual (Indonesian / English):**
  1. Requester Information
  2. Job Description
  3. Resources & Materials
  4. Safety & Compliance
  5. Approval Sign-off
  6. Close-out & Inspection

  Common industry technical terms stay as they are.
- **Urgency:** a choice on every Work Order.
- **Configurable per company:**
  - Approval Sign-off;
  - Close-out & Inspection, which may also be omitted or replaced by / linked to a QC document.
- **Layout:** professional and readable; an operational document.
- **The flow with PTW:**
  1. On creation the requester answers **PTW Requirement: Yes/No**.
  2. If Yes, they choose the permit type(s) needed.
  3. The Work Order goes through approval until it is **Authorized**.
  4. Once Authorized, the requester or another authorised person creates the PTW and links it to
     the Work Order.
  5. When the Work Order enters **Execution** and a PTW is required, the system reminds:
     *"Jangan lupa menyiapkan Permit to Work (PTW) apabila pekerjaan ini memerlukannya."*

  A PTW does **not** have to exist before the Work Order is approved.
- **Entitlement:** Work Order is an operational capability, **not** plan-locked like PTW. Volume is
  managed through quotas and storage add-ons. The proposed model is A9.

**Repository fact:** the field-level content of the six sections is **not** recorded anywhere in
the repository. Only the section names are. A10 asks for the original document, or approval of a
drafted field list.

### Other confirmations
- **Dashboards:** four audiences (Workforce, Supervisor, Manager, Company Admin), answering *what
  is happening, what to do, what waits on me, what needs attention, how the department or company
  is performing*.
- **My Work:** a personal operational workspace across departments, built on tasks, approvals,
  assignments and activity related to the person. The Tasks access and PTW `verified` findings must
  be handled.
- **Form Builder:** a mature open-source solution is preferred to building from zero, provided it
  is professional, secure and commercially licensable. Recommend first; integrate after approval.
- **Approval Flow:** a visual flowchart editor (rectangular nodes, arrows, multiple stages,
  approvers by user, position or role, sequential and parallel, conditions, escalation, preview
  before publishing). Reuse the Approval Engine if sound.
- **PTW:** bilingual form, logical grouping, clear mandatory fields and validation, mobile-first, the
  Work Order flow above, a clear lifecycle (expiry, extension, closure, statuses), and **every
  safety control kept**. The safety order is **not** changed on assumption (A8).
- **SIMOPS:** design and data requirements are proposed before implementation (A12).
- **Notifications:** audit the foundation and fix it properly; WhatsApp is not a priority.
- **Audit:** who, when, before and after, form-definition, Work Order, PTW and approval-flow
  changes, status history, all tenant-isolated.
- **Landing page:** only after the product passes QA, showing only what exists.

---

## 3. Notifications — the verdict

**Measured:** a notification created outside the page appeared in an open tab **2.9 s** later. The
badge went to "1 unread". The transport works: cursor delta plus self-hosted VAPID push (ADR 069).

**The foundation that fails people is the event layer above the transport:**
1. **Coverage.** Only three product paths ever notify:
   - the Approval Engine;
   - `HasWorkflow`, which tells **one** person (usually the creator) that a status changed;
   - the PTW controller.

   Nothing tells:
   - a task's assignee;
   - a CAPA owner;
   - a Work Order technician;
   - the *next* actor outside the Approval Engine and PTW;
   - anyone about a deadline, an expiry or a reminder.

   So most of what people expect to hear about produces nothing.
2. **No model of an event.** No category catalogue, priority, grouping or deduplication key. No
   per-user preferences.
3. **No delivery record.** A push that fails is logged and forgotten. There is no per-channel
   status, retry or pruning of dead subscriptions.

**Verdict: keep the transport, rebuild the event layer.** This means:
- a notification **catalogue**: typed events, each with a recipients resolver (who must act, not
  just who created), priority, group key, dedupe key and route-bound deep link (ADR 064 kept);
- per-user **preferences** by category and channel;
- a **delivery log** per channel with queued retries and failure handling;
- a **reminder scheduler** for deadlines and expiries.

Every module keeps calling one service. The 5-second poll becomes cheap now (F5) and is replaced by
WebSockets if A3 is approved. WhatsApp stays a later channel adapter behind the same service.

---

## 4. Decision pack — waiting for the owner

Each item states the recommendation, why, the alternatives, the trade-offs and the impact. **None is
being implemented until approved.**

### A1 — Redis for cache, sessions and queue
- **Recommend:** install Redis (open source, free) on the production server, and move `CACHE_STORE`,
  `SESSION_DRIVER` and `QUEUE_CONNECTION` to it.
- **Why:**
  - removes the 32 cache queries per request (F4) and the session read and write per request and
    per poll (F5);
  - gives the queue a real broker;
  - Laravel supports it natively.
- **Alternatives:**
  - keep everything in MySQL with per-request memoisation (P0 does this anyway; it halves F4 but
    the session and queue still load MySQL);
  - Memcached (cache only).
- **Cost and risk:**
  - about 50–100 MB RAM on the 2 GB server, and one more service in `ioms-ops`, backups and health
    checks;
  - sessions move, so everyone signs in once at cutover;
  - security: Redis bound to localhost with a password.
- **Fit:** high. No application code change beyond config.

### A2 — Long-term tenancy architecture
- **Recommend:** **keep the shared database with row-level isolation now, designed to be
  hybrid-ready**:
  - strengthen what exists (isolation test, fail-closed scopes, per-tenant storage registry,
    per-tenant export);
  - later, add a **dedicated database for a specific large customer** only when a contract or
    regulation requires it, through Laravel's per-connection routing.
- **Why:**
  - today's isolation is proven and tested;
  - per-tenant databases multiply migrations, backups and connections per customer (141 tables
    each), which a single 2 GB server cannot carry;
  - shared-database SaaS scales to thousands of tenants with indexes and caching.
- **Alternatives:**
  - database-per-tenant for everyone (for example via `stancl/tenancy`): strongest physical
    isolation, but a large migration of 94 models and much higher operating cost;
  - schema-per-tenant: not native to MySQL.
- **Consequences:**
  - one noisy tenant can affect others until hosting scales (vertical first, then a read replica);
  - the hybrid path keeps the door open without paying for it now.

### A3 — Real-time over WebSockets (Laravel Reverb)
- **Recommend:** Laravel **Reverb** (first-party, MIT, free), running as a process on the Tencent
  server behind Caddy, replacing the 5-second poll for open tabs.
- **Why:**
  - instant delivery to the live PTW board, My Work and notifications;
  - removes the poll load (F5).

  ADR 069 rejected WebSockets **because the old cPanel host could not run a long-lived process.
  The Tencent server can**, so the reason no longer holds.
- **Alternatives:**
  - keep polling, made lean in P0 (fine for tens of users, not hundreds);
  - Pusher or Ably (paid, external, data leaves the server);
  - Soketi (community-maintained).
- **Cost and risk:** one supervised process plus a Caddy route; a fallback to polling stays for
  networks that block WebSockets.
- **Fit:** the `transport` switch in `config/realtime.php` was designed for this.

### A4 — Object storage for files and off-site backups (Tencent COS)
- **Recommend:** store uploaded files in **Tencent COS** (S3-compatible) through Laravel's S3 driver
  (`league/flysystem-aws-s3-v3`), under a per-tenant prefix. Record every file in a **file
  registry** (tenant, owner record, size, type) so storage can be **metered and sold as an
  add-on**. Copy nightly encrypted backups to a separate COS bucket.
- **Why:**
  - server disk stops being the ceiling on customer data;
  - storage grows independently of server resources;
  - backups survive losing the server (the highest risk found).
- **Alternatives:**
  - keep the local disk and add backup copies only (fixes the risk, not the scaling or metering);
  - Cloudflare R2 (S3-compatible, no egress fee; but it moves data outside Tencent and adds
    another provider).
- **Cost:** COS bills per GB stored plus requests (small at today's volume; a recurring cost the
  owner pays). Credentials are created by the owner.
- **Migration:** existing files are copied and verified before switching, never moved blind. The
  44 upload call sites move to one storage service.

### A5 — A read-only production performance report in `ioms-ops`
- **Recommend:** a read-only `ioms-ops perf` command reporting:
  - table sizes and row counts;
  - the slow-query log summary (enabling MySQL's slow log at 1 s);
  - FPM, queue and DB connection stats.

  It prints aggregate numbers only, never customer data.
- **Why:** so that production capacity decisions use measurements, not the local copy.
- **Alternatives:** none worth having without numbers.
- **Risk:** low. Read-only, and in the same redacted channel as `status`.

### A6 — Form Builder: SurveyJS
- **Recommend:** **SurveyJS**, used in two parts:
  - the **Form Library** (MIT, free for commercial use) renders forms for users, on desktop and
    phone;
  - **Survey Creator**, the drag-and-drop builder for administrators, which requires a **paid
    developer licence**. The official price is from about US$589 once per developer with 12 months
    of updates; optional renewal is about 60% per year; **no limit on end users**.

  Definitions are stored as JSON in IOMS, **versioned**. Submissions are validated **again on the
  server** by an IOMS validator for the field types enabled. Core business columns (PTW times,
  status, personnel, quota) stay real columns; the builder **extends** them and never replaces
  them.
- **Why:**
  - the most complete and polished builder available for React: conditional logic through its own
    safe expression language (no `eval`), validation, panels and pages, matrices, signatures and
    file questions;
  - themeable to IOMS Light and Midnight;
  - actively maintained.
- **Alternatives:**
  - **Form.io** (`formio.js`): has a builder, but its server-side licensing changed to the copyleft
    OSL-3; its UI is Bootstrap-based and heavy; and its "custom JavaScript" logic evaluates code,
    which is an injection risk in a multi-tenant product;
  - **react-jsonschema-form / JSON Forms** (Apache / MIT): solid renderers with **no** builder, so
    we would build the builder;
  - **Formily / Designable** (MIT): tied to Ant Design, with uncertain maintenance;
  - **build our own** builder on SurveyJS's free library (no licence fee, full control of the UX,
    but months of work and less polish).
- **Decision needed:**
  - approve SurveyJS **with** the Creator licence (fastest, most professional), or
  - fully open source (Form Library plus an IOMS-built editor).

  The owner buys the licence and the key goes into the server environment, never into the
  repository.

### A7 — Visual Approval Flow Builder on React Flow, extending the existing engine
- **Recommend:**
  - **React Flow** (`@xyflow/react`, MIT) for the canvas: rectangular stage nodes, arrows, add,
    remove and reorder, parallel branches, and a full preview before publishing;
  - **extend the existing Approval Engine v2** instead of writing a new one. It already has
    numbered steps, `mode`, approver by role or user, and hourly escalation.

  Additions:
  - approver by **position** (job title, `positions`);
  - **conditions** on a stage (for example urgency, permit type);
  - **flow versions**: draft → published, where a request in flight keeps the version it started
    on;
  - an audit of every flow change.
- **Why:** React Flow is the de-facto standard for node editors in React (maintained, accessible,
  MIT); the engine is proven in three modules.
- **Alternatives:**
  - drawing our own SVG editor (more work, fewer features);
  - JointJS (commercial for its rich edition);
  - Rete.js (aimed at dataflow programming, not business flows).
- **Cost:** one dependency, about 150 KB, loaded only on the editor page.

### A8 — PTW approval and verification order (safety behaviour)

**Today (ADR 067), enforced by the state machine:**

```
Draft → Submitted → Verified (HSE) → Approved (approver) → Active (activated by a person) → Closed
                          ↘ Rejected ↙
```

HSE verifies that the permit is complete and safe. An approver then authorises the work, and
someone switches it on. Two different authorities. The order cannot be skipped. Nothing expires.

**As the owner described it earlier:** an "Approval Creator", then approval stages, then a **final
Verified** stage that **activates the permit automatically**.

**Differences and implications:**

| | ADR 067 (today) | Described |
|---|---|---|
| Where HSE verification sits | **First** gate | **Last** gate |
| Number of approval stages | One | Three, four or more, configurable |
| Activation | A person's act | Automatic at Verified |
| Implication | HSE screens the permit before approvers spend time on it | HSE has the final word on the exact permit the approvers signed. That is closer to "nothing goes live without HSE", but approvers may sign permits that HSE later sends back |

**Proposed final flow, for approval:**

```
Draft (Requester = the "creator" of the request)
  → Submitted
  → Approval stage 1..N   (configured per company in the visual flow builder; e.g. Supervisor,
                           Area Owner; sequential or parallel; by user, position or role)
  → Final Verification    (HSE; MANDATORY, always last, cannot be removed from any flow,
                           cannot be done by someone who approved the same permit)
  → Verified              (permit is authorised)
  → Active                AUTOMATICALLY at the permit's start time (or at Verified if the start time
                           has already passed). Never before the start time.
  → Expired               AUTOMATICALLY at the end time; work must stop; the permit awaits close-out
  → Closed                (close-out by the requester or area authority, with the closure check)
  Extension: requested before expiry, re-verified by Final Verification, up to a company limit.
  Rejected at any stage → back to Draft with the reason.
```

**Preserved:**
- server-enforced order;
- row-locked decisions;
- commit before notify (ADR 067);
- verify authority separate from approve authority;
- the Work Order link (section 2).

**The owner must confirm:**
1. **Final Verification is last** (supersedes ADR 067's order).
2. **Automatic activation** at the start time once Verified.
3. **What "Approval Creator" means:**
   - (a) the requester who creates the permit, as drawn above;
   - (b) a stage where someone formally "creates" the approval request on the requester's behalf;
   - (c) the administrator who designs the flow.
4. **What happens at expiry:**
   - automatic Expired, awaiting close-out, as proposed; or
   - automatic Closed.
5. **Extension rules:** who may extend, and how many times.
6. **Mandatory pre-activation checks**, such as a recorded gas test for confined space or hot work.
   IOMS does not enforce these today, so they would be new safety rules. Say which.

### A9 — Work Order entitlement
- **Recommend:**
  - Work Order is a **cross-department capability in every plan**, served from a universal route
    prefix and governed by roles, not by workspace grant;
  - volume is governed by the storage quota and add-ons (A4/A11);
  - the existing Maintenance fields (asset, maintenance request, spare parts) become **optional
    sections** used only where the company has assets.
- **Why:** this matches the owner's direction, and WO is the entry point to PTW, which every HSE
  plan sells.
- **Alternatives:**
  - Work Order only on plans with Logistics;
  - Work Order as a paid add-on.
- **Consequence:** the old retired-department routes are re-homed without losing historical rows.

### A10 — The field content of the six Work Order sections
- **Need:** the owner's original Work Order document, or approval of a drafted field list.
- **The drafted list, for review:**
  1. **Requester Information:** requester, department, operating unit, contact, date; WO number.
  2. **Job Description:** title, scope, work location (area), equipment or asset, planned start and
     end, **urgency**, **PTW Requirement Yes/No + permit types**.
  3. **Resources & Materials:** manpower (employees or contractor), tools, materials (optionally
     linked to a Material Request).
  4. **Safety & Compliance:** hazards, controls, required PPE, JSA/HIRADC reference, linked PTW(s)
     once created.
  5. **Approval Sign-off:** the company's configured stages, with name, position and time per
     signature.
  6. **Close-out & Inspection:** completion notes, inspection result, inspector, date, or a link to
     a QC document, or omitted, per company.

### A11 — Capacity model (commercial)
- **Recommend:** meter three things separately:
  - **users** (as today);
  - **file storage** (exact, from the A4 registry, sold in add-on blocks);
  - **server resources** (an internal hosting concern, not a customer limit).

  **Never block operational records** (PTW, WO, audit) for database size. The database is covered
  by fair use, so a full storage quota stops new *uploads*, never new *permits*.
- **Owner decides:** the included storage per plan, the add-on block size and the price.

### A12 — SIMOPS design and data
- **Data:** a **Work Area** master per operating unit (site → area → zone, with optional
  "adjacent to" links). PTW and Work Order reference an area; the free-text location stays for
  detail. Existing permits keep their text location; an area is required only on new ones.
- **Detection:**
  - two activities conflict when their **areas match or are adjacent**, their **time windows
    overlap**, and their **permit-type pair** is in the company's conflict matrix;
  - IOMS ships a reference matrix as a starting point, for example hot work × confined space, hot
    work × flammable or painting work, work at height × work below, energy isolation × electrical
    work;
  - the company confirms and edits the matrix; it is never silently applied.
- **Behaviour:** shown on the PTW/WO form at submission and to every approver, on a SIMOPS board,
  and in the live PTW dashboard. A supervisor's acknowledgement is recorded in the audit trail.
- **The owner must decide (safety behaviour):**
  - a conflict **warns**, and approval requires an acknowledgement (recommended); or
  - a conflict **blocks** approval until resolved.

  Also confirm that the reference matrix is acceptable as a starting point.

### A13 — Audit trail: before and after values
- **Recommend:** an append-only, tenant-scoped **`record_changes`** table written by a model trait.
  Each entry records:
  - actor, actor title at the time, time, record;
  - **changed fields with before and after values**, with secrets redacted;
  - for workflow models, the state transition.

  It covers PTW, Work Orders, approval flows and form definitions first, then every model with
  `HasWorkflow`. **Versioning:**
  - form definitions and approval flows are immutable published versions;
  - PTW and Work Orders get a full snapshot at each state transition.

  One record-history view shows approvals, transitions and changes together. The existing
  `ActivityLog` remains the human-readable feed.
- **Why:** `ActivityLog` stores a sentence, not the change (the planned "field-level audit" item in
  the Register).
- **Alternatives:** `owen-it/laravel-auditing` (MIT, mature); it adds a dependency and its own
  table. It is a reasonable alternative if preferred; IOMS's needs (title snapshot, tenant scope,
  redaction, append-only) are small enough to own.
- **Schema:** additive (new tables only). Storage grows with activity, so it is retained, not
  deleted, per the owner.

### A14 — Supervisor needs a reporting line
- **Fact:** IOMS records **no** supervisor or manager relation (checked on `employees`,
  `positions`, `departments` and `users`). A "Supervisor" dashboard has no team to show.
- **Recommend:** add `employees.reports_to_employee_id` (nullable, company-scoped), editable in
  the Employee form and importable. A person's *team* is their direct reports, plus the work they
  approve.
- **Alternatives:**
  - define Supervisor by **approval authority only**, with no schema change (they see what waits
    on them, but no team view);
  - a department head per department (coarser).

### D1 (from v1) — Tasks for Department Users
- **Recommend:** add `tasks` to the universal prefixes, while each task stays visible only to its
  assignee, its creator and those who manage tasks. That makes Tasks a cross-department capability,
  as My Work requires.
- This is a **permission change**, so it waits for approval. Without it, My Work can show tasks only
  to non-department accounts.

---

## 5. Delivery plan

Ordered by dependency, risk and the value each step unlocks. Each milestone is a releasable unit
reviewed on **staging** before production, and each production deploy needs the owner's approval
(ADR 076's pipeline: test gate, backup, health, automatic rollback).

| Milestone | Content | Needs | Status |
|---|---|---|---|
| **M0 — Fast foundations** (routine) | F1 history prop ✔; PTW `verified` count ✔; F4 per-request memo of settings and entitlements; F5 lean live endpoint; F2 lazy page chunks with prefetch; F3 self-hosted or scoped fonts; scheduled pruning of sessions, cache and failed jobs (no operational data); Settings split (the recorded prerequisite for the flow and form editors) | — | `#status/in-progress` (2 of 7 done on branch) |
| **M1 — Platform foundations** | Redis (A1); Reverb (A3); COS with storage registry and off-site backups (A4); production perf report (A5); `record_changes` audit (A13); **notification event layer** (section 3: catalogue, preferences, delivery log, retries, reminders) | A1, A3, A4, A5, A13 | `#status/planned` |
| **M2 — My Work and role dashboards** | My Work as the personal workspace (waiting on me, assigned to me, my permits and WOs, deadlines, by urgency; mobile-first); role layers on every overview for Workforce, Supervisor, Manager and Company Admin | D1, A14 | `#status/planned` |
| **M3 — Work Order** | The owner's six-section bilingual Work Order, urgency, configurable sign-off and close-out (or QC link), PTW Requirement Yes/No and types, approval to Authorized, PTW creation from an Authorized WO, the Execution reminder, the document/PDF | A9, A10 | `#status/planned` |
| **M4 — Visual Approval Flow** | React Flow editor; engine v2 extended (positions, conditions, versions); the PTW flow per A8; Work Order sign-off on the same engine | A7, A8 | `#status/planned` |
| **M5 — PTW enhancement and Live PTW Dashboard** | Bilingual, grouped, mobile-first PTW form with clear mandatory rules; lifecycle (scheduled activation, expiry, extension, close-out); the live board (by state, awaiting my stage, starting soon, ending soon, expired awaiting close-out, gas test due; filters by unit, area, type and status; real-time) | A8, M4 | `#status/planned` |
| **M6 — SIMOPS** | Work Area master; area on PTW/WO; conflict detection and matrix; SIMOPS board; acknowledgements | A12 | `#status/planned` |
| **M7 — Form Builder** | SurveyJS integration (or the open-source alternative), versioned definitions, server validation; first consumers are Work Order close-out/inspection and PTW per-type checklists, then HSE, HRD and Logistics forms | A6 | `#status/planned` |
| **M8 — UX sweep and release QA** | Remaining long forms and confusing modals moved to the Form Experience System (the deferred "workflow form pass"); feedback on save, submit, approve and error; full crawl of every role and plan in Light and Midnight; staging review; production release | M0–M7 | `#status/planned` |
| **M9 — Landing page** | Screenshots and copy from the released product only | M8 | `#status/deferred` (by the owner) |

### Engineering approach (owned by engineering, recorded for review)
- **One system, not ten features:**
  - one approval engine;
  - one notification service;
  - one storage service;
  - one audit trail;
  - My Work and dashboards read the registers and own no data.
- **Schema changes are additive** (new tables and columns), so ADR 076's pipeline deploys them
  without a person. Anything destructive is proposed first.
- **Backward compatibility:**
  - existing permits, approvals, flows and files keep working through every step;
  - migrations backfill and never rewrite history;
  - retired-department rows are re-homed, never dropped.
- **Testing in every milestone:**
  - business-rule tests;
  - **refusal** tests (who must not reach it, every plan and role);
  - tenant-isolation tests on new models;
  - the route-driven crawl in Light and Midnight;
  - viewport checks at 390, 1024 and 1440 px;
  - the performance budget: page response under 20 KB of shared props, and a query budget per
    page.
- **Deployment:**
  - branch → CI → staging (`staging.sh`) → owner review → `main` → production through
    `ioms-ops`, with backup and health check;
  - the knowledge base is updated in the same session.

---

## 6. Progress

| Item | Status | Evidence |
|---|---|---|
| v1 audit (S1–S10) | `#status/verified` | Section 2 of v1 (git history of this note) |
| Performance audit (F1–F6, database, tenancy, files, backups) | `#status/verified` | Section 1; measured 2026-11-01 |
| Notifications audit and verdict | `#status/verified` | Section 3; live delivery measured at 2.9 s |
| F1: release history off every page | `#status/implemented` | `account.version-history`; navigation 430 KB → 5.7 KB; `OperationalFoundationsTest` |
| My PTW counts `verified` as awaiting approval | `#status/implemented` | `DashboardController::myWork`; `OperationalFoundationsTest` |
| Decision pack A1–A14 + D1 | `#status/planned` | Section 4, awaiting the owner |
| M0 remainder, M1–M9 | `#status/planned` | Section 5 |
