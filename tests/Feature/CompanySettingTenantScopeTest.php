<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CHARACTERISATION TEST -- documents CURRENT behaviour, which is a
 * confirmed cross-tenant defect. These assertions deliberately assert the
 * BROKEN behaviour so the defect is proven empirically rather than
 * inferred from reading the schema. They are expected to be inverted by
 * whoever fixes it.
 */
class CompanySettingTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_defect_company_settings_table_has_no_tenant_discriminator(): void
    {
        $columns = Schema::getColumnListing('company_settings');

        $this->assertNotContains('tenant_id', $columns);
        $this->assertNotContains('company_id', $columns);
        $this->assertEqualsCanonicalizing(
            ['id', 'key', 'value', 'created_at', 'updated_at'],
            $columns,
            'company_settings has no tenant discriminator of any kind.'
        );
    }

    public function test_known_defect_a_setting_written_under_one_tenant_leaks_to_another(): void
    {
        $a = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $b = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        app(CurrentTenant::class)->set($a);
        CompanySetting::set('company_name', 'ACME Shipyard');
        CompanySetting::set('company_logo_path', 'uploads/company/acme-secret.png');

        app(CurrentTenant::class)->set($b);

        $this->assertSame(
            'ACME Shipyard',
            CompanySetting::get('company_name'),
            'DEFECT: Tenant B reads Tenant A company name.'
        );
        $this->assertSame(
            'uploads/company/acme-secret.png',
            CompanySetting::get('company_logo_path'),
            'DEFECT: Tenant B reads Tenant A logo path.'
        );
    }

    public function test_known_defect_a_second_tenant_destroys_the_first_tenants_branding(): void
    {
        $a = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $b = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        app(CurrentTenant::class)->set($a);
        CompanySetting::set('company_name', 'ACME Shipyard');

        app(CurrentTenant::class)->set($b);
        CompanySetting::set('company_name', 'Borneo Fabrication');

        $this->assertSame(1, CompanySetting::where('key', 'company_name')->count(),
            'DEFECT: only ONE row can exist per key platform-wide.');

        app(CurrentTenant::class)->set($a);
        $this->assertSame('Borneo Fabrication', CompanySetting::get('company_name'),
            'DEFECT: Tenant B destroyed Tenant A branding.');
    }
}
