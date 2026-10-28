# ADR 078: One motion and radius language; an equipment inspection is judged from its checklist

- Status: accepted (v2.135.0, on branch; not deployed until approved)
- Related: ADR 074 (equipment standing is derived), ADR 077 (waste figures and units), ADR 070
  (typography roles), ADR 061 (an offer is bound to what the server allows)

## Context

The owner asked for a redesign of the signed-in application toward a light, macOS/iOS-like feel
(rounded, quiet surfaces, consistent motion), plus workflow fixes in Safety Equipment, Compliance
and Waste Management. The visual reference image did not reach the session that did this work; the
written direction was followed.

## Decisions

1. **Motion is a system, not per-page effects.** Two curves (`out-soft` for arrivals, `in-soft` for
   departures) and three durations (fast 150 ms, base 200 ms, slow 260 ms) are Tailwind tokens. A page's
   content enters with opacity and a 6 px rise when a VISIT mounts it; a filter or partial reload keeps
   the page and plays nothing. The animation ends at `transform: none`, because a lingering transform
   makes an element the containing block of every `position: fixed` descendant.
   `prefers-reduced-motion` collapses every transition and animation globally.
2. **One radius scale, concentric:** controls 10 px, cards and popovers 14 px, dialogs 16 px; an item
   inside a 14 px popover with 6 px padding is 8 px.
3. **Type at 13 px for interface text at every width.** The 12 px and 11 px desktop downgrades on
   inputs, table cells and buttons were the most-cited "too small". Table headers are sentence case.
4. **The navy rail stays.** v2.42.0 documents why a near-white rail failed; without the reference image
   that product decision was kept and its states modernised. Reverting it is the owner's call.
5. **Equipment IDs are PREFIX-NN** (`APAR-01`), numbering continuing from the highest number in either
   form. A NUMBER is taken once per operating unit however it is padded, a short code finds a unit still
   labelled with the long one, and `ioms:equipment-codes` shortens existing codes only after proving
   there is no clash (read-only by default, each change in the unit's audit trail). The code is a label;
   every relation uses the numeric id.
6. **An equipment inspection is judged from a checklist per equipment type, by the server.** A template
   belongs to an equipment type (category `equipment`) or to an area-inspection category. Items declare
   their check (Pass/Fail, a reading with limits, a date read off the unit), whether a Fail is critical,
   and whether a Fail needs a photo. `SafetyEquipmentInspection::judge()` decides: a critical Fail is
   Fail, any other Fail is Needs Action, an out-of-range reading or a date before the inspection fails by
   itself, a critical item cannot be N/A, Damaged is never Pass. The items are filed with the answers.
   Anything other than Pass needs findings and a risk level (on the earlier free form too); follow-ups are
   `CorrectiveAction` rows (owner, due date, completion and VERIFICATION in the CAPA register), not a new
   mechanism. NCRs were not used: they belong to Quality Control, which the HSE workspace does not show.
7. **Reference checklists start a template; they are not procedures.** APAR (Permenakertrans
   PER.04/MEN/1980), Kotak P3K (PER.15/MEN/VIII/2008) and Gas Detector (manufacturer practice) live in
   `config/hse_checklists.php`, each with its basis; HSE reviews them before saving. Nothing is seeded.
8. **A waste quantity is in a unit the system can reason about.** One list (kg, ton, liter, m3, unit)
   plus the waste type's own unit; spellings are normalised; a count is whole; nothing converts between
   kinds. A record is stored in a TPS of its own operating unit.
9. **Labels stay English.** The brief asked for Indonesian-first copy; the language hierarchy (v2.53.0,
   pinned by `LanguageHierarchyTest`) assigns labels to English and guidance to Indonesian. Changing it
   is a whole-product decision for the owner, not a per-form drift.

## Consequences

- One additive migration (checklist, risk level, photos on inspections; equipment type on templates),
  classified `additive` by the deploy policy.
- Pages inherit the motion, radius and type changes from the shared primitives; pages that hand-roll
  their own markup still need moving onto them (listed as remaining work).
- A unit with an old long code keeps it until `ioms:equipment-codes --apply` is run; its printed label
  must be reprinted then.
