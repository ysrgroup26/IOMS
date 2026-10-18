# ADR 060 — PPE completeness is computed from declared roles, and it has three states

- **Status:** Accepted
- **Date:** 2026-10-18
- **Version:** 2.121.0
- **Relates to:** ADR 059 (bulk PPE issuance), ADR 050 / ADR 058 (shared catalogues and overrides)

## Context

The owner approved a completeness rule for PPE:

> A worker is safe when they have **work clothing** (Work Uniform **or** Coverall — either satisfies
> the group, nobody needs both), plus **Safety Shoes**, plus **Safety Helmet**. Safety Glasses,
> Harness and Headlamp are recorded and never required.

Two things had to be settled before any of it could be built, and both were checked in the code
first.

### IOMS had no completeness status at all

It could say whether **one item** was active, expiring or expired
(`EmployeePpe::getEffectiveStatusAttribute()`), and it could count employees with **no** PPE record
(`noPpeAssignedCount`). Nothing read a person's records together. The Employee PPE list showed
"N Assigned PPE", which is a quantity, not a verdict: four pairs of glasses and no boots is four
assigned items. So this is a new concept, not a label to rename — and the owner's instruction was
explicit that it must be computed rather than relabelled.

### A PPE type had no stable identity

`ppe_types` carried `id, company_id, source_default_id, name, replacement_interval_months,
is_active`. A type was identified **only by its display name**, and ADR 050 deliberately lets a
customer rename one through an override. A rule matching the literal string `"Safety Shoes"` would
therefore stop recognising a tenant who renamed theirs `"Sepatu Safety"` and would report that
tenant's **entire workforce** as non-compliant. A safety status that silently depends on spelling is
worse than no status.

## Decision

### 1. A type declares which requirement it satisfies

`ppe_types.compliance_role` — `work_clothing`, `safety_shoes`, `safety_helmet`, or unset for
additional equipment. The shipped built-ins declare their own roles in `PpeTypeSeeder` (and a
migration back-fills existing installations); **"Work Uniform" is not shipped**, because a uniform is
a per-customer item and ADR 058 says what a built-in is for. A customer who issues uniforms adds the
type once in PPE Master and marks it there, which is also why the column is editable rather than
hardcoded. Several types may carry `work_clothing`; any one of them satisfies the group.

**An override inherits its template's role** when it has none of its own, so adapting Safety Shoes
to a six-month interval cannot quietly remove footwear from the rule. Declassifying is possible but
must be **said explicitly** (`additional`), because `null` already means "never classified" and one
value cannot carry both meanings — without the sentinel, choosing "Additional" in the UI would
silently re-inherit and the screen would disagree with itself.

### 2. Three states, not two

| State | Meaning |
|---|---|
| `compliant` | all three groups held, nothing expired |
| `needs_replacement` | all three groups held, at least one expired |
| `incomplete` | a group has no issuance record at all |

The owner chose this over the literal reading of "has an issuance record". Collapsing the middle
state into `compliant` would print "Aman" beside boots whose service life ended two years ago.
Collapsing it into `incomplete` would hide the difference between somebody who was **never issued**
equipment and somebody whose equipment is simply **due** — two different problems, two different
actions, and HSE has to tell them apart in a list.

Further rules the implementation needed:

- A group is satisfied by its **best** record, not its latest: one expired pair and one current pair
  is an equipped worker, so a valid record must not be shadowed by an expired one issued later.
- Only `issued` / `in_use` count. An archived or replaced row is history, and **history does not keep
  somebody equipped**.
- Additional equipment never blocks compliance, expired or not.
- A company whose catalogue has **no active type** for a required group can never have a compliant
  employee. That is a configuration problem, so both PPE Master and the Employee PPE list say so
  rather than painting every person red.

### 3. The file is wide: one row per person, one dated column per kind of PPE

v2.120.0 read one row per issuance. That matches the database and is wrong for the people filling it
in: 300 workers with four items each is a 1,200-row file nobody can read across, and the question an
HSE officer asks is "what does this person have", which is a **row**.

- **An empty cell is not a fact.** It means no record, and it produces no transaction. Nothing
  invents a date to make a row look complete, and a blank row is reported as an absence rather than
  an error.
- The PPE columns are **not hardcoded**: each heading is resolved against the company's own effective
  catalogue, so a renamed override or a customer-added "Work Uniform" is already understood by both
  the template and the reader. A heading matching nothing is **reported by name** — silently ignoring
  a column is how a file imports "successfully" with half the equipment unrecorded.
- `expiry_date` is no longer a column at all: the type's replacement interval is the single source of
  truth and the model derives it.
- The template is generated per company, ordered **by requirement group** rather than alphabetically,
  and pre-filled with the active employees so only dates need typing.

Everything ADR 059 established is kept: no employee or type is ever created, the duplicate guard
still resolves a type **family**, tenant isolation is unchanged, and re-running a file issues nothing.

## Consequences

- One vocabulary across three surfaces: a badge per row on the Employee PPE list, a filter beside it,
  and a group-by-group breakdown on the employee's profile that names *which* requirement is missing.
- The filter resolves its ids **before** pagination. Compliance is computed in PHP (a role may be
  inherited; expiry is read against today), so filtering after `paginate()` would filter one page and
  report a total that is simply wrong.
- Two implementations of the role rule exist — `PpeType::effectiveComplianceRole()` (per model) and
  `PpeComplianceService::resolveRole()` (over a loaded set, to avoid an N+1 on a 24-row page).
  `PpeComplianceRuleTest` pins them against each other so they cannot drift.
- **A pre-existing defect surfaced while building this** and is fixed here: `orderedForDisplay()` ends
  with `->select('employees.*')`, which replaced the select list including the subquery `withCount()`
  had added before it — so the Employee PPE list has been rendering " Assigned PPE" with **no number**
  since the page was built. Found by driving the page in a browser, not by reading it. See
  `docs/CONVENTIONS.md`.
- Importing a historical register reads as `needs_replacement`, not `compliant`. That is the point of
  the middle state, and it is pinned by a test.
