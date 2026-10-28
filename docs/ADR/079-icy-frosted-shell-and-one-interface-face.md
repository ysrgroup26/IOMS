# ADR 079: Icy frosted shell, floating chrome, and one interface face (Manrope)

- Status: accepted (v2.136.0, on branch `claude/eloquent-newton-pbxxdg`; not deployed until approved)
- Supersedes: ADR 078 decision 4 ("the navy rail stays") and ADR 070's 800 weight for titles/metrics
- Related: ADR 078 (motion and radius), ADR 070 (typography roles), ADR 053 (documents)

## Context

The owner asked for a whole-application redesign toward the "Icy Blue Frosted" reference: a pale
icy-blue atmosphere, frosted bubble cards, a horizontal pill top bar (Vertix / Ember references), a
light vertical sidebar, refined premium typography including numerals, gradient icon tiles, and the
retirement of flat full-colour cards. The product itself -- features, routes, permissions, labels,
data -- was explicitly out of scope.

## Decisions

1. **One interface face: Manrope, bundled.** Satoshi was the first candidate, but Fontshare (its only
   distribution) is unreachable from the build environment and has no npm package, so it could neither
   be self-hosted nor verified in a browser. Manrope is the brief's named alternative: OFL, variable
   (200-800), geometric and calm, and shipped through `@fontsource-variable/manrope`, so Vite bundles
   it -- no CDN at runtime, nothing a firewall can block. `sans` and `display` both resolve to it.
   Archivo stays ONLY for a controlled document's condensed type band (`font-document`), because the
   PDF embeds Archivo and screen must match paper. Plex Mono stays for identifiers. Inter is no longer
   requested.
2. **Weights: 700 for titles and headline figures, never 600 or 800.** Manrope at 800 reads as
   shouting; 600 is still the "unfinished" weight ADR 070 removed.
3. **Numerals.** Columns (tables, inputs, badges, counters) use tabular figures globally from
   `app.css`; a single headline figure (`.type-metric`) uses proportional lining figures, because
   "11" set tabular at 30px reads as "1 1".
4. **Three layers of material** (`app.css`): `.ioms-canvas` (fixed icy radial washes), `.surface`
   (frosted bubble card: white 82%, white hairline, cool halo, modest blur), `.chrome` (the same
   material, more opaque, for the floating rail, top-bar pills and bottom nav). `Card` renders
   `.surface`, so every page using the shared Card inherits it.
5. **Full-colour cards become `.surface-feature`**: one luminous azure gradient with white ink at
   AA, at most one per screen. `Card tone="navy"` maps to it; navy heroes (HSE Overview, equipment
   compliance, waste lifecycle, permit header) were moved onto it. Semantic tones are faint washes.
6. **Icons sit on tiles whose colour states meaning**: `.icon-tile-{blue,green,amber,rose,violet,
   slate}` (saturated gradient) and `.icon-soft-*` (tinted, for dense lists). Lucide remains the one
   icon family. Emoji are not used as status icons.
7. **The rail floats and is light.** At the owner's direction the navy rail (ADR 078 §4) is replaced by
   a frosted panel inset 12px from the viewport. Active = a raised white pill + brand ink + bold +
   `aria-current`, so the state never depends on hue. Only ONE item can be lit: the layout now picks
   the longest matching path (`pickActiveItem`), fixing a latent bug where every Management item
   matched every Management page.
8. **The top bar is a row of floating pills**: the navigation group (Dashboard, the workspaces as
   pills from `xl` when there are at most six, Admin Space), search, round utility controls, profile.
   The pills are the SAME list and the SAME switch as the dropdown, which still serves narrower widths;
   short pill names (HSE, HRD, Warehouse) are presentation only and a tenant's own label override is
   shown as given.
9. **Radius scale moves up one notch** (controls 12, popovers 16, cards 20, dialogs/chrome 24, pills
   full), still concentric. Buttons are pills.

## Consequences

- No route, permission, prop, controller or label changed. Copy edits were limited to removing emoji.
- Pages that hand-roll their own markup were swept by pattern (white cards with shadows -> `.surface`,
  pagination buttons, inner stat boxes, navy heroes); bespoke one-off styles remain on some pages and
  are listed in the release notes as remaining work.
- The public website inherits Manrope through `font-display`; its layouts are otherwise unchanged.
- Dark mode remains disabled (`DARK_MODE_ENABLED = false`); its classes were not redesigned.
