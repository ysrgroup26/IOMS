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

    /**
     * v2.51.0 -- the full company identity a formal document letterhead
     * needs, as opposed to the four fields `branding()` returns for
     * on-screen chrome.
     *
     * This is what makes the shared letterhead partial possible. Before
     * it, each PDF view reached into CompanySetting for whichever fields
     * its own header happened to want, so the HSE permit grew a real
     * letterhead while every other document had none -- and adding one
     * anywhere meant copying that block again.
     *
     * Every value is tenant-scoped, because CompanySetting is (see
     * CompanySettingScope). A document therefore always carries the
     * identity of the tenant that generated it; there is no path here by
     * which one customer's letterhead can appear on another's document.
     *
     * Falls back to the platform name only when a tenant has not set a
     * company name at all -- an empty letterhead is worse than a generic
     * one, but nothing is ever invented beyond that.
     */
    public function identity(): array
    {
        $logoPath = CompanySetting::get('company_logo_path');

        $city = CompanySetting::get('company_city');
        $province = CompanySetting::get('company_province');
        $postcode = CompanySetting::get('company_postal_code');

        // "Batam, Kepulauan Riau 29444" -- assembled here rather than in
        // each Blade view so one document cannot format it differently
        // from another, and so a missing piece never leaves a stray comma.
        $locality = collect([
            trim(implode(', ', array_filter([$city, $province]))),
            $postcode,
        ])->filter()->implode(' ');

        return [
            'name' => CompanySetting::get('company_name', config('ioms.name')),
            'legal_name' => CompanySetting::get('company_legal_name'),
            'logo_url' => $logoPath ? asset('storage/'.$logoPath) : null,
            'address' => CompanySetting::get('company_address'),
            'locality' => $locality ?: null,
            'city' => $city,
            'province' => $province,
            'postal_code' => $postcode,
            'country' => CompanySetting::get('company_country'),
            'phone' => CompanySetting::get('company_phone'),
            'email' => CompanySetting::get('company_email'),
            'website' => CompanySetting::get('company_website'),
            'tax_id' => CompanySetting::get('company_tax_id'),
            'business_id' => CompanySetting::get('company_business_id'),
            'brand_color' => CompanySetting::get('brand_color', '#2563eb'),
        ];
    }
}
