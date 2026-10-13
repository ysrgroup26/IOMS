# ADR 055 — Deleting an employee is permanent, and one place answers for every reference

- **Status:** Accepted
- **Date:** 2026-10-13 (v2.115.0)
- **Supersedes:** [ADR 054](054-a-deleted-employee-is-restored-not-duplicated.md), which answered the same
  report by restoring the soft-deleted record instead of removing it.

## Context

ADR 054 has the full history of the reported defect; the short version is that `Employee` carried
`SoftDeletes`, so an employee the customer had deleted still held their employee number and NIK
against unique indexes that know nothing about `deleted_at`. An import of 75 people was refused row
by row as duplicates of employees the Employees page said did not exist.

ADR 054's answer was to restore the deleted record in place. That worked, and it kept the
person's KPI history attached — but it left the underlying oddity standing: **a deleted employee
still existed.** Two consequences the owner then had to live with:

- the only way to free an employee number was to import a row that reinstated the person, which is
  not what "delete this employee" means to anybody;
- `tests/Feature/...` and the import carried machinery for a state (deleted-but-present) that no
  screen in IOMS ever showed, offered to restore, or filtered for.

**The owner's decision, stated directly: deletion is permanent, for one employee and for a set.**
Soft deletion must not remain the mechanism that keeps a deliberately deleted employee blocking an
import.

That decision is the easy half. The hard half is that `employees` is the target of **twenty-four**
foreign keys, written months apart, with four different delete rules between them — ten CASCADE,
twelve SET NULL, two RESTRICT and one NO ACTION. A hard delete without a plan does three bad things
at once: it throws for anybody who filed a daily report, attended a toolbox meeting or requested
leave; it destroys operational history nobody decided to destroy; and it blanks a line on documents
that print the person's name.

## Decision

`Employee` loses `SoftDeletes`, and **`App\Services\EmployeeEraser` is the one place that knows what
removing an employee reaches.** It answers every reference explicitly rather than leaving the
database to do whatever its `onDelete` rule says.

### 1. Three kinds of reference, and they are not the same question

| Kind | What it is | What happens |
|---|---|---|
| **Dependents** | Records that exist only to describe this employee: KPI entries, man-hours, PPE issues, competencies, rosters, shift assignments, internships, disciplinary cases, leave requests, project manpower, permit personnel rows, toolbox-meeting attendance | **Deleted with them** |
| **References** | Records of their own that point at the employee: an incident report, a permit, an asset, an asset transfer, a handover record, an investigation interview, a project activity, a regulation register, a visitor log, a work order, a daily report | **Kept; the pointer is cleared** |
| **Names on documents** | The subset of References that PRINTS the person's name | **The name is written in as text first** |

A KPI score belonging to nobody is not a record of anything, and leaving one is strictly worse than
deleting it: `KpiRecord`, `EmployeePpe` and the rest reach their owning tenant *through* the
employee (`BelongsToCompanyThrough`), so an orphan is invisible to every tenant at once while still
being counted by any raw total. "Unassigned" is already a legitimate state for every record in the
second row.

### 2. A tombstone is not a duplicate

Two documents printed a name they did not own: `permits_to_work.pic_employee_id` renders the
permit's "Penanggung Jawab", and a pre-v1.5.1 `daily_reports.hse_officer_id` carries that report's
attribution. Both get a nullable text column — `pic_name`, `hse_officer_name` — and
`incidents.injured_person_name`, which already existed, is filled when it is empty.

**Nothing writes these on create or update.** The eraser writes them at the moment it clears the
link and only then, and neither column is in `$fillable`, so no request can put a name on a safety
document. The read paths ask the relation FIRST and fall back to the stored text
(`PermitToWork::picDisplayName()`, `resources/js/lib/permitPicName.js`), so while the employee exists
their record is the single truth and a rename still follows them. That ordering is what keeps this
from becoming the v2.101.0 pitfall — three places deciding the same fact and disagreeing.

### 3. The erasure is done in application code, not by changing the delete rules

