# ADR 051 — Bulk deletion is all-or-nothing, and the count is agreed in advance

- **Status:** Accepted
- **Date:** 2026-10-11 (v2.108.0)

## Context

Removing employees was one record at a time. The operation that actually needs doing is the other
one: a file imported with the wrong operating unit, or a duplicated register, leaves hundreds of
rows to remove, and `destroy()` means hundreds of confirmations. Customers do not do that — they
ask for the database to be edited, which is worse.

Bulk deletion is also the single most destructive thing the Employees module can offer, and the
obvious implementation of it is unsafe in three specific ways. This ADR records which ways, because
each refusal below looks like an inconvenience until you know what it is for.

## Decision

`DELETE /employees/bulk`, authorized by `EmployeePolicy::deleteAny()` (same `isAdmin()` authority as
single deletion — bulk is the same power applied to a set, never a wider one), with every safety
rule in `BulkDestroyEmployeesRequest` rather than in the controller.

### 1. All or nothing

If **any** id in the request is not a deletable employee of the caller's own tenant, the whole
request is refused and **nothing** is deleted.

The tempting alternative — "delete the ones you're allowed to" — is a cross-tenant oracle. The
number of rows deleted answers "does employee 4312 exist in another tenant?" for anyone who cares
to ask. It also leaves the customer unable to say what went and what stayed.

For the same reason the error message does **not** distinguish *belongs to another tenant* from
*never existed* from *already deleted*. All three get one sentence.

### 2. The count is agreed in advance

The request carries `expected_count`: the number of records the human was shown and agreed to. If
the server resolves a different number, it refuses.

This is what makes a stale selection safe. A list filtered, paginated or changed by someone else
between selecting and confirming would otherwise delete a set the user never saw. The server trusts
the number the human agreed to, not the list the page happened to submit.

### 3. The word is typed, and checked on the server

A dialog the client could skip is not consent for this. `confirmation` must equal `HAPUS` and is
validated server-side. The frontend disables its own button until the word matches, but that is the
explanation, not the enforcement.

### 4. Soft delete, always

Same reason `destroy()` has always soft deleted one employee: KPI history, man-hours, PPE issue
records and approvals all reference the person. Nothing here destroys history. A bulk-deleted
employee's number also stays occupied in the per-company unique index, which is exactly what
`EmployeesImport` already expects (it checks `withTrashed()`).

### 5. Bounded

500 per request. High enough for the real case — undoing a mis-imported file — and low enough that
a runaway client cannot ask to remove an entire workforce in one call.

### Where the rules live

All of them are in the FormRequest, including resolving the ids. The tenant boundary is a
validation question, not a cleanup step: resolving it in `after()` means the request never reaches
the controller in a state where a partial delete is even *expressible*. The controller deletes what
it is handed and records the audit trail.

## Consequences

- Every removal is recorded in `ActivityLog` against its own subject, plus one summary line for the
  act itself — so the Activity Center shows a bulk removal as the single decision it was, not only
  as N unrelated deletions. This is why the controller loops instead of issuing one
  `whereIn()->delete()`; the transaction keeps the set atomic regardless.
- The route must be declared **before** `employees/{employee}`, or Laravel binds `"bulk"` as an
  employee id and 404s. Pinned by `EmployeeBulkDeletionTest`.
- Refusals are a redirect carrying validation errors, which is the Inertia contract the rest of the
  app uses — not a 422 JSON body.
- There is deliberately **no bulk restore** and no bulk hard delete. Restoring is not symmetrical
  with deleting: it has to decide what to do about a number another record has since taken. If it
  is wanted, it is its own decision and its own ADR.
