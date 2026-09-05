<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantReadinessService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.39.0. Pins the distinction between "healthy zero" and "no data yet".
 *
 * The defect being guarded against: a brand-new tenant with an entirely
 * empty database was shown "✅ Great job! No critical issues detected
 * today." IOMS asserted a safety condition it had no data for. These
 * tests fix the boundary at which that assertion becomes legitimate.
 */
class TenantReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'C', 'tenant_id' => $this->tenant->id]);

        $admin = User::create([
            'name' => 'Admin', 'email' => 'r@example.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        $this->be($admin);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function snapshot(): array
    {
        return app(TenantReadinessService::class)->snapshot();
    }

    /** The core case: a fresh tenant is NOT operational, so its zeros mean nothing yet. */
    public function test_a_brand_new_tenant_is_not_operational(): void
    {
        $snapshot = $this->snapshot();

        $this->assertFalse($snapshot['is_operational']);
        $this->assertGreaterThan(0, $snapshot['total']);
    }

    /**
     * Employees are the gate: PPE, man-hours, KPI, PTW workforce, leave and
     * competency all hang off them, so with none of them present no
     * operational metric can be non-zero.
     */
    public function test_tenant_becomes_operational_once_workforce_data_exists(): void
    {
        $this->assertFalse($this->snapshot()['is_operational']);

        Employee::create([
            'employee_id' => 'E1', 'full_name' => 'Worker',
            'company_id' => $this->company->id, 'status' => 'active',
        ]);

        $this->assertTrue($this->snapshot()['is_operational']);
    }

    /**
     * Deliberately NOT "every step complete". A tenant with workforce but
     * no projects is genuinely operational and its zeros are genuinely
     * meaningful -- overstating what counts as "set up" would just swap
     * one dishonest state for another.
     */
    public function test_operational_does_not_require_every_setup_step(): void
    {
        Employee::create([
            'employee_id' => 'E1', 'full_name' => 'Worker',
            'company_id' => $this->company->id, 'status' => 'active',
        ]);

        $snapshot = $this->snapshot();

        $this->assertTrue($snapshot['is_operational']);
        $this->assertLessThan($snapshot['total'], $snapshot['completed'], 'Not every step should be complete in this scenario.');
    }

    public function test_completed_count_tracks_real_records(): void
    {
        $before = $this->snapshot()['completed'];

        Department::create(['name' => 'Produksi', 'company_id' => $this->company->id]);
        Project::create(['company_id' => $this->company->id, 'name' => 'Dock 1', 'status' => 'ongoing']);

        $this->assertSame($before + 2, $this->snapshot()['completed']);
    }

    /** Readiness must never be satisfied by another tenant's records. */
    public function test_readiness_ignores_other_tenants_data(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'OC', 'tenant_id' => $otherTenant->id]);

        Employee::create([
            'employee_id' => 'X1', 'full_name' => 'Foreign Worker',
            'company_id' => $otherCompany->id, 'status' => 'active',
        ]);
        Department::create(['name' => 'Foreign Dept', 'company_id' => $otherCompany->id]);

        $this->assertFalse(
            $this->snapshot()['is_operational'],
            "Another tenant's employees must never make this tenant look operational."
        );
    }

    /** Every step must point at a resolvable route, or the setup UI links nowhere. */
    public function test_every_setup_step_has_a_resolvable_route(): void
    {
        foreach ($this->snapshot()['steps'] as $step) {
            $this->assertNotNull($step['href'], "Step {$step['key']} has no route.");
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($step['href']), "Route {$step['href']} does not exist.");
        }
    }
}