The obvious alternative is a migration that rewrites the three RESTRICT/NO ACTION keys to CASCADE.
It was rejected for a specific reason: **SQLite cannot alter a constraint without rebuilding the
table**, so the suite and production would have disagreed about what deletion means — the one thing
that must never be true of a destructive operation. Doing it in code means the behaviour is
identical on both engines, is counted (the audit entry says how many rows went), and can be
asserted.

It also means the existing delete rules never fire: children are deleted and pointers cleared
*before* the employee row, so RESTRICT has nothing to refuse.

### 4. A hand-written list of twenty-four keys needs a guard, so it has one

`EmployeeErasureCoverageTest` reads the **live schema** — `information_schema` on MySQL,
`PRAGMA foreign_key_list` on SQLite — finds every column referencing `employees`, and fails naming
any the eraser has no policy for. Adding a twenty-fifth reference therefore means choosing what
happens to it. The same test fails if a policy names a table or column that no longer exists, since
the eraser guards each one with `Schema::hasColumn` and would otherwise hide a stale entry.

### 5. The authority does not change, but the confirmation does

`EmployeePolicy::delete`/`deleteAny` still ask `isAdmin()`: permanent deletion is what deletion now
means, not a new, wider power. `BulkDestroyEmployeesRequest` keeps all of ADR 051 — all-or-nothing,
the agreed count, the typed `HAPUS`.

What had to change is the copy. The bulk dialog said their KPI, man-hour, PPE and approval history
*"tetap tersimpan dan tidak ikut terhapus"*. That was true of a soft delete and is a lie about a
permanent one, and a confirmation that understates what it does is worse than none — the customer
agrees to something else. Both dialogs now state the consequence, and the single-record dialog
**counts the dependent records from the database** (`EmployeeEraser::impact()`, passed to the profile
page as `erasureImpact`) rather than describing them in prose a component made up.

### 6. The audit entry has to outlive its subject

`activity_logs` has no foreign key to its subject, so the row survives the deletion — but
`subject_id` then points at nothing. Each deletion therefore records a **snapshot**: employee number,
NIK, name, department, position, company, status, workforce type, join date, plus the count of
dependent records removed. This is `actor_name`'s reasoning (v2.100.0) applied to the subject
instead of the actor: an audit row can only honestly answer what it said at the time.

### 7. Employees already soft-deleted are purged, and that is the one irreversible step

`2026_12_21_100200_employee_deletion_becomes_permanent` deletes the rows whose `deleted_at` is set,
applying the same policy through its own frozen copy of the lists, and then drops the column. There
was no third option: keeping `deleted_at` leaves those rows blocking imports, which is the defect;
dropping it without purging makes **every employee the customer ever deleted reappear in their
active list**, which is worse.

The lists in the migration are a frozen copy and deliberately not a call into `EmployeeEraser`. A
data migration has to do the same thing in two years as it does today, against whatever that class
has become; one that follows live application code is a migration whose behaviour nobody can state.
`down()` restores the column and says plainly that it cannot restore the rows.

## Consequences

- The ordinary recovery works without ceremony: delete the bad import, correct the file, import it
  again. The numbers are free because the records are gone.
- `EmployeesImport` got simpler, not more complex: one live registry again, no trashed registries, no
  restore branch, no separate "restored" counter in the preview, the summary or the audit line.
- **Operational history is genuinely destroyed when an employee is deleted.** That is the owner's
  decision and the dialogs now say so. An administrator who wants the history must not delete the
  person — set their status to `inactive` or `resigned`, which is what those values are for.
- A historical toolbox meeting's attendee count drops when a participant is erased. The meeting, its
  topic, date, conductor and minutes all remain; the attendance row does not, because `tbm_attendees`
  is a pure pivot and a row pointing at nobody is not evidence of anything.
- Re-adding `SoftDeletes` to `Employee` would restore the defect rather than the safety, which is why
  a test asserts the trait and the column are both absent.

Pinned by `EmployeePermanentDeletionTest` (14 tests — the three formerly-blocking keys, the document
tombstones, the audit snapshot, the impact census, authorization and tenant isolation),
`EmployeeBulkDeletionDependenciesTest`, `EmployeeBulkDeletionTest`, `EmployeeErasureCoverageTest`,
`EmployeeSoftDeletePurgeMigrationTest` and `EmployeeReimportAfterDeletionTest`.
