<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * v2.40.0. These assertions were INVERTED in this release.
 *
 * They previously passed as characterisation tests documenting a proven
 * P0 defect: `company_settings` had no tenant discriminator and a
 * platform-wide unique(key), so one tenant read and destroyed every
 * other tenant's company identity -- reaching PDFs, Excel exports,
 * reports and notifications. They now assert the fixed behaviour.
 */
class CompanySettingTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    private function actingAsTenant(?Tenant $tenant): void
    {
        app(CurrentTenant::class)->set($tenant);
    }

    public function test_company_settings_is_owned_by_a_tenant(): void
    {
        $this->assertContains('tenant_id', Schema::getColumnListing('company_settings'));
    }

    /** The core fix: one tenant's identity must never be visible to another. */
    public function test_a_setting_written_by_one_tenant_is_not_readable_by_another(): void
    {
        $a = $this->tenant('tenant-a');
        $b = $this->tenant('tenant-b');

        $this->actingAsTenant($a);
        CompanySetting::set('company_name', 'ACME Shipyard');
        CompanySetting::set('company_logo_path', 'uploads/company/acme.png');

        $this->actingAsTenant($b);

        $this->assertNull(CompanySetting::get('company_name'));
        $this->assertNull(CompanySetting::get('company_logo_path'));
        $this->assertSame('Fallback', CompanySetting::get('company_name', 'Fallback'));
    }

    /** The second half of the defect: a save must not destroy another tenant's row. */
    public function test_one_tenant_save_does_not_destroy_another_tenants_branding(): void
    {
        $a = $this->tenant('tenant-a');
        $b = $this->tenant('tenant-b');

        $this->actingAsTenant($a);
        CompanySetting::set('company_name', 'ACME Shipyard');

        $this->actingAsTenant($b);
        CompanySetting::set('company_name', 'Borneo Fabrication');

        $this->actingAsTenant($a);
        $this->assertSame('ACME Shipyard', CompanySetting::get('company_name'));

        $this->actingAsTenant($b);
        $this->assertSame('Borneo Fabrication', CompanySetting::get('company_name'));

        $this->assertSame(2, CompanySetting::withoutGlobalScopes()->where('key', 'company_name')->count());
    }

    /** A tenant with no override of its own resolves to the shipped platform default. */
    public function test_platform_default_is_used_when_a_tenant_has_no_override(): void
    {
        $this->actingAsTenant(null);
        CompanySetting::set('company_subtitle', 'Industrial Operations Platform');

        $this->actingAsTenant($this->tenant('tenant-a'));

        $this->assertSame('Industrial Operations Platform', CompanySetting::get('company_subtitle'));
    }

    public function test_a_tenant_override_takes_precedence_over_the_platform_default(): void
    {
        $this->actingAsTenant(null);
        CompanySetting::set('company_name', 'IOMS');

        $a = $this->tenant('tenant-a');
        $this->actingAsTenant($a);
        CompanySetting::set('company_name', 'ACME Shipyard');

        $this->assertSame('ACME Shipyard', CompanySetting::get('company_name'));

        // ...and the platform default itself is untouched, so other
        // tenants and the login/landing pages still see IOMS.
        $this->actingAsTenant(null);
        $this->assertSame('IOMS', CompanySetting::get('company_name'));
    }

    /** A guest (login/landing) and a Platform Super Admin have no tenant: they get platform values, never a tenant's. */
    public function test_an_unresolved_request_never_sees_tenant_data(): void
    {
        $this->actingAsTenant(null);
        CompanySetting::set('company_name', 'IOMS');

        $this->actingAsTenant($this->tenant('tenant-a'));
        CompanySetting::set('company_name', 'ACME Shipyard');

        $this->actingAsTenant(null);

        $this->assertSame('IOMS', CompanySetting::get('company_name'));
    }

    /**
     * The cache is the second half of the isolation story: v1.6.8 keyed it
     * on the bare setting key, so even a correctly-scoped query would have
     * been served another tenant's cached value within the same process.
     */
    public function test_the_cache_does_not_leak_between_tenants(): void
    {
        $a = $this->tenant('tenant-a');
        $b = $this->tenant('tenant-b');

        $this->actingAsTenant($a);
        CompanySetting::set('company_name', 'ACME Shipyard');
        $this->assertSame('ACME Shipyard', CompanySetting::get('company_name'));  // warms the cache

        $this->actingAsTenant($b);
        $this->assertNull(CompanySetting::get('company_name'), 'Tenant B was served Tenant A cached value.');

        CompanySetting::set('company_name', 'Borneo Fabrication');
        $this->assertSame('Borneo Fabrication', CompanySetting::get('company_name'));

        $this->actingAsTenant($a);
        $this->assertSame('ACME Shipyard', CompanySetting::get('company_name'), 'Tenant A cache was clobbered by Tenant B write.');
    }

    /** The raw-Eloquent path (enabled_modules, notification_preferences) must be scoped too. */
    public function test_raw_queries_are_tenant_scoped_by_the_global_scope(): void
    {
        $a = $this->tenant('tenant-a');
        $b = $this->tenant('tenant-b');

        $this->actingAsTenant($a);
        CompanySetting::set('enabled_modules', '["hse","ppe"]');

        $this->actingAsTenant($b);

        $this->assertNull(CompanySetting::where('key', 'enabled_modules')->value('value'));
        $this->assertNull(CompanySetting::getUncached('enabled_modules'));
    }

    public function test_all_settings_merges_platform_defaults_under_tenant_overrides(): void
    {
        $this->actingAsTenant(null);
        CompanySetting::set('company_name', 'IOMS');
        CompanySetting::set('company_subtitle', 'Industrial Operations Platform');

        $this->actingAsTenant($this->tenant('tenant-a'));
        CompanySetting::set('company_name', 'ACME Shipyard');

        $all = CompanySetting::all_settings();

        $this->assertSame('ACME Shipyard', $all['company_name']);
        $this->assertSame('Industrial Operations Platform', $all['company_subtitle']);
    }
}
