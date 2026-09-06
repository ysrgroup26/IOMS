<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ReportSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v2.40.0 -- regression cover for a confirmed scheduler defect.
 *
 * `reports:dispatch-scheduled` runs hourly from routes/console.php, so it
 * has no HTTP request and therefore no resolved tenant. Every tenant-owned
 * analytics query resolves through `Company::query()`, whose TenantScope
 * fails CLOSED on an unresolved tenant. That is the correct security
 * posture -- no data leaked -- but it meant every scheduled report was
 * generated EMPTY while its owner was notified it was "ready", which is
 * indistinguishable from "you genuinely had no data".
 */
class ScheduledReportTenantContextTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithEmployees(string $slug, int $count): array
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
        $company = Company::withoutGlobalScopes()->create(['name' => strtoupper($slug), 'tenant_id' => $tenant->id]);
        $department = Department::create(['name' => 'Produksi', 'company_id' => $company->id]);

        for ($i = 1; $i <= $count; $i++) {
            Employee::create([
                'employee_id' => strtoupper($slug)."-{$i}",
                'full_name' => "Worker {$i}",
                'company_id' => $company->id,
                'department_id' => $department->id,
                'status' => 'active',
            ]);
        }

        $user = User::create([
            'name' => 'Admin', 'email' => "admin@{$slug}.test", 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        return [$tenant, $company, $user];
    }

    /** The defect itself: an unresolved tenant yields an empty dataset. */
    public function test_analytics_yields_nothing_without_tenant_context(): void
    {
        $this->tenantWithEmployees('acme', 3);

        app(CurrentTenant::class)->set(null);

        $dataset = app(AnalyticsService::class)->dataset('employees_by_department');

        $this->assertSame([], $dataset['values'], 'Sanity check: no tenant context must produce no rows (fail closed).');
    }

    public function test_the_scheduler_restores_tenant_context_so_reports_are_not_empty(): void
    {
        Storage::fake();

        [$tenant, $company, $user] = $this->tenantWithEmployees('acme', 3);

        ReportSchedule::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'dataset_key' => 'employees_by_department',
            'frequency' => 'daily',
            'format' => 'csv',
            'is_active' => true,
            'next_run_at' => null,
        ]);

        app(CurrentTenant::class)->set(null);

        $this->artisan('reports:dispatch-scheduled')->assertSuccessful();

        $files = Storage::allFiles('reports');
        $this->assertCount(1, $files, 'The due schedule should have produced exactly one report file.');

        $csv = Storage::get($files[0]);

        $this->assertStringContainsString('Produksi', $csv, 'Report was generated without tenant context (empty dataset).');
        $this->assertStringContainsString('3', $csv, "Report should carry this tenant's real employee count.");
    }

    /** A scheduler run must never let one tenant's context bleed into another's report. */
    public function test_each_schedule_uses_its_own_tenant_context(): void
    {
        Storage::fake();

        [$tenantA, $companyA, $userA] = $this->tenantWithEmployees('acme', 3);
        [$tenantB, $companyB, $userB] = $this->tenantWithEmployees('borneo', 7);

        foreach ([[$tenantA, $companyA, $userA], [$tenantB, $companyB, $userB]] as [$t, $c, $u]) {
            ReportSchedule::create([
                'tenant_id' => $t->id, 'company_id' => $c->id, 'user_id' => $u->id,
                'dataset_key' => 'employees_by_department', 'frequency' => 'daily',
                'format' => 'csv', 'is_active' => true, 'next_run_at' => null,
            ]);
        }

        app(CurrentTenant::class)->set(null);
        $this->artisan('reports:dispatch-scheduled')->assertSuccessful();

        $contents = collect(Storage::allFiles('reports'))->map(fn ($f) => Storage::get($f));

        $this->assertCount(2, $contents);
        // 3 and 7 -- never 10, which is what a leaked/merged context would produce.
        $this->assertTrue($contents->contains(fn ($c) => str_contains($c, ',3')), 'Tenant A report missing its own count.');
        $this->assertTrue($contents->contains(fn ($c) => str_contains($c, ',7')), 'Tenant B report missing its own count.');
        $this->assertFalse($contents->contains(fn ($c) => str_contains($c, ',10')), 'Counts were merged across tenants.');
    }

    /** Context must not survive the run and silently affect later work in the same process. */
    public function test_tenant_context_is_cleared_after_the_run(): void
    {
        Storage::fake();

        [$tenant, $company, $user] = $this->tenantWithEmployees('acme', 3);

        ReportSchedule::create([
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'user_id' => $user->id,
            'dataset_key' => 'employees_by_department', 'frequency' => 'daily',
            'format' => 'csv', 'is_active' => true, 'next_run_at' => null,
        ]);

        $this->artisan('reports:dispatch-scheduled')->assertSuccessful();

        $this->assertNull(app(CurrentTenant::class)->id(), 'Scheduler left a tenant bound after finishing.');
    }
}
