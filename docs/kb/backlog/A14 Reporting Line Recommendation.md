---
status: PROPOSAL — awaiting the owner's decision (A14). Nothing here is implemented.
date: 2026-11-01
---

# A14 — Reporting line (Supervisor / Manager): recommendation

The owner asked for a design recommendation before any structural change, covering:

- the impact on the organisation structure;
- support for a Supervisor dashboard;
- more than one superior or reporting line;
- the risks to permissions and tenant isolation;
- the impact on existing employee data.

## What exists today (checked in the code)

- `employees` has `company_id`, `department_id` and `position_id`. **No** supervisor, manager or
  reporting relation exists on `employees`, `positions`, `departments` or `users`.
- A login reaches its employee record through `users.employee_id`, which is nullable.
- Positions are **shared**: the local sandbox has 73 positions for 82 employees, and a real yard has many
  welders and one Project Manager per project. So a position cannot say who reports to whom.
- `projects.manager_id` points at a **user**. It is the only "manager" relation in the data, and it is
  per project.
- Approval authority is now configurable by role, position or person (v2.141.0). It answers "who
  decides", not "whose team is this".

## Recommendation

### 1. One primary line per employee, as a column (phase 1)

`employees.reports_to_employee_id`: nullable, pointing at another employee of the **same tenant**.

- **The obvious answer for "my team".** Direct reports are one indexed query, and the Supervisor
  dashboard has a definite population.
- **Edited where people already edit an employee:** the Employee form, the importer (a "Reports To
  (Employee ID)" column, matched like every other reference, never invented — ADR 059/066), and a bulk
  "set supervisor" action on the register filtered by department.
- **Validated on the server:**
  - same tenant (a manager may sit in another operating unit of the same organisation);
  - not self;
  - **no cycle** (A → B → A is refused with the chain named);
  - the target is active.
- **Deleting an employee:** the reference is set to null on their reports. This is the ADR 055 policy for
  a record that only describes a person, and the erasure test requires a policy for every new foreign key.

### 2. Additional lines as rows, only when needed (phase 2, separately approved)

`employee_reporting_lines`, holding:

- `employee_id`;
- `supervisor_employee_id`;
- `kind`: `functional` | `project` | `acting`;
- optional `project_id`;
- `starts_on` and `ends_on`.

This covers the matrix cases a yard really has:

- a welder reports to a section foreman (primary) **and** to the project's superintendent while on that
  project (project line);
- an acting supervisor covers leave (acting line, dated).

Recommended **not** to build this now: one primary line answers the Supervisor dashboard, and every extra
line widens what somebody can see (see the risks below). The phase-2 table extends phase 1 without changing
it.

### 3. The Supervisor dashboard reads the line; it grants nothing

For a user whose employee has direct reports, My Work gains a **My Team** section, and a Supervisor
overview shows, for the team:

- open and overdue tasks;
- permits requested by or led by team members, and their status;
- PPE completeness (ADR 060);
- overdue corrective actions;
- what is waiting on the supervisor.

Rules:

- **Read-only, and only inside modules the person's plan and role already open.** A reporting line never
  passes `RestrictDepartmentAccess`, entitlement or a module gate on its own (ADR 061: an offer is bound to
  what the server allows). A link to a record the supervisor cannot open is shown without a link, as My
  Work already does.
- **Direct reports by default.** A manager's team includes their supervisors' teams only if the owner
  wants it; recursion is depth-limited (for example 3 levels) and computed in one query.
- **Not an approval route by itself.** If a company wants "the requester's supervisor approves", that
  becomes a new approver type on a flow stage (`reports_to of requester`). It goes through the same
  validator and the same separation of duties (a supervisor who approved is never the Final Verifier).
  This is proposed as a later option, not part of phase 1.

## Risks and how the design contains them

| Risk | Containment |
|---|---|
| A line becomes a backdoor into another department's data | The line only selects **people**; every record shown is still read through its own tenant scope, module gate and entitlement. A test asserts that a supervisor in HSE with a report in Production sees that report's tasks only where Tasks already allows (D1), never Production's register. |
| Cross-tenant reference | The FK target is checked against the current tenant (`Employee::query()` passes the tenant scope); a foreign id is "not found". Covered by `TransitiveTenantIsolationTest`. |
| Cycles and self-reference | Refused on save and on import, with the chain named. |
| A stale line after somebody moves | The line is data the company maintains. Each person's record shows "Reports to …", and the dashboard flags reports who are inactive. |
| Matrix lines widen visibility silently | Deferred to phase 2, dated, typed and individually approved. |

## Impact on existing data

- **None at migration.** The column is nullable and the migration additive. No line is guessed from
  positions or departments: a wrong guessed hierarchy is worse than none.
- Companies populate it through the form, the import or the bulk action. Until they do, the Supervisor
  dashboard says so ("Belum ada anggota tim; atur Reports To pada data karyawan") rather than showing an
  empty team.

## Alternatives considered

| Option | Why not (as the primary design) |
|---|---|
| A hierarchy of **positions** (`positions.reports_to_position_id`) | Positions are shared by many people, so it cannot say which supervisor a given welder has. It is useful as an org chart of roles, and could complement phase 1. |
| A **department head** per department | Too coarse: one person per department, so no section supervisors. |
| **Approval authority only** (no schema) | Gives "what waits on me" (already built), never "my team". |
| A **user**-level line (`users.reports_to_user_id`) | Many employees have no login (field workers), and a team made of logins misses them. |

## Decision needed

1. Approve phase 1 (`employees.reports_to_employee_id` with form, import and bulk action, plus the
   read-only Supervisor dashboard)?
2. Direct reports only, or include indirect reports up to N levels?
3. Phase 2 (matrix and project lines) now, later, or never?
4. Should "requester's supervisor" become an approver type on flows (later)?
