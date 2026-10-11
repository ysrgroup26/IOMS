# ADR 052 — A suggested column mapping must be the one the import actually runs

- **Status:** Accepted
- **Date:** 2026-10-11 (v2.108.1)
- **Extends:** Smart Import (v2.102.0), `App\Services\ImportHeaderMatcher`

## Context

Smart Import reads a customer's own workforce list, decides which of their columns feeds which IOMS
field, and the import dialog renders that decision as a "Column Mapping" panel under the words
*"IOMS mencocokkan kolom pada berkas Anda dengan kolom yang dipakai sistem. Periksa bagian di bawah
ini sebelum melanjutkan"* — IOMS has matched your columns; check them before continuing.

The mapping was never sent back. `/employees/import/inspect` returned it, the dialog drew it, and
then `previewImport`, `import` and `createMissingMasterDataAndImport` each read the file through
`FieldMappingService::importColumnKeys('employees')` — the tenant's **configured** mapping, which
for any tenant that has not visited Settings is the official template's English labels. No endpoint
accepted a mapping at all.

So the panel described work that did not happen. Measured on a plain Indonesian workforce list
(`No. Induk Karyawan`, `Nama Lengkap`, `Tanggal Masuk`): the inspector matched every column, and the
import then stored **zero of two rows**. Where only optional headings differ the rows do land, and
those columns are silently empty — a customer gets "2 imported" and a register missing data that was
in the file they uploaded.

The inspect call also ran *after* the preview, purely to draw the panel, so even the preview's own
counts were computed through a different mapping than the one on screen.

## Decision

**The mapping travels with every request that reads the file.** `column_mapping` — `[field =>
heading in their file]` — is accepted by preview, import, and create-missing-and-import, and the
dialog inspects *before* it previews, so the preview is a promise the import keeps.

It is treated as **input, not as a hint**, and validated as such:

- only fields IOMS actually imports may appear (`array:` against `config/mapping_fields`), so a
  mapping cannot name something outside the import;
- each heading is a plain string, bounded in length, never a nested structure;
- **no two fields may claim the same heading** — that is silent field shifting by construction, and
  it is refused rather than resolved.

Absent, everything behaves exactly as before: the configured mapping applies, which is the official
template's path and every existing tenant's path.

The heading → key conversion lives in `FieldMappingService`, not at the call site, because that
class already owns the one rule that makes a heading into a `$data` key: `Str::slug($v, '_')`,
matching Maatwebsite's slug formatter — **not** `Str::snake`, which turns "Employee ID" into
`employee_i_d` and "NIK (KTP)" into `nik(ktp)`. A second copy of that rule is a second chance to get
it wrong, and a test in this repository had already got it wrong that exact way.

## Consequences

- Smart Import is now end-to-end rather than a preview feature. The customer's own file imports
  through the mapping they were shown and approved.
- The panel's copy changed to describe what happens: *"IOMS membaca berkas Anda memakai pencocokan
  kolom berikut"* — IOMS reads your file using the following matching. Copy that overstates what
  the software does is a defect with a long tail, because it suppresses the bug report.
- A mapping is per-request and per-file. It is deliberately **not** persisted: the tenant-wide
  mapping in Settings is a different thing (a standing rule for files that always look the same),
  and quietly rewriting it from one upload would change every later import.
- Any future importer that gains a suggestion step inherits this rule: **a suggestion the user
  confirms must be the thing that executes.** A suggestion that only decorates is worse than no
  suggestion, because it transfers confidence without transferring behaviour.
