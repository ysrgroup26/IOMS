# 018 — Approval Flow / Numbering Format Tenant Scoping, and Company Settings Completion (Milestone 3, Task #62)

## Status

Accepted.

## Problem

Building the Numbering and Approval Flow configuration UIs surfaced the same class of bug twice in a
row: both `numbering_formats` and `approval_flows` had a nullable `company_id` for "tenant-wide
default" but **no `tenant_id` column at all**. A tenant-wide default row (`company_id` null) was
therefore shared across the ENTIRE PLATFORM, not just one tenant -- the first tenant to trigger a
module's default format/flow creation would have their customization silently apply to every other
tenant too. For Approval Flow this is more than a config nuisance: the wrong people could end up
approving another company's requests.

## Decision

**Both tables got a `tenant_id` column**, mirroring the identical fix pattern:
- `NumberGeneratorService::resolveFormat()` now resolves in order: company-specific override → this
  tenant's own default (`tenant_id` set, `company_id` null) → a genuine platform-wide fallback (both
  null, created from `DEFAULTS` on first use).
- `ApprovalFlowResolver::resolve()` now filters every candidate flow to `tenant_id` = the current
  tenant (or the platform-wide null fallback) before even considering `company_id`/`conditions`.

**Settings UI always writes a tenant-scoped row explicitly**, never the platform-null fallback --
`SettingsController::updateNumberingFormats()`/`storeApprovalFlow()` set `tenant_id` from the
authenticated Company Admin's own tenant, so editing a format/flow can never leak into another
tenant's configuration.

**Verified end-to-end, not just unit-level**: created a real 2-step Approval Flow for
`material_request` via the Settings UI, then created a brand-new `MaterialRequest` and confirmed via
`tinker` that it was automatically routed through the new flow (`approval_flow_id` set, step 1) --
proving the full chain from Settings UI → DB → `ApprovalFlowResolver` → `ApprovalEngine` actually
works, not just that the form submits.

## Company Settings completion (same task)

Built out the remaining Settings sections UAT asked for, all genuinely functional (no placeholders):
- **Numbering** -- edit prefix/pattern/padding/reset-period per module.
- **Approval Flow** -- create/delete flows per module, edit their steps (mode/approver role/
  escalation) inline.
- **Notifications** -- per-category on/off toggle. Verified via `tinker`: disabling "success" and then
  running a real approval-to-completion cycle produced zero `success`-category rows, while `approval`
  notifications (a different category) still fired normally.
- **Branding** -- was silently missing `short_name`/`footer_copyright`/`logo_url`/`favicon_url` from
  the data passed to its own edit form (always rendered blank regardless of saved values -- fixed in
  the same pass). Added address/phone/email/website/brand color fields for the future Document Engine
  (Task #66) to consume automatically -- not consumed by anything yet, structure only.

## Consequences

- `NotificationService::preferences()` (and therefore the whole Notification Preferences feature) is
  stored via `CompanySetting`, which is install-wide, not per-tenant -- a known, PRE-EXISTING
  limitation of `CompanySetting` itself (predates Milestone 2's tenancy work entirely: branding,
  `enabled_modules`, watermark settings all share this same limitation already). Not a new regression
  introduced here; flagged as a real limitation worth a dedicated migration (`CompanySetting` gaining
  a `tenant_id` column) before a second tenant with genuinely different notification preferences is
  onboarded.
- Approval Flow UI currently only creates tenant-wide flows (`company_id` null) -- per-company
  overrides exist in the schema/engine but have no UI yet; a Company Admin who needs one has to reach
  for `tinker` today.
