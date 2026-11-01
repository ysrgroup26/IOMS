<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Position;
use App\Models\PtwWorkflowSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PtwWorkflowService;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.141.0 -- PTW on the company's own approval flow, with the owner's
 * separation-of-duties rules (A8b), exercised over several organisation
 * structures and over flow changes made while permits are under way.
 *
 *   Created -> Approval 1..N -> Final Verification -> Approved -> Active
 *
 * Every decision goes through the real endpoints (approvals.approve /
 * approvals.reject / permits-to-work.transition / settings), so what is
 * tested is what the server allows, not what a page offers.
 */
class PtwApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $admin;

    private Department $department;

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

        $this->admin = $this->user(User::ROLE_SUPER_ADMIN, 'Admin');
        $this->department = Department::create(['name' => 'Produksi', 'code' => 'PRD-'.uniqid(), 'company_id' => $this->yard->id]);
    }

    // ------------------------------------------------------------ helpers

    private function user(string $role, string $name, array $extra = []): User
    {
        return User::create(array_merge([
            'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => $role,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ], $extra));
    }

    private function holder(Position $position, string $name, string $role = User::ROLE_MANAGER): User
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-'.uniqid(), 'full_name' => $name, 'company_id' => $this->yard->id,
            'department_id' => $this->department->id, 'position_id' => $position->id, 'status' => 'active', 'join_date' => '2026-01-15',
        ]);

        $user = $this->user($role, $name);
        $user->forceFill(['employee_id' => $employee->id])->save();

        return $user;
    }

    private function flow(array $steps): ApprovalFlow
    {
        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'permit_to_work', 'name' => 'PTW Flow', 'is_active' => true, 'priority' => 0]);
        $this->actingAs($this->admin)->put(route('settings.approval-flows.steps', $flow->id), ['steps' => $steps])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('settings.ptw-workflow.mode'), ['company_id' => $this->yard->id, 'approval_mode' => 'flow'])->assertSessionHasNoErrors();

        return $flow->fresh();
    }

    private function submit(User $requester): PermitToWork
    {
        $this->actingAs($requester)->post(route('permits-to-work.store'), [
            'company_id' => $this->yard->id, 'permit_type' => 'hot_work', 'work_reference' => 'Overhaul',
            'location' => 'Dok 2', 'work_description' => 'Pengelasan.',
            'start_datetime' => now()->addHour()->format('Y-m-d\TH:i'),
            'end_datetime' => now()->addHours(6)->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        return PermitToWork::latest('id')->firstOrFail();
    }

    private function pending(PermitToWork $permit): Approval
    {
        return $permit->approvals()->where('status', Approval::STATUS_PENDING)->orderBy('id')->firstOrFail();
    }

    private function approve(User $user, PermitToWork $permit)
    {
        return $this->actingAs($user)->post(route('approvals.approve', $this->pending($permit)->id));
    }

    // -------------------------------------------- structure 1: role / role

    public function test_structure_manager_approves_then_hse_verifies(): void
    {
        $requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
        $manager = $this->user(User::ROLE_MANAGER, 'Manager');
        $hse = $this->user(User::ROLE_HSE, 'HSE Satu');
        $this->user(User::ROLE_HSE, 'HSE Dua');
        $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);

        $permit = $this->submit($requester);
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->status);
        $this->assertSame(1, $this->pending($permit)->step_number);

        $this->approve($hse, $permit)->assertForbidden();         // not stage 1's role
        $this->approve($manager, $permit)->assertRedirect();
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->fresh()->status, 'Approval alone never makes a permit approved.');

        $this->approve($manager, $permit)->assertForbidden();     // not the verifier's role, and approved earlier
        $this->approve($hse, $permit)->assertRedirect();

        $permit->refresh();
        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->status);
        $this->assertSame($hse->id, $permit->hse_verifier_id);
        $this->assertSame($manager->id, $permit->hse_approver_id);

        // Ready for activation; whoever decided a stage may activate.
        $this->actingAs($manager)->post(route('permits-to-work.transition', $permit), ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame(PermitToWork::STATUS_ACTIVE, $permit->fresh()->status);
    }

    // ----------------------------------- A8b rule 1: never the requester

    public function test_an_hse_requester_cannot_final_verify_their_own_permit(): void
    {
        $requesterHse = $this->user(User::ROLE_HSE, 'HSE Pemohon', ['ptw_access' => true]);
        $otherHse = $this->user(User::ROLE_HSE, 'HSE Lain');
        $manager = $this->user(User::ROLE_MANAGER, 'Manager');
        $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);

        $permit = $this->submit($requesterHse);
        $this->approve($manager, $permit);

        $this->approve($requesterHse, $permit)->assertForbidden();
        $waiting = $this->actingAs($requesterHse)->get(route('my-work'))->viewData('page')['props']['waitingOnMe'];
        $this->assertSame([], $waiting, 'The requester is not even offered the verification.');

        $this->approve($otherHse, $permit)->assertRedirect();
        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->fresh()->status);
    }

    // --------------------- A8b rule 2: never somebody who approved earlier

    public function test_an_earlier_approver_cannot_be_the_final_verifier(): void
    {
        $requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
        $hseA = $this->user(User::ROLE_HSE, 'HSE A');
        $hseB = $this->user(User::ROLE_HSE, 'HSE B');
        // HSE both approves (stage 1) and verifies (stage 2) -- allowed by
        // the company (rule 3), but never the same person on one permit.
        $this->flow([
            ['step_number' => 1, 'mode' => 'parallel_any', 'approver_role' => 'hse'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);

        $permit = $this->submit($requester);
        $this->approve($hseA, $permit)->assertRedirect();

        $this->approve($hseA, $permit)->assertForbidden();
        $this->approve($hseB, $permit)->assertRedirect();
        $this->assertSame($hseB->id, $permit->fresh()->hse_verifier_id);
    }

    public function test_a_super_admin_is_no_final_verifier_unless_the_flow_names_them(): void
    {
        $requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
        $this->user(User::ROLE_HSE, 'HSE A');
        $this->user(User::ROLE_HSE, 'HSE B');
        $this->user(User::ROLE_MANAGER, 'Manager');
        $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);

        $permit = $this->submit($requester);
        // The Super Admin override still decides an ordinary approval stage...
        $this->approve($this->admin, $permit)->assertRedirect();
        // ...but the Final Verification is decided only by whom the company named.
        $this->approve($this->admin, $permit)->assertForbidden();
    }

    // ------------------- structure 2: positions, parallel-all, PM verifies

    public function test_structure_project_manager_position_verifies_after_parallel_approvals(): void
    {
        $pm = Position::create(['name' => 'Project Manager', 'company_id' => $this->yard->id, 'is_active' => true]);
        $superintendent = Position::create(['name' => 'Superintendent', 'company_id' => $this->yard->id, 'is_active' => true]);
        $pmA = $this->holder($pm, 'PM Alfa');
        $pmB = $this->holder($pm, 'PM Bravo');
        $sup = $this->holder($superintendent, 'Superintendent');
        $hse = $this->user(User::ROLE_HSE, 'HSE');
        $requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);

        $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'hse'],
            ['step_number' => 2, 'mode' => 'parallel_all', 'approver_position_id' => $superintendent->id],
            ['step_number' => 2, 'mode' => 'parallel_all', 'approver_user_id' => $pmA->id],
            ['step_number' => 3, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_position_id' => $pm->id],
        ]);

        $permit = $this->submit($requester);
        $this->approve($hse, $permit)->assertRedirect();

        // Stage 2 needs BOTH.
        $stage2 = $permit->approvals()->where('status', 'pending')->orderBy('id')->get();
        $this->assertCount(2, $stage2);
        $this->actingAs($sup)->post(route('approvals.approve', $stage2[0]->id))->assertRedirect();
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->fresh()->status);
        $this->actingAs($pmA)->post(route('approvals.approve', $stage2[1]->id))->assertRedirect();

        // PM Alfa approved stage 2, so only PM Bravo may verify.
        $this->approve($pmA, $permit)->assertForbidden();
        $this->approve($pmB, $permit)->assertRedirect();
        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->fresh()->status);
    }

    // ------------------------------------- the configuration is refused

    public function test_a_flow_with_no_eligible_final_verifier_is_refused_with_the_reason(): void
    {
        $only = $this->user(User::ROLE_MANAGER, 'Satu Orang');
        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'permit_to_work', 'name' => 'Bad', 'is_active' => true, 'priority' => 0]);

        $response = $this->actingAs($this->admin)->put(route('settings.approval-flows.steps', $flow->id), ['steps' => [
            ['step_number' => 1, 'mode' => 'single', 'approver_user_id' => $only->id],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'single', 'approver_user_id' => $only->id],
        ]]);

        $response->assertSessionHasErrors('steps');
        $this->assertStringContainsString('Tidak ada Final Verifier yang memenuhi syarat', session('errors')->first('steps'));
        $this->assertSame(0, $flow->steps()->count(), 'Nothing is saved.');
    }

    public function test_a_single_verifier_who_can_also_request_permits_is_refused(): void
    {
        $this->user(User::ROLE_MANAGER, 'Manager');
        $soleHse = $this->user(User::ROLE_HSE, 'HSE Tunggal');   // HSE can create PTW
        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'permit_to_work', 'name' => 'Thin', 'is_active' => true, 'priority' => 0]);

        $this->actingAs($this->admin)->put(route('settings.approval-flows.steps', $flow->id), ['steps' => [
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'single', 'approver_user_id' => $soleHse->id],
        ]])->assertSessionHasErrors('steps');
        $this->assertStringContainsString('HSE Tunggal', session('errors')->first('steps'));
    }

    public function test_a_ptw_flow_must_end_with_final_verification_and_other_modules_cannot_use_it(): void
    {
        $this->user(User::ROLE_MANAGER, 'Manager');
        $ptw = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'permit_to_work', 'name' => 'No FV', 'is_active' => true, 'priority' => 0]);
        $this->actingAs($this->admin)->put(route('settings.approval-flows.steps', $ptw->id), ['steps' => [
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
        ]])->assertSessionHasErrors('steps');

        $mr = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'material_request', 'name' => 'MR', 'is_active' => true, 'priority' => 0]);
        $this->actingAs($this->admin)->put(route('settings.approval-flows.steps', $mr->id), ['steps' => [
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'single', 'approver_role' => 'hse'],
        ]])->assertSessionHasErrors('steps');

        // A gap in the numbering would finalise a request early -- refused.
        $this->actingAs($this->admin)->put(route('settings.approval-flows.steps', $mr->id), ['steps' => [
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 3, 'mode' => 'single', 'approver_role' => 'hse'],
        ]])->assertSessionHasErrors('steps');
    }

    public function test_a_unit_cannot_switch_to_the_flow_without_a_valid_ptw_flow(): void
    {
        $this->actingAs($this->admin)->post(route('settings.ptw-workflow.mode'), ['company_id' => $this->yard->id, 'approval_mode' => 'flow'])
            ->assertSessionHasErrors('approval_mode');
        $this->assertSame(PtwWorkflowService::MODE_LEGACY, app(PtwWorkflowService::class)->modeFor($this->yard->id));
    }

    // ---------------------------- submission is refused for THIS requester

    public function test_a_permit_nobody_could_verify_is_refused_before_it_is_created_or_charged(): void
    {
        $this->user(User::ROLE_MANAGER, 'Manager');
        $hseA = $this->user(User::ROLE_HSE, 'HSE A', ['ptw_access' => true]);
        $hseB = $this->user(User::ROLE_HSE, 'HSE B');
        $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);

        // HSE B leaves: HSE A is now the only verifier -- and the requester.
        $hseB->forceFill(['is_active' => false])->save();

        $this->actingAs($hseA)->post(route('permits-to-work.store'), [
            'company_id' => $this->yard->id, 'permit_type' => 'hot_work', 'work_reference' => 'Overhaul',
            'location' => 'Dok 2', 'work_description' => 'Pengelasan.',
            'start_datetime' => now()->addHour()->format('Y-m-d\TH:i'),
            'end_datetime' => now()->addHours(6)->format('Y-m-d\TH:i'),
        ])->assertRedirect()->assertSessionHas('flash.error');

        $this->assertSame(0, PermitToWork::count(), 'No permit, and no document spent.');
        $this->assertSame(0, \DB::table('ptw_quota_consumptions')->count());
    }

    // ------------------------- changes while permits are under way (P1/P2)

    public function test_editing_or_removing_the_flow_never_changes_a_permit_under_way(): void
    {
        $requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
        $manager = $this->user(User::ROLE_MANAGER, 'Manager');
        $hse = $this->user(User::ROLE_HSE, 'HSE');
        $this->user(User::ROLE_HSE, 'HSE Dua');
        $hrd = $this->user(User::ROLE_HRD, 'HRD');
        $flow = $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);

        $underWay = $this->submit($requester);

        // The administrator re-routes the flow: HRD approves, then Manager verifies.
        $this->actingAs($this->admin)->put(route('settings.approval-flows.steps', $flow->id), ['steps' => [
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'hrd'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'manager'],
        ]])->assertSessionHasNoErrors();
        $this->user(User::ROLE_MANAGER, 'Manager Dua');

        $this->approve($hrd, $underWay)->assertForbidden();
        $this->approve($manager, $underWay)->assertRedirect();

        // ...and then removes it altogether: the permit still needs its Final Verification.
        $this->actingAs($this->admin)->delete(route('settings.approval-flows.destroy', $flow->id))->assertSessionHasNoErrors();
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $underWay->fresh()->status);
        $this->approve($hse, $underWay)->assertRedirect();
        $this->assertSame(PermitToWork::STATUS_APPROVED, $underWay->fresh()->status);

        $progress = app(PtwWorkflowService::class)->flowProgress($underWay->fresh(), $hse);
        $this->assertSame(1, $progress['version']);
    }

    public function test_a_permit_started_on_the_new_version_takes_the_new_route(): void
    {
        $requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
        $this->user(User::ROLE_MANAGER, 'Manager');
        $this->user(User::ROLE_MANAGER, 'Manager Dua');
        $this->user(User::ROLE_HSE, 'HSE');
        $this->user(User::ROLE_HSE, 'HSE Dua');
        $hrd = $this->user(User::ROLE_HRD, 'HRD');
        $flow = $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);
        $this->actingAs($this->admin)->put(route('settings.approval-flows.steps', $flow->id), ['steps' => [
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'hrd'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'manager'],
        ]])->assertSessionHasNoErrors();

        $fresh = $this->submit($requester);
        $this->approve($hrd, $fresh)->assertRedirect();
        $this->assertSame(2, app(PtwWorkflowService::class)->flowProgress($fresh->fresh(), $hrd)['version']);
    }

    // ---------------------------------------- return and resubmission

    public function test_a_returned_permit_is_resubmitted_on_a_new_run_with_the_same_rules(): void
    {
        $requester = $this->user(User::ROLE_HSE, 'HSE Pemohon', ['ptw_access' => true]);
        $manager = $this->user(User::ROLE_MANAGER, 'Manager');
        $hse = $this->user(User::ROLE_HSE, 'HSE');
        $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);

        $permit = $this->submit($requester);
        $this->approve($manager, $permit);
        $this->actingAs($hse)->post(route('approvals.reject', $this->pending($permit)->id), ['comments' => 'APD kurang'])->assertRedirect();
        $this->assertSame(PermitToWork::STATUS_REJECTED, $permit->fresh()->status);

        $this->actingAs($requester)->post(route('permits-to-work.transition', $permit), ['status' => 'draft'])->assertSessionHasNoErrors();
        $this->actingAs($requester)->post(route('permits-to-work.transition', $permit), ['status' => 'submitted'])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->pending($permit)->step_number, 'A new run starts at stage 1.');
        $this->approve($manager, $permit)->assertRedirect();
        $this->approve($requester, $permit)->assertForbidden();
        $this->approve($hse, $permit)->assertRedirect();
        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->fresh()->status);
    }

    // ------------------------------------- the legacy route is untouched

    public function test_a_unit_on_the_standard_route_keeps_the_verify_then_approve_gates(): void
    {
        $requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
        $hse = $this->user(User::ROLE_HSE, 'HSE');
        $manager = $this->user(User::ROLE_MANAGER, 'Manager');

        $permit = $this->submit($requester);

        $this->assertSame(0, $permit->approvals()->count(), 'No flow rows on the standard route.');
        $this->actingAs($hse)->post(route('permits-to-work.transition', $permit), ['status' => 'verified'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('permits-to-work.transition', $permit), ['status' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->fresh()->status);
    }

    public function test_the_gate_endpoint_cannot_bypass_a_permit_on_the_flow(): void
    {
        $requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
        $this->user(User::ROLE_MANAGER, 'Manager');
        $hse = $this->user(User::ROLE_HSE, 'HSE');
        $this->user(User::ROLE_HSE, 'HSE Dua');
        $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);
        $permit = $this->submit($requester);

        $this->actingAs($hse)->post(route('permits-to-work.transition', $permit), ['status' => 'verified'])->assertSessionHas('flash.error');
        $this->actingAs($this->admin)->post(route('permits-to-work.transition', $permit), ['status' => 'approved'])->assertSessionHas('flash.error');
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->fresh()->status);
    }

    public function test_switching_back_to_standard_lets_a_permit_on_the_flow_finish_its_flow(): void
    {
        $requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
        $manager = $this->user(User::ROLE_MANAGER, 'Manager');
        $hse = $this->user(User::ROLE_HSE, 'HSE');
        $this->user(User::ROLE_HSE, 'HSE Dua');
        $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);
        $onFlow = $this->submit($requester);

        $this->actingAs($this->admin)->post(route('settings.ptw-workflow.mode'), ['company_id' => $this->yard->id, 'approval_mode' => 'legacy'])->assertSessionHasNoErrors();
        $this->assertSame('legacy', PtwWorkflowSetting::where('company_id', $this->yard->id)->value('approval_mode'));

        $this->approve($manager, $onFlow)->assertRedirect();
        $this->approve($hse, $onFlow)->assertRedirect();
        $this->assertSame(PermitToWork::STATUS_APPROVED, $onFlow->fresh()->status);

        $standard = $this->submit($requester);
        $this->assertSame(0, $standard->approvals()->count());
    }

    public function test_my_work_lists_a_final_verification_once_however_many_rows_name_the_verifier(): void
    {
        $pm = Position::create(['name' => 'Project Manager', 'company_id' => $this->yard->id, 'is_active' => true]);
        $this->holder($pm, 'PM Alfa');
        $pmHse = $this->holder($pm, 'PM HSE', User::ROLE_HSE);   // matches BOTH rows
        $manager = $this->user(User::ROLE_MANAGER, 'Manager');
        $requester = $this->user(User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
        $this->flow([
            ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager'],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_position_id' => $pm->id],
            ['step_number' => 2, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => 'hse'],
        ]);

        $permit = $this->submit($requester);
        $this->approve($manager, $permit);

        $waiting = $this->actingAs($pmHse)->get(route('my-work'))->viewData('page')['props']['waitingOnMe'];
        $this->assertCount(1, $waiting);
        $this->assertSame('final_verification', $waiting[0]['kind']);
        $this->assertSame($permit->ptw_number, $waiting[0]['number']);
    }
}
