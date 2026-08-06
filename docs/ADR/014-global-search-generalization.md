# 014 — Global Search Generalization (Milestone 3, Task #52)

## Status

Accepted.

## Problem

`GlobalSearchController` only searched Employees and Projects, with a doc comment explicitly noting
Incidents/Inspections/Permits/Assets weren't searched "since those modules don't exist yet" — stale by
Milestone 3, since Incident, LeaveRequest, MaterialRequest, Milestone, and GoodsReceipt are all now real.

## Decision

**Extended to every module with a real detail/show page**: Incidents, Material Requests, Leave
Requests, Milestones, Goods Receipts, Companies — 8 categories total. Still deliberately does NOT
search Asset/Document/PTW/Inspection, since those genuinely don't exist yet (same principle as before,
just re-applied to the current, larger set of real modules).

**Ctrl+K / Cmd+K focuses the existing inline search input**, rather than a new full-screen command
palette modal. The existing dropdown-style search already renders results well and matches this app's
visual language; building a second, competing search UI would be exactly the kind of "over-engineer /
build a new thing instead of reusing what works" the frozen architecture explicitly warns against.

**Frontend generalized to a category-list loop** (`CATEGORIES` array of `{key, label, icon}`) instead
of one hardcoded JSX block per category — adding a 9th searchable module later is one array entry, not
a copy-pasted block.

## A real bug caught during this same build (not shipped)

The first version assumed `material_requests` had a `title` column (it does not — only
`request_number`/`notes`; `Incident` and `Milestone` do have `title`, which is presumably why the
assumption felt natural). This produced a genuine `SQLSTATE[42S22]: Column not found` 500 error,
caught immediately via a browser network-request check during this same verification pass (not
discovered by a user later). Fixed by searching `request_number`/`notes` for that one model instead.
Documented here as a reminder: even reference code within the *same session* needs its schema
double-checked before being reused as a template for a sibling model — column shapes are not
guaranteed to match just because two models look structurally similar.

## Verified

Browser walkthrough: typed `MR-2026` into the search box, confirmed the underlying `/search?q=`
request returned `200 OK` (after the fix; `500` before) with the correct `material_requests` category
populated and correct URL; confirmed Ctrl+K focuses the input from anywhere on the page.
