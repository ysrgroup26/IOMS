<?php

namespace Tests\Feature;

use App\Exports\EmployeeExport;
use App\Models\CompanySetting;
use App\Models\Tenant;
use App\Services\DocumentEngine;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.40.0 -- the point of the company_settings fix.
 *
 * The tenant-scoping defect mattered because it did not stop at the app
 * shell: DocumentEngine feeds the letterhead of EVERY generated PDF, and
 * the Excel exports stamp a company name into the sheet. Before this
 * release both read a platform-global row, so one tenant's identity was
 * printed onto another tenant's documents -- artifacts that leave the
 * system and are handed to auditors, contractors and regulators.
 *
 * These tests pin the propagation itself, not just the accessor.
 */
class TenantBrandingPropagationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    public function test_pdf_letterhead_branding_is_tenant_specific(): void
    {
        $a = $this->tenant('acme');
        $b = $this->tenant('borneo');

        app(CurrentTenant::class)->set($a);
        CompanySetting::set('company_name', 'ACME Shipyard');
        CompanySetting::set('company_address', 'Batam, Indonesia');
        CompanySetting::set('brand_color', '#112233');

        app(CurrentTenant::class)->set($b);
        CompanySetting::set('company_name', 'Borneo Fabrication');

        $engine = app(DocumentEngine::class);

        $brandingB = $engine->branding();
        $this->assertSame('Borneo Fabrication', $brandingB['company_name']);
        $this->assertNull($brandingB['address'], "Tenant B letterhead carried Tenant A's address.");
        $this->assertSame('#2563eb', $brandingB['brand_color'], "Tenant B letterhead carried Tenant A's brand colour.");

        app(CurrentTenant::class)->set($a);
        $brandingA = $engine->branding();
        $this->assertSame('ACME Shipyard', $brandingA['company_name']);
        $this->assertSame('Batam, Indonesia', $brandingA['address']);
        $this->assertSame('#112233', $brandingA['brand_color']);
    }

    public function test_a_tenant_logo_never_appears_on_another_tenants_documents(): void
    {
        $a = $this->tenant('acme');
        $b = $this->tenant('borneo');

        app(CurrentTenant::class)->set($a);
        CompanySetting::set('company_logo_path', 'uploads/company/acme-logo.png');

        app(CurrentTenant::class)->set($b);

        $this->assertNull(
            app(DocumentEngine::class)->branding()['logo_url'],
            "Tenant A's logo was rendered onto Tenant B's documents."
        );
    }

    public function test_excel_export_company_name_is_tenant_specific(): void
    {
        $a = $this->tenant('acme');
        $b = $this->tenant('borneo');

        app(CurrentTenant::class)->set($a);
        CompanySetting::set('company_name', 'ACME Shipyard');

        app(CurrentTenant::class)->set($b);

        // The company name is stamped into the workbook METADATA
        // (creator/company/description), which is what travels with the
        // file once it leaves IOMS. No override for B -> product name.
        $props = (new EmployeeExport)->properties();

        $this->assertStringNotContainsString('ACME Shipyard', json_encode($props), "Tenant A's name leaked into Tenant B's export metadata.");
        $this->assertSame(config('ioms.name'), $props['company']);

        app(CurrentTenant::class)->set($a);
        $this->assertSame('ACME Shipyard', (new EmployeeExport)->properties()['company']);
    }

    /** The login and landing pages have no tenant: they must show IOMS, never a customer's identity. */
    public function test_unauthenticated_surfaces_show_product_branding_not_a_customer(): void
    {
        $a = $this->tenant('acme');

        app(CurrentTenant::class)->set($a);
        CompanySetting::set('company_name', 'ACME Shipyard');

        app(CurrentTenant::class)->set(null);

        $this->assertSame(
            config('ioms.name'),
            CompanySetting::get('company_name', config('ioms.name')),
            'A guest on the login page was shown a customer company name.'
        );
    }
}
