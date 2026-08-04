# Conventions & Known Pitfalls

House style, and a deliberately honest list of mistakes that have actually happened in this
codebase's history — kept here so they don't get repeated in a slightly different shape.

## Migrations

- **Additive, never destructive, for anything that might already hold real data.** When a column's
  shape needs to change (e.g. widening an enum, making a foreign key nullable), write a migration
  that adds/alters — never one that drops and recreates a table as routine logic. See "Known
  Pitfall #1" below for exactly why this matters, not just as a style preference.
- **Backfill existing rows explicitly, don't leave them null-and-hope.** When adding a required
  column to a table that already has rows (e.g. `company_id` on `departments`, then `positions`),
  the migration backfills every existing row to a sensible value *before* enforcing `NOT NULL` —
  usually the most accurate value derivable from existing relationships (a position's own
  department's company, for example), falling back to a documented default company only when
  nothing more specific is derivable.
- **Widening a real MySQL `ENUM`** is done via raw `DB::statement("ALTER TABLE ... MODIFY COLUMN
  ... ENUM(...)")`, not a schema-builder enum helper — Laravel's schema builder has no first-class
  "add one more allowed value" operation. Existing rows with already-valid values are unaffected;
  this only widens what's *allowed* going forward.
- **Verify the actual table name before writing a foreign key.** `->constrained()` without an
  explicit argument guesses the referenced table by stripping `_id` and naively pluralizing the
  column name. Most of the time that's correct (`company_id` → `companies`). It is **not** correct
  for `employee_ppe` (deliberately singular — see `MODULES.md`'s PPE section) or any other
  intentionally non-standard table name. When in doubt, check the model's `protected $table`
  property or the table's original creation migration directly — don't assume standard
  pluralization holds.
- **Double-check new routes land in the right middleware group**, especially when adding routes
  near an existing `role:super_admin`-restricted block. This has happened for real: an entire new
  module's routes were once accidentally nested inside a Super-Admin-only group, silently locking
  out the actual intended users. After adding routes, check indentation/nesting against the
  surrounding `Route::middleware(...)->group(...)` structure, not just that the route exists.

## Status / lifecycle enums

- Prefer a single `status` column with an explicit, small enum over multiple boolean flags, for
  anything with more than two meaningful states.
- **Don't create a stored status value that duplicates a status that already exists elsewhere in a
  related record.** The concrete example: Material Request does *not* have a `pending_approval`
  database value, even though the feature spec that requested the workflow described one — it's
  the *label* applied to `submitted` while the associated `Approval` record's own `status` is
  `pending`. Two tables agreeing to always be in lockstep is duplication, not a real distinction.
  Before adding a new status value, check whether a related model already represents the same fact.
- Reuse the shared `StatusBadge` component's canonical color mapping
  (`resources/js/Components/shared/StatusBadge.jsx`) rather than building a new status-to-color map
  per module.
- When a model's own status needs guarded transitions (not just any value settable at any time),
  use the `HasWorkflow` trait rather than hand-rolling `if ($old === 'x' && $new === 'y')` checks
  inline in a controller — see `ARCHITECTURE.md`.

## Roles & permissions

- `users.role` is a plain `VARCHAR`, not a real database `ENUM` — adding a new role (e.g.
  `warehouse`) needs zero migration. Do add it to: the `User` model's `ROLE_*` constants and
  `isX()`/`roleLabel()` methods, the relevant Form Request validation `in:` list, and the Settings
  role dropdown — all four, not just one, or the new role will be creatable in the database but
  invisible or unselectable somewhere in the UI.
- For workflow-style actions (approve/process/override), read from `config/workflow.php`'s role
  lists rather than hardcoding a role check inline — see `ARCHITECTURE.md`'s Authorization section
  for why this file exists and what it's preparing for.
- No RBAC package exists (see `ARCHITECTURE.md`). Don't introduce one without first re-reading
  `ADR/006-material-request-workflow.md`'s reasoning for why it was deliberately deferred, and
  confirming the actual need has changed since.

## Navigation

- **Adding a nav item** (v1.7.0+, workspace-based nav — see `ARCHITECTURE.md`'s Navigation
  Architecture section and `ADR/007-workspace-navigation.md`): add it to the right workspace's
  `items` array in `resources/js/lib/workspaces.js`. If it's a new toggleable module, also add its
  key/label to `config/modules.php`'s `available` registry — that's the whole change; the
  workspace switcher, the sidebar filtering, and the Settings → Modules toggle UI are all generic
  and pick it up automatically. Don't add a new item to `AuthenticatedLayout.jsx` directly — that
  file no longer holds the nav item list, only the rendering.
- **Adding a new workspace**: only do this for a domain that has at least one real, built module —
  don't add an empty workspace as a placeholder (a workspace with zero visible items is hidden by
  `getVisibleWorkspaces()` automatically, so an empty one would just be dead code, not a preview of
  something coming). Check `workspaces.js`'s "FUTURE WORKSPACES" comment block first — the intended
  workspace for most planned domains is already decided there.
- **Don't URL-namespace by workspace.** Existing route names/URLs are the single source of truth
  for what a route is; workspace is purely a frontend grouping over them (see `ADR/007`). A route's
  workspace is derived by matching its name's prefix (the part before the first `.`) against each
  workspace item's own prefix — so if a module ever gets multiple route-name prefixes (unusual, but
  possible for a fast-growing module), each prefix needs its own entry pointing at the same
  workspace, or pages on that second prefix will silently fail to auto-select the workspace.

