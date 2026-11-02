<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v2.141.0 -- THE SCHEDULER RUNS WITH NO TENANT.
 *
 * `approvals:escalate` runs hourly from the scheduler, where no request has
 * resolved a tenant. Approval rows are not tenant-scoped, but the FLOW they
 * point at is (BelongsToCompany, fail-closed). This pins that an overdue
 * step is escalated in exactly that context.
 */
class ApprovalEscalationTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_overdue_step_escalates_when_the_scheduler_runs_with_no_tenant(): void
    {
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        $package = Package::where('slug', 'business')->sole();
        $tenant = Tenant::create(['name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $company = Company::withoutGlobalScopes()->create(['name' => 'Yard', 'code' => 'YD', 'tenant_id' => $tenant->id, 'is_active' => true]);
        Subscription::create(['tenant_id' => $tenant->id, 'package_id' => $package->id, 'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription', 'billing_cycle' => Subscription::CYCLE_MONTHLY, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth()]);
        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($tenant);

        $requester = User::create(['name' => 'Pemohon', 'email' => 'r-'.uniqid().'@g.test', 'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_HSE, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true]);
        $hse = User::create(['name' => 'HSE', 'email' => 'h-'.uniqid().'@g.test', 'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_HSE, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true]);

        $otherTenant = Tenant::create(['name' => 'Lain', 'slug' => 'l-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Lain', 'code' => 'LN', 'tenant_id' => $otherTenant->id, 'is_active' => true]);
        $outsider = User::withoutGlobalScopes()->create(['name' => 'HSE lain', 'email' => 'o-'.uniqid().'@g.test', 'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_HSE, 'tenant_id' => $otherTenant->id, 'company_id' => $otherCompany->id, 'is_active' => true]);

        $flow = ApprovalFlow::create(['tenant_id' => $tenant->id, 'company_id' => null, 'module_key' => 'material_request', 'name' => 'One step', 'is_active' => true, 'priority' => 0]);
        $flow->steps()->create(['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager', 'escalate_after_hours' => 1, 'escalate_to_role' => 'hse']);

        $subject = Task::create(['task_number' => 'TSK-T-1', 'title' => 'Subject', 'company_id' => $company->id, 'created_by' => $requester->id, 'status' => Task::STATUS_OPEN, 'priority' => Task::PRIORITY_MEDIUM]);
        $id = DB::table('approvals')->insertGetId([
            'approvable_type' => $subject->getMorphClass(), 'approvable_id' => $subject->id,
            'approval_flow_id' => $flow->id, 'step_number' => 1, 'status' => Approval::STATUS_PENDING,
            'requested_by' => $requester->id, 'is_escalated' => false,
            'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]);

        app(CurrentTenant::class)->set(null);   // exactly how the scheduler runs it
        $this->artisan('approvals:escalate')->assertSuccessful();

        $this->assertTrue((bool) DB::table('approvals')->where('id', $id)->value('is_escalated'), 'An overdue approval step was not escalated by the scheduled command.');

        // The escalation reaches the role in THIS tenant, and nobody else's.
        $this->assertSame(1, DB::table('notifications')->where('user_id', $hse->id)->count());
        $this->assertSame(0, DB::table('notifications')->where('user_id', $outsider->id)->count());

    }
}
