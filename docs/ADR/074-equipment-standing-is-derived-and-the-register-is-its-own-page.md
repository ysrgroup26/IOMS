> [!note] v2.135.0 (ADR 078): new IDs are `{prefix}-01` (two-digit minimum), a number is taken once
> however it is padded, and `ioms:equipment-codes` shortens existing `{prefix}-00001` codes after an
> audit. Read "`{prefix}-00001`" below as the format at the time of this ADR.

# ADR 074: An equipment unit's standing is derived, and the register is its own page

- Status: accepted (v2.133.0)
- Related: v2.53.0 (`separate_equipment_master_from_register`), ADR 058 (shared catalogues),
  ADR 061 (an offer is bound to what the server allows), ADR 064 (a notification is a destination)

## Context

Safety Equipment & Compliance was one route (`hse.master`, `Hse/Master.jsx`) with five client-side
tabs. The Equipment Register tab listed every unit with **all** of its inspections eager-loaded,
had no search, no filter, no page for one unit, and showed only a free-text type and one date.

v2.53.0 had already done the hard modelling: `hse_equipment_types` declares which lifecycles a type
tracks (`tracks_inspection`, `tracks_calibration`, `tracks_service`, `tracks_expiry`), an inspection
interval and an ID prefix, and `safety_equipment` carries `equipment_type_id`, `equipment_code`,
brand, model and one date per lifecycle. **No form could write any of it.** The controllers did not
validate those fields, so every type tracked inspection only, every unit's code stayed null, and the
lifecycle dates were reachable only from a seeder.

The owner asked for a dashboard that separates equipment records, equipment types, inspection
templates, inspection history and supplies, with real statistics and no invented compliance states.

## Decision

1. **A unit's standing is derived, never stored.** `SafetyEquipment::complianceState()` returns
   exactly one of, worst first: `out_of_service`, `action_required` (the latest inspection result is
   Fail or Needs Action), `expired`, `overdue`, `due_soon` (30 days), `not_scheduled`, `compliant`.
   It reads only the dates the unit's **type** tracks (`lifecycles()`); a unit with no type is judged
   on inspection plus whatever dates it actually carries. A unit nobody scheduled is
   **Not Scheduled**, never counted as up to date. The overview figures are counts of these
   standings over the register, computed in one place (`SafetyEquipmentController::stats()`).
2. **The register is its own route.** `GET /hse/equipment` (`safety-equipment.index`) and
   `GET /hse/equipment/{id}` (`safety-equipment.show`), viewable by every role like the rest of HSE;
   writes stay in the `role:super_admin,hse` group and are re-checked with `canManageHse()`.
   `hse.master?tab=register` redirects to the register so old links still land.
3. **Reference data stays on Hse/Master.** Types, inspection templates, hazard categories, and
   supplies (consumables and first-aid stock, which are counted and restocked, not inspected per
   unit) remain there. One shared `AreaNav` strip, grouped Equipment / Supplies / Reference Data,
   joins both routes so the distinction is visible before anything is clicked.
4. **Equipment IDs are generated per company and prefix**: `{type prefix or EQ}-{00001}`, the next
   number after the highest existing one, read under a row lock in the saving transaction. A code
   typed by hand is respected and must be unique within its company. An existing code is never
   renumbered; a legacy unit with none gets one the next time it is saved.
5. **Inspection history is append-only and cannot rewind a unit.** A back-dated inspection is filed
   but does not move the unit's current last/next dates. A Fail or Needs Action requires findings.
   A blank next date is proposed from the type's configured interval, a rule the company set.
6. **QR labels are functional or absent.** The code carries only the unit's own URL. Scanning it opens
   the record through ordinary sign-in and tenant checks, so a label photographed on a wall reveals
   nothing and always shows today's standing. Generated client-side with `qrcode` 1.5.4.

## Not done, deliberately

- **No `TPL-APAR-001` template IDs.** `hse_checklist_templates` has no code column; adding one is a
  migration and a numbering decision the owner has not made. The register's `EQ-00001` form fits the
  existing `equipment_code` column, so it was done; templates were not.
- **No new status values.** `safety_equipment.status` stays `active` / `out_of_service`; everything
  else is derived.
- **Deletion is unchanged**: removing a unit still cascades its inspection rows (existing FK). The
  confirmation now says so.

## Consequences

- The register loads all of a tenant's units to derive standings and filters in PHP. That is fine for
  hundreds to a few thousand units; past that, the standing should become a generated column or a
  nightly snapshot, not a query rewrite.
- Every new consumer of equipment status should call `complianceState()` rather than compare
  `next_inspection_due` itself. The HSE dashboard's existing "Equipment Overdue" tile still uses its
  own narrower query (inspection date only) and links to the register filtered to *Needs Attention*.
