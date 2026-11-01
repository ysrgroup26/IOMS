---
title: Operational Experience and Workflow Enhancement
type: backlog
status: IN PROGRESS — v3 (owner corrections 2026-11-01): M0 under way; decisions A1–A7, A8b, A10–A14, W1–W3, A15 awaiting the owner
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
>
> **Technology principle (owner, v3):**
> - Free and open-source first, when its quality, security, maintenance and capability meet IOMS's
>   needs.
> - A paid option is proposed only with its reasons, the free alternative, a capability comparison,
>   the licence, and the initial and recurring cost.
> - **No paid service is integrated or activated without approval.**
>
> **Plans (owner, v3): IOMS has exactly THREE plans.**
>
> | Plan | Price | Positioning | Full Users | My Work Users | PTW documents | Operating Units |
> |---|---|---|---|---|---|---|
> | Starter | Rp189.000 / month | Digitalize HSE | 3 | 10 | 50 / month | 1 |
> | Professional | Rp555.000 / month | HSE + Workforce | 10 | 30 | 200 / month | 2 |
> | Business | Rp1.249.000 / month | Operations end to end | 25 | 50 | 500 / month | 4 |
>
> - Professional includes all of Starter plus People / HRD.
> - Business includes all of Professional plus Warehouse Logistics and Management.
> - Every entitlement, menu and permission derives from this. **Enterprise is not used.** The
>   repository already matches these figures (`packages`); see A15 for the leftover row.

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
- **Cost (paid service; not activated without approval):** COS bills per GB stored plus requests.
  That is small at today's volume, but it is a **recurring** cost.
- **The free alternative:**
  - keep files on the server disk and copy backups to a second machine you already own;
  - or MinIO (AGPL-3.0, self-hosted). It is free, but it needs a second server to be off-site, and
    it carries AGPL obligations only if modified and offered over a network.

  Recommendation unchanged: COS, because "off-site" has to mean a different failure domain.
- Credentials are created by the owner.
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

### A6 — Form Builder (v3: open-source visual builder first)

**The owner's requirement:** a **complete visual builder**, not just a renderer; open source
preferred, as long as it is licensable for commercial SaaS.

| Candidate | Visual builder | Licence (verified 2026-11-01) | Verdict |
|---|---|---|---|
| **Form.io `@formio/js`** | **Yes: full drag-and-drop builder plus renderer in one MIT package.** Layout (panels, columns, tabs, wizards), data grids and edit grids, file, signature, select from a data source, validation, conditional logic | **MIT**: `LICENSE.txt` reads "The MIT License (MIT) — Copyright (c) 2018 Form.io"; `package.json` `"license": "MIT"` | **Recommended** |
| SurveyJS | Survey Creator, the most polished | Form Library MIT; **Creator commercial** (≈US$589 once per developer, ~60%/year for updates) | Paid alternative |
| FormEngine (Optimajet) | Designer | Core MIT; **designer commercial** | Rejected (paid) |
| react-jsonschema-form, JSON Forms | **No builder** | Apache-2.0 / MIT | Renderer only; does not meet the requirement |
| Formily / Designable | Yes | MIT | Tied to Ant Design; maintenance uncertain |
| Formbricks, OpnForm, … | Survey products, not embeddable builders | AGPL | Rejected: AGPL obligations for a commercial SaaS |

**Recommendation: Form.io `@formio/js` (MIT, free)**, used only as a client-side library. No Form.io
server and no Form.io premium modules. The definitions and submissions live in IOMS's own
database. These **conditions** are part of the recommendation:
1. **No custom JavaScript.**
   - Form.io can evaluate JS in "custom" logic, validation and calculated values. In a
     multi-tenant product that is code injection.
   - The builder is configured with those tabs removed, and conditions use **JSON Logic**
     (declarative and safe).
   - The server **rejects** any definition that contains a JS-bearing key.
2. **Server-side validation:** an IOMS validator re-checks every submission against the stored,
   versioned definition (required, type, length and pattern limits, allowed options). The browser
   is never trusted.
3. **IOMS's look:** a custom template and theme for Light and Midnight, so it does not look like
   Bootstrap.
4. **Performance:** the library is about 1 MB, loaded **only** on builder and form pages (it is
   never in the main bundle).
5. **Extension, not replacement:** core business columns stay real columns (rule 5).

- **Trade-off against SurveyJS:** the SurveyJS Creator UX is more refined out of the box, and its
  expression language is safe by design. Form.io needs the configuration above and theming work.
  Both meet the requirement; Form.io costs nothing.
- **Cost:** Rp0 licence; engineering time for theming, the validator and the JS lockdown.

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

### A8 — PTW flow (v3: corrected by the owner)

**Decided by the owner, 2026-11-01:**

