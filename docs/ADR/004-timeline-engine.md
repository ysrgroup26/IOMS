# 004 — Activity Timeline

## Status

Accepted. Recording mechanism already existed before this version; the viewing mechanism is new.

## Problem

Every important action (create, update, submit, approve, reject) should be recorded per-record,
viewable by anyone looking at that record later, and reusable across every module rather than
each one building its own history log.

## Decision

Before implementing anything, verified the existing codebase rather than assuming a gap: an
`ActivityLog` model already existed, already polymorphic (`subject_type`/`subject_id`), already
used in 32+ places across existing controllers via a `record()` convenience static. The recording
half of this requirement was already done and did not need to be rebuilt.

What genuinely didn't exist anywhere in the application was a way to actually *view* this data --
no controller exposed it, no page rendered it. That's the actual gap this ADR addresses: a
reusable `ActivityTimeline` React component that any page already eager-loading its own list of
`ActivityLog` rows for a given subject can render, the same way `MaterialRequests/Show.jsx` now
does (`ActivityLog::where('subject_type', ...)->where('subject_id', ...)` in the page's own
controller method, passed as a prop).

## Alternatives Considered

**A dedicated `GET /activity-log?subject_type=X&subject_id=Y` endpoint the component fetches from
itself** -- considered, not built this version. Keeping the query in each page's own controller
(where the subject is already loaded and known) avoids a second network round-trip and keeps the
component itself simpler (a plain list renderer, not a data-fetching component). A shared endpoint
becomes worth it if a *global* activity feed (not scoped to one record) is ever needed -- that's a
different, larger feature (closer to "Smart Dashboard"'s "Recent Activities" widget) than what this
version asked for.

**A separate table per module for history** -- rejected outright; this is exactly the duplication
`ActivityLog` already existing was meant to avoid, and rebuilding it would have contradicted this
session's own "verify first, don't rebuild what exists" instruction.

## Consequences

- Any future module wanting a visible timeline needs only: eager-load its own `ActivityLog` rows in
  its Show controller method, pass them as a prop, render `<ActivityTimeline activities={...} />`.
  No new backend code.
- `ActivityLog::record()` calls remain the caller's responsibility to add at each meaningful action
  point (as `MaterialRequestController` now does for create/submit) -- there's no automatic
  model-event-based recording, so a developer adding a new mutating action to an existing
  approvable model must remember to log it explicitly, the same as the 32 existing call sites
  already do today.
