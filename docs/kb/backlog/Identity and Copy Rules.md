---
title: Identity and Copy Rules
type: backlog
status: COMPLETED (v2.100.0) — identity separated, actors snapshotted, copy rules pinned
updated: 2026-10-05
tags: [kb/backlog, status/verified]
---

# Identity and Copy Rules

**Source:** owner brief, "Organization Identity vs User Identity separation", admin-settable user
display name, actor identity across documents and audit logs, historical identity auditability, and
the copy rules forbidding the em dash and stored identifiers in user-facing text ·
**Board:** [[Project Board]] · **Release:** [[Release Index]]

## What was actually wrong

One column was answering two identity questions, and a third question was not being stored at all.

| | Before | After |
|---|---|---|
| What somebody is called | `users.name` only, so renaming to suit colleagues destroyed the registered identity | `display_name` (optional) beside `name`, resolved by `User::displayName()` |
| Who did the thing | Resolved by following a foreign key to the account's **current** name | Captured when the action happens |
| Organization vs user identity | Already correct, and confirmed | Pinned by a test |

**Organization identity was not conflated.** Worth recording, because it was the first thing
checked: `TenantProvisioningService` sets the Tenant and Company from the registration's company
display name and the first user from `contact_name`, which is right. The company name reaches the
UI as `company.name`, a person's as `auth.user.name`. A test now holds the two apart.

## The one that mattered: an audit trail that rewrote itself

`activity_logs` stored only `user_id`; `approvals` only `approved_by` / `requested_by`. Every actor
on screen — timeline entry, audit row, approval stamp, the signature block on a **printed permit** —
was resolved live through that key.

So renaming one person silently rewrote history: every approval they had ever granted, on every
permit already filed, retroactively carried the new name. Removing them emptied the stamp. On a
product whose permits are the compliance record, that is not an audit trail.

Captured now, never recomputed: `activity_logs.actor_name`, `approvals.requester_name`,
`approvals.approver_name`. The foreign keys stay and stay authoritative for *which account*; the
snapshots answer *what did this say at the time*.

**Nothing was backfilled, deliberately.** A null means "not captured when it happened", which is
true, and every read falls back to the live relation, so historical rows render exactly as before.
Writing today's names into yesterday's rows would manufacture a historical fact.

## Copy rules

- **Em dash removed from user-facing text:** 140 placeholder occurrences replaced mechanically with
  a hyphen, then **38 prose occurrences rewritten one at a time** as a comma, colon or full stop
  depending on the sentence, plus 5 blade views and 6 PHP strings. The 21 in code comments were left
  alone, deliberately: comments are not user-facing and this codebase uses em dashes in them heavily.
- **Stored identifiers removed from user-facing text:** real rows were printing `in_progress` and
  `under_maintenance` at people through the activity feeds and the asset history. `humanizeToken()`
  is now the single shared definition, and `StatusBadge` uses it instead of its own copy.
- `ActivityLog` entries for user changes now name the role as "HSE", not `super_admin`.

## Verified

- **Migration applied, rolled back, and re-applied** against MySQL.
- **Browser:** a display name set on an account showed as `auth.user.name` and in the sidebar, while
  the users table showed the display name with the registered name beneath it. The edit dialog
  renders Name then Display Name with the Indonesian help text and English label. Settings copy
  re-read with no em dash on the page.
- **`IdentitySeparationTest` (17)** and **`CopyRulesTest` (3)**. The em dash test was confirmed to
  fail, and to name the offending file, when a violation was reintroduced.
- **Full suite 886 passed / 4680 assertions**, up from 866, no collateral damage. Build clean,
  ESLint 0 errors (4 pre-existing unused-directive warnings).

## Not done here

- **Purchase Order** keeps its own `requester` / `approver` / `issuer` columns and is not on the
  Approval Engine, so its signature block still resolves live. Same for the PTW *requester* line;
  the PTW **authorisation stamp**, which is the part that acts as a signature, does use the snapshot.
  Worth closing if PO signatures are ever treated as compliance artefacts.
- The activity feeds eager-load `user:id,name`, so the **fallback** for a pre-v2.100.0 row shows the
  registered name rather than the display name. Honest, and only affects rows written before the
  snapshot existed.

## Links

- [[Settings Configuration Reachability]] · [[Current State]] · [[Requirements Register]]
- `docs/ARCHITECTURE.md` § *Identity: what somebody is called, and who did the thing*
- `docs/CONVENTIONS.md` § *an actor resolved through a foreign key is not an audit trail*,
  § *one column was answering two identity questions*, § *The copy rules*
