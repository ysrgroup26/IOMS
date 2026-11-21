<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSite;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\EmployeeRoster;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveRequest;
use App\Models\ManHourLog;
use App\Models\Package;
use App\Models\RosterPattern;
use App\Models\Shift;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AttendanceService;
use App\Support\CurrentTenant;
use Carbon\Carbon;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.146.0 -- Field Attendance (owner-approved design, 21 Nov 2026).
 *
 * The rules a payroll dispute or an audit would test: time is the
 * server's, lateness follows the shift, the location check, no double
 * check-in, leave blocks check-in, the crew roll call never overwrites a
 * worker's own check-in, HRD corrections need a reason, overtime reaches
 * Man-Hour only once approved, a manual Man-Hour row is never overwritten,
 * and the whole thing is People / HRD (Business) only.
 */
class FieldAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Shift $shift;

    private User $hrd;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        $this->boot('business');
        // 25 Nov 2026 06:55 WIB (Jakarta) -- before a 07:00 shift.
        $this->at('06:55');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function boot(string $plan): void
    {
        $package = Package::where('slug', $plan)->sole();
        $this->tenant = Tenant::create(['name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Yard A', 'code' => 'YA', 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);
        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Shift Pagi', 'code' => 'PG',
            'start_time' => '07:00', 'end_time' => '16:00', 'break_duration_minutes' => 60, 'is_active' => true,
        ]);
        $this->hrd = $this->user(User::ROLE_HRD);
    }

    private function at(string $hm, string $date = '2026-11-25'): void
    {
        Carbon::setTestNow(Carbon::parse("$date $hm", 'Asia/Jakarta')->utc());
    }

    private function user(string $role, string $type = User::TYPE_FULL, ?Employee $employee = null): User
    {
        $u = User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@g.test', 'password' => bcrypt('secret-pass-1'),
            'role' => $role, 'user_type' => $type, 'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id, 'is_active' => true,
        ]);
        if ($employee) {
            $u->forceFill(['employee_id' => $employee->id])->save();
        }

        return $u->fresh();
    }

    private function employee(string $name, bool $scheduled = true): Employee
    {
        $e = Employee::create(['employee_id' => 'EMP-'.uniqid(), 'full_name' => $name, 'company_id' => $this->company->id, 'status' => 'active']);
        if ($scheduled) {
            EmployeeShiftAssignment::create([
                'employee_id' => $e->id, 'shift_id' => $this->shift->id,
                'effective_date' => '2026-11-01', 'status' => 'active', 'created_by' => $this->hrd->id,
            ]);
        }

        return $e;
    }

    private function worker(string $name = 'Rudi'): array
    {
        $e = $this->employee($name);

        return [$e, $this->user(User::ROLE_HSE, User::TYPE_MY_WORK, $e)];
    }

    private function svc(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    // ------------------------------------------------------------------ self check-in

    public function test_check_in_on_time_then_late_and_no_double_check_in(): void
    {
        [$e, $u] = $this->worker();
        $this->actingAs($u)->post(route('my-attendance.check-in'))->assertSessionHasNoErrors();
        $r = AttendanceRecord::where('employee_id', $e->id)->sole();
        $this->assertSame('present', $r->status);
        $this->assertSame('self', $r->source);
        $this->assertSame($this->shift->id, $r->shift_id);

        $this->actingAs($u)->post(route('my-attendance.check-in'))->assertSessionHasErrors('attendance');
        $this->assertSame(1, AttendanceRecord::count());

        [$e2, $u2] = $this->worker('Bayu');
        $this->at('07:20');                                          // 20 minutes after 07:00 > 15-minute grace
        $this->actingAs($u2)->post(route('my-attendance.check-in'));
        $this->assertSame('late', AttendanceRecord::where('employee_id', $e2->id)->value('status'));
    }

    public function test_check_out_fills_man_hour_and_overtime_waits_for_approval(): void
    {
        [$e, $u] = $this->worker();
        $this->at('07:00');
        $this->actingAs($u)->post(route('my-attendance.check-in'));
        $this->at('18:00');                                          // 11h span - 1h break = 10h worked; shift 8h → 2h overtime
        $this->actingAs($u)->post(route('my-attendance.check-out'))->assertSessionHasNoErrors();

        $r = AttendanceRecord::where('employee_id', $e->id)->sole();
        $this->assertSame(600, $r->worked_minutes);
        $this->assertSame(120, $r->overtime_minutes);
        $this->assertSame('pending', $r->overtime_status);

        $log = ManHourLog::where('employee_id', $e->id)->sole();
        $this->assertEquals(8.0, (float) $log->regular_hours);
        $this->assertEquals(0.0, (float) $log->overtime_hours);      // not before approval
        $this->assertSame(AttendanceService::MAN_HOUR_NOTE, $log->notes);

        $this->actingAs($this->hrd)->post(route('attendance.overtime', $r), ['approve' => true])->assertSessionHasNoErrors();
        $this->assertEquals(2.0, (float) $log->fresh()->overtime_hours);
        $this->assertSame('approved', $r->fresh()->overtime_status);

        $this->actingAs($u)->post(route('my-attendance.check-out'))->assertSessionHasErrors('attendance');   // already out
    }

    public function test_break_is_deducted_only_after_four_hours(): void
    {
        [$e, $u] = $this->worker();
        $this->at('07:00');
        $this->actingAs($u)->post(route('my-attendance.check-in'));
        $this->at('10:00');                                          // 3 hours: no break taken, none deducted
        $this->actingAs($u)->post(route('my-attendance.check-out'));
        $this->assertSame(180, AttendanceRecord::where('employee_id', $e->id)->value('worked_minutes'));
    }

    public function test_crew_cannot_set_leave(): void
    {
        $e = $this->employee('Hasan');
        $this->actingAs($this->hrd)->post(route('attendance.crew.store'), ['work_date' => '2026-11-25', 'entries' => [$e->id => 'leave']])
            ->assertSessionHasErrors('entries.'.$e->id);
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_short_overrun_is_not_overtime(): void
    {
        [$e, $u] = $this->worker();
        $this->at('07:00');
        $this->actingAs($u)->post(route('my-attendance.check-in'));
        $this->at('16:20');                                          // 20 minutes over: below the 30-minute floor
        $this->actingAs($u)->post(route('my-attendance.check-out'));
        $r = AttendanceRecord::where('employee_id', $e->id)->sole();
        $this->assertSame(0, $r->overtime_minutes);
        $this->assertSame('none', $r->overtime_status);
    }

    public function test_check_out_without_check_in_is_refused(): void
    {
        [, $u] = $this->worker();
        $this->actingAs($u)->post(route('my-attendance.check-out'))->assertSessionHasErrors('attendance');
        $this->assertSame(0, AttendanceRecord::count());
    }

    // ------------------------------------------------------------------ location

    public function test_location_check_against_sites(): void
    {
        AttendanceSite::create(['company_id' => $this->company->id, 'name' => 'Yard A', 'latitude' => -1.2654, 'longitude' => 116.8312, 'radius_m' => 300]);
        [$e, $u] = $this->worker();

        $this->actingAs($u)->post(route('my-attendance.check-in'))->assertSessionHasErrors('attendance');                     // no location
        $this->actingAs($u)->post(route('my-attendance.check-in'), ['latitude' => -1.2854, 'longitude' => 116.8312])           // ~2.2 km away
            ->assertSessionHasErrors('attendance');
        $this->assertSame(0, AttendanceRecord::count());

        $this->actingAs($u)->post(route('my-attendance.check-in'), ['latitude' => -1.2660, 'longitude' => 116.8315])           // ~75 m
            ->assertSessionHasNoErrors();
        $r = AttendanceRecord::where('employee_id', $e->id)->sole();
        $this->assertNotNull($r->site_id);
        $this->assertLessThan(300, $r->distance_m);
    }

    public function test_location_rule_can_be_switched_off(): void
    {
        AttendanceSite::create(['company_id' => $this->company->id, 'name' => 'Yard A', 'latitude' => -1.2654, 'longitude' => 116.8312, 'radius_m' => 300]);
        CompanySetting::set(AttendanceService::SETTING_REQUIRE_LOCATION, '0');
        [$e, $u] = $this->worker();
        $this->actingAs($u)->post(route('my-attendance.check-in'))->assertSessionHasNoErrors();
        $this->assertSame(1, AttendanceRecord::where('employee_id', $e->id)->count());
    }

    public function test_approved_leave_blocks_check_in(): void
    {
        [$e, $u] = $this->worker();
        LeaveRequest::create([
            'leave_number' => 'LV-'.uniqid(), 'employee_id' => $e->id, 'company_id' => $this->company->id, 'leave_type' => 'annual',
            'start_date' => '2026-11-24', 'end_date' => '2026-11-26', 'days' => 3, 'status' => LeaveRequest::STATUS_APPROVED, 'requested_by' => $this->hrd->id,
        ]);
        $this->actingAs($u)->post(route('my-attendance.check-in'))->assertSessionHasErrors('attendance');
        $this->assertSame(0, AttendanceRecord::count());
    }

    // ------------------------------------------------------------------ schedule

    public function test_roster_off_day_is_not_scheduled(): void
    {
        $e = $this->employee('Candra', false);
        $pattern = RosterPattern::create(['company_id' => $this->company->id, 'name' => '2 on 1 off', 'code' => '21', 'days_on' => 2, 'days_off' => 1, 'is_active' => true]);
        EmployeeRoster::create([
            'employee_id' => $e->id, 'shift_id' => $this->shift->id, 'roster_pattern_id' => $pattern->id, 'site_name' => 'Dok 2',
            'start_date' => '2026-11-23', 'cycle_start_date' => '2026-11-23', 'status' => EmployeeRoster::STATUS_ACTIVE, 'created_by' => $this->hrd->id,
        ]);
        $ids = [$this->company->id];
        $this->assertTrue($this->svc()->scheduled($ids, Carbon::parse('2026-11-24'))->has($e->id));     // day 2: on
        $this->assertFalse($this->svc()->scheduled($ids, Carbon::parse('2026-11-25'))->has($e->id));    // day 3: off
        $this->assertSame('Dok 2', $this->svc()->scheduled($ids, Carbon::parse('2026-11-23'))[$e->id]['site_name']);
    }

    // ------------------------------------------------------------------ crew roll call

    public function test_crew_roll_call_marks_many_and_never_overwrites_a_self_check_in(): void
    {
        [$self, $u] = $this->worker('Andi');
        $this->actingAs($u)->post(route('my-attendance.check-in'));
        $a = $this->employee('Bayu');
        $b = $this->employee('Candra');
        $foreman = $this->user(User::ROLE_MANAGER);

        $this->actingAs($foreman)->post(route('attendance.crew.store'), [
            'work_date' => '2026-11-25',
            'entries' => [$self->id => 'absent', $a->id => 'present', $b->id => 'sick'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('present', AttendanceRecord::where('employee_id', $self->id)->value('status'));   // untouched
        $this->assertSame('self', AttendanceRecord::where('employee_id', $self->id)->value('source'));
        $this->assertSame('crew', AttendanceRecord::where('employee_id', $a->id)->value('source'));
        $this->assertEquals(8.0, (float) ManHourLog::where('employee_id', $a->id)->value('regular_hours'));
        $this->assertSame('sick', AttendanceRecord::where('employee_id', $b->id)->value('status'));
        $this->assertFalse(ManHourLog::where('employee_id', $b->id)->exists());
    }

    public function test_roll_call_never_overwrites_an_hrd_correction_and_is_logged(): void
    {
        $e = $this->employee('Fajar');
        $this->actingAs($this->hrd)->post(route('attendance.correct'), [
            'employee_id' => $e->id, 'work_date' => '2026-11-25', 'status' => 'sick', 'reason' => 'Surat dokter diterima pagi ini.',
        ]);
        $this->actingAs($this->user(User::ROLE_MANAGER))->post(route('attendance.crew.store'), ['work_date' => '2026-11-25', 'entries' => [$e->id => 'present']]);
        $this->assertSame('sick', AttendanceRecord::where('employee_id', $e->id)->value('status'));

        $g = $this->employee('Gilang');
        $this->actingAs($this->user(User::ROLE_MANAGER))->post(route('attendance.crew.store'), ['work_date' => '2026-11-25', 'entries' => [$g->id => 'present']]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'attendance.crew_roll_call']);
    }

    public function test_nobody_approves_their_own_overtime(): void
    {
        $e = $this->employee('Supervisor Hadi');
        $sup = $this->user(User::ROLE_MANAGER, User::TYPE_FULL, $e);
        $this->at('07:00');
        $this->actingAs($sup)->post(route('my-attendance.check-in'));
        $this->at('18:00');
        $this->actingAs($sup)->post(route('my-attendance.check-out'));
        $r = AttendanceRecord::where('employee_id', $e->id)->sole();
        $this->actingAs($sup)->post(route('attendance.overtime', $r), ['approve' => true])->assertSessionHasErrors('overtime');
        $this->assertSame('pending', $r->fresh()->overtime_status);
    }

    public function test_a_manual_man_hour_row_is_never_overwritten(): void
    {
        $e = $this->employee('Kontraktor');
        ManHourLog::create(['company_id' => $this->company->id, 'employee_id' => $e->id, 'work_date' => '2026-11-25',
            'regular_hours' => 6, 'overtime_hours' => 0, 'notes' => 'input manual HRD', 'recorded_by' => $this->hrd->id]);
        $this->actingAs($this->hrd)->post(route('attendance.crew.store'), ['work_date' => '2026-11-25', 'entries' => [$e->id => 'present']]);
        $log = ManHourLog::where('employee_id', $e->id)->sole();
        $this->assertEquals(6.0, (float) $log->regular_hours);
        $this->assertSame('input manual HRD', $log->notes);
    }

    public function test_only_crew_leads_take_roll_call(): void
    {
        $e = $this->employee('Bayu');
        $warehouse = $this->user(User::ROLE_WAREHOUSE);
        $this->actingAs($warehouse)->post(route('attendance.crew.store'), ['work_date' => '2026-11-25', 'entries' => [$e->id => 'present']])
            ->assertForbidden();
        $this->assertSame(0, AttendanceRecord::count());
    }

    // ------------------------------------------------------------------ HRD correction

    public function test_correction_needs_a_reason_and_is_logged(): void
    {
        $e = $this->employee('Dedi');
        $this->actingAs($this->hrd)->post(route('attendance.correct'), [
            'employee_id' => $e->id, 'work_date' => '2026-11-24', 'status' => 'present', 'check_in' => '07:05', 'check_out' => '16:00', 'reason' => 'lupa',
        ])->assertSessionHasErrors('reason');
        $this->assertSame(0, AttendanceRecord::count());

        $this->actingAs($this->hrd)->post(route('attendance.correct'), [
            'employee_id' => $e->id, 'work_date' => '2026-11-24', 'status' => 'present', 'check_in' => '07:05', 'check_out' => '16:00',
            'reason' => 'Lupa absen, dikonfirmasi foreman Regu Las B.',
        ])->assertSessionHasNoErrors();
        $r = AttendanceRecord::where('employee_id', $e->id)->sole();
        $this->assertSame(475, $r->worked_minutes);                  // 07:05-16:00 = 535 - 60 break
        $this->assertNotNull($r->corrected_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'attendance.corrected']);
        $this->assertEquals(7.92, (float) ManHourLog::where('employee_id', $e->id)->value('regular_hours'));

        $manager = $this->user(User::ROLE_MANAGER);
        $this->actingAs($manager)->post(route('attendance.correct'), [
            'employee_id' => $e->id, 'work_date' => '2026-11-24', 'status' => 'absent', 'reason' => 'Bukan HRD mencoba mengoreksi.',
        ])->assertForbidden();
    }

    public function test_correcting_to_absent_removes_the_hours_attendance_produced(): void
    {
        $e = $this->employee('Eko');
        $this->actingAs($this->hrd)->post(route('attendance.crew.store'), ['work_date' => '2026-11-25', 'entries' => [$e->id => 'present']]);
        $this->assertTrue(ManHourLog::where('employee_id', $e->id)->exists());
        $this->actingAs($this->hrd)->post(route('attendance.correct'), [
            'employee_id' => $e->id, 'work_date' => '2026-11-25', 'status' => 'absent', 'reason' => 'Ternyata tidak masuk, salah tandai.',
        ])->assertSessionHasNoErrors();
        $this->assertFalse(ManHourLog::where('employee_id', $e->id)->exists());
    }

    // ------------------------------------------------------------------ register & dashboard

    public function test_register_and_summary(): void
    {
        [$self, $u] = $this->worker('Andi');
        $this->at('07:00');
        $this->actingAs($u)->post(route('my-attendance.check-in'));
        $this->employee('Bayu');                                       // scheduled, nothing recorded
        $this->at('09:00');

        $s = $this->svc()->summary([$this->company->id], Carbon::parse('2026-11-25'));
        $this->assertSame(2, $s['scheduled']);
        $this->assertSame(1, $s['present']);
        $this->assertSame(1, $s['not_in']);
        $this->assertCount(14, $s['trend']);

        $this->actingAs($this->hrd)->get(route('attendance.index', ['date' => '2026-11-25']))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Attendance/Index')->has('rows', 2)->where('summary.present', 1));
        $this->actingAs($this->hrd)->get(route('hr.dashboard'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('workforceToday.scheduled', 2));
        $this->actingAs($u)->get(route('my-work'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('attendance.check_in', '07:00')->where('attendance.can_check_out', true));
    }

    // ------------------------------------------------------------------ boundaries

    public function test_a_my_work_account_reaches_check_in_but_not_the_register(): void
    {
        [, $u] = $this->worker();
        $this->actingAs($u)->get(route('attendance.index'))->assertForbidden();
        $this->actingAs($u)->post(route('attendance.crew.store'), ['work_date' => '2026-11-25', 'entries' => []])->assertForbidden();
        $this->actingAs($u)->post(route('my-attendance.check-in'))->assertSessionHasNoErrors();
    }

    public function test_attendance_is_business_only(): void
    {
        $this->boot('professional');
        [, $u] = $this->worker();
        $this->assertNotSame(200, $this->actingAs($this->hrd)->get(route('attendance.index'))->status());
        $this->actingAs($u)->post(route('my-attendance.check-in'));
        $this->assertSame(0, AttendanceRecord::count());
        $this->actingAs($u)->get(route('my-work'))->assertInertia(fn ($p) => $p->where('attendance', null));
    }
}
