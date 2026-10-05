---
title: Employee Import and Admin Space Naming
type: backlog
status: COMPLETED (v2.101.0) — master data normalised, Admin Space names aligned
updated: 2026-10-05
tags: [kb/backlog, status/verified]
---

# Employee Import and Admin Space Naming

**Source:** owner brief — decide whether Admin Space "Module Management" and Settings "Module
Visibility" are duplicates, and make Department/Position auto-create normalise so that HSE, hse, Hse
and " HSE" do not become separate records · **Board:** [[Project Board]]

## Are the two module surfaces duplicates? Yes, literally

The Admin Space row pointed at `settings.index?tab=modules` — **the same page** as the tab labelled
"Module Visibility". One destination, two names.

It is not a redundant feature to delete: Admin Space deep-links **six** Settings tabs as first-class
destinations (same route, same controller, same authorization), which is the deliberate IA from
[[Admin Context and Billing Flow]]. The duplication was in the **naming**, and two of the six had
drifted:

| Sidebar said | Tab said |
|---|---|
| Module Management | Module Visibility |
| Users & Access | Users |

"Management" also over-promised. Which modules a tenant **has** is decided by the plan;
`updateModules()` validates against `Rule::in($grantedKeys)` and its own doc comment calls the
boundary "visibility/labeling only".

Resolved toward the truthful name in each direction: the sidebar took **Module Visibility**, and the
tab took **Users & Access** because it holds User Management *and* Field & PTW Access.
`AdminSpaceNavigationTest` now pins every deep-linking row against the tab it opens.

## Employee Import had no tests at all, and three things were wrong

Three pieces of code had to agree on whether `" HSE "` is the existing **HSE** department. None did.

| | Compared with | Result for `" HSE "` |
|---|---|---|
| `MasterDataDetector::classify()` | lowercase, **no trim** | NEW |
| `EmployeesImport` lookup | `where('name', $cell)` — the **database** decides | depends on the connection |
| `createMissingMasterDataAndImport()` | `firstOrCreate(['name' => $raw])` | a department **named with a leading space** |

So a file spelling one department three ways produced three departments.

**Matching depended on the database.** `where('name', ...)` is case-insensitive under MySQL's
default collation and case-sensitive under the SQLite the suite runs on. Production quietly forgave
a case difference, the tests could not have caught it either way, and identity belonged to the
connection rather than to the code.

**The create-missing flow could not find what it had just created.** The controller creates a
Position with a company and a name; the import resolved positions with `where('department_id', ...)`,
and a freshly created position has none. The one flow whose entire purpose is to let blocked rows
land was landing them **without a position**. `MasterDataDetector`'s own doc comment says positions
resolve "by name within the company" — which the import used to do and no longer did, so the comment
had become wrong about the code beside it.

**Fixed** with one definition of "the same name": `normalise()` for what is stored and shown
(trimmed, inner whitespace collapsed) and `comparisonKey()` for what is compared (also case-folded),
used by all three stages. Resolution now reads a map built once per import rather than a query per
row, and a position is found by name within the company, preferring the row's department.

## Verified

- **9 import tests driving real .xlsx files through the real endpoints** (not stubs), **5 of which
  were confirmed to fail before the fix**. The official template's headings are now pinned against
  what the import actually reads, since the two are maintained in separate files.
- **3 navigation tests**, confirmed to fail and name the exact mismatch when the old label is put
  back.
- **Full suite 896 passed / 4711 assertions**, up from 886, no collateral damage.
- Browser: the sidebar row and the tab both read "Module Visibility", the strip shows "Users &
  Access", and the tab still selects correctly.

## Not done here, and why

- **Employee PDF export does not exist.** The brief asks for one; there is an Excel export and an
  import template, but no `resources/views/pdf/employee*.blade.php`. This is a new feature rather
  than a fix, and is the next item rather than something silently skipped.
- **Smart Import header mapping** (aliases, fuzzy headers, sample-value matching) is only partly
  covered: `FieldMappingService` lets a tenant configure which column feeds which field, and
  `MasterDataDetector` handles typos in master-data *values*. Matching an unfamiliar **header** by
  alias or by looking at sample values is not built.
- Positions created by the create-missing flow are company-wide with no department, because the
  dialog collects names rather than name-and-department pairs. Attaching them is a Settings action.

## Links

- [[Settings Configuration Reachability]] · [[Identity and Copy Rules]] · [[Current State]]
- `docs/ARCHITECTURE.md` § *Master data from a spreadsheet*
- `docs/CONVENTIONS.md` § *three places decided whether two names are "the same"*
