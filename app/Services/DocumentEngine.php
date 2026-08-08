<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\DocumentTemplate;
use App\Support\CurrentTenant;

/**
 * Milestone 3 (Dynamic Document Engine, Task #66). Resolves which
 * DocumentTemplate (and which branding values) a module's PDF should
 * use -- deliberately NOT a second PDF rendering pipeline.
 * `App\Services\PdfGeneratorService`'s own doc comment already earmarked
 * this exact seam ("Company document templates ... this service takes a
 * `company` in its data so a future version can swap in a per-company
 * template without changing this class at all") -- this class fills
 * that seam. A controller resolves a template here, passes it (plus
 * branding()) into its EXISTING Blade view alongside its existing data,
 * and that view's own header/footer/signature/watermark markup reads
 * from it. No parallel rendering path, no generic layout fighting each
 * document type's real, purpose-built form design.
 *
 * Resolution mirrors NumberGeneratorService/ApprovalFlowResolver
 * (ADR-018): company-specific override -> this tenant's own default ->
 * null (the view falls back to its current hardcoded behavior, so every
 * document works exactly as before until an admin actually creates a
 * template in Settings > Documents).
 */
class DocumentEngine
{
    public function __construct(private readonly CurrentTenant $tenant) {}

    public function resolveTemplate(string $moduleKey, ?int $companyId): ?DocumentTemplate
    {
        $tenantId = $this->tenant->id();

        if ($companyId) {
            $override = DocumentTemplate::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('module_key', $moduleKey)
                ->where('is_default', true)
                ->first();

            if ($override) {
                return $override;
            }
        }

        return DocumentTemplate::where('tenant_id', $tenantId)
            ->whereNull('company_id')
            ->where('module_key', $moduleKey)
            ->where('is_default', true)
            ->first();
    }

    /** Branding values every document template chrome partial needs -- same fields Settings > Branding (Task #62) already collects. */
    public function branding(): array
    {
        return [
            'company_name' => CompanySetting::get('company_name', 'IOMS'),
            'logo_url' => CompanySetting::get('company_logo_path') ? asset('storage/'.CompanySetting::get('company_logo_path')) : null,
            'address' => CompanySetting::get('company_address'),
            'brand_color' => CompanySetting::get('brand_color', '#2563eb'),
        ];
    }
}
