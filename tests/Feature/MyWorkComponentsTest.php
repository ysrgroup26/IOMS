<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\Package;
use App\Models\PermitToWork;
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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.141.0 -- My Work: Waiting on Me, Assigned to Me, My Permits.
 *
 * Each list is the server's answer for THIS person: an approval appears only
 * when ApprovalEngine::authorize() would let them decide it, a permit only at
 * the stage their company configured them for, and a link only where
 * RouteReach says they could open it.
 */
class MyWorkComponentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();
        $this->tenant = Tenant::create(['name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $this->yard = Company::withoutGlobalScopes()->create(['name' => 'Yard', 'code' => 'YD', 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
        ]);
        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($this->tenant->fresh());
    }

    private function user(string $role, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => $role,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ], $extra));
    }

    private function permit(User $requester, string $status, array $extra = []): PermitToWork
    {
        return PermitToWork::create(array_merge([
            'ptw_number' => 'PTW-'.uniqid(), 'company_id' => $this->yard->id, 'permit_type' => 'hot_work',
            'work_description' => 'Uji.', 'start_datetime' => now()->addHour(), 'end_datetime' => now()->addHours(5),
            'requested_by' => $requester->id, 'status' => $status,
        ], $extra));
    }

    private function task(User $assignee, string $number, ?string $due, string $priority = Task::PRIORITY_MEDIUM): Task
    {
        return Task::create([
            'task_number' => $number, 'title' => 'Task '.$number, 'company_id' => $this->yard->id,
            'created_by' => $assignee->id, 'assigned_user_id' => $assignee->id,
            'status' => Task::STATUS_OPEN, 'priority' => $priority, 'due_date' => $due,
        ]);
    }

    private function props(User $user): array
    {
        return $this->actingAs($user)->get(route('my-work'))->assertOk()->viewData('page')['props'];
    }

    public function test_an_approval_waits_only_on_the_person_its_step_names(): void
    {
        $requester = $this->user(User::ROLE_HSE);
        $manager = $this->user(User::ROLE_MANAGER);
        $warehouse = $this->user(User::ROLE_WAREHOUSE);

        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'material_request', 'name' => 'One step', 'is_active' => true, 'priority' => 0]);
        $flow->steps()->create(['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager']);
        $subject = $this->task($requester, 'TSK-A-1', null);
        DB::table('approvals')->insert([
            'approvable_type' => $subject->getMorphClass(), 'approvable_id' => $subject->id,
            'approval_flow_id' => $flow->id, 'step_number' => 1, 'status' => Approval::STATUS_PENDING,
            'requested_by' => $requester->id, 'is_escalated' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->props($manager)['counts']['waitingOnMe']);
        $this->assertSame('approval', $this->props($manager)['waitingOnMe'][0]['kind']);
        $this->assertSame(0, $this->props($warehouse)['counts']['waitingOnMe']);
    }

    public function test_a_permit_waits_on_the_stage_its_status_names(): void
    {
        $requester = $this->user(User::ROLE_HSE);
        $hse = $this->user(User::ROLE_HSE);
        $manager = $this->user(User::ROLE_MANAGER);

        $submitted = $this->permit($requester, PermitToWork::STATUS_SUBMITTED);
        $verified = $this->permit($requester, PermitToWork::STATUS_VERIFIED);

        $forHse = collect($this->props($hse)['waitingOnMe']);
        $this->assertSame(['ptw_verify'], $forHse->pluck('kind')->all(), 'HSE verifies; it does not approve by default.');
        $this->assertSame($submitted->ptw_number, $forHse[0]['number']);
        $this->assertNotNull($forHse[0]['href']);

        $forManager = collect($this->props($manager)['waitingOnMe']);
        $this->assertSame(['ptw_approve'], $forManager->pluck('kind')->all());
        $this->assertSame($verified->ptw_number, $forManager[0]['number']);
    }

    public function test_assigned_work_lists_overdue_first_then_by_deadline_then_priority(): void
    {
        $me = $this->user(User::ROLE_HSE);
        $this->task($me, 'TSK-1', null, Task::PRIORITY_HIGH);
        $this->task($me, 'TSK-2', now()->addDays(5)->toDateString());
        $this->task($me, 'TSK-3', now()->subDays(2)->toDateString());
        CorrectiveAction::create([
            'company_id' => $this->yard->id, 'source_type' => 'safety_observation', 'source_id' => 1, 'action' => 'Pasang pagar', 'assigned_to' => $me->id,
            'priority' => 'high', 'due_date' => now()->addDay()->toDateString(),
            'status' => CorrectiveAction::STATUS_OPEN, 'created_by' => $me->id,
        ]);
        // Somebody else's work never appears.
        $this->task($this->user(User::ROLE_HSE), 'TSK-X', now()->subDay()->toDateString());

        $props = $this->props($me);
        $order = collect($props['assignedToMe'])->map(fn ($item) => $item['number'] ?? $item['title'])->all();

        $this->assertSame(['TSK-3', 'Pasang pagar', 'TSK-2', 'TSK-1'], $order);
        $this->assertTrue($props['assignedToMe'][0]['overdue']);
        $this->assertSame(1, $props['counts']['overdue']);
    }

    public function test_a_department_user_sees_their_task_without_a_link_they_cannot_open(): void
    {
        $me = $this->user(User::ROLE_HSE);
        $me->forceFill(['department_key' => 'hse'])->save();
        $this->task($me, 'TSK-D', null);

        $props = $this->props($me->fresh());

        $this->assertSame('TSK-D', $props['assignedToMe'][0]['number']);
        $this->assertNull($props['assignedToMe'][0]['href'], 'Tasks is not in any department yet (D1): no link that answers 403.');
        $this->assertNull($props['links']['tasks']);
    }

    public function test_my_permits_holds_what_i_requested_or_lead_and_not_closed_ones(): void
    {
        $me = $this->user(User::ROLE_HSE);
        $other = $this->user(User::ROLE_HSE);

        $mine = $this->permit($me, PermitToWork::STATUS_ACTIVE);
        $this->permit($me, PermitToWork::STATUS_CLOSED);
        $this->permit($other, PermitToWork::STATUS_SUBMITTED);

        $numbers = collect($this->props($me)['myPermits'])->pluck('number')->all();

        $this->assertSame([$mine->ptw_number], $numbers);
    }

    public function test_a_my_work_account_is_offered_only_routes_on_its_allow_list(): void
    {
        $worker = $this->user(User::ROLE_HRD, ['user_type' => User::TYPE_MY_WORK]);
        $this->task($worker, 'TSK-M', null);

        $props = $this->props($worker);

        $this->assertNotNull($props['assignedToMe'][0]['href']);
        $this->assertNull($props['links']['workCenter'], 'Work Center is refused to a My Work account.');
        $this->assertNotNull($props['links']['tasks']);
    }

    public function test_another_tenants_permits_never_wait_on_me(): void
    {
        $hse = $this->user(User::ROLE_HSE);

        $otherTenant = Tenant::create(['name' => 'Lain', 'slug' => 'l-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Lain', 'code' => 'LN', 'tenant_id' => $otherTenant->id, 'is_active' => true]);
        DB::table('permits_to_work')->insert([
            'ptw_number' => 'PTW-FOREIGN', 'company_id' => $otherCompany->id, 'permit_type' => 'hot_work',
            'work_description' => 'Asing.', 'start_datetime' => now(), 'end_datetime' => now()->addHours(2),
            'requested_by' => $hse->id, 'status' => PermitToWork::STATUS_SUBMITTED,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $props = $this->props($hse);

        $this->assertSame(0, $props['counts']['waitingOnMe']);
        $this->assertSame(0, $props['counts']['myPermits']);
    }
}
