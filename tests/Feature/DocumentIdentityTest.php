<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Tenant;
use App\Services\DocumentEngine;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.51.0 -- the shared document identity system.
 *
 * Every generated document now draws its letterhead from one place
 * (DocumentEngine::identity()), which reads tenant-scoped
 * company_settings. These tests pin the two properties that matter:
 * the identity is real, and it is the GENERATING tenant's own -- never a
 * hardcoded company, and never another customer's.
 */
class DocumentIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $name, array $settings): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => str($name)->slug()->value()]);
        Company::withoutGlobalScopes()->create(['name' => $name, 'tenant_id' => $tenant->id]);

        $previous = app(CurrentTenant::class)->get();
        app(CurrentTenant::class)->set($tenant);

        foreach ($settings as $key => $value) {
            CompanySetting::set($key, $value);
        }

        app(CurrentTenant::class)->set($previous);

        return $tenant;
    }

    /** The letterhead is assembled from the tenant's own settings. */
    public function test_identity_is_built_from_the_tenants_own_company_settings(): void
    {
        $tenant = $this->makeTenant('Contoh Industri', [
            'company_name' => 'Contoh Industri',
            'company_legal_name' => 'PT Contoh Industri Nusantara',
            'company_address' => 'Jl. Industri No. 1',
            'company_city' => 'Batam',
            'company_province' => 'Kepulauan Riau',
            'company_postal_code' => '29444',
            'company_phone' => '+62 778 000000',
            'company_tax_id' => '01.234.567.8-901.000',
        ]);

        app(CurrentTenant::class)->set($tenant);
        $identity = app(DocumentEngine::class)->identity();

        $this->assertSame('Contoh Industri', $identity['name']);
        $this->assertSame('PT Contoh Industri Nusantara', $identity['legal_name']);
        $this->assertSame('01.234.567.8-901.000', $identity['tax_id']);

        // Locality is assembled centrally so one document cannot format it
        // differently from another, and a missing piece leaves no stray comma.
        $this->assertSame('Batam, Kepulauan Riau 29444', $identity['locality']);
    }

    /** A missing province/postcode must not produce a dangling comma on the printed page. */
    public function test_a_partial_address_still_formats_cleanly(): void
    {
        $tenant = $this->makeTenant('Sparse Co', [
            'company_name' => 'Sparse Co',
            'company_city' => 'Surabaya',
        ]);

        app(CurrentTenant::class)->set($tenant);

        $this->assertSame('Surabaya', app(DocumentEngine::class)->identity()['locality']);
    }

    /** THE ISOLATION PROPERTY: one tenant's letterhead can never appear on another's document. */
    public function test_one_tenants_document_identity_never_leaks_into_another(): void
    {
        $a = $this->makeTenant('Alpha Shipyard', [
            'company_name' => 'Alpha Shipyard',
            'company_legal_name' => 'PT Alpha Galangan',
            'company_tax_id' => '11.111.111.1-111.000',
        ]);

        $b = $this->makeTenant('Beta Fabrication', [
            'company_name' => 'Beta Fabrication',
            'company_legal_name' => 'PT Beta Fabrikasi',
            'company_tax_id' => '22.222.222.2-222.000',
        ]);

        app(CurrentTenant::class)->set($a);
        $identityA = app(DocumentEngine::class)->identity();

        app(CurrentTenant::class)->set($b);
        $identityB = app(DocumentEngine::class)->identity();

        $this->assertSame('Alpha Shipyard', $identityA['name']);
        $this->assertSame('11.111.111.1-111.000', $identityA['tax_id']);

        $this->assertSame('Beta Fabrication', $identityB['name']);
        $this->assertSame('22.222.222.2-222.000', $identityB['tax_id']);

        $this->assertNotSame($identityA['legal_name'], $identityB['legal_name']);
    }

    /**
     * v2.126.0 -- AN UNCONFIGURED TENANT REPORTS NOTHING, AND THE VIEW
     * DECIDES WHAT TO SHOW.
     *
     * This test used to assert `identity()['name'] === config('ioms.name')`,
     * and that default is what put **IOMS** on a customer's Permit to Work
     * letterhead over a permit belonging to a company called "Business
     * Unit" -- found by rendering the sheet, not by reading the code. Every
     * consumer of this field was already written as
     * `$identity['name'] ?? config('ioms.name')`, so a truthy default
     * defeated each of their fallbacks at the first link, and the operating
     * unit's real name sat one `??` away unused.
     *
     * The intent the old test was protecting -- "a tenant that has set
     * nothing still gets a usable header rather than a block of blanks" --
     * is unchanged and still enforced, one layer out: the letterhead falls
     * back to the company the document is ABOUT, and only then to the
     * product name. What moved is WHERE the decision is made, to the only
     * place that knows which company that is.
     */
    public function test_an_unconfigured_tenant_reports_no_name_so_the_view_can_fall_back(): void
    {
        $tenant = Tenant::create(['name' => 'Blank Co', 'slug' => 'blank-co']);
        app(CurrentTenant::class)->set($tenant);

        $identity = app(DocumentEngine::class)->identity();

        $this->assertNull($identity['name'], 'A default here silently outranks every caller\'s own fallback.');
        $this->assertNull($identity['legal_name']);
        $this->assertNull($identity['address']);
        $this->assertNull($identity['tax_id']);
        $this->assertNull($identity['logo_url']);
    }

    /**
     * And the promise that replaces it: no document view may render the
     * PRODUCT'S name as a company identity without first asking for the
     * company's own.
     *
     * Read as source rather than rendered, because these are four separate
     * documents with four fixtures and the rule is about the expression,
     * not about one permit.
     */
    public function test_no_document_letterhead_reaches_for_the_product_name_before_the_company(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views/pdf/*.blade.php')) as $file) {
            /*
             * Blade comments stripped first. These views carry long
             * historical notes -- one of them QUOTES the old
             * `config('ioms.company')` expression while explaining why it
             * was removed -- and a scan that reads those as code fails on
             * the documentation of the very fix it is checking for. Caught
             * by this test failing on its own first run.
             */
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($file)) ?? '';
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            // `ioms.company` is the DEVELOPER's name. It has no business on
            // a customer's document at any depth of a fallback chain.
            if (str_contains($source, "config('ioms.company')")) {
                $offenders[] = $relative.": reaches for config('ioms.company'), which is the developer, not the customer";
            }
        }

        $this->assertSame([], $offenders, implode("\n  - ", array_merge([''], $offenders))."\n");
    }

    /** Every shared document partial the new templates depend on must exist. */
    public function test_the_shared_document_partials_exist(): void
    {
        foreach (['styles', 'letterhead', 'signatures', 'footer'] as $partial) {
            $this->assertTrue(
                view()->exists("pdf.partials.{$partial}"),
                "Missing shared document partial: pdf.partials.{$partial}"
            );
        }

        foreach (['purchase-order', 'purchase-requisition', 'goods-receipt', 'work-order', 'permit-to-work'] as $document) {
            $this->assertTrue(view()->exists("pdf.{$document}"), "Missing document template: pdf.{$document}");
        }
    }
}
