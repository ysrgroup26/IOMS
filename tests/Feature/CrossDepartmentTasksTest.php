<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.141.0 -- D1 (approved by the owner): Tasks across departments.
 *
 * A Department User reaches the tasks handed to them (or that they
 * created), whatever their department, and nothing else of the register.
 * The server decides: the route is let through, the controller confines.
 */
class CrossDepartmentTasksTest extends TestCase
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

    private function task(User $creator, ?User $assignee, string $number): Task
    {
        return Task::create([
            'task_number' => $number, 'title' => 'Task '.$number, 'company_id' => $this->yard->id,
            'created_by' => $creator->id, 'assigned_user_id' => $assignee?->id,
            'status' => Task::STATUS_OPEN, 'priority' => Task::PRIORITY_MEDIUM,
        ]);
    }

    public function test_a_department_user_sees_only_the_tasks_handed_to_them(): void
    {
        $manager = $this->user(User::ROLE_MANAGER);
        $hseDept = $this->user(User::ROLE_HSE, ['department_key' => 'hse']);

        $mine = $this->task($manager, $hseDept, 'TSK-MINE');
        $other = $this->task($manager, $manager, 'TSK-OTHER');

        $this->actingAs($hseDept)->get(route('tasks.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('tasks.total', 1)->where('tasks.data.0.task_number', 'TSK-MINE'));

        $this->actingAs($hseDept)->get(route('tasks.show', $mine))->assertOk();
        $this->actingAs($hseDept)->get(route('tasks.show', $other))->assertNotFound();
    }

    public function test_a_department_user_works_their_task_but_cannot_create_or_delete(): void
    {
        $manager = $this->user(User::ROLE_MANAGER);
        $dept = $this->user(User::ROLE_HSE, ['department_key' => 'hse']);
        $mine = $this->task($manager, $dept, 'TSK-W');
        $other = $this->task($manager, $manager, 'TSK-X');

        $this->actingAs($dept)->get(route('tasks.edit', $mine))->assertOk();
        $this->actingAs($dept)->get(route('tasks.edit', $other))->assertNotFound();
        $this->actingAs($dept)->get(route('tasks.create'))->assertForbidden();
        $this->actingAs($dept)->delete(route('tasks.destroy', $mine))->assertForbidden();
        $this->assertNotNull($mine->fresh());
    }

    public function test_the_role_does_not_widen_a_department_users_reach(): void
    {
        // An HSE-role Department User is not an "admin" over other people's tasks.
        $manager = $this->user(User::ROLE_MANAGER);
        $dept = $this->user(User::ROLE_HSE, ['department_key' => 'hse']);
        $other = $this->task($manager, $manager, 'TSK-Y');

        $this->actingAs($dept)->get(route('tasks.edit', $other))->assertNotFound();
    }

    public function test_a_my_work_account_sees_only_its_own_tasks(): void
    {
        $manager = $this->user(User::ROLE_MANAGER);
        $worker = $this->user(User::ROLE_HRD, ['user_type' => User::TYPE_MY_WORK]);
        $this->task($manager, $worker, 'TSK-MW');
        $other = $this->task($manager, $manager, 'TSK-NOT');

        $this->actingAs($worker)->get(route('tasks.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('tasks.total', 1));
        $this->actingAs($worker)->get(route('tasks.show', $other))->assertNotFound();
    }

    public function test_a_full_user_keeps_the_whole_register(): void
    {
        $manager = $this->user(User::ROLE_MANAGER);
        $hse = $this->user(User::ROLE_HSE);
        $this->task($manager, $manager, 'TSK-1');
        $this->task($manager, null, 'TSK-2');

        $this->actingAs($hse)->get(route('tasks.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('tasks.total', 2));
    }

    public function test_another_tenants_task_stays_unreachable(): void
    {
        $other = Tenant::create(['name' => 'Lain', 'slug' => 'l-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Lain', 'code' => 'LN', 'tenant_id' => $other->id, 'is_active' => true]);
        $dept = $this->user(User::ROLE_HSE, ['department_key' => 'hse']);
        $foreign = Task::create([
            'task_number' => 'TSK-F', 'title' => 'Asing', 'company_id' => $otherCompany->id,
            'created_by' => $dept->id, 'assigned_user_id' => $dept->id,
            'status' => Task::STATUS_OPEN, 'priority' => Task::PRIORITY_MEDIUM,
        ]);

        $this->actingAs($dept)->get(route('tasks.show', $foreign))->assertNotFound();
    }
}
