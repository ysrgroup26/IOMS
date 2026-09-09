# IOMS — UX Architecture Discovery

**Status:** Discovery / proposal. Phases 1–2 (forms) implemented in v2.65.0 and v2.66.0, Phase 5
(accessibility) implemented for the shell in v2.67.0 — see §16
and the rollout log at §20.

> **Correction (v2.65.0).** §8 and §16 recommend adopting `Combobox` for option lists over ~15.
> That was wrong and is superseded: `Combobox` returns free text and must never back a foreign key.
> A new `SearchableSelect` primitive covers id-backed lists; see its own doc comment for how the
> three selectors divide.

**Scope:** Navigation architecture and data-entry experience.
**Audited against:** v2.64.0, commit `419ed7a`.

---

## 1. Executive Summary

Two things are true at once, and holding both is the point of this document.

**The existing architecture is better than it looks.** The workspace registry
(`resources/js/lib/workspaces.js`) is a genuinely good piece of design: one declarative source of
truth, a two-tier model (`department` vs `global`), recursive permission gating, DB-driven
label/icon/order overrides, and route-derived active state with no client-persisted "last
workspace". The mobile bottom bar already derives from the exact same filtered array rather than
duplicating it. Almost none of this needs replacing.

**The problem the user reports is real, and it is not a bug.** The sidebar changing completely
between Dashboard and HSE is the intended behaviour of a decision recorded in v1.10.2. The design
gave one screen slot two mutually exclusive jobs — "this department's menu" *or* "the global/admin
menu" — and made them alternate. The Dashboard belongs to no department, so it gets the admin menu.
That is why it feels like a different application: **structurally, for that moment, it is one.**

The fix is not a new navigation system. It is **splitting one slot into two persistent zones** so
that platform identity and workspace context are visible simultaneously instead of taking turns.
That is an evolution of what exists, mostly in `AuthenticatedLayout.jsx`, and it scales to any
number of workspaces without a redesign.

On forms, the finding is sharper than "they feel like CRUD". There is already a consistent
convention (27 modules, one `Form.jsx` each) and there are already good primitives. What is missing
is a **shared form contract**. Measured across all 27 authenticated module forms:

| Property | Forms that have it |
|---|---|
| Required-field marking | **0 / 27** |
| Validation error summary | **0 / 27** |
| Sticky / always-reachable save | **0 / 27** |
| Unsaved-changes protection | **0 / 27** |
| Searchable selector for large lists | **2 / 27** (25 use a raw `<Select>`) |
| Sectioned with explanatory descriptions | **1 / 27** (PTW) |

The public marketing signup form (`Pages/Public/GetStarted.jsx`) already contains a `Field`
component that marks required fields, renders hints, and shows errors with an icon. **The pattern
exists in this codebase and was never brought inside the product.** That is the cheapest, highest-
leverage change available.

---

## 2. Current UX Architecture Assessment

**What is strong**

- One declarative navigation registry, not per-page menus.
- Permission gating is recursive and server-derived (`is_admin`, `enabled_modules`,
  `department_key`, `workspace_catalog`), and the frontend only ever *hides* what the server already
  refuses. Navigation visibility is subordinate to authorization, as required.
- Active workspace is derived from the route, never remembered. "No workspace active" is a
  first-class state rather than an edge case.
- The mobile bottom bar consumes the same `visibleNav` array the sidebar renders — no second
  permission system.
- The app shell has one visual identity (navy rail, navy header) since v2.53.0.

**What is weak**

- The sidebar is *modal*: it shows department nav or global nav, never both, so platform identity
  disappears exactly when the user zooms out to the Dashboard.
- There is no persistent "you are here" affordance. The breadcrumb only renders when a nested child
  is active (`Breadcrumb` returns `null` unless `activeItem.children` matched) — so on most pages
  there is no location indicator at all.
- Administration's six sidebar entries all point at **one route** with different `?tab=` values.
- Forms share a file convention but not a behaviour contract.

---

## 3. Current Navigation Architecture

### 3.1 What exists

```
TopBar     Dashboard · Calendar · [Department ▾] ............ clock · search · notifications · user
Sidebar    (navy rail) — EITHER the active department's items
                        OR the global nav (Reports + Administration), flat
Mobile     bottom tab bar (Dashboard pinned + first N items of visibleNav + "More")
```

The registry defines 12 workspaces plus `reports` and `administration` (`tier: 'global'`).

### 3.2 Why the Dashboard/sidebar transition happens

Three lines, and they are all deliberate:

1. Items marked `global: true` — the "Dashboard" link repeated inside every department — are
   **excluded** from `PREFIX_TO_WORKSPACE`, so that visiting the Dashboard does not falsely
   highlight whichever department defined the link last.
2. Therefore `getWorkspaceKeyForRoute('dashboard')` returns `null`.
3. `AuthenticatedLayout` reads that null and falls back:
   ```js
   const visibleNav = isDepartmentUser
       ? (selectableDepartments[0]?.items ?? [])
       : (activeWorkspace?.items ?? getGlobalNavItems(...));   // ← the swap
   ```
   `getGlobalNavItems()` returns Reports + Administration merged flat — exactly the list the user
   quoted: Reports, Analytics, Report Center, Users, Departments, Positions, Operating Units,
   Settings, Module Management, Audit Logs.

### 3.3 Who actually experiences it

This matters for scoping the fix, and it narrows the problem considerably:

| Audience | Dashboard sidebar shows | Verdict |
|---|---|---|
| **Administrator** | Reports + full Administration (10 items) | **The reported problem.** Whole menu swaps. |
| Non-admin office user | Reports, Analytics, Report Center only | Mild — but the department menu still vanishes. |
| **Department User** (`department_key` set) | Their own department, always, on every page | Already correct. Never sees global nav. |
| Field user (My Work) | Same as Department User | Already correct. |

**The department-user and field experiences are already right and must not regress.** The problem is
specifically the administrator's zoomed-out state.

### 3.4 Same problem, worse on mobile

`MobileBottomNav` receives `visibleNav`. On the Dashboard that array is the admin menu, so an
administrator's *primary mobile navigation* becomes Reports/Users/Positions/Settings. On a phone
there is no sidebar visible alongside to provide context, so the discontinuity is sharper.

### 3.5 Administration is a menu over a monolith

The six Administration entries (`Users`, `Departments`, `Positions`, `Operating Units`, `Settings`,
`Module Management`) are all `settings.index` with a `?tab=` parameter. The destination is a single
**2,526-line, 17-tab page**. The sidebar advertises structure the page does not have.

### 3.6 Two parallel department concepts

`WORKSPACES` (frontend registry, by `key`) and `config('departments')` (route prefixes, used for
department-user confinement) describe overlapping things in two places and are kept in sync by
hand. `workspaces.js` notes this; `config/departments.php` carries a "keep in sync" comment. Not
today's problem, but it is the thing most likely to break when workspace count grows.

---

## 4. Future Navigation Architecture Proposal

### 4.1 Mental model

> **IOMS is one platform. A workspace is a lens on it, not a place you go instead of it.**

The current model treats a workspace as a *mode* the app enters. The proposed model treats it as a
*filter* the app applies, with the platform frame always present.

### 4.2 Proposed structure — two persistent zones

Replace the single alternating sidebar with a **workspace rail** plus a **context panel**. Both are
always present; neither is ever replaced by the other.

```
┌────┬──────────────────────┬──────────────────────────────────────┐
│    │  CONTEXT PANEL       │  TopBar: breadcrumb · search · user   │
│ R  │                      ├──────────────────────────────────────┤
│ A  │  Health, Safety &    │                                       │
│ I  │  Environment         │   page content                        │
│ L  │  ─────────────       │                                       │
│    │  Overview            │                                       │
│ ▪  │  Permit & Work ▾     │                                       │
│ ▪  │    PTW               │                                       │
│ ●  │    Gas Test          │                                       │
│ ▪  │    LOTO              │                                       │
│ ▪  │  People & PPE        │                                       │
│    │  Waste Management    │                                       │
│ ── │                      │                                       │
│ ⚙  │                      │                                       │
└────┴──────────────────────┴──────────────────────────────────────┘
  ↑        ↑
  │        └── changes with the workspace
  └── NEVER changes: every entitled workspace, always visible
```

**The rail** (~56px, icon-only with tooltips): every workspace the user is entitled to, in registry
order, with the active one marked. Below a divider, the pinned platform destinations —
Dashboard/Reports/Administration. The rail is the answer to *"what does IOMS contain"* and
*"where am I"*, and it is **identical on every page**. That single property removes the
"different application" feeling entirely.

**The context panel**: the active workspace's items — exactly today's `visibleNav`, unchanged.

**The Dashboard** stops being a context-less hole. The rail is unchanged; the context panel shows a
short *Platform* list (Dashboard, Calendar, Reports, Analytics, Report Center). Administration moves
out of that fallback and becomes its own rail destination.

### 4.3 Why this over the alternatives

| Alternative | Why not |
|---|---|
| One giant sidebar with every module | The user explicitly ruled it out, and rightly — cognitive load, and it degrades as workspaces grow. |
| Keep alternating, add a header label | Treats the symptom. The menu still swaps; the label just narrates the swap. |
| Top-level horizontal workspace tabs | Breaks at ~8 items, and this product will exceed that. Vertical rails scale by scrolling. |
| Command palette as primary nav | Excellent as an *accelerator*, useless as the only answer to "what does this product contain". |

### 4.4 Role and entitlement interaction

No new gating. The rail is `getVisibleWorkspaces()` — already filtered by `is_admin`, `moduleKey`,
`departmentUserOnly` and the DB catalog.

- **Department User:** the rail renders their single entitled workspace plus pinned items. It does
  not become a discovery surface for things they cannot reach — the same collapse
  `getSelectableDepartments()` already performs.
- **Administrator:** full rail.
- Nothing in the frontend may widen access. Server middleware, policies, `CompanyOwnedScope` and
  entitlements remain authoritative, exactly as today.

### 4.5 Mobile strategy

Do not put the rail on a phone. Instead:

- **Bottom bar stays** and keeps deriving from `visibleNav` — but it should derive from the *active
  workspace's* items on every page, including the Dashboard, which the two-zone model makes possible
  because a workspace is always resolvable.
- **"More" opens a drawer showing the rail as a list** — workspace switcher and context items in one
  sheet. This is where mobile users get the "what does IOMS contain" answer.
- Field users are untouched: My Work stays their landing surface.

### 4.6 Scalability

Adding a workspace stays a single entry in `WORKSPACES`. The rail grows by one icon and scrolls; the
context panel is unaffected. At roughly 15+ workspaces the rail should gain lightweight grouping
(Operations / Support / Platform) — a presentational change to one component, not a re-architecture.