```
PTW Created → Approval Stage 1..N → Final Verification → Permit Active
```

- **Every stage is configured per company**, including **who performs Final Verification**: HSE,
  a Project Manager, a Manager Project, by person, position or role. It is **not** fixed to HSE.
- When Final Verification is approved, the permit becomes **Active automatically at its scheduled
  start time**. There is **no second, manual activation**. If the start time has not arrived, the
  permit waits as **Verified (scheduled)** and is activated by the scheduler exactly at the start
  time, never earlier. If the start time has already passed, it activates immediately.

**Engineering consequences (no decision needed; how it will be built):**
- **ADR 067 is superseded** for the *order* and *who verifies*. Its other guarantees stay:
  - the server enforces the order (no stage can be skipped by a direct request or by holding two
    authorities);
  - decisions are row-locked;
  - the decision commits before anyone is notified;
  - the actor's job title is snapshotted on the record (ADR 068).
- **Final Verification is structural:** every PTW flow must end with exactly one Final Verification
  stage. The flow editor refuses to publish a PTW flow without one.
- **Existing permits are untouched:** permits already in flight finish on the flow version they
  started under (flow versioning, A7). Today's companies get a default flow equivalent to their
  current settings, so nothing changes until they edit it.
- **Activation is a scheduled job**, idempotent and audited, running every minute. Activation emits
  a notification to the requester, the PIC and the verifier.

### A8b — Safety decisions still open (the owner's call)

| # | Question | Recommendation | Consequence if not decided |
|---|---|---|---|
| A8b-1 | **Separation of duties:** may the same person approve a stage *and* perform Final Verification on the same permit? | **No**: one person, one decision per permit (today's rule) | Without it, one person holding two roles could authorise a permit alone |
| A8b-2 | **At the end time:** what happens? | **Expired, awaiting close-out**: work must stop, the permit is not "closed" until someone closes it out | Auto-*Closed* would hide permits whose site was never made safe |
| A8b-3 | **Extension:** who extends, and how many times? | Requested before expiry; approved by the **Final Verifier role**; at most N per permit (company setting, default 1) | Without a rule, an extension is either impossible or unchecked |
| A8b-4 | **Pre-activation checks:** must anything be recorded before a permit can go Active (e.g. a gas test for confined space or hot work)? | Make it a **per-permit-type company setting**, off by default, so IOMS does not impose a rule the company never adopted | Not enforced today; enforcing it is a new safety rule |
| A8b-5 | **"Approval Creator":** (a) the requester who creates the permit, (b) a person who formally raises the request on the requester's behalf, or (c) the admin who designs the flow? | (a), unless you meant (b) | The first node of the flow is labelled and authorised accordingly |

### A9 — Work Order entitlement (v3: decided by the owner)

| Plan | Work Order |
|---|---|
| Starter | **No access** |
| Professional | Access |
| Business | Access |

**Two separate checks, both always applied:**
1. **Subscription entitlement:** may this tenant use Work Order at all?
2. **Document quota:** may it create another Work Order document now? Document-based, like PTW,
   with a reference cost of **Rp600 per document**.

**Engineering approach (no new billing engine):**
- PTW's metering (`PtwQuotaService`, `ptw_quota_grants`, `ptw_quota_consumptions`, top-up packs
  sold through the existing invoice flow) already has everything needed:
  - monthly included grants;
  - purchased packs that carry forward;
  - **one consumption per document, row-locked**, so a retry or a double submit cannot charge
    twice;
  - an idempotent credit per invoice.
- It is **generalised** to a document *kind* (`ptw`, `work_order`) with **additive** columns.
  PTW's behaviour, tables and figures are unchanged, and a test pins that.
- The Work Order **entitlement** is the plan's workspace grant, the same question every gate
  already asks (ADR 061). My Work, menus and links ask it too.

### W1–W3 — Work Order quota decisions (the owner's call)

| # | Question | Recommendation | Why |
|---|---|---|---|
| **W1** | Included Work Order documents per month | **Professional 100, Business 300** | Below PTW on purpose: a Work Order spans days or weeks and a PTW is per shift or task, so companies raise several permits per Work Order. **Please set the real numbers** |
| **W2** | Is the quota **shared with PTW** or **separate**? | **Separate pools, same price ladder** (Rp1.000 / Rp800 / Rp600 per document in packs of 50 / 150 / 500) | A shared pool lets heavy Work Order use silently consume a crew's permits, which is a safety-side surprise |
| **W3** | **When is a document counted?** | **At creation, as PTW does** (one rule for both; the number is issued then). A cancelled document is **not refunded**, same as PTW. A failed save is never counted (same transaction) | Alternative: count at submission, so abandoned drafts are free but the two document types behave differently |

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

