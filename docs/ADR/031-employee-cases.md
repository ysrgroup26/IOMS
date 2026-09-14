# 031 — Employee Cases (HR Employee Relations and Discipline)

## Status

Accepted (v2.69.0).

## Problem

HR had no way to record that a concern had been raised about an employee, what was decided, what was
issued, or whether it was still in force. The obvious shapes were both wrong:

- **A flag or colour on `employees`** cannot answer any of the questions the record exists for:
  what happened, when, who decided, what was issued, is it still valid, and has it happened before.
- **A single "disciplinary status" column** goes stale silently. A Surat Peringatan is valid for a
  fixed term — six months is the usual one — and a column does not know the date has passed.

## Decision

### 1. A case, with actions as child rows

`EmployeeCase` is the container: what was raised, about whom, who is handling it, and how it ended.
`EmployeeCaseAction` is what was actually issued, and only exists when something was.

This mirrors the case-and-actions shape IOMS already uses (`Incident -> CorrectiveAction`, `Ncr`)
rather than inventing a new one. It deliberately does **not** reuse the polymorphic
`corrective_actions` table: that models an assigned remedial *task* (assignee, due date, evidence,
verification), whereas a disciplinary action models an issued *sanction* with a validity window and
an acknowledgement. Forcing one table to be both would leave half its columns meaningless in either
direction.

### 2. "Case", not "Disciplinary Record"

Not every concern raised is misconduct, and naming the container after the worst outcome prejudges
it. A case can be **dismissed** with no action at all, and that outcome has to be recordable without
the record itself calling the person disciplined.

`dismissed` is therefore distinct from `closed`: the first means "reviewed, nothing to answer", the
second means "ran its course". From the employee's side that difference is the whole point, so it is
not collapsed into one terminal state.

### 3. Standing is derived, never stored

`Employee::currentDisciplinaryStanding()` returns the most severe action still in force, computed
from the actions' own `issued_at`/`effective_until` windows. Nothing is cached and no job runs.

This is the same rule `Employee::profile_status`, `PurchaseOrderItem::delivered_quantity` and Vendor
Performance already follow in this codebase: **if it can be computed from records that already
exist, it is not state.** Here it matters more than usual — a stored level would be correct on the
day it was written and wrong every day after, with nothing to signal the change.

`effective_until` is nullable, and null means "does not lapse" (termination), not "unknown".

### 4. `action_issued` is reachable only from `under_review`

A sanction cannot be issued on a case nobody reviewed. The lifecycle guard enforces it, and the
controller creates the action and moves the status in one transaction so the two can never disagree
— the status is a *consequence* of an action existing, not something set independently.

### 5. Confidentiality is the security decision in this module

`User::canManageEmployeeCases()` is **narrower than every other HR permission**: HR and Company Admin
only. `isManager()` is explicitly absent even though Manager reads most of the HR workspace.

Most HR data in IOMS is operational — a manager has a legitimate reason to see who is on shift, on
leave, or certified. A record that someone was investigated for misconduct is not that. "Can view
the employee list" must not silently become "can read their disciplinary history".

A line manager who genuinely needs to act on a case is given it through `assigned_to` on that case:
a per-case grant, auditable, rather than a role that opens every case in the company.

**The employee profile enforces this before serialization, not in React.** An Inertia page ships its
props to the browser whether or not a component renders them, so hiding the card client-side would
leave the data in the page source. `EmployeeController::show()` resolves the `disciplinary` prop only
for a viewer who may read cases, and a test asserts the payload is null otherwise.

### 6. Local terms stay local

`sp1`/`sp2`/`sp3` keep the established term the way PTW, HIRADC, JSA and LOTO do elsewhere in IOMS.
Renaming them "Warning Letter Level 1" would stop them matching the document the company actually
issues. `disciplinaryActionLabel()` renders them "SP 1", in one place, so the case record and the
employee profile cannot drift.

## Consequences

- An employee's history is a list of dated, attributed, expiring records rather than a flag.
- An escalation decision can be read against prior cases and current standing on the same screen.
- Cases are soft-deleted: an HR record is evidence of a decision about a person and may be needed
  long after the fact.
- `EmployeeCaseAction` has no `company_id`; it is owned through its case via
  `BelongsToCompanyThrough`, so the isolation rule is stated once.

## What this deliberately does not do

- **No grievance or appeal workflow.** A case is currently raised *about* an employee. An
  employee-initiated grievance is a different actor and a different confidentiality model, and
  bolting it onto this lifecycle would make both worse.
- **No automatic escalation** from SP1 to SP2 on a repeat offence. The system shows the standing and
  the prior count; deciding to escalate is a human judgement with legal consequences, and inferring
  it would be the system making that call.
- **No letter generation.** `reference_number` records the company's own letter; IOMS's document
  engine could render one later, but issuing a real disciplinary letter from a template nobody
  reviewed is not a feature to add speculatively.
- **No notification to the employee.** IOMS's notification centre targets `User` accounts, and most
  employees in this product are not users. The acknowledgement field records service of the letter
  as a fact, which is what an employment dispute turns on.
