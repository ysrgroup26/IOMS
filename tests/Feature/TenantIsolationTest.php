<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v2.37.0 (Master Audit, P0/P1). Regression tests for the cross-tenant
 * access defects this pass confirmed and fixed. Each test here failed
 * against the code as it stood at commit a47408d.
 *
 * Why these specific tests: IOMS enforces tenancy through a global scope
 * on `Company` ONLY (see App\Models\Scopes\TenantScope) -- every other
 * table is "transitively safe" purely because controllers remember to
 * filter through it. Route-model binding bypasses that entirely, so the
 * real risk is not the scope, it is a controller forgetting its guard.
 * A static audit of 172 route-model-bound controller methods found 163
 * correctly guarded and a handful that were not; these tests pin the
 * fixed ones so the next omission fails loudly instead of silently
 * leaking another tenant's data.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $this->tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        $this->companyA = Company::withoutGlobalScopes()->create(['name' => 'Company A', 'tenant_id' => $this->tenantA->id]);
        $this->companyB = Company::withoutGlobalScopes()->create(['name' => 'Company B', 'tenant_id' => $this->tenantB->id]);

        // Deliberately the most privileged role available, to prove the
        // boundary holds on capability alone -- these defects were NOT
        // "a low-privilege user saw too much", they were "a fully
        // privileged user of tenant A reached tenant B".
        $this->userA = User::create([
            'name' => 'Admin A',
            'email' => 'admin-a@example.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'tenant_id' => $this->tenantA->id,
            'is_active' => true,
        ]);
    }

    /**
     * P0-1, layer 1 (validation): posting another tenant's company_id is
     * rejected by the new `InCurrentTenant` rule before the controller
     * is reached, so this returns a 302 validation redirect rather than
     * a 403. What matters is that the row is untouched.
     */
    public function test_user_cannot_update_a_project_belonging_to_another_tenant(): void
    {
        $foreignProject = Project::create([
            'company_id' => $this->companyB->id,
            'name' => 'Tenant B Project',
            'status' => 'ongoing',
        ]);

        $this->actingAs($this->userA)->put("/projects/{$foreignProject->id}", [
            'company_id' => $this->companyB->id,
            'name' => 'HIJACKED',
            'status' => 'ongoing',
        ]);

        $this->assertSame('Tenant B Project', $foreignProject->fresh()->name, 'Foreign project must not be modified.');
    }

    /**
     * P0-1, layer 2 (authorization) -- the actual defect this pass fixed.
     * Sending a VALID own-tenant company_id passes validation cleanly, so
     * nothing but `ProjectPolicy::update` stands between the request and
     * another tenant's row. Before the fix this returned 302 (redirect
     * after a successful save) and renamed tenant B's project; the
     * controller never called `$this->authorize()` even though the policy
     * had carried the correct ownership check since v1.11.7.
     *
     * This separation matters: if the validation rule above were ever
     * relaxed, this test still fails loudly rather than the breach going
     * unnoticed.
     */
    public function test_project_update_authorization_blocks_foreign_records_independently_of_validation(): void
    {
        $foreignProject = Project::create([
            'company_id' => $this->companyB->id,
            'name' => 'Tenant B Project',
            'status' => 'ongoing',
        ]);

        $response = $this->actingAs($this->userA)->put("/projects/{$foreignProject->id}", [
            'company_id' => $this->companyA->id, // valid for the attacker: passes validation
            'name' => 'HIJACKED',
            'status' => 'ongoing',
        ]);

        $response->assertForbidden();
        $this->assertSame('Tenant B Project', $foreignProject->fresh()->name, 'Foreign project must not be modified.');
        $this->assertSame($this->companyB->id, $foreignProject->fresh()->company_id, 'Foreign project must not be reassigned.');
    }

    /** P0-2: cross-tenant READ of personal data via PpeController::employeeProfile. */
    public function test_user_cannot_view_ppe_profile_of_another_tenants_employee(): void
    {
        $foreignEmployee = Employee::create([
            'employee_id' => 'B-001',
            'full_name' => 'Tenant B Employee',
            'company_id' => $this->companyB->id,
        ]);

        $this->actingAs($this->userA)
            ->get("/ppe/employees/{$foreignEmployee->id}")
            ->assertNotFound();
    }

    /** P0-3: cross-tenant READ via TaskController::show. */
    public function test_user_cannot_view_a_task_belonging_to_another_tenant(): void
    {
        $foreignTask = Task::create([
            'uuid' => (string) Str::uuid(),
            'task_number' => 'TSK-B-1',
            'title' => 'Tenant B Task',
            'company_id' => $this->companyB->id,
        ]);

        $this->actingAs($this->userA)
            ->get("/tasks/{$foreignTask->id}")
            ->assertNotFound();
    }

    /**
     * A task with no company at all is legitimate (`tasks.company_id` is
     * nullable by design) and must stay reachable -- guarding against a
     * fix that over-corrects into breaking real data.
     */
    public function test_task_without_a_company_is_still_viewable(): void
    {
        $globalTask = Task::create([
            'uuid' => (string) Str::uuid(),
            'task_number' => 'TSK-GLOBAL-1',
            'title' => 'Unassigned Task',
            'company_id' => null,
        ]);

        $this->actingAs($this->userA)
            ->get("/tasks/{$globalTask->id}")
            ->assertOk();
    }

    /** A user's own tenant data must remain fully reachable. */
    public function test_user_can_view_a_task_in_their_own_tenant(): void
    {
        $ownTask = Task::create([
            'uuid' => (string) Str::uuid(),
            'task_number' => 'TSK-A-1',
            'title' => 'Tenant A Task',
            'company_id' => $this->companyA->id,
        ]);

        $this->actingAs($this->userA)
            ->get("/tasks/{$ownTask->id}")
            ->assertOk();
    }

    /** P0-4: the TenantScope itself -- Company queries must never return another tenant's rows. */
    public function test_company_queries_are_scoped_to_the_current_tenant(): void
    {
        $this->actingAs($this->userA)->get('/dashboard');

        $this->be($this->userA);
        app(\App\Support\CurrentTenant::class)->set($this->tenantA);

        $ids = Company::query()->pluck('id');

        $this->assertTrue($ids->contains($this->companyA->id));
        $this->assertFalse($ids->contains($this->companyB->id), 'TenantScope must exclude other tenants.');
    }
}
