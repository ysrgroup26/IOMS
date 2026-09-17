# ADR 035 — A proper application language system: feasibility, not implementation

**Status:** Investigated. Not implemented, deliberately.
**Date:** 2026-09-17 (v2.72.0)
**Supersedes nothing.** Extends `docs/CONVENTIONS.md` § *The language hierarchy*, which stays in force.

---

## What was asked, and what was explicitly not asked

IOMS should eventually let a user choose between **English** and **Bahasa Indonesia**, using
deterministic, application-controlled translation resources — a string catalogue the product ships
and controls, not a runtime machine-translation service.

The instruction for this release was equally explicit about what *not* to do, and it is worth
restating because every one of these is the tempting shortcut:

- **Do not translate the existing product.** The current mixing is intentional.
- **Do not mass-translate copy.**
- **Do not introduce runtime AI translation.** Not as a stopgap, not behind a flag.
- **Do not add a language switch that does not work.** A visible control that changes nothing is
  worse than no control, because it converts a missing feature into a broken one.

So this ADR records what the work would actually involve. No user-facing behaviour changed in
v2.72.0 on account of it, and no `lang/` directory was created.

## Why "just add i18n" is the wrong estimate

There is a version of this task that sounds like an afternoon: install a translation library, wrap
strings in `t()`, ship two JSON files. That estimate is wrong here for reasons that are structural
rather than a matter of volume, and the volume is not small either.

### Finding 1 — There is no translation layer at all. Not a thin one; none.

Measured on the v2.72.0 tree:

| | |
|---|---|
| `lang/` directory | does not exist (Laravel 12 ships without one) |
| `__()` / `trans()` calls in `app/` and `resources/views/` | **3**, all of them Laravel's own password-broker status keys in `Auth/` controllers |
| Frontend i18n library | none installed |
| `config/app.php` locale | `en`, from env, never switched at runtime |

Every other user-facing string in the product is a literal, in the file that displays it.

### Finding 2 — The surface is ~3,900 string sites across 190 files.

A scan of `resources/js` (245 files) counts **1,418** copy-bearing prop literals (`label=`,
`title=`, `description=`, `placeholder=`, `hint=`, `emptyTitle=`, `note=` …), **36** template-literal
props, and **2,471** JSX text nodes — **~3,889 sites in 190 files**. The distribution has a long
tail rather than a hot spot: `Settings/Index.jsx` alone carries 282, `Hse/Master.jsx` 115,
`Employees/Profile.jsx` 88, and then it thins out across everything else.

Add **209** server-side flash messages (`->with('success', 'Asset assigned.')` and friends) in
`app/Http/Controllers/`, plus the Blade email and PDF templates, which are a third rendering path
with their own copy.

This is the honest scale. It is not a blocker by itself — it is just work — but it means the
extraction cannot be done as a side-effect of another pass, and it is why this ADR exists instead
of a half-migrated tree.

### Finding 3 — Status labels are *derived from stored values*, not written anywhere.

This is the genuinely interesting blocker, and it would not show up in a string-extraction tool at
all.

`StatusBadge` renders `label || humanize(value)` — where `value` is the database enum string
(`submitted`, `replacement_requested`, `consolidating`). The visible English is *computed from the
persisted value* at render time. There is no string to extract, because the English text does not
exist in the source: it is an artefact of the column's own vocabulary.

Translating status therefore requires something the product does not have: **an explicit
status → label map, per module, as a first-class thing**, decoupling what is stored from what is
shown. That is a real architectural change with real benefits (it is also what would let a status
be renamed without a migration), and it must land *before* any translation catalogue, not after —
otherwise the catalogue gets keyed on database values, which is exactly the coupling that makes a
schema change into a copy change.

The same applies wherever the UI humanises an enum, which this codebase does in more places than
`StatusBadge`.

### Finding 4 — Locale-dependent formatting is hardcoded in 126 places.

