<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PtwActivationService;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.141.0 -- PTW automatic activation (owner's final rule, ADR 083).
 *
 *   Created -> Approval 1..N -> Final Verification -> waits for start -> Active
 *
 * Normal, late, failed and duplicate-scheduler cases; tenant isolation; and
 * the standard (legacy) route, which this must never touch.
 */
class PtwAutomaticActivationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $admin;

    private User $requester;

    private User $manager;

    private User $hse;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        [$this->tenant, $this->yard] = $this->organisation('Galangan');

        $this->admin = $this->user(User::ROLE_SUPER_ADMIN, 'Admin');
        $this->requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
        $this->manager = $this->user(User::ROLE_MANAGER, 'Manager');
        $this->hse = $this->user(User::ROLE_HSE, 'HSE Satu');
        $this->user(User::ROLE_HSE, 'HSE Dua');

        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'permit_to_work', 'name' => 'PTW', 'is_active' => true, 'priority' => 0]);
        $this->actingAs($this->admin)->put(route('settings.approval-flows.steps', $flow->id), ['steps' => [
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('settings.ptw-workflow.mode'), ['company_id' => $this->yard->id, 'approval_mode' => 'flow'])->assertSessionHasNoErrors();
    }

    private function organisation(string $name): array
    {
        $package = Package::where('slug', 'business')->sole();
        $tenant = Tenant::create(['name' => $name, 'slug' => str($name)->slug().'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $company = Company::withoutGlobalScopes()->create(['name' => $name, 'code' => strtoupper(substr(uniqid(), -6)), 'tenant_id' => $tenant->id, 'is_active' => true]);
        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id, 'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
        ]);
        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($tenant->fresh());

        return [$tenant, $company];
    }

    private function user(string $role, string $name, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => $name, 'email' => uniqid().'@g.test', 'password' => bcrypt('secret-pass-1'), 'role' => $role,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ], $extra));
    }

    private function submit(string $startsIn = '+2 hours', string $endsIn = '+8 hours'): PermitToWork
    {
        $this->actingAs($this->requester)->post(route('permits-to-work.store'), [
            'company_id' => $this->yard->id, 'permit_type' => 'hot_work', 'work_reference' => 'Overhaul',
            'location' => 'Dok 2', 'work_description' => 'Pengelasan.',
            'start_datetime' => now()->modify($startsIn)->format('Y-m-d\TH:i'),
            'end_datetime' => now()->modify($endsIn)->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        return PermitToWork::latest('id')->firstOrFail();
    }

    private function approve(User $user, PermitToWork $permit): void
    {
        $approval = $permit->approvals()->where('status', Approval::STATUS_PENDING)->orderBy('id')->firstOrFail();
        $this->actingAs($user)->post(route('approvals.approve', $approval->id))->assertRedirect();
    }

    private function throughFinalVerification(PermitToWork $permit): PermitToWork
    {
        $this->approve($this->manager, $permit);
        $this->approve($this->hse, $permit);

        return $permit->fresh();
    }

    public function test_verified_before_the_start_it_waits_then_activates_by_itself(): void
    {
        $permit = $this->throughFinalVerification($this->submit('+2 hours'));

        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->status, 'Never Active before its start time.');
        $this->artisan('ptw:activate-due')->assertSuccessful();
        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->fresh()->status);

        $this->travel(2)->hours();
        $this->artisan('ptw:activate-due')->assertSuccessful();

        $permit->refresh();
        $this->assertSame(PermitToWork::STATUS_ACTIVE, $permit->status);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $permit->id, 'action' => 'active', 'user_id' => null]);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->requester->id)->where('title', 'Permit is now active')->count(), 'The requester is told.');
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->hse->id)->where('title', 'Permit is now active')->count(), 'The Final Verifier is told.');
    }

    public function test_verified_after_the_start_it_is_active_at_once_with_no_second_step(): void
    {
        $permit = $this->submit('+1 minute');
        $this->travel(10)->minutes();

        $permit = $this->throughFinalVerification($permit);

        $this->assertSame(PermitToWork::STATUS_ACTIVE, $permit->status);

        // Activated inside the verifier's request -- but by the start time, not by them.
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $permit->id, 'action' => 'active', 'user_id' => null]);
        $audit = DB::table('record_changes')->where('subject_id', $permit->id)->where('to_status', 'active')->first();
        $this->assertNull($audit->actor_id);
        $this->assertSame('system', json_decode($audit->context, true)['channel']);
    }

    public function test_a_permit_is_never_active_before_its_approvals_and_final_verification(): void
    {
        $permit = $this->submit('+1 minute');
        $this->travel(10)->minutes();

        $this->artisan('ptw:activate-due')->assertSuccessful();
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->fresh()->status, 'Approvals not done.');

        $this->approve($this->manager, $permit);
        $this->artisan('ptw:activate-due')->assertSuccessful();
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->fresh()->status, 'Final Verification not done.');

        // Even forced, the service re-checks every requirement.
        DB::table('permits_to_work')->where('id', $permit->id)->update(['status' => PermitToWork::STATUS_APPROVED]);
        $this->assertSame(PtwActivationService::NOT_READY, app(PtwActivationService::class)->activateIfDue($permit->fresh()));
        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->fresh()->status);
    }

    public function test_a_late_scheduler_still_activates_and_says_it_was_late(): void
    {
        $permit = $this->throughFinalVerification($this->submit('+1 hour'));

        $this->travel(4)->hours();   // the scheduler was down for hours
        $this->artisan('ptw:activate-due')->assertSuccessful();

        $this->assertSame(PermitToWork::STATUS_ACTIVE, $permit->fresh()->status);
        $this->assertStringContainsString('scheduled', DB::table('activity_logs')->where('subject_id', $permit->id)->where('action', 'active')->value('description'));
    }

    public function test_a_permit_whose_window_closed_is_not_activated_and_the_requester_is_told_once(): void
    {
        $permit = $this->throughFinalVerification($this->submit('+1 hour', '+3 hours'));

        $this->travel(5)->hours();
        $this->artisan('ptw:activate-due')->assertSuccessful();
        $this->artisan('ptw:activate-due')->assertSuccessful();

        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->fresh()->status);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->requester->id)->where('dedupe_key', 'ptw.window_passed.'.$permit->id)->count());
    }

    public function test_running_the_scheduler_twice_activates_once(): void
    {
        $permit = $this->throughFinalVerification($this->submit('+1 hour'));
        $this->travel(2)->hours();

        $service = app(PtwActivationService::class);
        $first = $service->activateIfDue($permit->fresh());
        $second = $service->activateIfDue($permit->fresh());
        $this->artisan('ptw:activate-due')->assertSuccessful();

        $this->assertSame([PtwActivationService::ACTIVATED, PtwActivationService::NOT_APPROVED], [$first, $second]);
        $this->assertSame(1, DB::table('activity_logs')->where('subject_id', $permit->id)->where('action', 'active')->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->hse->id)->where('title', 'Permit is now active')->count());
    }

    public function test_a_cancelled_permit_is_never_activated(): void
    {
        $permit = $this->throughFinalVerification($this->submit('+1 hour'));
        $this->actingAs($this->hse)->post(route('permits-to-work.transition', $permit), ['status' => 'cancelled'])->assertSessionHasNoErrors();

        $this->travel(2)->hours();
        $this->artisan('ptw:activate-due')->assertSuccessful();

        $this->assertSame(PermitToWork::STATUS_CANCELLED, $permit->fresh()->status);
    }

    public function test_manual_activation_is_refused_on_the_flow(): void
    {
        $permit = $this->throughFinalVerification($this->submit('+2 hours'));

        $this->actingAs($this->hse)->post(route('permits-to-work.transition', $permit), ['status' => 'active'])->assertSessionHas('flash.error');
        $this->actingAs($this->admin)->post(route('permits-to-work.transition', $permit), ['status' => 'active'])->assertSessionHas('flash.error');
        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->fresh()->status);
    }

    public function test_the_standard_route_is_never_activated_automatically(): void
    {
        // A permit already running on the standard gates (as in production).
        $this->actingAs($this->admin)->post(route('settings.ptw-workflow.mode'), ['company_id' => $this->yard->id, 'approval_mode' => 'legacy']);
        $permit = $this->submit('+1 hour');
        $this->actingAs($this->hse)->post(route('permits-to-work.transition', $permit), ['status' => 'verified'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('permits-to-work.transition', $permit), ['status' => 'approved'])->assertSessionHasNoErrors();

        $this->travel(2)->hours();
        $this->artisan('ptw:activate-due')->assertSuccessful();

        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->fresh()->status, 'The standard route keeps its manual activation.');
        $this->assertSame(PtwActivationService::NOT_FLOW, app(PtwActivationService::class)->activateIfDue($permit->fresh()));
    }

    public function test_each_tenant_is_activated_in_its_own_context_and_no_other(): void
    {
        $mine = $this->throughFinalVerification($this->submit('+1 hour'));

        // Another organisation with its own flow permit, created directly.
        [$otherTenant, $otherCompany] = $this->organisation('Lain');
        $foreign = PermitToWork::create([
            'ptw_number' => 'PTW-LAIN-1', 'company_id' => $otherCompany->id, 'permit_type' => 'hot_work',
            'work_description' => 'Asing.', 'start_datetime' => now()->addHour(), 'end_datetime' => now()->addHours(6),
            'requested_by' => $this->requester->id, 'status' => PermitToWork::STATUS_APPROVED, 'hse_verifier_id' => $this->hse->id,
        ]);
        DB::table('approvals')->insert([
            'approvable_type' => $foreign->getMorphClass(), 'approvable_id' => $foreign->id, 'status' => 'approved',
            'requested_by' => $this->requester->id, 'step_number' => 1, 'is_escalated' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A suspended organisation is not visited.
        DB::table('tenants')->where('id', $otherTenant->id)->update(['status' => Tenant::STATUS_SUSPENDED]);

        app(CurrentTenant::class)->set(null);   // exactly how the scheduler runs
        $this->travel(2)->hours();
        $this->artisan('ptw:activate-due')->assertSuccessful();

        $this->assertSame(PermitToWork::STATUS_ACTIVE, PermitToWork::withoutGlobalScopes()->find($mine->id)->status);
        $this->assertSame(PermitToWork::STATUS_APPROVED, PermitToWork::withoutGlobalScopes()->find($foreign->id)->status);

        DB::table('tenants')->where('id', $otherTenant->id)->update(['status' => Tenant::STATUS_ACTIVE]);
        $this->artisan('ptw:activate-due')->assertSuccessful();
        $this->assertSame(PermitToWork::STATUS_ACTIVE, PermitToWork::withoutGlobalScopes()->find($foreign->id)->status);
        $this->assertSame(null, app(CurrentTenant::class)->get(), 'The scheduler leaves no tenant behind.');
    }

    public function test_the_permit_page_says_when_it_will_become_active(): void
    {
        $permit = $this->throughFinalVerification($this->submit('+2 hours'));

        $flow = $this->actingAs($this->requester)->get(route('permits-to-work.show', $permit))->assertOk()
            ->viewData('page')['props']['approvalFlow'];

        $this->assertSame('scheduled', $flow['activation']['state']);
        $this->assertFalse($this->actingAs($this->hse)->get(route('permits-to-work.show', $permit))->viewData('page')['props']['workflow']['can_activate']);
    }
}
