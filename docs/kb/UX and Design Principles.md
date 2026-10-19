---
title: UX and Design Principles
type: reference
updated: 2026-10-19
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

### Brand asset hierarchy (v2.76.0)

The official IOMS master logo is never redrawn. Every asset below is derived from the designer's
files; `config/branding.php` is the single registry, and `BrandWordmark` is the one React component
that draws the lockup.

| Asset | Key in `config/branding.php` | Use |
|---|---|---|
| Primary lockup, light surfaces | `logo`, `logo_png` | public site, auth, PDFs; `logo_png` is also the schema.org Organization `logo` (search engines composite it onto white) |
| Light lockup, dark surfaces | `logo_dark`, `logo_dark_png` | app rail, navy headers |
| Email header | `logo_email_png` | the dark lockup flattened onto navy #0f2747 (ADR 039) |
| Mark alone, transparent | `icon` | in-UI mark only |
| **Favicon family** | `favicon_ico` (/favicon.ico, 16/32/48), `favicon_48`, `favicon` (SVG), `favicon_png` (32) | browser tabs and search results |
| Apple touch | `apple_touch_icon` (180) | iOS home screen |
| PWA | `icon_192/512` (any) and `maskable_192/512` | manifest |
| Social | `social` (1200×630) | Open Graph and Twitter cards |

**The earlier opaque favicon decision (v2.76.0–2.78.0, superseded in v2.88–2.90.0).** Contrast was
measured, not eyeballed:

| Pair | Contrast |
|---|---|
| navy tile on a white results page | 18.9:1 |
| navy tile on Google's grey `#f1f3f4` | 17.0:1 |
| cyan mark on its navy ground | 8.9:1 |
| the cyan mark alone on white (a transparent variant) | **2.1:1** |

A transparent cyan mark has lower contrast on a light surface. That measured trade-off remains true;
the v2.88–2.90 owner-directed decision selects the transparent official symbol anyway. The contrast
measurement is historical context, not a rule that icons require a navy ground.

**Current icon treatment (v2.90.0):** the mark alone, in cyan and without a baked-in background, is
used by browser favicons, the Apple touch icon and PWA icons. Every raster output is square and
transparent; the SVG favicon uses a tightly-cropped derivative of the canonical supplied source.
The broader `ioms-icon.svg` vector remains the regular product mark for watermarks and controlled
documents.

`any` and `maskable` remain separate manifest purposes. The latter keeps the artwork inside the
guaranteed 40%-radius safe circle. The [W3C manifest specification](https://www.w3.org/TR/appmanifest/#icon-masks-and-safe-zone)
says a user agent composites transparent pixels onto its own solid fill; Safari 17.2 documents
spacing adjustments for a transparent custom-shaped icon ([Apple release notes](https://developer.apple.com/documentation/safari-release-notes/safari-17_2-release-notes?language=_5)).
Those are platform display choices, not a need to place a navy square in the asset.

`BrandIconsTest` pins transparency across all raster contexts, and
`PwaInstallabilityTest` pins the manifest identity. The pre-rebrand `branding/icon.png` and
`wordmark.png` (a 2 MB photograph of a neon "icms" sign) remain absent from every active head
reference.

### Landing page storytelling

Each operational domain is told as **visual → domain → heading → explanation → optional CTA**
(`Components/public/StorySection.jsx`), with the content in `Components/public/domainStories.js`.
The sequence follows how a reader meets an operation: the operation as a whole, HSE, people, the
field, projects, procurement and warehouse, logistics, and finally what management sees.

- **The text is always HTML.** Imagery goes into the `image` slot and carries only the visual story.
- **Until real IOMS imagery exists**, `StoryVisual` draws a restrained panel in the product's own
  language: a navy header, the domain icon, and that domain's real capabilities. It states something
  true, so the page reads as finished today.
- **Rows alternate sides.** There is one brand accent and no colour per domain. This replaced the
  eight-card `DepartmentGrid`, which was deleted rather than left unused.
- **To add a photograph:** put `{ src, alt }` on the story entry. Nothing else changes. Use only real
  IOMS imagery; never stock photography or competitor assets.

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

## A controlled document carries stamps, and a stamp is an action record

v2.125.0. Full reasoning: **ADR 065**.

**A STATE stamp and an ACTION stamp are different objects**, because they answer different
questions. `ACTIVE` (green) and `CLOSED` (red) are a standing: slanted, double-ruled, distressed,
identical but for colour and word, and carrying **no date** — a date inside a standing stamp reads as
the date the standing was granted, which is a different fact from the validity window in the
document body. `APPROVED` (blue) and `VERIFIED` (green) are an act: upright, iconed, naming the
person, their job title and the time.

**Only a standing gets a stamp.** Every other status keeps its `StatusBadge`. A stamp that can
appear in six colours is a status badge wearing a costume, and the failure worth designing out is
somebody glancing at an amber seal on site and reading it as authorisation.

**A design reference is a specification, not an asset.** The stamps are CSS and type in
`Components/shared/DocumentStamp.jsx`. An image could not change when the permit closes, could not
name who signed, could not be read aloud, and could not be printed at another size without going
soft. The distressed ink is two layered gradients in `currentColor`, `aria-hidden`, and the stamp is
fully legible without it.

**Mobile gets a pill, not a shrunken stamp** — and the choice is made in CSS
(`hidden sm:inline-block`), not with `useMediaQuery`, because a document view is also *printed*: a JS
media query resolves against the viewport, so a stamp chosen at phone width would follow the
document into print at phone width. The PDF gets the composition without the slant and the distress,
which Dompdf cannot honour.

**`compact` means smaller, not less true.** The first cut dropped the job title from the compact
action stamp, so the detail page — the most-visited surface — left out the one fact the title exists
for. Caught in the browser.

## "Opsional" is a label beside the title, never part of it

v2.125.0. The PTW form carried the card title `Workforce -- Optional`, which got three house rules
wrong in one string: an em dash in user copy, a field's requirement state welded into the name of
the section, and the optionality shouting at the same weight as the title it qualifies.

`Components/shared/form/OptionalTag.jsx` is the one place that word is set: small, grey,
lower-weight, baseline-aligned, and carrying **no colour of its own**, because nothing about a field
being optional is a warning or a success. `FormField` uses it too, so a hand-built section heading
and a managed field cannot word or weight it differently.

## A form that needs a directory is not a form without it

v2.125.0. `EmployeeSelector` read a refused response as an empty result set, so a **403** on
`/employee-lookup` rendered as "Karyawan tidak ditemukan." — a permission failure displayed as a
statement about the workforce. People searched harder, because the message told them to.

Three rules came out of it:

- **A transport outcome is not a result set.** A refusal, a network failure and a genuine miss are
  three different messages.
- **The results must be where the keyboard is not.** A list that opens below its input opens behind
  the keyboard the tap just raised, so the field is scrolled into view after the (animated) viewport
  resize settles, and the list is bounded by the viewport as well as by a fixed ceiling.
- **A placeholder shows the SHAPE of the answer.** Anything the reader must not miss belongs in
  helper text, which wraps. A 78-character caveat inside a single-line input is invisible on a phone
  at exactly the width it matters.

---

See also: [[Product Identity and Principles]] · [[Known Issues and Limitations]] · [[Verification Status]]
