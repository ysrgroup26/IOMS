# ADR 059 — Bulk PPE issuance is its own import, and it issues to people who already exist

- **Status:** Accepted
- **Date:** 2026-10-18
- **Version:** 2.120.0
- **Relates to:** ADR 050 / ADR 058 (shared catalogues and overrides), ADR 053 (one importer, many input formats)

## Context

The owner asked for bulk PPE import and offered two shapes:

- **A** — import PPE for many employees from **PPE Master**.
- **B** — extend **Employee Import** so one file carries the employee *and* their PPE.

Both were examined against the code before anything was written.

### Why B was rejected

`EmployeesImport` only ever **creates**. A row whose Employee ID already exists is skipped
entirely (`EmployeesImport.php:415`), so its PPE columns would never be read. Option B's own
requirement — "for employees already registered, handle the PPE relation correctly" — therefore
demands changing what a duplicate row *does*.

That behaviour is pinned (`EmployeeReimportAfterDeletionTest`: re-importing a roster imports 0 and
skips 3) and surrounded by roughly 3,800 lines of regression tests across nine files, which exist
because this one class produced four production incidents in quick succession: v2.105 (an employee
number unique across the planet), v2.113 (a NIK destroyed by scientific notation), v2.114
(seventy-five rows refused as duplicates of people the list said did not exist) and v2.115
(permanent deletion). Widening its duplicate semantics to carry a second module's data is a large
bet against a surface that has only just stopped moving.

### Why A was right in mechanism and wrong in location

PPE Master states its own boundary in the product: *"Data konfigurasi, bukan operasional harian …
Untuk mengeluarkan/mengganti APD karyawan, buka tab Employee PPE."* It also carries an `Admin`
badge, and the two tabs are gated differently:

| | PPE Master | Employee PPE |
|---|---|---|
| Gate | `canManagePpeMaster()` → Super Admin alone | `canManagePpeDistribution()` → Super Admin **and** HSE |

Putting bulk **issuance** behind the Master gate would have locked out HSE — the people who
actually hand equipment out — which is a access defect, not a tidiness complaint.

## Decision

**A separate importer, on the Employee PPE tab, that matches existing records and creates nothing
else.**

1. `EmployeePpeImport` mirrors `EmployeesImport`'s shape (`OnEachRow`, critical/optional split,
   per-row `try/catch`, a `previewOnly` mode so the preview and the import cannot disagree) and
   shares the Document Platform's front door through the new `ReadsImportedSpreadsheets` trait —
   extracted verbatim from `EmployeeController`, not copied, because a second copy of "when the
   bridge is taken and how a row number maps back to the customer's file" would drift from the
   first, and the row-number half of that already cost a release (v2.111.0).
2. It **never creates an Employee and never creates a PpeType.** An unmatched value is refused with
   a reason naming where to fix it. An import that invents master data is how a catalogue fills up
   with "Safety Shoes", "safety shoes " and "Sepatu Safety".
3. **The duplicate rule, set by the owner:** a person still holding PPE of that kind (`issued` /
   `in_use`) is reported, not issued again.

### The subtlety that decided the implementation

"That kind" cannot be one `ppe_type_id`. A shared built-in and this company's override of it are
**one kind of equipment wearing two ids** (ADR 050), and `EmployeePpe::creating()` silently remaps
a template to the override on the way in. So an employee issued the built-in *before* the override
existed holds the other id. Guarding on a single id would hand them a second pair of boots — the
exact outcome the rule exists to prevent. The guard therefore resolves a **family**: the template,
its overrides in this company, and the template an override points at.

This matters because **the database will not catch it.** `(employee_id, ppe_type_id)` on
`employee_ppe` is a plain index, not unique — deliberately, since re-issuing after a replacement is
normal — so idempotency lives in the importer or nowhere.

## Consequences

- Re-running the same file issues nothing and says why, per row. Verified in a browser on MySQL 8:
  a second pass over an identical roster reported 8 rows read, 0 to be issued, 6 already held, and
  the confirm button disabled.
- An archived or replaced item does **not** block a reissue, so the replacement lifecycle is intact.
- `issued_date` is **required**: an unreadable one costs the row rather than dating the equipment to
  the day of the import (the v2.108.0 rule — a date nobody typed is worse than no date).
  `expiry_date` is optional and normally omitted, because the type's replacement interval is the
  single source of truth and the model derives it.
- Rows are written with `EmployeePpe::create()`, never a bulk insert: the `creating` hook is what
  resolves the override and derives the expiry, and a bulk insert would quietly produce rows the
  manual form could never produce.
- **Four of the five types the owner named ship as built-ins** (Safety Helmet, Safety Shoes,
  Coverall, Safety Glasses). **"Seragam (Office)" does not**, and was deliberately NOT added to
  `PpeTypeSeeder`: that seeder is the installation-wide catalogue every customer receives (ADR 058),
  and a uniform is a per-customer item, not something to push into every tenant's list. The
  supported path is the one ADR 050 established — add it once in PPE Master, where it becomes a
  company-owned type. Verified end to end: before adding, the row is refused and the type is named
  on screen; after adding, the same file imports it.
- The guards are load-bearing, not decorative: removing the duplicate guard fails 2 of the 24 tests,
  and removing the company filter on the employee lookup fails the 2 isolation tests.
