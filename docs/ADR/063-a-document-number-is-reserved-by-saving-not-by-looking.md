# ADR 063 — A document number is reserved by saving, not by looking

- **Status:** Accepted
- **Date:** 2026-10-19
- **Version:** 2.124.0
- **Relates to:** ADR 025 (numbering series), ADR 061 (an offer is bound to what the server allows)

## Context

IOMS issues document numbers from a per-tenant sequence: `PTW-2026-00007`, `INC-2026-00012`,
`BAST-2026-00003`. `NumberGeneratorService::generate()` takes the next one by incrementing
`numbering_sequences.last_number` inside a locked transaction, which is correct for a number that
must never be issued twice.

Twenty-four create pages also wanted to TELL the reader what the record would be called — "Nomor
kasus EC-2026-00002 akan dibuat saat disimpan" — and each of them did it by calling `generate()`.

That is two different questions answered by one method, and the consequences were measured in a
browser against MySQL 8, not reasoned about:

- Opening `/incidents/create` three times moved the incident sequence from **2 to 5** while the
  `incidents` table held **one** record.
- `employee_cases` stood at sequence **3** with **zero** rows: three people had opened the form, and
  EC-2026-00001 through 00003 will never exist.
- The number on the form was never the number the record received. `store()` calls `generate()`
  again, so the form promised EC-2026-00002 and the save produced EC-2026-00003.

For an internal counter this would be untidy. For these registers it is worse: a Permit to Work
register, an incident log and an NCR series are read by auditors, and a gap in the numbering is a
question about deleted records. There was nothing to delete — the gaps were page views.

## Decision

**Taking a number is a consequence of saving a record. Looking at a form is not saving.**

Two methods, named for what they do:

| | `generate()` | `preview()` |
|---|---|---|
| Increments the sequence | yes | no |
| Creates the sequence row if absent | yes | no |
| Called from | `store()` | a create page's Inertia prop |
| Guarantee | this number is yours | this is what the next save would get |

`preview()` reads the same format and the same sequence row and returns `last_number + 1`, formatted
identically. It writes nothing at all — not even the sequence row, because a page view is a read and
a brand-new tenant has issued nothing.

Every model with a `generateX()` now has a `previewX()` beside it, delegating to `preview()` with the
same module key, so the key stays in one place per model.

## Consequences

- **A preview is a forecast and is honest about being one.** Two people on the same form see the
  same number, and whoever saves first gets it; the second save gets the next one. That is the
  truthful behaviour for a shared counter, and it is what a reader of a register expects — numbers
  in order, no holes.
- **Abandoning a form costs nothing**, which is the whole point. Opening Create to look at the
  fields, or bouncing off a validation error, no longer consumes anything.
- **Nothing about uniqueness changed.** `generate()` is untouched: same lock, same transaction, same
  per-tenant scope (ADR 025). The sequences this release inherits keep their current position — the
  numbers already burned stay burned, because renumbering issued documents would be worse than the
  gap.
- `tests/Feature/DocumentNumberPreviewTest.php` pins both the behaviour and the mistake: a source
  scan fails if any `'...Number' => Model::generate...()` Inertia prop reappears, which is the shape
  that was copied twenty-four times.

## What this does NOT claim

It does not claim production is fixed. Per ADR 057 that question is answered by comparing the
signature `app:deploy` prints with the one Master Admin → Running Release prints, and by nothing
else.
