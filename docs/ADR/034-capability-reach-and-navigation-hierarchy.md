# 034 — Capability Reach, Navigation Hierarchy, and the Master/Operational Distinction

## Status

Accepted (v2.71.0).

## Problem

Six observations from real use. Auditing them, four were symptoms of two underlying defects and two
were genuine product gaps.

### 1. A capability the product sells was unreachable by the customers it was sold to

"From the HSE department, I need to request goods/materials."

IOMS answers *may this person use this module* in three independent places, and they had silently
diverged:

| Source of truth | What it said about Material Request |
|---|---|
| `User::canManageMaterialRequests()` | `isSuperAdmin() \|\| isHse()` — **HSE owns it** |
| `config/departments.php` | filed under **`logistics`** |
| `config/plans.php` | Starter `['hse']`, Professional `['hse','hr']` — **no `logistics`** |

Two consequences, both confirmed against the running application:

- A Department User in HSE was refused by `RestrictDepartmentAccess` before the request ever reached
  the controller's own permission check.
- Worse: `EnforceTenantEntitlement` resolves a route's owning **workspace** through that same
  department map. So on the two plans that *sell HSE*, the HSE team could not open the module their
  own permission says they manage. **A feature was unreachable for exactly the customers it was sold
  to**, and no error said why — it was a 403 from a layer nobody would think to look at.

This shape had already been found and fixed twice: `permits-to-work` (v2.42.0) and `man-hour`
(v1.11.15). It was found a third time because nothing was asserting the invariant.

### 2. The sidebar scroll reset to the top on every navigation

Reported as "scroll to the bottom, click a bottom item, and the sidebar jumps back to the top".

Root cause, and it is structural: **all 144 authenticated pages wrap their own
`<AuthenticatedLayout>`, and nothing uses Inertia's persistent-layout pattern.** The layout is
unmounted and remounted on every navigation, so the rail's scroll container is a brand-new DOM node —
and a new node starts at `scrollTop = 0`. This was never a route quirk; it happened on every link in
the product.

### 3. The sidebar hierarchy was inverted, not merely "too similar"

| Row | Size | Weight | Ink |
|---|---|---|---|
| Top-level item (Overview, Waste Management) | 13px | 500 | navy-300 |
| **Group header** (Safety Management, Permit & Work Safety) | **11px** | 500 | **navy-400** |
| Child inside that group (PTW, Gas Test) | 13px | 400 | navy-400 |

A group header was **smaller than, and no brighter than, the items inside it** — the least prominent
row in the list was the one naming the section. It was deliberate once (a v1.11.13 note calls it
"SMALLER-LABELED-parent/LARGER-item"), borrowed from the convention where a plain text caption sits
above an ungrouped list. That convention does not survive contact with a *collapsible group*, which
is a control.

### 4. Nothing said which screens configure the system and which record daily work

A configuration screen and a daily-work screen were the same white panel, the same title treatment
and the same blue Save button. The only way to tell them apart was to already know.

### 5. HSE could not tell where field PTW users are managed

The capability was real but split across two roles, with nothing saying so:

| What | Who |
|---|---|
| Creating the account, its email and password | Super Admin (User Management card) |
| PTW Access + field workspace | Super Admin **or** HSE (Field & PTW Access card) |

An HSE administrator saw only the second card and reasonably concluded the feature was missing. And
one control there was genuinely broken: `updateFieldAccess` required `canManageSystemSettings()`
(Super Admin) while its route admitted HSE and the UI rendered the toggle to HSE — so HSE was shown
a switch that 403'd every time.

### 6. Safety Equipment & Compliance read as a generic admin page

It was a bare `<h1>`/`<p>` on the page background while every other module page had moved to the
shared `PageHeader` surface, its five tabs were undifferentiated siblings, and every `CardDescription`
was in English, against the language hierarchy.

## Decision

### 1. A capability that spans departments is declared cross-department at the routing layer

`material-requests` moves out of `config/departments.php` into
`RestrictDepartmentAccess::UNIVERSAL_PREFIXES`, joining `permits-to-work` and `man-hour`.

