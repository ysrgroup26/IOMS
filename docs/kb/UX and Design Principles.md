---
title: UX and Design Principles
type: reference
updated: 2026-09-16
tags: [kb/ux, kb/design]
---

# UX and Design Principles

The design language, the rules that keep it coherent, and the specific mistakes that produced each
rule.

**Authoritative sources: `CONVENTIONS.md`** (the rules) and **`UX_ARCHITECTURE_DISCOVERY.md`** (the
reasoning behind the navigation and form architecture). This note is the working summary.

---

## The visual identity

Navy rail and header · a cool graphite page ground · white cards reading as *raised* against it.

The tonal step is load-bearing rather than decorative: the ground was once so close to white that
cards had nothing to sit on, and the whole app read as one flat sheet. Stepping the ground down in
v2.43.0 is what let every existing white surface read as raised — **one change, no page edits**,
which is why the rest of that pass could stay restrained.

**The goal is a cohesive, premium industrial SaaS product — not a generic admin dashboard.** Do not
recolour things to look different; change them when they fail to communicate.

---

## The Form Experience System

Five components and one hook in `Components/shared/form/`. Deliberately not a framework — it does
not own validation, describe forms as data, or wrap Inertia's `useForm`.

> [!important] Page forms and dialog forms take different amounts of it
> **Page form:** `FormSection` + `FormField` + `FormActions` + `ErrorSummary`
> **Dialog form:** `FormField` only
>
> A dialog already has an action area (`DialogFooter`), and an error summary above five visible
> fields restates what the reader can see. The v2.66.0 rollout hit this and the answer was to use
> **less** of the system, not to grow a dialog-flavoured second set.

Two principles from that rollout:

- **Ownership belongs near the top.** The Operating Unit field decides who owns the record and
  therefore who will ever see it again — it goes in the first section, beside identity, never under
  a heading like "Capability & Notes".
- **Not every form needs restructuring.** A seven-field form has no hierarchy problem. It gets the
  *contract* — accurate required marking, findable errors, a reachable action bar, unsaved-change
  protection — and nothing else. **The contract generalises; the restructuring is situational.**

---

## Rules with a scar behind them

| Rule | The failure that produced it |
|---|---|
| **A card may clip its value, never its label** | The KPI strip gave labels 33px, so `Fatality` — the most severe category in an HSE product — rendered as `FAT...` |
| **Colour must not borrow a meaning the app already assigns it** | A 14-slice pie drew departments from a palette containing the exact red and green used for danger and healthy, so a department could read as "in trouble" |
| **Past ~6 categories, stop using a pie** | Nobody can rank two similar wedges, which is the only question that card is asked |
| **A category that owns a colour keeps it everywhere** | Fatality was red on its card and blue on the trend line directly beneath it |
| **Exactly one item in a bar may flex** | A hard `w-[380px]` on the global search made every *other* control give way, so the symptom read as "cramped" rather than "137px wider than the tablet" |
| **Hide-because-duplicated uses the other component's breakpoint** | Dashboard was drawn twice from 640px to 1023px — right reasoning, wrong breakpoint |
| **A tab/chip row needs `overflow-x-auto` or `flex-wrap`** | Without it, it drags the whole page sideways, not just itself |
| **`EmployeeSelector` for any employee field** | Ten pages have shipped the entire directory to render one dropdown |
| **A parent is never smaller or dimmer than its children** | Sidebar group headers rendered at 11px in navy-400 above 13px navy-400 children, so the least prominent row was the one naming the section. A caption convention applied to a *collapsible control* |
| **Never spend a colour that already has a meaning** | The same rule as the pie chart above, restated for the master-data cue: red means destructive-or-wrong in IOMS, so "this is configuration" got a calm slate chip and the consequence went into words |
| **`requestAnimationFrame` is for the next frame, not for persistence** | The sidebar's scroll memory wrote through rAF, which does not run in a non-painting tab — the feature was silently dead wherever it mattered most |

---

## Accessibility

The shell work in v2.67.0 established the standard, and it is the baseline for anything new:

- **A modal is a dialog, not a div with a scrim.** It owes the user three things: focus *enters*,
  Tab *stays*, and focus *returns* to the opener. `useFocusTrap` provides all three.
- **A closed drawer must be `inert`.** Moving it off-screen with a transform leaves it rendered and
  in the tab order — roughly twenty invisible links on every phone.
- **`role` is an attribute, not a style.** The sidebar is a landmark above `lg` and a modal below it,
  and no CSS media query can say that — hence `useMediaQuery`.
- **Icon-only controls need an accessible name.** `title` is a tooltip: unreliable to assistive
  technology and invisible on touch.
- **Never colour alone.** Aging shows the day count next to the emphasis; risk matrices pair colour
  with a label.
- **A skip link**, because reaching content otherwise means tabbing the entire rail on every
  navigation.

---

## Measure, do not eyeball

Screenshots hide horizontal overflow. `LOCAL-VERIFICATION.md` carries the exact snippet; the habit
is to check `scrollWidth > clientWidth` at **320, 375, 390 and 430px**.

This matters because it has been wrong before in the direction that counts: an overflow was recorded
as "known, deferred" in three consecutive releases without anyone measuring it. When it finally was,
it started at 640px rather than 768px and reached **41% of the viewport**. Twenty minutes of
measurement would have changed its priority immediately.

---

## Navigation

One declarative registry (`workspaces.js`), permission-gated server-side, with active state derived
from the route. **Navigation only ever hides what the server already refuses.**

**Two levels, and rank reads top to bottom** (v2.71.0). Every top-level row — plain link *or* group
header — is 13px/500/navy-300; items inside a group are 13px/400/navy-400. A group header is a
level-1 row that happens to carry a chevron, not a caption above a list. Dashboard / My Work /
Overview are separated from the rest by a hairline, derived rather than declared, because they answer
"where am I" while everything below answers "what do I do".

**The rail keeps its place across navigation.** Every page wraps its own layout, so the rail is
remounted — and reset to the top — on every link. `navigationMemory.js` restores the offset before
paint, per department, clamped to the current list. ADR
[[034-capability-reach-and-navigation-hierarchy|034]].

**A page says which kind it is.** `PageHeader`'s `kind` labels `master`, `monitoring` and
`administration`; `operational` is the unlabelled default, because it is most of the product and
badging everything would make the badge furniture. Where one page holds both kinds, the chip follows
the open tab.

The **two-zone navigation model** — a permanent workspace rail plus a context panel — is designed in
`UX_ARCHITECTURE_DISCOVERY.md` §4 and **deliberately not built**. It affects the administrator's
zoomed-out state only; department and field users are already correct. See [[Requirements Register]]
before starting it.

---

See also: [[Product Identity and Principles]] · [[Known Issues and Limitations]] · [[Verification Status]]
