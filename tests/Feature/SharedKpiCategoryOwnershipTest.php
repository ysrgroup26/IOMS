<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\KpiCategory;
use App\Models\KpiRecord;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.119.0 -- THE HOLE v2.106.0 CLOSED FOR PPE AND LEFT OPEN FOR KPI.
 *
 * `kpi_categories` is shared reference data in exactly the way `ppe_types`
 * is: a null `company_id` is the built-in catalogue every customer starts
 * from. v2.106.0 found that any tenant administrator could rewrite one of
 * those shared PPE rows; the same write path on KPI categories was never
 * guarded, because `SettingsController::assertCompanyInTenant()` returns
 * EARLY for a null company -- correct for "there is no owner to compare
 * against", and read as a licence to write.
 *
 * The consequence was worse here than on PPE. `kpi_records.kpi_category_id`
 * points at the row by id, and the delete guard asks a TENANT-SCOPED
 * question ("does MY company have records in this category"), so one
 * customer could delete a category another customer's scoring history
 * depends on, and the guard meant to prevent exactly that would answer
 * "no records" and allow it.
 *
 * Fixed the way ADR 050 fixed PPE rather than by refusing the edit: a
 * customer whose KPI weighting differs from the shipped default must still
 * be able to change it.
 */
class SharedKpiCategoryOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Company $companyA;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        [$this->tenantA, $this->companyA, $this->adminA] = $this->makeTenant('a');
    }

    /** @return array{0: Tenant, 1: Company, 2: User} */
    private function makeTenant(string $suffix): array
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => 'Yard '.$suffix, 'slug' => 'k-'.$suffix.'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Co '.$suffix, 'code' => strtoupper($suffix).'K',
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($tenant->fresh());

        $admin = User::create([
            'name' => 'Admin '.$suffix, 'email' => 'kadmin-'.$suffix.'-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true,
        ]);

        return [$tenant, $company, $admin];
    }

    private function sharedCategory(string $name = 'Safety Awareness', string $code = 'safety_awareness'): KpiCategory
    {
        return KpiCategory::withoutGlobalScopes()->create([
            'company_id' => null,
            'code' => $code,
            'name' => $name,
            'short_label' => 'Aware',
            'is_active' => true,
            'show_on_dashboard' => true,
            'sort_order' => 10,
        ]);
    }

    /** The payload the Settings form posts for a category edit. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Safety Awareness',
            'short_label' => 'Aware',
            'description' => null,
            'company_id' => $this->companyA->id,
            'is_negative' => false,
            'show_on_dashboard' => true,
            'count_in_dashboard_stats' => true,
            'supports_quick_attendance' => false,
            'requires_approval' => false,
            'sort_order' => 10,
            'is_active' => true,
        ], $overrides);
    }

    /* ==================================================================
     * THE HOLE ITSELF
     * ================================================================== */

    public function test_a_tenant_cannot_rewrite_the_shared_built_in(): void
    {
        $shared = $this->sharedCategory();

        $this->actingAs($this->adminA)
            ->put(route('settings.kpi-categories.update', $shared), $this->payload([
                'name' => 'Safety Awareness', 'short_label' => 'SA-6',
            ]))
            ->assertRedirect();

        // The row every other customer reads is untouched.
        $shared->refresh();
        $this->assertSame('Aware', $shared->short_label);
        $this->assertNull($shared->company_id);
        $this->assertNull($shared->source_default_id);
    }

    public function test_the_edit_is_accepted_and_lands_on_an_override_this_company_owns(): void
    {
        $shared = $this->sharedCategory();

        $this->actingAs($this->adminA)
            ->put(route('settings.kpi-categories.update', $shared), $this->payload(['short_label' => 'SA-6']))
            ->assertRedirect();

        $override = KpiCategory::withoutGlobalScopes()
            ->where('company_id', $this->companyA->id)
            ->where('source_default_id', $shared->id)
            ->sole();

        $this->assertSame('SA-6', $override->short_label);
        $this->assertFalse($override->isSharedDefault());
    }

    public function test_a_tenant_cannot_delete_the_shared_built_in_even_with_no_records_of_its_own(): void
    {
        $shared = $this->sharedCategory();

        // The old guard asked a TENANT-SCOPED question and would have said
        // "no records here, go ahead" -- while another customer's scoring
        // history pointed at this very row.
        $this->actingAs($this->adminA)
            ->delete(route('settings.kpi-categories.destroy', $shared))
            ->assertRedirect();

        $this->assertNotNull(KpiCategory::withoutGlobalScopes()->find($shared->id));
    }

    public function test_deleting_a_built_in_deactivates_it_for_this_company_only(): void
    {
        $shared = $this->sharedCategory();

        $this->actingAs($this->adminA)
            ->delete(route('settings.kpi-categories.destroy', $shared))
            ->assertRedirect();

        app(CurrentTenant::class)->set($this->tenantA);
        $visible = KpiCategory::visibleForCompany($this->companyA->id)->get();
        $mine = $visible->firstWhere('name', 'Safety Awareness');

        $this->assertNotNull($mine, 'The category is still listed, as this company\'s own inactive copy.');
        $this->assertFalse((bool) $mine->is_active);
        $this->assertSame($shared->id, $mine->source_default_id);

        // Another tenant still has it, and still active.
        [$tenantB, $companyB] = $this->makeTenant('b');
        app(CurrentTenant::class)->set($tenantB);

        $theirs = KpiCategory::visibleForCompany($companyB->id)->get()->firstWhere('name', 'Safety Awareness');
        $this->assertNotNull($theirs);
        $this->assertTrue((bool) $theirs->is_active);
        $this->assertNull($theirs->company_id);
    }

    /* ==================================================================
     * THE OVERRIDE REPLACES THE TEMPLATE, IT DOES NOT SIT BESIDE IT
     * ================================================================== */

    public function test_the_company_sees_one_row_not_two(): void
    {
        $shared = $this->sharedCategory();

        $this->actingAs($this->adminA)
            ->put(route('settings.kpi-categories.update', $shared), $this->payload(['short_label' => 'SA-6']))
            ->assertRedirect();

        app(CurrentTenant::class)->set($this->tenantA);

        $visible = KpiCategory::visibleForCompany($this->companyA->id)->get()
            ->where('name', 'Safety Awareness');

        $this->assertCount(1, $visible);
        $this->assertSame('SA-6', $visible->first()->short_label);
    }

    public function test_the_settings_screen_lists_the_catalogue_in_force(): void
    {
        $shared = $this->sharedCategory();

        $this->actingAs($this->adminA)
            ->put(route('settings.kpi-categories.update', $shared), $this->payload(['short_label' => 'SA-6']))
            ->assertRedirect();

        $props = $this->actingAs($this->adminA)->get(route('settings.index'))
            ->assertOk()->viewData('page')['props'];

        $listed = collect($props['kpiCategories'])->where('name', 'Safety Awareness');

        // The screen a customer configures KPIs on must not show each
        // adapted category twice with the wrong one selectable.
        $this->assertCount(1, $listed);
        $this->assertSame('SA-6', $listed->first()['short_label']);
    }

    public function test_quick_attendance_also_shows_the_override_rather_than_both(): void
    {
        $shared = $this->sharedCategory();

        $this->actingAs($this->adminA)
            ->put(route('settings.kpi-categories.update', $shared), $this->payload([
                'short_label' => 'SA-6', 'supports_quick_attendance' => true,
            ]))
            ->assertRedirect();

        app(CurrentTenant::class)->set($this->tenantA);

        $all = KpiCategory::active()->withoutSupersededTemplates()->get()->where('name', 'Safety Awareness');

        $this->assertCount(1, $all);
        $this->assertSame('SA-6', $all->first()->short_label);
    }

    public function test_another_tenants_override_never_hides_the_built_in(): void
    {
        $shared = $this->sharedCategory();

        $this->actingAs($this->adminA)
            ->put(route('settings.kpi-categories.update', $shared), $this->payload(['short_label' => 'SA-6']))
            ->assertRedirect();

        [$tenantB, $companyB, $adminB] = $this->makeTenant('b');
        app(CurrentTenant::class)->set($tenantB);
        $this->actingAs($adminB);

        $visible = KpiCategory::active()->withoutSupersededTemplates()->get()->where('name', 'Safety Awareness');

        $this->assertCount(1, $visible);
        $this->assertNull($visible->first()->company_id, 'Tenant B must still see the built-in itself.');
        $this->assertSame('Aware', $visible->first()->short_label);
    }

    public function test_editing_twice_updates_the_same_override(): void
    {
        $shared = $this->sharedCategory();

        foreach (['SA-6', 'SA-9'] as $label) {
            $target = KpiCategory::withoutGlobalScopes()
                ->where('company_id', $this->companyA->id)
                ->where('source_default_id', $shared->id)
                ->first() ?? $shared;

            $this->actingAs($this->adminA)
                ->put(route('settings.kpi-categories.update', $target), $this->payload(['short_label' => $label]))
                ->assertRedirect();
        }

        $overrides = KpiCategory::withoutGlobalScopes()
            ->where('company_id', $this->companyA->id)
            ->where('source_default_id', $shared->id)
            ->get();

        $this->assertCount(1, $overrides, 'A second edit must not create a second override.');
        $this->assertSame('SA-9', $overrides->first()->short_label);
    }

    /* ==================================================================
     * A CATEGORY THE TENANT OWNS BEHAVES AS BEFORE
     * ================================================================== */

    public function test_a_tenants_own_category_is_still_edited_in_place(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);

        $own = KpiCategory::withoutGlobalScopes()->create([
            'company_id' => $this->companyA->id, 'code' => 'housekeeping',
            'name' => 'Housekeeping', 'short_label' => 'House', 'is_active' => true,
        ]);

        $this->actingAs($this->adminA)
            ->put(route('settings.kpi-categories.update', $own), $this->payload([
                'name' => 'Housekeeping', 'short_label' => 'HK',
            ]))
            ->assertRedirect();

        $own->refresh();
        $this->assertSame('HK', $own->short_label);
        $this->assertNull($own->source_default_id, 'An owned row is not turned into an override.');
        $this->assertSame(1, KpiCategory::withoutGlobalScopes()->where('name', 'Housekeeping')->count());
    }

    public function test_an_owned_category_with_records_still_cannot_be_deleted(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);

        $own = KpiCategory::withoutGlobalScopes()->create([
            'company_id' => $this->companyA->id, 'code' => 'drill_own',
            'name' => 'Drill Own', 'short_label' => 'Drill', 'is_active' => true,
        ]);

        $department = Department::create(['company_id' => $this->companyA->id, 'name' => 'HSE']);
        $employee = Employee::create([
            'employee_id' => 'EMP-K1', 'full_name' => 'Budi', 'company_id' => $this->companyA->id,
            'department_id' => $department->id, 'status' => 'active', 'join_date' => '2026-01-15',
        ]);

        KpiRecord::create([
            'employee_id' => $employee->id,
            'kpi_category_id' => $own->id,
            'department_id' => $department->id,
            'record_date' => now()->toDateString(),
            'quantity' => 1,
            'created_by' => $this->adminA->id,
        ]);

        $this->actingAs($this->adminA)
            ->delete(route('settings.kpi-categories.destroy', $own))
            ->assertRedirect();

        $this->assertNotNull(KpiCategory::withoutGlobalScopes()->find($own->id));
    }

    public function test_an_unused_owned_category_can_still_be_deleted(): void
    {
        app(CurrentTenant::class)->set($this->tenantA);

        $own = KpiCategory::withoutGlobalScopes()->create([
            'company_id' => $this->companyA->id, 'code' => 'campaign_own',
            'name' => 'Campaign Own', 'short_label' => 'Camp', 'is_active' => true,
        ]);

        $this->actingAs($this->adminA)
            ->delete(route('settings.kpi-categories.destroy', $own))
            ->assertRedirect();

        $this->assertNull(KpiCategory::withoutGlobalScopes()->find($own->id));
    }
}