Raising a request is a need every department has. **Fulfilling** it is the specialised part, so
`purchase-requisitions`, `rfqs`, `purchase-orders` and `goods-receipts` stay owned by
procurement/logistics — and a test asserts they stay that way.

**Nothing is weakened.** `MaterialRequestController` still gates every write on
`canManageMaterialRequests()` / `canConsolidateDemand()`, and `MaterialRequest::scopeVisibleTo()`
still confines every read to the viewer's own tenant and company. This stops the *routing* layer from
second-guessing a capability check that is already stricter than it is.

> [!important] The invariant, now asserted
> `DepartmentCapabilityReachTest` fails if a capability whose permission spans several departments is
> filed under one of them. That is the check that would have caught this in v1.6.8, and it is what
> stops the fourth instance.

### 2. Navigation memory, not persistent layouts

The alternative fix for the scroll reset is converting all 144 pages to Inertia persistent layouts.
That is a large, risky change — every page's local-state assumptions change, and the mobile drawer
currently *relies* on the remount — and it would buy nothing else here. Instead, `useScrollMemory`
persists the rail's offset to `sessionStorage` and restores it in `useLayoutEffect`, before the
browser paints.

Decisions inside that:

- **`sessionStorage`, not `localStorage`.** Navigation context belongs to the tab being worked in,
  not to the browser profile until next week.
- **Keyed per workspace.** A different department renders a different list; an offset from the
  previous one is meaningless. Verified: an HSE offset does not leak into the global menu.
- **Clamped on restore.** The same department can render a shorter list than last time (a module
  disabled, a plan changed), and restoring past the new maximum would silently land at the bottom.
- **Falls back to revealing the active item.** With no stored offset — a fresh session, a department
  not opened yet — `scrollIntoView({block:'nearest'})` ensures the current page is visible without
  moving a menu that already fits.
- **The write is synchronous, not inside `requestAnimationFrame`.** The first implementation
  throttled through rAF, which reads as ordinary scroll-handler hygiene and silently disabled the
  entire feature in any tab that is not painting, because rAF callbacks do not run there. Found in
  browser verification — the stored value came back absent rather than wrong — and pinned by a test.
  rAF is for work whose only purpose is the next frame; persistence has to happen whether or not
  anything is drawn again.

### 3. A route may be owned by several departments, and the one you are in wins

`PREFIX_TO_WORKSPACES` now records *every* owner of a route prefix rather than letting the last
declaration win. When a shared route resolves, the department the user is already working in is
preferred. Man-Hour and Material Request are declared in two menus on purpose; previously, opening
Material Request from HSE threw the user into the Logistics sidebar mid-task.

Single-owner routes — almost all of them — are unaffected, so ADR
[[007-workspace-navigation|007]]'s "the route is the source of truth" still holds everywhere there is
only one truth to have.

### 4. Two navigation levels, and rank reads top to bottom

| Level | What | Size | Weight | Ink |
|---|---|---|---|---|
| 1 | Every top-level row — leaf **or** group header | 13px | 500 | navy-300 |
| 2 | Items inside a group | 13px | 400 | navy-400 |
| — | Active, either level | 13px | 600 | white + surface + rule |

A group header is a level-1 row that happens to carry a chevron. It is not a caption, not uppercased,
and not smaller than its contents. `aria-expanded` now reports the state the rotating chevron was
conveying only visually.

**Entry items are separated by a hairline, not by a third type size.** Dashboard, My Work and
Overview answer "where am I"; everything below answers "what do I do". The boundary is *derived*
(`entryBoundary()`), so a new department gets it without declaring anything.

### 5. Page kind, carried by the shared header

`PageHeader` gains an optional `kind`, with four values matching what the product actually contains:

| kind | Means | Chip |
|---|---|---|
| `master` | Definitions the system is configured with | **Master Data** (steel) |
| `operational` | Records of daily work | **none** |
| `monitoring` | Reading, not writing | **Monitoring** (brand) |
| `administration` | The system itself — users, roles, settings | **Administration** (graphite) |

