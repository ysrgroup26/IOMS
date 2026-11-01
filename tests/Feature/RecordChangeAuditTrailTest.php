<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\PermitToWork;
use App\Models\RecordChange;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

/**
 * v2.141.0 -- A13: the append-only audit trail (record_changes).
 */
class RecordChangeAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $hse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $this->yard = Company::withoutGlobalScopes()->create(['name' => 'Yard', 'code' => 'YD', 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        app(CurrentTenant::class)->set($this->tenant);
        $this->hse = User::create([
            'name' => 'HSE Lead', 'email' => 'h-'.uniqid().'@g.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_HSE, 'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    private function permit(): PermitToWork
    {
        return PermitToWork::create([
            'ptw_number' => 'PTW-'.uniqid(), 'company_id' => $this->yard->id, 'permit_type' => 'hot_work',
            'work_description' => 'Pengelasan.', 'start_datetime' => now(), 'end_datetime' => now()->addHours(4),
            'requested_by' => $this->hse->id, 'status' => PermitToWork::STATUS_DRAFT,
        ]);
    }

    public function test_a_change_is_recorded_with_its_value_before_and_after_and_who_made_it(): void
    {
        $this->actingAs($this->hse);
        $permit = $this->permit();
        $permit->update(['location' => 'Dok 2']);
        $permit->update(['location' => 'Dok 3']);

        $last = RecordChange::query()->for($permit)->latest('id')->first();

        $this->assertSame(RecordChange::EVENT_UPDATED, $last->event);
        $this->assertSame(['Dok 2', 'Dok 3'], $last->diff['location']);
        $this->assertArrayNotHasKey('updated_at', $last->diff);
        $this->assertSame($this->hse->id, $last->actor_id);
        $this->assertSame($this->tenant->id, $last->tenant_id);
        $this->assertSame(3, RecordChange::query()->for($permit)->count(), 'created + two updates');
    }

    public function test_a_permit_transition_records_the_states_and_a_snapshot(): void
    {
        $this->actingAs($this->hse);
        $permit = $this->permit();
        $permit->transitionTo(PermitToWork::STATUS_SUBMITTED, $this->hse);

        $row = RecordChange::query()->for($permit)->latest('id')->first();

        $this->assertSame(PermitToWork::STATUS_DRAFT, $row->from_status);
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $row->to_status);
        $this->assertSame($permit->ptw_number, $row->snapshot['ptw_number']);
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $row->snapshot['status']);
    }

    public function test_an_unchanged_save_writes_nothing(): void
    {
        $permit = $this->permit();
        $before = RecordChange::count();
        $permit->save();

        $this->assertSame($before, RecordChange::count());
    }

    public function test_a_record_change_cannot_be_updated_or_deleted(): void
    {
        $permit = $this->permit();
        $row = RecordChange::query()->for($permit)->first();

        try {
            $row->update(['event' => 'tampered']);
            $this->fail('An audit row was updated.');
        } catch (LogicException) {
        }

        try {
            $row->delete();
            $this->fail('An audit row was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame(RecordChange::EVENT_CREATED, $row->fresh()->event);
    }

    public function test_no_route_writes_to_the_audit_trail(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uses = $route->getActionName();
            $this->assertStringNotContainsStringIgnoringCase('RecordChange', $uses, "Route {$route->uri()} reaches the audit trail.");
        }
    }

    public function test_secrets_are_never_stored(): void
    {
        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'material_request', 'name' => 'Flow', 'is_active' => true, 'priority' => 0]);
        $row = RecordChange::query()->for($flow)->first();
        $this->assertSame(['', 'Flow'], [(string) $row->diff['name'][0], $row->diff['name'][1]]);

        $this->assertTrue((fn () => $this->isRedactedField('api_token'))->call($flow));
        $this->assertTrue((fn () => $this->isRedactedField('password'))->call($flow));
        $this->assertFalse((fn () => $this->isRedactedField('pic_employee_id'))->call($flow));
    }

    public function test_another_tenant_cannot_read_the_trail(): void
    {
        $permit = $this->permit();
        $this->assertGreaterThan(0, RecordChange::query()->for($permit)->count());

        $other = Tenant::create(['name' => 'Lain', 'slug' => 'l-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        app(CurrentTenant::class)->set($other);
        $this->assertSame(0, RecordChange::query()->for($permit)->count());

        app(CurrentTenant::class)->set(null);
        $this->assertSame(0, RecordChange::count(), 'No tenant, no rows: the scope fails closed.');
    }

    public function test_an_approval_flow_step_is_recorded_under_its_flows_tenant(): void
    {
        $flow = ApprovalFlow::create(['tenant_id' => $this->tenant->id, 'company_id' => null, 'module_key' => 'material_request', 'name' => 'Flow', 'is_active' => true, 'priority' => 0]);
        $step = $flow->steps()->create(['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager']);
        $step->update(['approver_role' => 'hse']);

        $row = RecordChange::query()->for($step)->latest('id')->first();
        $this->assertSame(['manager', 'hse'], $row->diff['approver_role']);
        $this->assertSame($this->tenant->id, $row->tenant_id);
    }

    public function test_the_history_reads_newest_first_with_readable_fields(): void
    {
        $this->actingAs($this->hse);
        $permit = $this->permit();
        $permit->update(['location' => 'Dok 2']);
        $permit->transitionTo(PermitToWork::STATUS_SUBMITTED, $this->hse);

        $history = RecordChange::historyFor($permit);

        $this->assertSame(['updated', 'updated', 'created'], array_column($history, 'event'));
        $this->assertSame([PermitToWork::STATUS_DRAFT, PermitToWork::STATUS_SUBMITTED], [$history[0]['from_status'], $history[0]['to_status']]);
        $this->assertSame([['field' => 'Location', 'before' => null, 'after' => 'Dok 2']], $history[1]['fields']);
        $this->assertSame('HSE Lead', $history[1]['actor']);
    }
}
