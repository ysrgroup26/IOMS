# ADR 054 — A deleted employee is RESTORED by an import, never duplicated

- **Status:** **Superseded by [ADR 055](055-deleting-an-employee-is-permanent.md)** (v2.115.0)
- **Date:** 2026-10-12 (v2.114.0)

> [!warning] The decision below no longer holds; the CONTEXT still does.
> This ADR answered the reported defect by RESTORING a soft-deleted employee instead of refusing
> their row. The owner then decided that employee deletion must be PERMANENT, which removes the
> state this document is about: there is no deleted-but-present employee left to restore, so the
> importer's two registries, the restore-in-place write and the separate "restored" counter are all
> gone. Read it for why the defect happened and why ignoring trashed rows was not the fix — both are
> still true and still worth knowing. Read ADR 055 for what the product does now.

## Context

This decision exists because of a report from use, and the report reads like it cannot be true:

> I imported 75 employees, every single row came back **"Duplicate Employee ID"**, and the Employees
> page says **"Belum ada data karyawan"**.

Both halves were accurate.

`Employee` uses `SoftDeletes`. Deleting a mis-imported roster sets `deleted_at` and leaves the rows
in the table — which is the entire point: `kpi_records`, `man_hour_logs`, `employee_ppes`,
`project_manpowers`, approvals and `activity_logs` all reference `employees.id`, and a hard delete
would orphan every one of them (see ADR 051, and the v2.107.0 pitfall on the 93 foreign keys).

The Employees list applies the soft-delete scope, so it showed nothing. `EmployeesImport` built its
duplicate registry with `Employee::withTrashed()`, so it saw all 75. One screen was answering *who
works here*; the other was answering *which employee numbers are taken*. Nothing told the customer
those were different questions, and the product was unusable in the single most ordinary recovery
there is: delete a bad import, fix the file, import again.

Two facts constrain any fix:

1. **The unique indexes include soft-deleted rows.** `employees_company_employee_id_unique` and
   `employees_company_nik_unique` are plain SQL indexes; they know nothing about `deleted_at`. So the
   trashed row genuinely occupies the number.
2. **The history hangs off the primary key**, not off the employee number.

## Decision

The importer keeps **two** registries, not one, and the two trashed-row cases are handled
differently from each other and from the live case.

| State of that employee number in this company | What the import does |
|---|---|
| A **live** employee holds it | **Refuse the row.** Named, with the reason: already used in this Operating Unit |
| A **deleted** employee holds it | **Restore that row in place**, then fill it from the file |
| Nobody holds it | Create a new employee |

### 1. Restore in place — the same row, the same primary key

`restore()` on the existing record, then `fill($attributes)->save()`. Not `create()`, and not a
hard delete followed by an insert.

This is the whole decision. The record comes back **with its id**, so its KPI records, man-hours, PPE
issues, project assignments, approvals and activity log are attached to the person they describe.
Creating a second row for the same human being would leave all of that pointing at a record nobody
can find in any list — the data would still exist and would be unreachable, which is worse than
either losing it or never having it.

It also means **the customer's correction lands on the record they corrected.** A restored employee
is updated by the file, not left at whatever values it held when it was deleted; that is why the
`fill()` follows the `restore()` rather than replacing it.

### 2. Why NOT simply stop looking at trashed rows

The one-line fix is to drop `withTrashed()` from the duplicate check. It is wrong, and it is wrong in
a way that is worse than the bug:

Because the unique indexes include soft-deleted rows, the insert would then hit the database
constraint and throw `UniqueConstraintViolationException`, rolling the entire import transaction
back — **including the departments and positions created moments earlier**. The customer would
exchange 75 readable, per-row refusals they can act on for one opaque "Import failed" and no
indication of which row caused it. That is exactly the failure mode the v2.105.0 migration was
written to end; re-creating it by a different route would not be a fix.

### 3. A restoration is REPORTED as a restoration

`EmployeesImport` carries `$restored` alongside `$imported`, and the two are never merged:

- the **preview** says so before anything is written ("karyawan pada berkas ini pernah dihapus …"),
- the **result summary** reads `0 imported successfully · 75 restored`,
- the **audit trail** records *Restored 75 previously deleted employee(s) from …*.

"We created 75 records" and "we brought 75 records back" are different events with different
consequences, and the customer has to be able to tell which one happened to their database. A single
merged counter would also make the behaviour untestable from the outside, which is how a regression
here would go unnoticed.

### 4. A NIK held by a DIFFERENT deleted employee is refused, not restored

If the row's NIK belongs to a deleted employee with a different employee number, the row is refused
and the message names that employee number, suggesting restore-or-purge. The alternative — restoring
whichever record matched first — would merge two people, and merging two employees is not a mistake
anybody can unwind from the UI.

### 5. The tenant boundary is applied explicitly, because the global scopes are dropped

Both registries query with `withoutGlobalScopes()` and an explicit
`where('company_id', $this->companyId)`. The scopes are dropped deliberately — the soft-delete scope
has to be, since the whole point is to see trashed rows — so the company filter is written out by
hand rather than inherited. A trashed employee of **another tenant** can never be restored, and that
is asserted by a test rather than left to the reader.

## Consequences

- The ordinary recovery path works: delete a bad import, correct the file, import it again, and the
  roster comes back intact with its history.
- A live duplicate is still refused, with a readable reason, per row. Nothing about the
  already-employed case got looser.
- An import can now **modify** existing (deleted) records, not only create new ones. That is a real
  widening of what the importer does, which is why it is reported separately in three places and
  announced in the preview before anything is written.
- A customer who deliberately wants a previously-deleted employee to stay deleted must not re-import
  their row. There is no "restore nothing" switch; adding one would be a guess at a need nobody has
  expressed, and the preview says plainly what will happen.

Pinned by `tests/Feature/EmployeeReimportAfterDeletionTest.php` (12 tests), which walks the exact
reported sequence and asserts the primary key survives, that a live duplicate is still refused, that
a mixed file restores and refuses in the same pass, and that another tenant's deleted employee is
never touched.