**Only three of the four are labelled, and that is the point.** `operational` is the overwhelming
majority of the product and the state a user is right to assume; badging every page would make the
badge furniture and stop it carrying information. The absence of a chip means ordinary work, and a
page that creates records says so far more loudly through its own primary action ("New Material
Request") than a label would.

> [!warning] Not red, although red was offered as an option
> Red already means destructive-or-wrong everywhere in IOMS — `text-danger`, a rejected status, a
> validation error, the Delete button. Spending it on "this is configuration" would teach two
> meanings for one colour and make a genuine error harder to notice. Master data is not dangerous; it
> is **consequential**, which reads better as a calm slate chip than as an alarm. The consequence is
> stated in words instead, in the subtitle: *"Mengubahnya mengubah pilihan yang tersedia bagi semua
> orang berikutnya, dan tidak mengubah data yang sudah tercatat."*

The chip is a **label** (English); the sentence explaining consequence is **prose** (Indonesian) —
the language hierarchy, applied to one component.

### 6. Where a page holds both kinds, the cue follows the tab

Safety Equipment & Compliance genuinely contains both, so its tab strip is grouped and labelled —
**Registers** (Equipment Register, Supplies & First Aid) versus **Reference Data** (Equipment Types,
Inspection Templates, Hazard Categories) — and the header chip follows the open tab. Claiming the
whole page is one kind would mislabel half of it.

Two labels changed and nothing moved: *Equipment Master* → **Equipment Types** (the chip now carries
"Master"), and *HSE Supplies & Facilities* → **Supplies & First Aid**, which says what is in it and
so distinguishes it from the Equipment Register. Routes, tab keys and `?tab=` bookmarks are unchanged.

### 7. Field PTW access: fix the broken half, explain the half that is correct

- `updateFieldAccess` is aligned to `canManageHse()`, matching its own route and its own UI. The two
  halves only make sense together: a field PTW user needs **both** the permission to raise a permit
  and the workspace that shows it to them, and granting HSE one without the other meant HSE could
  not produce a working field account at all. `is_field_user` chooses a landing workspace — it grants
  no data access, no capability and no role.
- **Account creation, email and password stay Super-Admin-only.** Issuing credentials is an
  administrative act, and widening it to HSE would be a real authorization change made to solve a
  discoverability problem. What was missing is the product *saying so*, which an inline note now
  does, shown only to someone who cannot do it themselves.
- The sidebar item is renamed *PTW Access* → **Field & PTW Access**, matching the card it opens —
  a menu item and its destination must be recognisably the same thing.
- The HSE Overview gains an **HSE Administration** row, kept separate from the operational module
  grid so the master/operational distinction this ADR sharpens is not immediately blurred.

## Consequences

**Good**

- A capability is reachable by whoever owns it, and an automated test now says so for every
  cross-department capability rather than for the three that happened to be found.
- The rail keeps its place, and a shared route no longer throws the user into another department.
- The sidebar's visual rank matches its structural rank.
- "Am I configuring the system or doing my job" is answerable at a glance, product-wide and by one
  shared component rather than per page.

**Accepted costs**

- `useScrollMemory` exists because the app remounts its layout per page. If IOMS ever adopts
  persistent layouts, this becomes unnecessary and should be removed rather than kept alongside —
  `NavigationBehaviourTest` fails loudly if a page starts using `Page.layout`, so the decision gets
  revisited rather than forgotten.
- `kind` is applied where it is meaningful, not stamped onto all 165 pages in one pass. The
  convention and the component are product-wide; the rollout follows the pages as they are touched.
  `CONVENTIONS.md` records the rule so it does not drift.

## Notes

- Related: [[007-workspace-navigation]] (the registry this extends), [[008-tenancy-foundation]] (the
  authorization layers that had diverged), [[016-tenant-module-workspace-grants]] (what a plan
  grants), [[030-material-request-lifecycle-and-demand-consolidation]] (the lifecycle whose
  requester-facing half this completes).
