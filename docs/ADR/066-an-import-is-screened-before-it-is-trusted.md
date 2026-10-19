# ADR 066 — An import is screened before it is trusted

**Status:** Accepted · **Date:** 2026-10-19 · **Release:** v2.126.0

Extends [ADR 059](059-bulk-ppe-issuance-is-its-own-import.md) (bulk PPE issuance is its own import)
and [ADR 053](053-a-document-is-a-definition-not-an-export.md) (one front door for every format).
Does not change a single PPE business rule.

---

## The report

v2.125.0 added a sheet picker and a column-mapping panel to the PPE import. The owner's reply was
that, in use, nothing had changed.

**That was correct, and the reason is instructive.** The mappable fields for this import are
`employee_id` and `employee_name` — so the panel offered two rows. Everything that actually matters
in a company's own PPE register was still decided silently:

- which column is which piece of equipment,
- which column holds a date and which holds a size,
- which rows are even people,
- and who each row is about.

All of it by matching a heading against a catalogue name **in English**. A register whose columns
read *Tgl Sepatu Safety*, *Ukuran Sepatu*, *Tgl Baju Kerja* matched nothing, so the import read a
file with no PPE columns and reported it as such. The interface had grown; the reading had not.

## What a company's register actually looks like

Measured against two real-shaped workbooks, not imagined:

| What it has | What the old reader did |
|---|---|
| An instructions tab before the data tab | Opened on sheet one |
| A title, a subtitle and a blank line above the headings | Took a sub-table ten rows in, and reported a worker's NAME as a column heading |
| `No. Induk` for the badge number | Not recognised — `comparisonKey()` keeps the period, so the dictionary missed `no. induk` |
| `Tgl Sepatu Safety` / `Ukuran Sepatu` as two columns | Neither recognised |
| `FABRIKASI` as a department separator row | Read as an employee, and reported as **"Employee ID berikut tidak ditemukan: FABRIKASI"** |
| A `Total` subtotal row | Same — reported as a missing member of staff |
| `Baju Kerja` **and** `Coverall` as two garments | Only Coverall existed in the catalogue at all |

Every one of those was seen by opening the dialog and uploading the file.

## The decision

**An import is a screening, then a confirmation.** Seven stages, and nothing is written until the
last one: upload → read the structure → correct the mapping → check the identities and dates →
review the totals → confirm → report.

The screening endpoint (`POST /ppe/import/screen`) returns the server's **whole reading, with its
reasons**, and the panel renders exactly that. Three properties make it honest:

1. **It writes nothing.** Not a transaction, not a draft row, nothing.
2. **Every correction is posted back and RE-SCREENED**, never applied in the browser. What the
   person is looking at is always what the server would actually do — which is the only thing that
   makes a preview a promise rather than an illustration.
3. **Three confidence states that never share a colour.** `matched` is "this is certain", `review`
   is "probably right, look at it", `unmatched` is "I do not know". The brief is explicit that
   automatic detection must not be presented as certainty.

### The dictionary proposes; the catalogue decides

`PpeColumnVocabulary` maps a heading to a **canonical concept** and nothing more. Whether that
concept exists is answered by `PpeType::effectiveForCompany()`, and a concept the catalogue has no
row for is **reported by name** rather than created — ADR 059's "an import matches master data and
never invents it", unchanged. There is no path by which a synonym becomes a PPE type.

**A work uniform is not a coverall.** They are separate entries with no shared synonym, deliberately.
A coverall is worn over work clothing and takes the wear; a uniform is replaced less often. Folding
`Baju Kerja` into `Coverall` because both satisfy the same completeness group would issue the wrong
garment and record it as the right one. A *bare* garment word (`Baju`, `APD`) proposes **nothing** and
asks, because it genuinely is ambiguous.

### Identity is matched by number, then NIK, then — carefully — name

A name resolving to two people returns `ambiguous` **with the candidates**, never the first row.
Duplicate names are normal in an industrial workforce; this codebase has already shipped a defect
where two different "Siti Wijaya" records were selected at once (v2.38.0).

### One reader, so the screening and the import cannot read two files

`WorkbookGrid` is shared by both paths. If the import found its own sheet and its own header row,
everything the person approved would be a description of something else — the v2.108.1 defect ("the
preview is a promise the import keeps") in a larger and far more convincing disguise.

The screening reads the **raw grid** rather than an `ExtractedTable` for the same reason: the
locator picks ONE table out of a document, which is right for an import that must guess and wrong
for a screen whose entire purpose is to let a person choose.

### The row classification travels with the plan

Rows the screening read as furniture are sent as `ignored_rows` and skipped **silently**, counted
on their own. They are not errors, and without this the report named a department heading and a
subtotal as missing staff. Rows the person **rejected** are sent separately, counted as rejected,
and checked before the Employee ID is even looked at — so a rejected row cannot contribute a
warning, a duplicate claim or a "not found" either.

## What did not change, and was not allowed to

- **Every PPE business rule.** An empty date cell is still not a fact and produces no transaction. A
  type is still resolved against the company catalogue. The duplicate guard still resolves a type
  FAMILY (ADR 059). Tenant isolation still holds — and a plan arriving from a request has every
  type id re-checked against the company's own catalogue, so it cannot name another tenant's row.
- **The IOMS template path.** An import that sends no plan resolves its own headings exactly as it
  did before this release, and a test asserts it.
- **The employee importer.** Untouched. "No regression to the existing Employee Import" was an
  explicit requirement, and `EmployeeImportDialog` has deliberately not adopted this panel yet — it
  is surrounded by ~3,800 lines of regression born of four consecutive production incidents, and
  that is not a thing to change in the same release as this.

## One new column

`employee_ppe.size`, nullable, not backfilled. A company's register records a size beside the date
for every item, because that is what the storeman needs at replacement; IOMS recorded the date and
dropped the size. A field rather than a line in `remarks`: parsing it back out of free text is how
the relation gets lost a second time.

## Evidence

Driven through the real dialog against MySQL 8, with a workbook carrying every awkwardness in the
table above. `Tgl Baju Kerja` → Work Uniform and `Tgl Coverall` → Coverall as two different
catalogue rows from one file; sizes 42 and 41 recorded against Safety Shoes; `FABRIKASI`, `PRODUKSI`
and `Total` read as furniture and absent from the report; `ZZZ-9999` refused; an unreadable date
refused by column AND row; and after pairing `Ukuran Baju` with Work Uniform by hand, sizes L and M
landed on the uniform issuances. Pinned by `PpeImportScreeningTest` (32 tests).
