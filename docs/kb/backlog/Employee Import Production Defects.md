---
title: Employee Import Production Defects
type: backlog
status: COMPLETED (v2.105.0) — root causes found, fixed and regression-tested
updated: 2026-10-07
tags: [kb/backlog, status/verified]
---

# Employee Import Production Defects

**Source:** owner report from production — "an account created by the Admin detects master data, the
Admin account sometimes detects nothing at all", and "the preview says 141 rows and 141 valid, then
Create Missing Master Data & Import says Import failed" · **Board:** [[Project Board]]

Neither was a reporting problem, and the error message was the least interesting part of both.

## 1. The employee number was unique across the entire installation

`employees.employee_id` carried a **global** unique index from `2024_01_01_000005`, written when
IOMS was one company's internal tool. It was never revisited when multi-company arrived, and never
when multi-tenancy did.

**One customer's `EMP-001` prevented every other customer from ever having one.** EMP-001, 1001 and
00123 are exactly the sequences every yard starts with, so collisions between unrelated customers
were the expected case, not a remote risk.

**What made it hard to find is that the preview was right.** The import checks for an existing
number with `Employee::withTrashed()->where('employee_id', $id)->exists()`, correctly scoped by
`CompanyOwnedScope` to the customer's own data. Another tenant's rows are invisible to it, as they
should be. So the 141-row file reported **141 valid rows** — a true statement about everything that
query could see — and the insert then threw against data the customer could neither see nor act on.
Everything ran in one transaction, so all 141 rows and the departments created with them were rolled
back.

Fixed by scoping the index to `(company_id, employee_id)`, which is what an employee number has
always meant, and moving the check that predicts it to the same granularity (now one query per
import rather than one per row). `nik` had the identical defect and was fixed with it.

**The rollback deserves a note.** Restoring the global index can legitimately fail once two companies
each have an EMP-001, so `down()` adds the old index **before** dropping the new one — a failed
rollback then leaves the stronger constraint in place instead of a table with no uniqueness at all.
An earlier version of that method did it the other way round and was caught in testing.

## 2. One bad row cost the other hundred and forty

A failure raised by the **database** (a phone number longer than its column, a constraint this code
did not anticipate) threw straight out of the row handler. Running inside the caller's transaction,
that discarded every row already imported.

This contradicted the importer's own documentation, which has always said it never stops on one bad
row — true of every check it performs itself, and never true of the ones the database performs.
Failures are now caught per row, recorded with the row number and a readable reason, and the import
finishes.

## 3. The dialog silently did nothing

It preselected an operating unit only when the account could see exactly one. Because
`CompanyAuthorizationScope` means two accounts legitimately see different lists, an administrator
who could see several got **nothing** selected — and `runPreview()` opened with
`if (!file || !companyId) return;`.

Two accounts, two behaviours, no message. It now defaults to the account's own operating unit
(`auth.user.company_id`, newly shared) and names whatever is still missing.

## 4. The naming rules the owner specified

`App\Support\NameFormatter` is the single definition, applied at detection, matching, creation and
storage — **and to master data typed by hand in Settings as well as imported**, because it arrives
by two doors and both must agree.

| | |
|---|---|
| Department | `MECHANIC`, `MECHANIC VESSEL` |
| Position and person | `Mechanic Senior`, `HSE Officer`, `Budi Santoso` |
| Acronyms | `config/naming.php`, so `hse officer` is not `Hse Officer` |

Only case and spacing are collapsed. **`Mechanic` and `Mechanic Vessel` stay two departments** —
nothing does stemming or prefix matching, and the only place similarity is considered is the typo
*suggestion*, which proposes and never merges.

## Verified

- **18 tests**, including a real 141-row .xlsx driven through the real endpoints.
- The **cross-customer collision reproduced** and confirmed to fail with the migration disabled.
- A rejected row proven not to discard the rest; a number already used in the same unit reported as
  a skipped row rather than thrown.
- Migration applied, and its `down()` tested — which is how the rollback-ordering flaw was found.
- **Browser**: the dialog checked with two operating units visible, confirming the account's own
  unit is preselected where previously nothing was.
- **Full suite 973 passed / 5206 assertions.** Build clean, ESLint 0 errors.

## Deployment note

This release contains a **migration that changes an index on `employees`**. It is additive in effect
(it only permits rows previously refused) and cannot fail on existing data, but it rewrites an index
on a populated table, so it belongs in a normal maintenance-window deploy rather than a casual one.

## Links

- [[Employee Import and Admin Space Naming]] · [[Settings Configuration Reachability]]
- `docs/CONVENTIONS.md` § *a uniqueness constraint narrower than the tenancy around it*,
  § *a form that silently does nothing when a default is missing*
- `docs/ARCHITECTURE.md` § *How a name is compared, and how it is written*