### My Work and Work Order (owner, v3 — rules, not decisions)
- Work Orders can be shown and actioned in My Work by people who hold the permission.
- My Work **never**:
  - opens Work Order to a Starter tenant;
  - bypasses the subscription entitlement or the document quota;
  - turns an approval right into a right to manage every Work Order. An approver sees and decides
    the Work Orders **waiting on them**, and nothing more.
- My Work is where **permitted** actions are done, not a shortcut into modules outside the plan.
  Every item it lists links to a page that answers 200 for that person, and every action goes
  through the same server gate as the module (ADR 061). Tests pin each refusal: Starter, no quota,
  approver-only.

### A15 — The leftover "Enterprise" plan row
- **Fact:** `packages` still holds an `enterprise` row (Rp2.499.000, `is_public = 0`), kept so any
  tenant subscribed to it keeps working. The **development seeders** also put the local test tenant
  on Enterprise.
- **Recommend:**
  1. Move development and test seeding to **Business** now (routine; no production effect).
  2. Check production for tenants still on Enterprise, through A5's read-only report or the Master
     Admin tenant list.
  3. Then move any such tenant to Business (a commercial decision, per tenant) and only after that
     remove the row and the `enterprise` entries in `config/plans.php`.

  Deleting it first would strand a paying tenant.
- **Decision:** confirm steps 2–3.

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
| **M0 — Fast foundations** (routine) | F1 history prop ✔; PTW `verified` count ✔; F4 request memo for settings and entitlements ✔ (`/tasks` 70 → 27 queries, `/dashboard` 191 → 73, live poll 60 → 19); F2 per-page chunks with idle prefetch ✔ (entry 2.3 MB → 329 KB; throttled phone-class load: usable 3.45 s → 1.96 s, in-app click 977 → 549 ms); F3 Google Fonts only on signed-out pages, Archivo from `resources/fonts` ✔; Settings split; dev seeders off Enterprise (A15 step 1) | — | `#status/in-progress` (5 of 7 done on branch) |
| **M1 — Platform foundations** | Redis (A1); Reverb (A3); COS with storage registry and off-site backups (A4); production perf report (A5); `record_changes` audit (A13); **notification event layer** (section 3: catalogue, preferences, delivery log, retries, reminders) | A1, A3, A4, A5, A13 | `#status/planned` |
| **M2 — My Work and role dashboards** | My Work as the personal workspace (waiting on me, assigned to me, my permits and WOs, deadlines, by urgency; mobile-first); role layers on every overview for Workforce, Supervisor, Manager and Company Admin | D1, A14 | `#status/planned` |
| **M3 — Work Order** | The owner's six-section bilingual Work Order, urgency, configurable sign-off and close-out (or QC link), PTW Requirement Yes/No and types, approval to Authorized, PTW creation from an Authorized Work Order, the Execution reminder, the document/PDF. **Professional and Business only**, with a document quota on the generalised PTW metering | A10, W1–W3 (A9 decided) | `#status/planned` |
| **M4 — Visual Approval Flow** | React Flow editor; engine v2 extended (positions, conditions, versions); the PTW flow **as decided in A8** (stages 1..N, company-chosen Final Verifier, scheduled automatic activation); Work Order sign-off on the same engine | A7, A8b | `#status/planned` |
| **M5 — PTW enhancement and Live PTW Dashboard** | Bilingual, grouped, mobile-first PTW form with clear mandatory rules; lifecycle (scheduled activation, expiry, extension, close-out); the live board (by state, awaiting my stage, starting soon, ending soon, expired awaiting close-out, gas test due; filters by unit, area, type and status; real-time) | A8, M4 | `#status/planned` |
| **M6 — SIMOPS** | Work Area master; area on PTW/WO; conflict detection and matrix; SIMOPS board; acknowledgements | A12 | `#status/planned` |
| **M7 — Form Builder** | Form.io `@formio/js` (MIT) with JS logic disabled, JSON Logic conditions, IOMS theme, versioned definitions, server validation; first consumers are Work Order close-out/inspection and PTW per-type checklists, then HSE, HRD and Logistics forms | A6 | `#status/planned` |
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
| F4 request memo (settings map per tier, entitlement grants) | `#status/implemented` | `App\Support\RequestMemo` + `RequestMemoScope`; inactive outside a request (queue worker safe); `OperationalFoundationsTest` |
| F2 per-page chunks, F3 fonts | `#status/implemented` | `app.jsx` lazy glob + idle prefetch; release check still 186/186 pages; `@guest` font stylesheet; measured throttled load −43% |
| Decision pack A1–A14 + D1 | `#status/planned` | Section 4. v3: **A8 and A9 decided by the owner**; A6 re-evaluated (open source); A8b, W1–W3 and A15 added |
| M0 remainder, M1–M9 | `#status/planned` | Section 5 |
