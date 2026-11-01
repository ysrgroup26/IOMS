---
title: "ADR 082 — Midnight Frosted Glass is a Starter pilot, offered by the server and mapped in one place"
status: Accepted — pilot approved by the owner; rolled out to the whole signed-in application in v2.140.0
date: 2026-11-01
version: 2.139.0
tags: [adr, design-system, theme]
---

# ADR 082 — Midnight Frosted Glass is a Starter pilot, offered by the server and mapped in one place

**Status:** Accepted. The owner reviewed and approved the Starter pilot (`6a572a0`, v2.139.0), and
v2.140.0 rolled it out to every signed-in account — see **Addendum: the global rollout** at the end.
**Builds on:** ADR 079 (Icy Blue Frosted shell), ADR 080 (backdrop and milky glass), ADR 081 (one
meaning, one colour).

## Context

IOMS has had a dormant dark mode since v1.6.3: a `.dark` class, a `useTheme` hook and a toggle,
switched off app-wide by a `DARK_MODE_ENABLED = false` constant duplicated in `useTheme.js` and
`app.blade.php`. About half of the 186 pages carry older `dark:` utilities in the slate palette.
The other half, and everything the v2.136–2.138 redesign added (frosted glass, icon capsules,
semantic tints, the backdrop), has none.

The owner asked for **Midnight Frosted Glass**: the dark counterpart of Icy Blue Frosted Glass,
offered to the **Starter plan only**, reviewed on staging as both a Company Admin and an HSE user,
and kept away from every other account until approved.

## Decisions

### 1. Who is offered it is one server answer, read before first paint

`App\Support\DarkTheme::availableTo($user)` is true when the user's organisation is on a plan
listed in `config('ioms.dark_theme.plans')` (`['starter']`). It is read in two places:

- **`app.blade.php`** prints it into the inline head script (`DARK_THEME_AVAILABLE`), so the page
  is painted dark *before* React loads, or never painted dark at all. A Business account whose
  device prefers dark is never painted dark, not even for one frame.
- **The shared Inertia prop `darkTheme`** decides whether the top bar shows the switch.
  `lib/useTheme.js` re-reads it on every Inertia navigation, because signing in or out is a
  visit, not a document load (ADR 070). Without that, the login page would stay dark after
  logout, and the dashboard would stay light after login, until a refresh.

It decides only what is **offered**. It grants nothing; no route, permission or entitlement reads
it. Widening the rollout means adding a plan slug to the config, after the owner approves; it is
not a code change. Emptying the list turns the theme off for everyone.

### 2. The preference lives in the browser, follows the device until chosen

`ioms-theme` in localStorage is `light`, `dark` or absent. Absent means "follow the device", and
follows it live. An explicit choice wins over the device. localStorage survives refresh, logout
and login on the same device. It is not a column, because the pilot changes no schema.
Consequence: the choice does not travel to a second device. That is an open decision for the
rollout, not something the pilot assumes.

### 3. The switch is a two-segment radio group in the top bar

Sun and moon in a `chrome` pill, before Install / Work Center / Notifications / profile. It is a
radio group (WAI-ARIA): Tab reaches the selected segment, arrow keys move and choose, and each
segment has an English accessible name ("Light theme" / "Dark theme"). Switching toggles the class
in place, with no reload. A `theme-switching` class held for one transition gives a 220 ms
cross-fade of colour, border and shadow. It is a class held briefly, never a permanent
`transition` on every element (which every hover would pay for), and reduced motion collapses it
through the global rule.

### 4. One theme system, extended rather than duplicated

The dormant hook was **replaced in place**, and `DARK_MODE_ENABLED` is gone. The `.dark` CSS
variables were retuned to the brief's palette: ground `#101925`, surface `#192636`, secondary
`#223246`, edge `#34465A`, accent `#5795E8`, ink `#E8EEF6` / `#A5B3C5`. The shadcn tokens therefore
follow automatically.

### 5. The six layers of ADR 080, at night