---

## 5. Navigation Migration Strategy

**Reuse unchanged**

- `WORKSPACES`, `tier`, `applyItemGates`, `applyCatalog`, `getVisibleWorkspaces`,
  `getSelectableDepartments`, `getWorkspaceKeyForRoute`, `isDepartmentWorkspaceKey`
- All server props and all gating
- `MobileBottomNav`'s derivation contract
- Route names — **no route changes at all**

**Evolve**

- `AuthenticatedLayout.jsx` — the layout split; the largest single change.
- The `visibleNav` fallback: instead of swapping the whole menu, resolve a `platform` pseudo-
  workspace so the context panel always has content and the rail never changes.
- `Breadcrumb` — render on every page (`Workspace › Section › Page`), not only for nested children.

**Deprecate**

- `getGlobalNavItems()`'s role as a *whole-sidebar replacement*. The function can stay; what changes
  is that its output populates a pinned rail section rather than displacing the panel.
- The per-workspace repeated `{ name: 'Dashboard', global: true }` items — the rail pins Dashboard
  permanently, so the repetition (already a source of the v2.35.0 duplicate-tab bug) can go.
- The Department Selector dropdown in the TopBar becomes redundant once the rail exists. Keep it
  through one release, then remove.

**Leave alone**

- Tenant isolation, RBAC, entitlements, PTW authorization, approval authorization, subscription
  logic, payment, authentication, `config/departments.php`.

---

## 6. Current Form UX Assessment

Structure is consistent — 27 modules, one `Form.jsx` each, serving create and edit. Behaviour is
not. The measurements in §1 are the assessment; the interpretation follows.

**Fields are grouped visually but not semantically.** Most forms are one `<Card>` titled with the
entity name, containing stacked `grid-cols-1 sm:grid-cols-2` rows. `Employees/Form.jsx` puts ~20
fields under a single heading, "Employee Information". The grid provides rhythm; nothing provides
*meaning*. Nothing explains why a field is needed or which fields relate.

**Required fields are invisible.** Not under-emphasised — genuinely unmarked in all 27 forms. The
user discovers requiredness by submitting and failing.

**Validation failure is silent at the top.** Errors render per field
(`{errors.x && <p className="text-xs text-red-600">…</p>}`). On a 20-field form with the failure
below the fold, the page appears to do nothing. No summary, no focus move, no scroll.

**Save can be off-screen.** No form has a sticky action bar. On mobile the submit button on a long
master-data form is a long scroll from the field being edited.

**Unsaved work is unprotected.** No `beforeunload` or Inertia `onBefore` guard in any module form. A
misclick on a half-completed PTW loses it.

**Selectors do not scale.** `Combobox` and `EmployeeSelector` exist and are good. Two forms use
them. Twenty-five use a raw `<Select>` — including Employee → Operating Unit → Department →
Position, which is exactly the chain that grows with tenant size.

**Conditional logic exists but is unexplained.** `Employees/Form.jsx` disables Department until an
Operating Unit is chosen and swaps the placeholder to "Select operating unit first" — good, and the
only instance of that care. Elsewhere dependent fields are simply `disabled` with no reason given.

**Settings is the outlier.** One page, 17 tabs, 2,526 lines, five visual clusters. It is
configuration presented as a filing cabinet.

---

## 7. Form Taxonomy

| Type | Examples | Defining trait | Right interaction model |
|---|---|---|---|
| **A. Workflow / transaction** | PTW, Material Request, Purchase Requisition/Order, Incident, NCR, Work Order, Daily Report | Creates a record that will be *approved* and has a lifecycle | Sectioned, numbered, progressive disclosure; state what happens after save |
| **B. Master data** | Employee, Department, Position, Asset, Item, Vendor, Project, PPE Type | Reference data entered once, edited rarely, read constantly | Dense but *grouped*; efficiency over guidance; **not** a wizard |
| **C. Configuration** | Branding, Numbering, Approval Flow, Modules, Roles, Documents | Changes how the system behaves for everyone | Explain consequence; preview where possible; separate destructive actions |
| **D. Conditional / dependent** | Employee (unit→dept→position), PTW (permit type → controls), Stock transactions | Later fields depend on earlier answers | Progressive disclosure with *stated* dependencies |
| **E. Field capture** *(added)* | My Work quick actions, Safety Observation, Gas Test | Entered on a phone, on site, possibly one-handed, often in a hurry | Large targets, minimum fields, defaults, no horizontal grids |

Category E is the one the brief did not name and the product most depends on — IOMS's differentiator
is that field workers actually use it.

---

## 8. Proposed IOMS Form Experience System

The smallest contract that fixes the measured gaps. Five components, no framework.

