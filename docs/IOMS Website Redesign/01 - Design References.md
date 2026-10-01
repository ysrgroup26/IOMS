---
title: IOMS Website Redesign - Design References
tags: [ioms, website-redesign, design]
updated: 2026-10-01
---

# Design References

The four references provide principles only. Do not reproduce their layout, identity, copy, assets, or signature effects. Build an original IOMS system and preserve IOMS' own color language and brand recognition. The exact reference URLs/assets were not present in the supplied project context; confirm them before implementation and add links/screenshots/observations here.

## Targo

**Borrow**
- Strong visual atmosphere and expressive, asymmetric composition.
- Strong typography and visual/video storytelling.
- Controlled use of accent color.

**Avoid**
- Copying cyan, typography, copy, or angular identity.
- Reusing signature compositions as templates.

**IOMS application**
Use atmosphere and visual hierarchy to make operational value feel tangible, while retaining IOMS color and a calmer technical tone.

## Halo

**Borrow**
- Premium restraint, strong typography, editorial grid, and intentional whitespace.
- Asymmetric composition and clean product storytelling.

**Avoid**
- Fintech/purple visual language or investor-oriented treatment.
- Copying its brand-specific typography or composition.

**IOMS application**
Make complex product information easy to scan; pair clear editorial copy with purposeful product evidence.

## Boomerang

**Borrow**
- Focused hero with one strong statement.
- Minimal navigation and intentional motion.
- Establish product identity before listing features.
- Consider a strong full-bleed visual where it supports the story.

**Avoid**
- AI/fintech language and literal video technique.
- Feature claims or visual motifs that do not belong to IOMS.

**IOMS application**
Lead with a clear IOMS proposition and use motion/imagery to support it, not distract from it.

## Axion Studio

**Borrow**
- Editorial section structure and numbered storytelling.
- Asymmetric layouts and floating navigation potential.
- Interactive visual presentation and strong image/video usage.

**Avoid**
- Agency positioning, orange identity, or partner badges.
- Copying signature motion or unrelated studio content.

**IOMS application**
Use a coherent sequence of sections and interactive product/environmental visuals with restrained navigation behavior.

## Combined design principles

1. Lead with one specific, verified IOMS value proposition.
2. Give the page an editorial rhythm, with varied layouts and generous whitespace.
3. Balance product UI with authentic operational and environmental imagery.
4. Use motion for explanation, continuity, or feedback; honor reduced motion.
5. Keep typography, contrast, and accent color disciplined and recognizably IOMS.
6. Use proof only when substantiated by the product or customer evidence.
7. Keep every layout responsive, accessible, and performant.

---

# How the references were applied, 2026-09-30

The four reference sites were not available as URLs in this environment, so they were used as the principles recorded above rather than as visual sources. Nothing was copied. What each contributed:

- **Targo:** atmosphere and asymmetric composition. The landing hero moved off a centred stack onto a left margin with a second column, which is the single change that gives the page an axis. IOMS navy and steel were kept throughout; no cyan, no angular identity.
- **Halo:** editorial restraint and scannability. Section headings sit on one left margin at a consistent measure, and the four workspaces are presented as a specification list on hairline rules rather than as four cards.
- **Boomerang:** one strong statement before any feature list. The hero states what IOMS does in one sentence, and the workspace list is a plain enumeration rather than a feature pitch.
- **Axion Studio:** numbered, sequential storytelling. The workspace list and the existing five-stage operating loop both carry monospaced numerals, which also gave the type system its instrument-label voice.

The strongest borrowed idea was restraint rather than any composition: several sections were made quieter, and the hero diagram lost three of its eight nodes because they were not true, not because the design needed fewer.

---

# The visual pass, 2026-10-01

The reference principles above were applied a second time, with the page in front of us rather than
in the abstract. What each one actually produced:

- **Targo, atmosphere and asymmetric composition.** The hero gained a subject: a Permit To Work,
  cropped by the right edge and tilted two degrees, lit by a single soft source. Atmosphere came from
  an object and a light, not from a gradient.
- **Halo, editorial restraint.** The eyebrow count went from 23 to 4. Restraint turned out to mean
  removing the labels, not styling them better.
- **Boomerang, one strong statement before the feature list.** The hero now holds four text elements
  and fits a single viewport. The workspace list and the industries moved below it.
- **Axion Studio, sequence.** The six domain rows stopped being six identical rows. Two carry the
  story, four became a brief.

**The strongest borrowed idea was still restraint.** The most valuable changes in this pass were
subtractions: an eyebrow removed, four zigzag rows collapsed, a column removed from a grid that was
balancing around a plan that no longer exists.

**What none of the references could supply: photography.** All four lean on real imagery, and there
is no honest way to reproduce that here. Stock is ruled out by the brief and by a passing test, and
no image-generation tool exists in this environment. The slots are wired and take one field each; see
`04 - Implementation Log.md`. Until real photographs of the customer's own operations exist, the site
is carried by product evidence and typography, which is the honest version rather than the complete
one.

---

# The photography, 2026-10-01

The references all lean on real imagery, and the previous pass had to record that as the one thing it
could not supply honestly. Eight photographs arrived, and the reference principles finally had
something to apply:

- **Targo, atmosphere.** The hero gained a dusk dock scene behind the permit. Atmosphere now comes
  from a real place and real light rather than from a gradient over navy.
- **Halo, editorial restraint.** The photographs are cropped and scrimmed to serve the composition,
  not dropped in at full saturation. The hero image keeps its detail because it sits beside the copy;
  the closing band is heavily scrimmed because it sits under it.
- **Boomerang, one strong full-bleed visual.** The page now ends on the shipyard aerial rather than on
  a flat navy block, so it arrives somewhere instead of running out.
- **Axion Studio, image and sequence.** Industries became three establishing shots in sequence rather
  than seven pills in a row.

**What the photography did NOT get used for.** Not every section, and not every asset. A photograph
behind the product showcase would compete with the interface it is meant to prove; a photograph on
the platform story would be decoration where a product panel is evidence. Three of the eight are used
once each, two are used twice, and one is unused in the stories on purpose.

The remaining reference gap is now closed. What is left is a judgement call rather than a missing
asset: whether the sub-pages should carry photography too, which is recorded as deferred in
`04 - Implementation Log.md`.