`toLocaleDateString('en-…')` and friends appear **126** times in `resources/js`; `id-ID` appears
**25** times, chosen per call site. Dates, times and numbers are *part of* a language setting —
a user who picks Bahasa Indonesia and still sees `Sep 17, 2026` has not had their language changed,
they have had their nouns changed.

So a language system needs a single formatting boundary — one module that owns date, time and
number rendering and reads the active locale — and 151 call sites have to route through it. This is
mechanical, but it is a prerequisite, and it is invisible if you think of i18n as "strings".

Note that this is a *separate axis* from `config/ioms.php`'s `display_timezone`, which is an
operational setting (what wall-clock a permit's timestamps are read in) and must **not** be folded
into the language choice.

### Finding 5 — The language hierarchy is pinned by a test, and that is a feature.

`tests/Feature/LanguageHierarchyTest.php` asserts the per-slot English/Indonesian rule against both
the JSX literals and the rendered server props. A translation refactor moves every one of those
strings out of the files the test reads, so **the test would stop protecting anything while still
passing** — the most dangerous failure mode a guard rail has.

The rule must survive the migration, which means the test has to be rewritten to assert over the
*catalogues* (does every key exist in both locales; is the English catalogue English in the slots
where English is mandatory) before the strings move, not after.

## The blocker that is a product decision, not a technical one

The current mixing is deliberate: English for the naming layer (modules, navigation, page titles,
column headers, status, actions), Indonesian for the explanatory layer (subtitles, help text,
empty-state guidance). Both languages appear on the same page, by design, because a menu item and
the page it opens must be recognisably the same thing.

**A language switch does not obviously mean "translate everything".** For an Indonesian user
selecting Bahasa Indonesia, does `Permit To Work` become `Izin Kerja` in the sidebar? The
established terms — HSE, PPE, JSA, HIRADC, PTW, LOTO, CAPA, TBM, NCR, RFQ, FPB, SPK — are never
translated in either direction, and much of the naming layer is closer to those than to prose. A
catalogue cannot answer that question; it can only encode an answer.

So the first deliverable of a real language system is **not code**. It is a decision about which
slots are translatable at all — quite possibly leaving the naming layer fixed and translating only
the explanatory layer, which would make the feature both smaller and more faithful to how the
product is actually read.

## What the sequence would have to be

Stated as an order rather than a plan, because each step is independently useful and none of them
is safe to skip:

1. **Decide the translatable surface** (product decision, above). Record it here.
2. **Extract status/enum labels** into explicit per-module maps. Useful on its own; unblocks 3.
3. **Centralise date/number formatting** behind one locale-aware module; route the 151 call sites.
4. **Rewrite `LanguageHierarchyTest`** to assert over catalogues rather than literals.
5. **Establish the catalogues** — Laravel `lang/{en,id}` for server, email and PDF copy; a matching
   frontend catalogue shared through the existing Inertia prop path.
6. **Migrate strings module by module**, with the catalogue test failing on any missing key.
7. **Add the switch last**, wired to a persisted per-user preference — only once 1–6 make it true.

## Decision

**Do not begin the migration in v2.72.0.** Steps 2 and 3 are genuinely valuable independent of
translation and are the right next increment if this is picked up. Step 1 is blocking and is not a
decision to make in code.

Nothing was added toward this in this release: no `lang/` directory, no unused i18n dependency, no
placeholder switch. A half-built language system is not a step toward a language system — it is a
second source of truth for copy, and this codebase already has documented history of what two
sources of truth for one fact cost (`CHANGELOG.md` at 71 releases behind, the settings cache with a
key mismatch between `get()` and `set()`).

## What would make this ADR wrong

If the product decision in step 1 comes back as "translate only the explanatory layer", the surface
drops from ~3,900 sites to the subtitle/description/hint/empty-state slots, findings 3 and 4 become
much less pressing, and the honest estimate changes substantially. That is a good reason to make
that decision first rather than to start extracting strings.