## Caching

- **Be genuinely cautious with `Cache::rememberForever()` for anything whose default value is
  derived from mutable `config()`.** `CompanySetting::get()` originally cached
  `enabled_modules`'s computed default this way; the very first time it ran (before a since-added
  module existed in config) it permanently cached the old list, with nothing to invalidate it when
  the config file itself changed later. Fixing that introduced a *second* bug (the cache key
  changed but the corresponding `set()`'s cache-`forget()` call wasn't updated to match, so saving
  a setting stopped actually invalidating anything). The eventual fix: for a small, rarely-changed,
  cheap-to-query setting, just read the database directly rather than cache it at all — not every
  read needs `rememberForever`, and the settings most likely to need one (something read on every
  single request, like enabled modules) are exactly the ones where getting the invalidation subtly
  wrong is most damaging.
- If you do cache something, make sure whatever busts the cache is actually reachable from every
  code path that changes the underlying value — not just the one you're thinking about right now.

## Company scoping

- The convention, everywhere in this app, is a per-request query parameter (`?company_id=`) — not
  a persistent session/"active company" concept, because none exists. Match this convention for
  any new filterable list rather than inventing a different mechanism.
- A model that's genuinely company-specific master data (departments, positions, and similar)
  should have a required, direct `company_id` — even if it's also reachable transitively through
  another relationship (a position's department, for instance) — because a direct column avoids a
  join for the extremely common "show me X for this company" query, and because it lets the record
  exist correctly even before the more specific relationship is chosen.

## Verification discipline (and its real limits)

- This project's development has, in practice, never included actually running `php artisan
  migrate`, `npm run build`, or the application in a browser — verification has been static:
  balance/brace checking on every edited file, cross-referencing every `route()` call against
  `routes/web.php`, and matching every Inertia page's destructured props against exactly what its
  controller passes. This has caught real, would-have-shipped bugs, but it is fundamentally not the
  same as a runtime test, and treating "carefully verified statically" as "confirmed working" would
  be overclaiming. When reporting on work like this, say so plainly rather than implying more
  certainty than the verification method actually supports.
- Two genuinely severe bugs were caught this way that a build-and-move-on approach might have
  shipped: the Super-Admin-only route-group mistake, and the settings cache staleness. Both are
  detailed above specifically so the pattern is recognizable next time, not just the individual
  fixes.

## "Verify first" as a default posture

Before building something that sounds like a new feature, check whether the underlying mechanism
already exists and only the surface is missing. The concrete example: `ActivityLog` (a polymorphic
activity-recording model) already existed and was already used 32+ times across controllers before
anyone built a way to *view* that data — the real gap was a viewing component, not the recording
mechanism, and building a second, parallel logging system would have been pure duplication. This
kind of check is cheap (a few minutes of reading) relative to the cost of maintaining a duplicate
system afterward.