| Layer | Light (unchanged) | Midnight |
|---|---|---|
| Backdrop | artwork + milky veil + 22% film | **same artwork** under an 80% midnight film plus a left-weighted veil: about a fifth of the geometry shows through as muted steel-blue light. Never a flat colour, never black. |
| Chrome | 80–66% white glass | 78–70% dark steel glass, blur 24 px, 14% steel edge |
| Surface | 86–75% white glass | 74–66% dark steel glass, blur 20 px, 13% edge, inset 6% highlight, deep ambient shadow |
| Nested | 62% white | 34% midnight well, steel edge |
| Overlay | 95% white | 95% `#1A2839`, a step lighter than cards so it reads as in front |
| Feature panel | azure glass | a quieter navy-blue glass: the one blue moment, without glow |

Where `backdrop-filter` is unsupported, glass becomes 97% opaque `#182535`, the same fallback
shape as light. `html.dark .surface` and similar carry one extra element of specificity, so that
an older `dark:bg-slate-900` on a card cannot turn the glass back into an opaque slab.

**Frosted icon capsules** keep their shape. At night the capsule is a dark translucent pebble
tinted by meaning, with the glyph in a lightened semantic ink, so icons are not all blue.
**Semantic tints** keep their 150° top-left wash over dark glass.

### 6. Light colour classes are mapped in one generated block, not on 186 pages

Most pages hard-code light classes (`text-graphite-800`, `bg-white/80`, `border-steel-100`,
`hover:bg-graphite-50` …). Adding a `dark:` partner to each would be roughly 1,500 edits across
186 files, and would drift the first time a page is touched.

Instead, `resources/css/tools/midnight-map.py` scans `resources/js` for every colour class in use
and writes each one's Midnight value into one block of `app.css`, between `midnight-map:start` and
`midnight-map:end`. Its rules:

- Neutral ink 300–950 maps onto the palette's ink scale. Light ink meant for a navy panel is
  left alone.
- Semantic ink keeps its **hue** and is lightened for a dark ground. Red stays red and amber stays
  amber, so ADR 081's meanings do not move.
- White and pale neutral fills become dark translucent glass. Pale semantic fills become a 12–26%
  wash of the same hue. Pale borders, rings and dividers become soft steel.
- Placeholders get one dim ink (`#75849A`, about 4:1 on a field well), so an example is never
  mistaken for an answer.
- The `success` / `warning` / `danger` tokens are read as their hue; `-light` is read as the wash.

The block sits in `@layer components`, so an explicit `dark:` utility a page already carries
**still wins**. Re-run the script after adding a light colour class the map does not know.

### 7. The older dark vocabulary is retuned, not deleted

The legacy `dark:` utilities' **text** tones (slate-100…500) are already close to the palette and
are kept. Their opaque `bg-slate-900/950` and slate-900 gradient stops were near-black slabs; a
small unlayered block after the utilities turns them back into translucent wells and overlays at
equal specificity. Nothing in the pages was rewritten for it.

### 8. Light Mode cannot be touched

Every rule the dark theme adds is scoped under `.dark` (or `html.dark`), apart from the switch's own
two classes. `DarkThemePilotTest::test_every_midnight_rule_is_scoped_to_the_dark_class` parses the
Midnight section of `app.css` and fails on any selector that is not scoped.

### 9. A Starter organisation to review, on staging only

Every seeded tenant is on Enterprise, so the pilot had nothing to be reviewed through.
`StarterPilotSeeder` builds one Starter organisation in the shape `TenantProvisioningService`
gives a real one, with `starter.admin@ioms.local` (Company Admin) and `starter.hse@ioms.local`
(HSE, Department User of `hse`). It also adds a small operation, built by **DemoTenantSeeder's own
builders** through `Closure::call`, so the reviewer sees the Sandbox's records rather than a second
set of fixtures that would drift. The seeder refuses production. It invents no password: on staging,
`staging.sh pilot` hands it the staging app password `setup` already generated, through the
environment of one command.

## Consequences

- Starter accounts see the switch. Everyone else sees exactly 2.138.0. This was measured
  pixel-for-pixel against `main` (see Verification below).
- A new light colour class on a page shows up light in dark mode until the map is regenerated.
  The automated dark audit described below finds it.
- Charts: grid, axes, tick labels and tooltips are retuned; series colours carry meaning and are
  untouched.
- The `About` dialog's `tone="adaptive"` wordmark pattern now also serves the rail, so the light
  lockup appears on a midnight rail.

## Verification (2026-11-01, local, MySQL, Chromium)

