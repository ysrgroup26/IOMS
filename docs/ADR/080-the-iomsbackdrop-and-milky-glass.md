# ADR 080: The IOMS backdrop, milky frosted glass, frosted icon capsules, and a 100%-zoom scale

- Status: accepted (v2.137.0, on branch `claude/eloquent-newton-pbxxdg`; not deployed until approved)
- Refines: ADR 079 (icy frosted shell). Supersedes its decisions 4 (surface material), 5 (feature
  panel), 6 (icon tiles) and 9 (radius/size steps) where they differ below.

## Context

After v2.136.0 the owner reported that the interface still looked flat and white (the "frost" sat on
a near-uniform pale gradient, so `backdrop-filter` had nothing to diffuse), that coloured bubbles
were saturated and mostly blue, and that the screens felt too large even at 75% browser zoom. The
owner supplied an abstract geometric artwork as THE backdrop and asked for the HSE Modules shortcut
grid to be removed from HSE Overview only.

## Decisions

1. **The supplied artwork is the backdrop.** The file as received (1672 x 941 WebP) is kept untouched
   in `resources/images/ioms-backdrop-source.webp`; the served copies in `public/images/app/` are
   diffused ONCE at build time (Gaussian blur r=3, and a 960px variant for phones), so the geometry
   stays recognisable and the browser never blurs a full-viewport layer on every frame. It sits on a
   fixed `::before` of `.ioms-canvas` (not `background-attachment: fixed`, which iOS ignores),
   `cover`-sized and anchored at 72% / 42% so a crop keeps the hexagonal layers, under a milky veil
   that is heavier on the left (rail, titles) and lighter on the right (gaps between cards).
   A dark blue-grey FILM (`--ioms-film`, rgba(30,48,78,0.22)) is the top layer of that `::before`,
   over both the artwork and the veil: measured on the backdrop alone it lowers the mean grey level
   by 17.2% (16% opacity gave only 12.6%, so opacity was tuned by measurement, not assumed). It
   never sits over cards, text or icons; muted ink on glass still measures 5.5:1 under it.
2. **Milky glass with real diffusion.** `.surface` is a 86%->75% white gradient with
   `backdrop-filter: blur(20px) saturate(150%)`, a white hairline, an inner highlight and a cool
   ambient shadow; `.chrome` (rail, top-bar pills, bottom bar) is a little clearer and blurs more;
   `.surface-nested` is the quieter inner box; overlays stay the most opaque. Where
   `backdrop-filter` is unsupported the glass falls back to 94% white.
3. **Contrast is measured, not assumed.** On the darkest part of the artwork slate-500 ink fell to
   3.6:1 on glass, so the two quietest greys are deepened ON glass surfaces only (#4E5D70, #5F6E82),
   by a two-class rule that hover/focus utilities still override.
4. **Semantic colour is reserved.** Ordinary cards are plain glass; `tint-{blue,green,amber,rose,
   violet,slate}` adds a muted wash only where the information warrants it (StatCard red/amber, the
   Card `tone` prop, the dashboard alert card when it has warnings). The feature panel is deep
   steel-blue GLASS (still diffusing the artwork), no longer a bright azure slab.
5. **Frosted icon capsules.** `.icon-tile-*` (and `.icon-soft-*`, the squarer list variant) are
   round, tinted frosted capsules with the GLYPH in the semantic colour (blue, teal, green, amber,
   rose, violet, slate), not white icons on saturated squares. The HSE Overview accents and the KPI
   category cards (whose colour is admin-configured) use the same capsule.
6. **Scale for 100% zoom.** Page titles 22/24px, card titles 14px, KPI figures 24px (19px compact),
   capsules 36px, controls and buttons 36px, table rows ~40px, rail 248px with 36px rows, top bar
   64px with 32px pills. Sizes were reduced per component, never by a global zoom.
7. **HSE Overview loses the HSE Modules grid** (and the single HSE Administration card beneath it).
   Every destination is still in the HSE sidebar with the same routes and permissions; no module,
   route, component or record was removed.

## Consequences

- No route, permission, prop contract, controller behaviour or label changed (beyond the removed
  shortcut grid).
- Dark mode is still disabled (`DARK_MODE_ENABLED = false`); a dark backdrop and glass would need
  their own design decision.
- A sharper original of the artwork can replace `resources/images/ioms-backdrop-source.webp`; the
  two served copies are regenerated from it (see the commit that introduced this ADR).