**`FormSection`** — titled group with an optional one-line description in *question* form ("Who is
involved in this work?"). Optional step number. Replaces the bare `<Card>` + entity-name title.

**`Field`** — label, required marker, hint, error with icon. **Promote the existing component from
`Pages/Public/GetStarted.jsx`** into `Components/shared/form/`. It already does exactly this.

**`FormActions`** — sticky footer on mobile, inline on desktop. Primary action, cancel, and a
destructive slot that is visually separated.

**`ErrorSummary`** — renders above the form when submission fails: count, list, each entry linking
to and focusing its field. Also the accessibility fix (§12).

**`useUnsavedChanges`** — one hook comparing current `data` to initial, wired to Inertia's `onBefore`
and `beforeunload`.

**Rules**

- Selector rule: **more than ~15 options → `Combobox`, not `<Select>`.**
- Grid rule: never more than 2 columns on a form; always `grid-cols-1 sm:grid-cols-2`.
- Measure rule: `max-w-3xl` for workflow forms (PTW already does), `max-w-4xl` for dense master data.
- Optional fields go last, or inside `CollapsibleSection`. Never interleaved with required ones.
- Every form states what happens after save when it is not obvious ("This will be submitted to HSE
  for approval").
- Destructive actions never sit beside Save.

**What NOT to do**

- No multi-step wizards for master data. An Employee form is 20 fields an HR admin enters weekly;
  paginating it makes it slower.
- No converting every card into a section for its own sake.
- No animation on form transitions.

---

## 9. PTW Lessons

**Why it feels better** (`Pages/PermitsToWork/Form.jsx`):

1. **Numbered sections** (`StepBadge` 01–04) create a reading order and a sense of finite length.
2. **Descriptions ask questions** — "Siapa yang terlibat dalam pekerjaan ini?" — so the section
   explains its own purpose rather than naming a table.
3. **Progressive disclosure** — Optional/Advanced is collapsed via `CollapsibleSection`.
4. **A real selector** — `EmployeeSelector` searches server-side instead of rendering a 250-option
   dropdown.
5. **Constrained measure** — `max-w-3xl`, so no field line is uncomfortably wide.
6. **Explicit mobile fallbacks** — every grid has a `sm:` breakpoint.
7. **Visual continuity with its output** — the step badges match `Document.jsx`'s section numbering,
   so the form and the printed permit read as one artifact.

**Should become reusable:** 1, 2, 3, 4, 5, 6.

**Should stay PTW-specific:** the 01–04 step numbering as a *sequence* (justified by a genuinely
sequential safety process — misleading on master data), and the form↔document numbering symmetry
(PTW produces a formal printed permit; an Employee record does not).

---

## 10. Reusable UX Patterns — the minimum set

**Navigation (2):** `WorkspaceRail`, `ContextPanel` — plus making the existing `Breadcrumb`
unconditional.

**Forms (5):** `FormSection`, `Field`, `FormActions`, `ErrorSummary`, `useUnsavedChanges`.

**Already exist, need adoption not creation:** `Combobox`, `EmployeeSelector`, `CollapsibleSection`,
`PageHeader`, `SectionHeader`, `StatusBadge`, `FilterBar`, `EmptyState`.

Seven new components total. Deliberately small — the failure mode here is a speculative form
framework nobody adopts.

---

## 11. Future-Proofing Assessment

| Growth | Rail model | Today's model |
|---|---|---|
| 12 → 25 workspaces | Rail scrolls; add grouping | Selector dropdown becomes a scroll-hunt |
| Deeper module trees | Context panel already recurses | Same |
| More roles | Same gating | Same |
| More Operating Units | Unaffected (unit scope is orthogonal) | Same |
| New global surfaces | Pinned rail section | Each one enlarges the fallback menu that replaces the sidebar |

The one structural debt to plan for is §3.6 — two department definitions kept in sync by hand.
Worth an ADR before workspace count grows, not worth fixing in this pass.

---

## 12. Accessibility Assessment

**Present:** semantic controls throughout, Radix primitives for tabs/dropdowns/dialogs (focus
management for free), `motion-safe:` discipline, visible focus rings on public pages,
`prefers-reduced-motion` respected in the public motion system.

**Gaps to close in this work:**

- **Validation is not announced.** Per-field `<p>` elements are not linked to their inputs. `Field`
  should wire `aria-describedby` and `aria-invalid`, and `ErrorSummary` should be a focus target on
  submit failure — the single largest accessibility win available.
- **Icon-only rail needs names.** Every rail item needs an accessible label and a visible tooltip;
  the active item needs `aria-current="page"`. — **DONE, v2.67.0.** See §21.
- **No skip link** to main content past the nav. — **DONE, v2.67.0.**
- **Required state is not programmatic** — no `required`/`aria-required` anywhere in module forms.

---

## 13. Performance Considerations

- The rail renders ~15 icons and is static across navigations — cheaper than today's alternating
  menu, which re-derives a different array on Dashboard.
- `Combobox` adoption *reduces* DOM: a 250-option `<Select>` renders 250 nodes; a searchable input
  renders what it shows.
- `Settings/Index.jsx` at 2,526 lines ships every tab's markup on every visit. If Settings is split
  (§16 Phase 4), that is a real bundle win.
- No new dependencies are proposed. Bundle is ~433 kB gzipped; none of this meaningfully adds to it.

---

## 14. Product-Fidelity Findings

### 14.1 Release dates are ~20 days in the future — **confirmed**

The screenshot showed "Version 2.64.0 Released — Sep 29, 2026" beside a header reading "Wed, Sep 9".
The two come from different sources and **the release metadata is the wrong one**:

- The header is `useClock()` → the browser's live local date. **Correct.**
- The release line is `config('ioms.release_date')` — a hand-written string.

Verified against git:

| Version | Declared `date` | Actual commit date | Drift |
|---|---|---|---|
| 2.64.0 | 2026-09-29 | 2026-09-09 | **+20 days** |
| 2.63.0 | 2026-09-28 | 2026-09-08 | +20 days |
| 2.62.0 | 2026-09-28 | 2026-09-08 | +20 days |
| 2.61.0 | 2026-09-27 | 2026-09-08 | +19 days |
| 2.60.0 | 2026-09-26 | 2026-09-08 | +18 days |

**Not** a timezone issue, not stale seed data. `release_date`, `build` and every `version_history`
date are manual fields that no test checks, and each release has been written by incrementing the
previous entry rather than reading the clock. The drift compounds.

This is disclosed to customers on the About dialog and the release panel, so it is customer-facing
inaccuracy, not just internal untidiness.

**Recommended fix (small, separate from this UX work):** assert in the test suite that
`config('ioms.release_date')` is not in the future, and that the newest `version_history` entry
matches `release_date`. That converts a silent drift into a failing test.

**Disclosure:** I wrote the v2.64.0 entry in the previous session and continued the pattern without
checking it against the clock.

### 14.2 Administration menu overstates structure

Six sidebar items resolve to one route and one 17-tab page (§3.5). Low severity, but it is the kind
of mismatch that erodes trust in navigation.

---

## 15. Risks / Trade-offs

| Risk | Severity | Mitigation |
|---|---|---|
| Rail costs ~56px of horizontal space | Low | Icon-only; collapses under `lg` where it is not rendered |
| `AuthenticatedLayout` is 876 lines; a layout split touches every page | **High** | Phase it; ship the rail behind the existing derivation before removing the old fallback |
| Icon-only rail is less legible than labels | Medium | Tooltips + labelled drawer on mobile; rail is a *switcher*, the panel carries labels |
| Users are used to the current model | Low–Medium | Nothing moves destination; only where the switcher lives |
| Form component adoption stalls halfway | **Medium** | Convert by category, complete each category before starting the next; leave none half-migrated |
| Scope creep into a form framework | Medium | Seven components, hard cap |

**The honest trade-off:** the two-zone model spends permanent screen space to buy permanent
orientation. On a 1280px laptop that is ~4% of width. I judge that worth it for a product whose
navigation currently disorients its administrators — but it is a real cost and it is the product
owner's call.

---

## 16. Recommended Implementation Phases

Ordered by value per unit of risk. Each phase ships independently.

**Phase 1 — Form primitives** *(low risk, high value, no navigation change)* — **DONE, v2.65.0.**
Shipped six primitives (the five proposed plus `SearchableSelect`) and converted four forms covering
the taxonomy. Four latent defects surfaced during conversion; see the release notes.
Extract `Field` from `GetStarted.jsx` into `Components/shared/form/`. Add `FormSection`,
`FormActions`, `ErrorSummary`, `useUnsavedChanges`. Convert **three** representative forms — one per
category — and stop. Validate the contract before scaling.

**Phase 2 — Form rollout by category** — **DONE for master data, v2.66.0.** See §20.
Master data first (most forms, most repetition), then workflow, then field capture. Adopt `Combobox`
wherever an option list can exceed ~15. Configuration last — it depends on Phase 4.

**Phase 3 — Navigation two-zone model**
`WorkspaceRail` + `ContextPanel` in `AuthenticatedLayout`. Resolve a `platform` pseudo-workspace so
the panel always has content. Make `Breadcrumb` unconditional. Update the mobile drawer. Remove the
Department Selector only after a release of overlap.

**Phase 4 — Settings decomposition**
Split the 17-tab monolith along the five clusters that already exist as visual groupings. Makes the
Administration sidebar entries honest and cuts the bundle.

**Phase 5 — Accessibility completion** — **DONE, v2.65.0 (forms) + v2.67.0 (shell).** See §21.
`aria-describedby`/`aria-invalid` wiring, focus-on-error, skip link, rail labelling.

**Separate, not part of this work:** the release-date test (§14.1). One test, one commit, today.

---

## 17. Example Target Experiences

*Conceptual descriptions, not specifications.*

**Master data — Employee.** Stays one page, no wizard. Four `FormSection`s instead of one card:
*Identity* (name, employee ID, status, type), *Placement* (Operating Unit → Department → Position,
each a `Combobox`, each stating its dependency in the hint when disabled), *Employment* (dates,
contract), *Contact & Documents* (collapsed by default — rarely edited). Required fields marked.
Save is sticky on mobile. Leaving with unsaved changes asks first. Roughly the same density as
today, but a scannable one: four ideas instead of twenty fields.

**Settings — Numbering.** Configuration, so it explains consequence. Each document type is a row
showing its **current pattern and a live example** (`PTW-2026-00185`). Editing opens a drawer with
the pattern builder and a preview that updates as you type, plus a plain-language note that changing
the format does not renumber existing documents. Reads as a control panel, not a table of strings.

**Workflow — Material Request.** Adopts PTW's grammar without copying its layout: numbered sections,
question-form descriptions, line items in a proper editable table with a sticky "add row" and a
running total, optional fields collapsed. Above the actions, one line: *"This will be submitted to
your Project Manager for approval."* — answering "what happens after I save?" before it is asked.

**Navigation — Administrator on the Dashboard.** The rail is exactly as it was on the HSE page:
every workspace, HSE no longer marked active, Dashboard marked instead. The context panel shows a
short Platform list. The breadcrumb reads `IOMS › Dashboard`. **Nothing about the frame changed** —
the user zoomed out, they did not change application. Clicking HSE in the rail returns them with the
panel repopulated and the breadcrumb reading `IOMS › Health, Safety & Environment › Overview`.

---

## 18. Decisions Required From Product Owner

1. **Does the rail earn its screen space?** (§15) The one genuinely subjective trade-off here.
2. **Retire the Department Selector dropdown once the rail ships,** or keep both permanently?
   Recommendation: retire after one release of overlap.
3. **Phase order** — forms first (my recommendation: more users affected daily, far lower risk) or
   navigation first (more visible, better demo)?
4. **Settings decomposition appetite.** Phase 4 is the largest single refactor proposed and is
   deferrable indefinitely without blocking anything else.
5. **Should release dates be automated** from the release commit rather than hand-written? (§14.1)
6. **Language policy for form content.** Navigation is English, page content Indonesian (a recorded
   decision). New form hints and "what happens next" copy will be *content* — confirm they follow
   the Indonesian rule inside the product, as PTW does today.

---

## 19. Final Recommendation

**Do Phase 1 first, and do it narrowly.** The form gaps are measured, unambiguous, affect every user
every day, and the fix is seven small components — one of which already exists in the repository and
only needs moving. There is no architectural risk.

**Then Phase 3.** The navigation problem is real and worth solving, but it touches an 876-line layout
that every authenticated page depends on, and the current model is *working* — it is disorienting,
not broken. It deserves to be done deliberately, after the low-risk win has landed.

**Do not** rebuild the workspace registry. It is the strongest part of the current architecture and
the proposal depends on keeping it.

**Do not** let this become a component framework. Seven components, then stop.

The success criteria in the brief map cleanly onto the two phases:

| Question | Answered by |
|---|---|
| Where am I in IOMS? | Rail + unconditional breadcrumb (Phase 3) |
| What workspace am I using? | Rail active state (Phase 3) |
| What can I do here? | Context panel (Phase 3) |
| Where should I go next? | Rail (Phase 3) |
| What information do I need to enter? | `FormSection` + required marking (Phase 1) |
| Why is this information required? | Section descriptions + field hints (Phase 1) |
| What happens after I save? | Workflow form outcome line (Phase 2) |

---

## 20. Rollout log — what the master-data pass actually taught us (v2.66.0)

### 20.1 What was upgraded

| Form | Shape | Notable change |
|---|---|---|
| Vendors | Page, 23 fields | Operating Unit moved from second-to-last to the identity group |
| Projects | Page, 7 fields | Contract kept, structure barely touched — see §20.3 |
| Contractors | Page, 6 fields | "Company" → "Operating Unit"; two adjacent fields had meant opposite things |
| Visitors | Page, 8 fields | Host picker → `EmployeeSelector`; directory no longer preloaded |
| PPE Types | Dialog | First dialog conversion; established the dialog rule |
| Settings → Departments | Dialog | Operating Unit → `SearchableSelect` |

### 20.2 The system changed once, and it was a subtraction

The rollout hit a real limit: **`FormActions` and `DialogFooter` both want to be the action area**,
and an `ErrorSummary` above five fields that are already entirely on screen restates what the reader
can see. The temptation was a second, dialog-flavoured set of components.

The answer was to use *less* of the system, not to grow it:

> **Page forms** take `FormSection` + `FormField` + `FormActions` + `ErrorSummary`.
> **Dialog forms** take `FormField` only — `DialogFooter` is already the action area, and a dialog
> small enough to see whole does not need a summary.

No new component. The rule is recorded in `Components/shared/form/index.js` and pinned by a test.

### 20.3 New principle: ownership belongs near the top

Three of the four page forms buried the Operating Unit field near the bottom — Vendors had it
second-to-last, under a heading reading "Capability & Notes". That field decides **who owns the
record and therefore who will ever see it again**. Master data is created so the rest of IOMS can
rely on it, so the reader has to know whose register they are writing into *before* they fill it in.

**Ownership and context fields belong in the first section, beside identity.**

### 20.4 New principle: not every form needs restructuring

Projects is seven fields. It did not have a hierarchy problem, and inventing sections for it would
have added ceremony without clarity. It got the *contract* — accurate required marking, findable
errors, a reachable action bar, unsaved-change protection — and almost nothing else.

**The contract is the valuable part and applies everywhere. The restructuring is situational.**

### 20.5 The `<select>` over a directory keeps reappearing

`EmployeeSelector` was built in v2.38.0 for exactly this and named seven offending controllers.
v2.65.0 found an eighth (Assets), v2.66.0 a ninth (Visitors). Each one shipped the entire employee
directory to render a dropdown nobody could scroll. **When adding an employee field, reach for
`EmployeeSelector` and do not add an `employees` prop to the controller.**

### 20.6 Intentionally left unchanged

- **The 20 workflow/transaction forms** (PTW, Incidents, JSA, HIRADC, Purchase Orders, Goods
  Receipts, Daily Reports, Tasks, Leave, LOTO, TBM, RFQ, Work Orders, NCR, Maintenance Requests,
  Inspection Requests, Safety Observations, HSE Inspections, Purchase Requisitions, Risk
  Assessments). Out of scope for a master-data phase, and several are approval-bearing — they
  deserve their own pass with the workflow-specific question ("what happens when I submit?") in
  view. Material Request in v2.65.0 is the reference for that pass.
- **Settings → Positions, Operating Units, KPI Categories and the other 13 tabs.** Positions and
  Operating Units are each *two* dialog variants inside the 2,526-line `Settings/Index.jsx`.
  Converting them piecemeal adds edit risk to that file without delivering the structural benefit;
  they should be done as part of the Settings decomposition (§16 Phase 4), where the file is being
  split anyway.
- **`Master.jsx` inline editors** for Items, Warehouses, Shifts and Competency — same reasoning as
  the Settings tabs, and lower traffic than the pages converted here.

### 20.7 Remaining rollout work

1. Settings decomposition + its dialog forms (Phase 4).
2. Workflow forms, as their own pass.
3. `Master.jsx` inline editors.

---

## 21. The application shell (v2.67.0) — and the defect this discovery missed

Phase 5 was scoped as "accessibility completion". Doing it surfaced a **layout** defect that no
part of this document had caught, which is the more useful finding of the two.

### 21.1 The overflow had been named three times and measured zero times

Every authenticated page scrolled sideways across a wide band of viewport sizes. v2.65.0 recorded it
as "roughly 768px to 1100px … the TopBar global search at a fixed `w-[380px]`", v2.66.0 confirmed it
as pre-existing, and both deferred it to the navigation phase. The cause was right. The band was
wrong, and it was wrong in the direction that mattered:

| Viewport | Header overflow, before | After |
|---|---|---|
| 640px | **265px** (41% of the viewport) | 0 |
| 768px (iPad portrait) | **137px** | 0 |
| 820px | 85px | 0 |
| 900px | 5px | 0 |
| 1024px (iPad landscape) | **89px** | 0 |
| 1100px | 13px | 0 |
| 1180px and up | 0 | 0 |

It starts at **640px**, not 768px — exactly where `sm:` reveals the search field. Measured against
the real compiled stylesheet, before and after, at thirteen widths from 320px to 1920px.

**The lesson is about the note, not the bug.** "Known issue, deferred" was recorded three times
without anyone spending the twenty minutes to measure it. Had it been measured once, the 41%
overflow at 640px would have changed its priority immediately.

### 21.2 Why one hard width survived three releases

`w-[380px]` on a flex item with no `min-w-0` cannot shrink. But flexbox's default `shrink: 1` let
*every other* control give way instead, so the symptom presented as "the header looks cramped"
rather than "the header is 137px wider than the tablet it is on". **A shared shrink budget hides
which item is actually at fault.**

The rule now: **exactly one item in a bar may flex.** Every other control is `shrink-0`; the
department selector — the only unbounded string in the header, since customers name their own
departments — may `truncate`. One flexible item makes the result predictable at every width.

### 21.3 A fixed width was never the right shape

One number cannot serve a header whose contents change at four breakpoints. The field now has two
presentations over one piece of state:

| Range | Presentation |
|---|---|
| `< sm` | nothing — unchanged; `MobileBottomNav` owns navigation there |
| `sm → lg` | an icon that opens the field full-size in a popover |
| `lg +` | the inline field; `w-[380px]` is a basis it may shrink **from** |

### 21.4 The same mistake, made twice, four years apart in version numbers

v2.38.0 hid the header's Dashboard link below `sm` because `MobileBottomNav` pins Dashboard as its
first tab. Correct reasoning, **wrong breakpoint** — the bottom nav renders up to `lg`, so Dashboard
was drawn twice on every screen from 640px to 1023px, the exact band with no room to spare.

**When you hide something because another component duplicates it, the breakpoint must be that
component's breakpoint, not a guess.**

### 21.5 The drawer looked modal and behaved like a div

It had a scrim and a close button, and none of the three things a dialog owes its user:

- **Enter** — focus never moved into it.
- **Stay** — Tab walked straight out into the page behind, which was still there and still
  focusable, so a keyboard user operated a page they could not see. Escape did nothing.
- **Return** — closing it dropped focus to `<body>`, restarting the next Tab from the top of the
  document rather than from the control that opened it.

Worse, a **closed** drawer is moved off-screen with a `transform`, so it was still rendered and
still in the tab order — roughly twenty invisible links between the header and the page on every
phone. It is `inert` when closed now.

`useFocusTrap` and `useMediaQuery` are the two primitives this needed. `useMediaQuery` exists for a
reason worth stating: **the rail is a permanent landmark above `lg` and a modal dialog below it, and
`role` is an attribute, not a style.** No CSS media query can say that.

### 21.6 Invalid markup that had a real cost

The drawer's close control was a `<span onClick>` nested **inside** the About button. Interactive
content inside a button is invalid HTML, and the practical cost was that Close could not be reached
or activated from a keyboard at all — on a phone, where it is the only way to dismiss the drawer
short of finding the scrim.

### 21.7 What was verified, and what was not

Stated plainly because the distinction matters:

- **Browser-measured:** the layout, at thirteen widths, before and after, against the real compiled
  stylesheet.
- **Contract-tested and reviewed, not interaction-verified:** the focus trap, `inert`, the skip
  link, accessible names, `aria-current`. Signing in could not be automated in this environment, and
  the shell is React that never runs server-side — an Inertia response is a prop bag containing none
  of this markup. This is the same limit `FormExperienceSystemTest` records.

### 21.8 Still open

1. **The two-zone navigation model (§4)** — untouched. This release fixed how the *existing* shell
   behaves; it did not restructure it. Nothing here blocks that work, and the `shrink-0` discipline
   and the two hooks carry straight into it.
2. **Settings decomposition (Phase 4)** and its dialog forms.
3. **Workflow forms**, as their own pass.
4. **The release-date test (§14.1)** — still one test, one commit.
5. **`CHANGELOG.md` stops at 2.0.0.** Every release from v2.1.0 to v2.67.0 is recorded only in
   `config/ioms.php`'s `version_history`. Reconstructing 23 entries from those summaries is its own
   task and should not be improvised inside an unrelated release.

---

## 22. The product-level reassessment (v2.68.0) — and why it did not become a redesign

This pass began as an open-ended reassessment of the whole product, public and authenticated, with
no assumption that the answer was a redesign. That mattered, because **it wasn't**.

### 22.1 What the running application said that the source could not

Everything in §21.7's "browser-measured" column was measured again, plus the app was driven against
**MySQL with the real seeded tenant** (3 tenants, 68 employees, 29 departments) rather than an empty
database. Three defects surfaced that no amount of source reading would have produced, and all three
needed *real data* rather than merely a running app:

| Defect | Why source reading missed it |
|---|---|
| `Fatality` rendering as `FAT...` | The label is a DB column (`kpi_categories.short_label`, max 20 chars). The JSX says `label={c.short_label}` — correct. The width only fails once eight real categories sit in a `lg:grid-cols-8` strip. |
| A 14-slice rainbow pie | `backgroundColor: CHART_COLORS` reads fine. It only breaks when a tenant has more departments than the palette has colours. |
| A bare `404 NOT FOUND` | The component, the handler and the route table are each correct alone. The gap is in the *guard*, and only a full-page request exposes it. |

The last one is the sharpest reminder of §21's lesson: **the defect was in an intent-versus-reality
gap that a doc comment actively concealed.** The comment said "non-Inertia, non-JSON request (e.g. a
raw asset 404)" — which describes what the author *meant*, and reads as correct, while the condition
as written also caught every ordinary page load.

### 22.2 The finding that turned out to be the product-level one

The brief asked whether the public website and the authenticated product feel like the same product
family. They did not, and the reason was not visual — the visual system is genuinely coherent and was
left alone. **They were not speaking the same language, and neither was the app speaking it to
itself.**

This is written up as convention and pitfall in `docs/CONVENTIONS.md`. The short version: v2.53.0's
language hierarchy is a good rule that lived only in a changelog summary, so it was violated three
times over sixteen releases, and the five department Overview pages were still running the policy it
replaced. The sidebar said `CAPA`; the card underneath said `Tindakan Perbaikan`.

**The fix is a subtraction, not an addition** — the same shape as §20.2. No new abstraction, no
translation layer, no i18n framework. The slot each string already sat in (`title`/`label` versus
`description`/`subtitle`) *already encoded* which language it should be, so the rule could be applied
mechanically and then pinned by a test that reads those same slots.

### 22.3 What was deliberately not touched, and why

Restraint was the main design decision of this pass:

- **The two-zone navigation model (§4).** Still the largest open item, still not done here, and
  deliberately so. §3.3 establishes that it affects *the administrator's zoomed-out state only* —
  department and field users are already correct. It is a restructure of the shell justified by one
  reported experience, and this pass found no new evidence for it while finding several defects that
  were unambiguous. Doing it as a rider on a language-and-quality pass would have been the wrong
  order.
- **Settings decomposition (Phase 4)** and the **20 workflow forms.** Unchanged, for the reasons
  already in §20.6 and §20.7.
- **The visual system.** Navy rail, graphite ground, white raised cards, the `StatCard`/`PageHeader`/
  `EmptyState` family. It is coherent and it is the product's identity. Nothing here recoloured
  anything for the sake of looking different.
- **The shell's accessibility work from v2.67.0.** Re-verified in a browser this time rather than by
  contract test — landmarks, skip link, no unnamed controls on the pages checked — and left alone.
- **Every business boundary.** Tenant isolation, RBAC, entitlements, subscription, payment, approval
  and PTW rules, workflow transitions, server-side validation: untouched. The one change that comes
  near them (`bootstrap/app.php`) alters *rendering* only and is covered by
  `ErrorPagePresentationTest`, which asserts the status code does not move.

### 22.4 A note on `truncate`

The KPI-label defect generalised further than expected. `truncate` had been applied to *labels* in
three separate shared components, which means the same class of defect was one long customer name or
one wide department away in every module that uses them. The convention now recorded — **a card may
clip its value, never its label** — is worth more than the specific 33px fix that prompted it.

### 22.5 Still open

Unchanged from §21.8 except where noted:

1. **The two-zone navigation model (§4)** — see §22.3 for why it is still open on purpose.
2. **Settings decomposition (Phase 4)** and its dialog forms.
3. **Workflow forms**, as their own pass.
4. **The release-date test (§14.1)**.
5. **`CHANGELOG.md` stops at 2.0.0.** Now 24 releases behind (v2.1.0 → v2.68.0).
6. **Bundle size.** `npm run build` emits a single ~1.8 MB JS chunk (~440 KB gzipped) and warns about
   it on every build. Not addressed here — code-splitting an Inertia app is a real piece of work with
   its own verification needs, and it is a performance concern rather than a correctness one — but it
   is the largest unexamined quality item left in the frontend.
7. **Two stat labels clip at 320px**, where a single word is wider than its column. Clean from 375px
   up. Recorded in `docs/CONVENTIONS.md` rather than fixed with a font-size special case.
