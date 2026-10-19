# ADR 065 — A stamp is an action record, and its title belongs to the company

**Status:** Accepted · **Date:** 2026-10-19 · **Release:** v2.125.0

Extends [ADR 057](057-a-release-must-be-able-to-prove-what-is-running.md)'s habit of refusing to
display what cannot be proven, and keeps the contract v2.100.0 established for the approver's name.

---

## What was asked

A stamp design reference, and a rule about it: *"bukan gambar final yang harus dipasang utuh ke
aplikasi"* — the reference is a specification of style and composition, not an image to paste in.
Plus: ACTIVE green and CLOSED red, slanted and distressed, **no date inside either**; the applicant
as a profile card and not a stamp; a verification mark in green and an approval mark in blue; and
job titles that are **not** hardcoded to "HSE Officer" or "Manager" but read from the company's own
configuration and representing who actually acted.

## Decision 1 — two families, because they answer two different questions

| Family | Answers | Composition | Carries |
|---|---|---|---|
| **State** (`active`, `closed`) | "what is this document's standing right now" | slanted, double-ruled, distressed | the word only |
| **Action** (`verified`, `approved`) | "who did what, and when" | upright, rectangular, iconed | act, person, job title, time |

A state stamp **carries no date**, and the brief is right about why: a date inside a standing stamp
reads as the date the standing was granted, which is a different fact from the validity window
printed in the document body. Two facts under one mark is how a controlled document comes to
contradict itself.

**Only two statuses get a stamp.** Every other status keeps its `StatusBadge`. A stamp that can
appear in six colours is a status badge wearing a costume, and the failure worth designing out is
somebody glancing at an amber seal on site and reading it as authorisation.

## Decision 2 — the reference is rebuilt, not embedded

CSS and type, in a component. An image could not change when the permit closes, could not name who
signed, could not be read by a screen reader, and could not be printed at another size without going
soft.

The distressed ink is two layered repeating gradients in `currentColor`, so one rule serves both
colours and both themes. It is `aria-hidden` and carries no information: the stamp is fully legible
without it.

**The slant and the distress are deliberately NOT attempted in the PDF.** Dompdf's CSS is print-era:
no reliable `transform: rotate()` on a table cell, no blend modes. Both would land as a
mis-positioned box or as nothing. A stamp that renders wrong on the sheet an auditor is holding is
worse than one that renders plainly, so paper gets the composition — double rule, weight, tracking,
colour — without the two effects it cannot honour.

**Mobile gets a pill, not a shrunken stamp.** Chosen in CSS (`hidden sm:inline-block`) rather than
with `useMediaQuery`, because a document view is also *printed*: a JS media query resolves against
the viewport, so a stamp chosen at phone width would have followed the document into print at phone
width.

## Decision 3 — the job title is the company's, and it is a fact about the past

`authorizationFor()` returned `$approver->roleLabel()`: the IOMS **system role** — "Super Admin",
"HSE". On a permit taped to a bulkhead that is the wrong fact. The authority a reader recognises is
the yard's own title, and which title signs which permit is the customer's configuration, held in
`positions` and reached from the account through `users.employee_id`.

Read in this order, every step a real record:

1. **`activity_logs.actor_title`** — the position that account held *at the moment it signed*.
2. the approver's **current** position, for a permit signed before that column existed.
3. the system role, last, because it is at least true about the account.

Null if none is available, and the stamp then renders **without a title line** rather than with a
fabricated one.

Step 1 is a new nullable column, captured in `ActivityLog::record()` and **not backfilled**. It
exists for the same reason `actor_name` does (v2.100.0): the stamp used to resolve the approver's
*current* name, so renaming one person retroactively rewrote every permit they had ever signed and
removing them blanked the stamp. A title is subject to the identical defect — a company reorganising
must not alter what a permit filed last year says about who signed it.

## What was NOT done, and why it matters more than what was

**No approval stage, status or authority was invented to make the design fit.**

The reference shows `HSE VERIFIED` *and* `MANAGER APPROVED`. IOMS has **one** PTW gate:
`hse_approver_id`, written only by a `canManageHse()` user. So:

- the component carries both variants, and PTW renders only what a real action record supports;
- the `verified` variant renders nothing today, because there is no verification record to render,
  and that is the honest outcome;
- the word on the seal is the act the workflow actually performed (`APPROVED`), while the **title**
  under it is whoever did it — so a company whose approver is an SPV HSE sees "APPROVED / SPV HSE"
  rather than a forced "MANAGER".

Changing a visual label cannot change who may approve anything. `kindLabel` is a label; the gate is
the gate.

**And the seal still cannot be rendered without a server-side record.** There is no `approved`
boolean anywhere in this chain. `ApprovalStamp` takes the authorization record or returns null, and
`DocumentActionStamp` repeats the refusal on its own `by` prop — two layers, neither of which a page
can talk into drawing an approval that never happened.

## Consequences

- `activity_logs` carries one more nullable string per row.
- `hseApprover` must be eager-loaded with `employee_id` and its position, or the title silently
  falls all the way back to the system role. That constraint caused the v2.72.0 defect once already
  (a missing column made `roleLabel()` return `''` rather than fail), so the load is commented at
  the call site.
- The approval seal changed colour from green to blue. Green is now reserved for a verification
  record, per the design's own separation of the two acts.
- A permit approved before this release shows its approver's *current* position, and says nothing
  if they hold none. Nothing is invented for it.

## Evidence

Browser, MySQL 8. A permit was raised by a My Work account, approved by an HSE account whose linked
employee holds the position "SPV HSE", and activated. The detail page and the document view both
render `APPROVED` with `SPV HSE`; the document view renders the `ACTIVE` standing stamp; the PDF is
8,053 bytes of real document; `activity_logs` holds `actor_title = 'SPV HSE'` on the approving row
and `'Welder'` on the row the requester wrote. Renaming the position afterwards leaves the permit's
stamp unchanged, which is pinned by
`PtwFieldRevisionTest::test_renaming_a_position_later_does_not_rewrite_a_signed_permit()`.
