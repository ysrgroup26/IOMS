# 021 — Dynamic Document Engine (Milestone 3, Task #66)

## Status

Accepted.

## Problem

The UAT brief asked for a Document Template Designer -- company-configurable header/footer/logo/
branding/QR/signature/watermark, applied to a module's documents without code changes, form-based
(explicitly not a drag-drop canvas).

## Verify-before-building result

`App\Services\PdfGeneratorService`'s own doc comment already earmarked this exact seam: *"Company
document templates (letterhead-per-company) are an explicitly deferred future feature -- this
service takes a `company` in its data so a future version can swap in a per-company template without
changing this class at all."* Building a second, parallel PDF pipeline (a first draft of this task
did exactly that, with its own generic Blade view and its own DomPDF call) would have ignored that
seam and produced two competing "how PDFs get rendered" systems. Reverted that first draft before
committing to this design.

## Decision

**`App\Services\DocumentEngine`** does template *resolution* only -- `resolveTemplate($moduleKey,
$companyId)` (company override -> tenant default -> null) and `branding()` (the same fields Settings
> Branding, Task #62, already collects). It does not render anything itself.

**Every module's existing PDF Blade view stays the single source of truth for that document's
layout** -- `DocumentEngine`'s output is passed into it as two extra variables
(`documentTemplate`, `branding`), and the view's own markup decides what to do with them via
`?? null`-safe conditionals that preserve the exact original appearance when no template exists.
`resources/views/pdf/material-request.blade.php` is the first (and, as of this task, only) wired
consumer: its hardcoded company-name-only header now optionally shows a logo, address, template
header/footer text, and a watermark; the original hardcoded values remain the fallback.

**`document_templates` table**: `tenant_id` required + `company_id` nullable, `module_key`,
`is_default` (app-enforced single default per module, mirroring NumberingFormat/ApprovalFlow's
resolution pattern from ADR-018), `header_text`/`footer_text`, and four booleans
(`show_logo`/`show_qr`/`show_signature`/`show_watermark`) + `watermark_text`.

**Settings > Documents** (form-based, per the brief -- no drag-drop canvas): one form per
module, list of existing templates with inline edit, matches the existing Numbering/Approval Flow
tab pattern exactly.

**QR is a toggle without a real QR image yet.** No QR-code generation package (`simple-qrcode`,
`bacon-qr-code`, etc.) exists in `composer.json` -- faking a QR-shaped placeholder image would be
exactly the kind of dummy feature CLAUDE.md prohibits. `show_qr` is real schema, real Settings UI,
and the render seam exists; wiring an actual QR image is a one-package, one-partial follow-up, not
done here.

## Bug caught during verification

Live browser test: created a template with module_key `material_request` via the real Settings UI,
then called `resolveTemplate('material_requests', ...)` (plural) from `MaterialRequestController`
-- mismatch against `NumberGeneratorService::DEFAULTS`' actual singular key (`material_request`,
matching Numbering's own module-key convention). Caught by resolving the template directly in
`tinker` and getting `NONE` back despite a real row existing, not by assuming the wired call was
correct. Fixed the controller call; re-verified resolution returns the real template, then rendered
the PDF standalone (2859 bytes, no exception in `storage/logs/laravel.log`) before/after the fix.

## Consequences

- Only Material Request is wired today. PPE Replacement Request, Incident, Leave, Milestone, Goods
  Receipt, Task have no PDF export at all yet (pre-existing gap, not introduced here) -- when one is
  built, it should follow the same `DocumentEngine::resolveTemplate()` + `??`-fallback pattern this
  ADR establishes, not a bespoke chrome implementation.
- Tenant-wide templates only (`company_id` null) from the UI -- per-company overrides exist in
  schema/engine but have no UI yet, same scope decision Approval Flow made in ADR-018.
