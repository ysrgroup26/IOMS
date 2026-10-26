# ADR 077: A waste figure is a count of real rows, and storage duration is not storage capacity

- Status: accepted (v2.134.0)
- Related: ADR 074 (a standing is derived, never stored), ADR 061 (an offer is bound to what the
  server allows), MODULES.md § HSE Waste Management

## Context

The Waste Management dashboard was a set of static tiles and a recent-records list. The owner asked
for an interactive workspace for an Indonesian shipyard: clickable summary figures, the lifecycle
from generation to handover, a priority panel, a filterable register, quick actions, and TPS storage
inventory, "without inventing regulatory limits or marking compliance from assumptions" and without
new modules, duplicate records, fake counts or migrations that are not needed.

What existed already decided most of the shape: `WasteRecord::ALLOWED_TRANSITIONS` (generated →
stored | scheduled_pickup, stored → scheduled_pickup, scheduled_pickup → in_transit | stored,
in_transit → disposed, disposed → closed), a per-TYPE `storage_limit_days` that the company sets,
computed `is_approaching_storage_limit` / `is_storage_overdue`, a per-TPS `capacity` with its own
`capacity_unit`, and `WasteMovement` carrying vendor, manifest, pickup/disposal dates and documents.
Nothing needed a new column.

## Decision

1. **Every figure is a count of real rows and every figure is a filter.** The eight summary cards and
   the six lifecycle steps are counted from the same tenant-scoped records the list shows, and
   clicking one narrows the list to exactly those rows (`?view=` / `?status=`). There is no figure
   the list cannot reproduce.
2. **The lifecycle is shown as the model allows, not as one forced path.** A record may go straight
   from Generated to Awaiting Pickup without a TPS (`skipped_storage`); the stepper says how many did
   rather than pretending every record passed through storage.
3. **Storage duration and storage capacity are two different limits, kept apart.** Duration comes
   only from the company's own `storage_limit_days` per waste type; a type without one is "no limit
   set", never "within limit". Capacity is compared only when the record units match the TPS
   `capacity_unit`. Mixed or mismatched units are reported as such, never converted (litres of oil
   are not kilograms). The 80 % "filling up" marker is a display threshold, labelled as such, not
   a regulation.
4. **Missing data is a finding, not a pass.** No TPS while on site, a B3 type without a waste code,
   a B3 movement without a transporter or manifest, and a handed-over record without a handover
   document are listed as gaps by name. They make a record "incomplete"; they never make it
   "compliant".
5. **One new write, and it reuses the guard.** Assigning or moving a record's TPS
   (`POST /waste-records/{id}/storage`) is the only new action: `canManageHse()`, tenant-scoped,
   active same-company TPS only, only while the waste is on site, and Generated → Stored through
   `canTransitionTo()`. Moving between TPS keeps the original received date, so storage age is not
   reset by moving a drum. Pickup and handover keep their existing movement form, reached from the
   dashboard by a deep link (`?action=movement`).
6. **Offers follow authority.** Quick actions render as buttons only for accounts that can manage
   HSE; other roles see the read-only workspace and a plain statement of why.

## Consequences

- No migration and no new table; existing records display unchanged.
- The dashboard loads the tenant's waste records to count them. That is fine at shipyard volumes
  (hundreds to low thousands); if a tenant grows past that, the counts move to grouped queries
  without changing the props.
- Pinned by `tests/Feature/WasteWorkspaceTest.php`: counts and lifecycle, every filter, duration vs
  capacity and the mixed-unit case, gaps, the storage action's permissions, on-site rule and tenant
  isolation, and read access per role.