- **Automated dark audit:** every Starter screen each role can reach was rendered in dark mode:
  65 for the Company Admin, 53 for the HSE user, with seeded records. On each, a check flagged any
  visible box painted light (luminance > 0.55 at > 35% opacity, or a white gradient) and any text
  in dark or dim ink (luminance < 0.15). Result: **0 findings on all 118**. The same check run in
  light mode reports 29 light boxes and 42 dark-ink texts on HSE Overview alone, so it does fire.
- **Light-mode regression:** screenshots compared against a `main` checkout served beside the
  branch. For an Enterprise account with the device set to **dark**, six screens were
  **pixel-identical**. For both Starter roles, the only changed pixels were the top-bar strip
  where the switch was inserted.
- **Behaviour (all pass):**
  - device preference is the default in both directions;
  - the switch applies without a reload, together with the cross-fade class;
  - aria-checked is correct;
  - the choice survives refresh, overriding the device;
  - the theme holds across in-app navigation;
  - arrow keys move selection and focus;
  - signed out is light, and signing back in is dark again without a reload;
  - an Enterprise account in the same browser with "dark" stored stays light and is offered no
    switch;
  - the server prints `true` / `false` to match.
- **Viewed:** 1440 (HSE Overview, Equipment, PTW list and form, Employee PPE, Settings, Incident
  validation, select, account menu, notifications, Add Equipment dialog), 1280 (Administration
  Overview, Waste, PPE dashboard), 1024 (Billing, Settings), 390 (HSE Overview, Equipment, PTW).
- **Not verified:**
  - a real phone or GPU;
  - Safari or Firefox;
  - a screen reader.

  In headless Chromium without a GPU, the full-page restyle takes 140–260 ms to the first frame
  with or without the fade. The dark theme adds no blur layer that light does not already have.


## Addendum: the global rollout (v2.140.0)

The owner approved the pilot as built and asked for the same design across the **whole**
application, with nothing excluded: every workspace, Admin Space, **My Work**, every page, component
and interactive state.

1. **One value, not a second system.** `ioms.dark_theme.plans` became `['*']`.
   `DarkTheme::availableTo()` reads `'*'` as *every signed-in account*: any plan, a My Work login,
   and a platform operator with no tenant. A list of slugs still narrows it, and an empty list is
   still the kill switch. Signed-out pages (the public site, sign-in and registration) are not the
   application and stay light. That surface was explicitly deferred.
2. **Master Admin** (`PlatformLayout`) gets the same `ThemeSwitch` and the adaptive wordmark. Its
   plain light classes were already covered by the generated map.
3. **Charts were never reachable by CSS.** IOMS draws charts with Chart.js on a `<canvas>`.
   The pilot's Recharts selectors matched nothing; the HSE trend chart only looked right because it
   is a hand-drawn SVG. `lib/chartSetup.js` now captures Chart.js's own light defaults, swaps in
   Midnight labels (`#A5B3C5`), grid (`rgba(52,70,90,.7)`) and tooltip while `<html>` has `dark`,
   and redraws open charts on a switch. Series colours are untouched, and Light Mode gets the
   captured defaults back exactly.
4. **Inline styles are invisible to a stylesheet.** `KpiSummaryCard` drew its capsule with an
   inline white gradient built from the category's admin-configured colour. The colour now travels
   as CSS variables (`--kpi-tint/ring/glow`) and `.kpi-capsule` draws the material, so `.dark` can
   reach it. Light Mode is pixel-identical.
5. **The static guarantee for pages with no data.** Tailwind generates only classes written out
   whole in the source, and `midnight-map.py` maps every light colour class found anywhere in
   `resources/js`. So no page, visited or not, can carry an unmapped light colour class. The
   residual risks are inline styles and canvases (points 3–4), which a scan found nowhere else
   apart from Google's own logo colours and the paper-white permit document. That document stays
   white on purpose: it is the printed sheet, the same as its PDF.
6. **A schema limitation found on the way, not fixed here.** `departments.code` is unique across
   **all** tenants, so two organisations cannot both code a department `HSE`. `StarterPilotSeeder`
   now skips its sample records, with a warning, where another organisation holds those codes,
   instead of failing. The constraint itself is recorded in Known Issues: changing it is a
   migration and a product decision, outside a theme release.
