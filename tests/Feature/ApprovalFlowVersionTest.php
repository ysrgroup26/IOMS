<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalFlowVersion;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MaterialRequest;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalEngine;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

/**
 * v2.141.0 -- an approval keeps the route it started on.
 *
 * Before this, the engine read a flow's steps live: editing a flow re-routed
 * requests already in it, removing it collapsed them onto the single-step
 * path, and an escalation added a permanent approver to the flow itself.
 */
class ApprovalFlowVersionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $requester;

    private User $manager;

    private User $hse;

    private User $hrd;

    private ApprovalEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->tenant = Tenant::create(['name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $this->yard = Company::withoutGlobalScopes()->create(['name' => 'Yard', 'code' => 'YD', 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        app(CurrentTenant::class)->set($this->tenant);

        $this->requester = $this->user(User::ROLE_WAREHOUSE);
        $this->manager = $this->user(User::ROLE_MANAGER);
        $this->hse = $this->user(User::ROLE_HSE);
        $this->hrd = $this->user(User::ROLE_HRD);
        $this->engine = app(ApprovalEngine::class);
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@g.test', 'password' => bcrypt('secret-pass-1'),
            'role' => $role, 'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    /** Manager, then HSE. */
    private function twoStepFlow(): ApprovalFlow
    {
        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'material_request', 'name' => 'Two steps', 'is_active' => true, 'priority' => 0]);
        $flow->steps()->create(['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager', 'escalate_after_hours' => 1, 'escalate_to_role' => 'hrd']);
        $flow->steps()->create(['step_number' => 2, 'mode' => 'single', 'approver_role' => 'hse']);

        return $flow;
    }

    private function request(): MaterialRequest
    {
        return MaterialRequest::create([
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'request_number' => 'MR-'.uniqid(),
            'requested_by' => $this->requester->id, 'status' => 'submitted', 'request_date' => now()->toDateString(),
        ]);
    }

    private function pending(MaterialRequest $request): Approval
    {
        return $request->approvals()->where('status', Approval::STATUS_PENDING)->sole();
    }

    private function replaceSteps(ApprovalFlow $flow, array $steps): void
    {
        $flow->steps()->get()->each->delete();
        foreach ($steps as $step) {
            $flow->steps()->create($step);
        }
    }

    public function test_a_request_is_pinned_to_the_published_version(): void
    {
        $flow = $this->twoStepFlow();
        $first = $this->request();
        $this->engine->start($first, $this->requester);
        $second = $this->request();
        $this->engine->start($second, $this->requester);

        $version = ApprovalFlowVersion::withoutGlobalScopes()->where('approval_flow_id', $flow->id)->sole();
        $this->assertSame(1, $version->version, 'An unchanged flow reuses its version.');
        $this->assertSame($version->id, $this->pending($first)->approval_flow_version_id);
        $this->assertSame($version->id, $this->pending($second)->approval_flow_version_id);
    }

    public function test_editing_the_flow_does_not_reroute_a_request_already_in_it(): void
    {
        $flow = $this->twoStepFlow();
        $inFlight = $this->request();
        $this->engine->start($inFlight, $this->requester);

        // The administrator now removes the HSE step and hands step 1 to HRD.
        $this->replaceSteps($flow, [['step_number' => 1, 'mode' => 'single', 'approver_role' => 'hrd']]);

        $this->assertFalse($this->engine->authorize($this->pending($inFlight), $this->hrd), 'The edit must not hand an in-flight request to somebody else.');
        $this->assertTrue($this->engine->authorize($this->pending($inFlight), $this->manager));

        $this->engine->decide($this->pending($inFlight), $this->manager, Approval::STATUS_APPROVED);

        $this->assertSame('submitted', $inFlight->fresh()->status, 'The removed step 2 still applies to the request that started with it.');
        $this->assertTrue($this->engine->authorize($this->pending($inFlight), $this->hse));

        // A request starting now takes the edited route.
        $fresh = $this->request();
        $this->engine->start($fresh, $this->requester);
        $this->assertTrue($this->engine->authorize($this->pending($fresh), $this->hrd));
        $this->assertSame(2, ApprovalFlowVersion::withoutGlobalScopes()->find($this->pending($fresh)->approval_flow_version_id)->version);
    }

    public function test_removing_the_flow_does_not_collapse_a_request_onto_a_single_approver(): void
    {
        $flow = $this->twoStepFlow();
        $inFlight = $this->request();
        $this->engine->start($inFlight, $this->requester);

        $flow->delete();

        $pending = $this->pending($inFlight);
        $this->assertNull($pending->approval_flow_id, 'The foreign key is nulled on delete...');
        $this->assertNotNull($pending->approval_flow_version_id, '...but the version still holds the route.');

        $this->engine->decide($pending, $this->manager, Approval::STATUS_APPROVED);
        $this->assertSame('submitted', $inFlight->fresh()->status, 'Step 2 still has to decide.');
        $this->assertFalse($this->engine->authorize($this->pending($inFlight), $this->manager));

        $this->engine->decide($this->pending($inFlight), $this->hse, Approval::STATUS_APPROVED);
        $this->assertSame('approved', $inFlight->fresh()->status);
    }

    public function test_an_escalation_widens_one_request_and_never_the_flow(): void
    {
        $flow = $this->twoStepFlow();
        $late = $this->request();
        $this->engine->start($late, $this->requester);
        DB::table('approvals')->where('id', $this->pending($late)->id)->update(['created_at' => now()->subHours(3)]);

        $this->assertSame(1, $this->engine->checkEscalations());

        $this->assertTrue($this->engine->authorize($this->pending($late), $this->hrd), 'The escalation role may decide the late request.');
        $this->assertSame(2, ApprovalFlowStep::where('approval_flow_id', $flow->id)->count(), 'The flow itself is unchanged.');

        $next = $this->request();
        $this->engine->start($next, $this->requester);
        $this->assertFalse($this->engine->authorize($this->pending($next), $this->hrd), 'A later request is not decidable by the escalation role.');
    }

    public function test_a_published_version_cannot_be_changed_or_removed(): void
    {
        $flow = $this->twoStepFlow();
        $version = ApprovalFlowVersion::currentFor($flow);

        $this->expectException(LogicException::class);
        $version->update(['steps' => []]);
    }

    public function test_a_request_started_before_versions_existed_still_reads_the_live_flow(): void
    {
        $flow = $this->twoStepFlow();
        $legacy = $this->request();
        DB::table('approvals')->insert([
            'approvable_type' => $legacy->getMorphClass(), 'approvable_id' => $legacy->id,
            'approval_flow_id' => $flow->id, 'step_number' => 1, 'status' => Approval::STATUS_PENDING,
            'requested_by' => $this->requester->id, 'is_escalated' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue($this->engine->authorize($this->pending($legacy), $this->manager));
        $this->assertFalse($this->engine->authorize($this->pending($legacy), $this->hse));
    }

    public function test_a_step_can_name_a_position_from_the_company_structure(): void
    {
        $position = Position::create(['name' => 'SPV Gudang', 'company_id' => $this->yard->id, 'is_active' => true]);
        $department = Department::create(['name' => 'Gudang', 'code' => 'GD-'.uniqid(), 'company_id' => $this->yard->id]);
        $holderEmployee = Employee::create([
            'employee_id' => 'EMP-P1', 'full_name' => 'Sari', 'company_id' => $this->yard->id,
            'department_id' => $department->id, 'position_id' => $position->id, 'status' => 'active', 'join_date' => '2026-01-15',
        ]);
        $holder = $this->user(User::ROLE_WAREHOUSE);
        $holder->forceFill(['employee_id' => $holderEmployee->id])->save();
        $sameRoleOther = $this->user(User::ROLE_WAREHOUSE);

        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'material_request', 'name' => 'By position', 'is_active' => true, 'priority' => 0]);
        $flow->steps()->create(['step_number' => 1, 'mode' => 'single', 'approver_position_id' => $position->id]);

        $request = $this->request();
        $this->engine->start($request, $this->requester);

        $this->assertTrue($this->engine->authorize($this->pending($request), $holder->fresh()));
        $this->assertFalse($this->engine->authorize($this->pending($request), $sameRoleOther), 'Holding the same ROLE is not holding the position.');
        $this->assertSame(1, DB::table('notifications')->where('user_id', $holder->id)->count(), 'The position holder is told.');
    }

    /**
     * P1 + P2 + P3 and the scheduler escalation fix, TOGETHER, on one
     * request -- the way they will meet in production.
     */
    public function test_escalation_edit_and_removal_together_keep_one_requests_route(): void
    {
        $flow = $this->twoStepFlow();      // manager (escalates to hrd after 1h), then hse
        $request = $this->request();
        $this->engine->start($request, $this->requester);
        DB::table('approvals')->where('id', $this->pending($request)->id)->update(['created_at' => now()->subHours(3)]);

        // The scheduler runs with no tenant (the production context).
        app(CurrentTenant::class)->set(null);
        $this->artisan('approvals:escalate')->assertSuccessful();
        app(CurrentTenant::class)->set($this->tenant);

        $pending = $this->pending($request);
        $this->assertTrue((bool) $pending->is_escalated, 'Escalation runs from the scheduler.');
        $this->assertSame('hrd', $pending->escalated_to_role);
        $this->assertSame(2, ApprovalFlowStep::where('approval_flow_id', $flow->id)->count(), 'P3: the flow is not modified.');

        // P1: the administrator re-routes the flow.
        $this->replaceSteps($flow, [['step_number' => 1, 'mode' => 'single', 'approver_role' => 'warehouse']]);
        $this->assertFalse($this->engine->authorize($this->pending($request), $this->requester), 'P1: the edit does not hand the request to the new role.');

        // P2: ...and removes the flow.
        $flow->delete();

        // The escalation still lets HRD decide stage 1 of THIS request.
        $this->engine->decide($this->pending($request), $this->hrd, Approval::STATUS_APPROVED);
        $this->assertSame('submitted', $request->fresh()->status, 'P2: stage 2 is still required.');
        $this->assertFalse($this->engine->authorize($this->pending($request), $this->manager));
        $this->assertFalse($this->engine->authorize($this->pending($request), $this->hrd), 'P3: the escalation does not carry to the next stage.');
        $this->engine->decide($this->pending($request), $this->hse, Approval::STATUS_APPROVED);
        $this->assertSame('approved', $request->fresh()->status);

        // A request on a new flow is not decidable by the earlier escalation role.
        $next = $this->twoStepFlow();
        $later = $this->request();
        $this->engine->start($later, $this->requester);
        $this->assertFalse($this->engine->authorize($this->pending($later), $this->hrd));
        $this->assertNotSame($next->id, $flow->id);
    }

    /** P4: on an "any one approver" stage the other rows close once one decides. */
    public function test_the_other_approvers_rows_close_when_an_any_one_stage_is_decided(): void
    {
        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'material_request', 'name' => 'Any', 'is_active' => true, 'priority' => 0]);
        $flow->steps()->create(['step_number' => 1, 'mode' => 'parallel_any', 'approver_role' => 'manager']);
        $flow->steps()->create(['step_number' => 1, 'mode' => 'parallel_any', 'approver_role' => 'hse']);
        $flow->steps()->create(['step_number' => 2, 'mode' => 'single', 'approver_role' => 'hrd']);

        $request = $this->request();
        $this->engine->start($request, $this->requester);
        [$forManager, $forHse] = $request->approvals()->orderBy('id')->get()->all();

        $this->engine->decide($forManager, $this->manager, Approval::STATUS_APPROVED);

        $this->assertSame(Approval::STATUS_SKIPPED, $forHse->fresh()->status, 'Nobody else is still "waiting" on a decided stage.');
        $this->assertSame(1, $request->approvals()->where('status', Approval::STATUS_PENDING)->count(), 'Only stage 2 waits.');
        $this->assertSame(2, $this->pending($request)->step_number);

        // The skipped row cannot be decided through the endpoint.
        $this->actingAs($this->hse)->post(route('approvals.approve', $forHse->id))->assertStatus(422);
        $this->assertSame(1, $request->approvals()->where('step_number', 2)->count(), 'Stage 2 was not opened twice.');
    }

    public function test_a_rejection_closes_the_rest_of_its_stage(): void
    {
        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'material_request', 'name' => 'All', 'is_active' => true, 'priority' => 0]);
        $flow->steps()->create(['step_number' => 1, 'mode' => 'parallel_all', 'approver_role' => 'manager']);
        $flow->steps()->create(['step_number' => 1, 'mode' => 'parallel_all', 'approver_role' => 'hse']);

        $request = $this->request();
        $this->engine->start($request, $this->requester);
        [$forManager, $forHse] = $request->approvals()->orderBy('id')->get()->all();

        $this->engine->decide($forManager, $this->manager, Approval::STATUS_REJECTED, 'Tidak perlu');

        $this->assertSame(Approval::STATUS_SKIPPED, $forHse->fresh()->status);
        $this->assertSame('rejected', $request->fresh()->status);
    }

    /** A MySQL JSON column re-orders keys; the same steps must not publish a new version. */
    public function test_an_unchanged_flow_reuses_its_version_whatever_the_stored_key_order(): void
    {
        $flow = $this->twoStepFlow();
        $version = ApprovalFlowVersion::currentFor($flow);
        $reordered = array_map(fn ($row) => array_reverse($row, true), $version->steps);
        DB::table('approval_flow_versions')->where('id', $version->id)->update(['steps' => json_encode($reordered)]);

        $this->assertSame($version->id, ApprovalFlowVersion::currentFor($flow->fresh())->id);
    }
}
