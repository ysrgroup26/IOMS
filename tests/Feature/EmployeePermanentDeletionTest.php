<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\DailyReport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Position;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\TbmMeeting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EmployeeEraser;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DELETING ONE EMPLOYEE, PERMANENTLY.
 *
 * The bulk path has its own two files. This one is about the single
 * `DELETE /employees/{employee}` action and the three things the owner's
 * decision made load-bearing:
 *
 *  1. IT COMPLETES. Three of the twenty-four foreign keys to `employees`
 *     were RESTRICT or NO ACTION -- `daily_reports.hse_officer_id`,
 *     `tbm_attendees.employee_id` and `leave_requests.employee_id`. A
 *     hard delete of anybody who had filed a daily report, attended a
 *     toolbox meeting or requested leave would simply throw. Every one of
 *     those is exercised below, because "it works on an employee with no
 *     history" is not the case that fails.
 *
 *  2. THE DOCUMENTS STILL READ. A Permit to Work prints its accountable
 *     person. An incident report prints the injured party. Those are the
 *     documents an insurer, a client auditor or a labour inspector asks
 *     for, and an HR action must not blank a line on one.
 *
 *  3. THE AUDIT TRAIL STILL ANSWERS. `activity_logs` has no foreign key
 *     to its subject, so the row outlives the record -- but `subject_id`
 *     then points at nothing, so the identity has to be IN the entry.
 */
class EmployeePermanentDeletionTest extends TestCase
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

        $this->tenant = Tenant::create([
            'name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->yard = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Utama', 'code' => 'YU', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->admin = User::create([
            'name' => 'Admin Utama', 'email' => 'admin-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);

        $this->department = Department::create(['company_id' => $this->yard->id, 'name' => 'Produksi']);
    }

    private function employee(string $number = 'EMP-1', string $name = 'Budi Santoso'): Employee
    {
        return Employee::create([
            'employee_id' => $number,
            'nik' => '320101010190'.substr(md5($number), 0, 4),
            'full_name' => $name,
            'company_id' => $this->yard->id,
            'department_id' => $this->department->id,
            'position_id' => Position::firstOrCreate(
                ['company_id' => $this->yard->id, 'name' => 'Welder'],
            )->id,
            'status' => 'active',
            'join_date' => '2024-03-01',
        ]);
    }

    /**
     * Named `removeThroughTheUi`, not `delete`: `delete()` is Laravel's
     * own HTTP helper on the base TestCase and shadowing it is a fatal
     * error, not a warning.
     */
    private function removeThroughTheUi(Employee $employee): TestResponse
    {
        return $this->actingAs($this->admin)->delete(route('employees.destroy', $employee->id));
    }

    // --- 1. it completes --------------------------------------------------

    public function test_an_employee_with_no_history_is_deleted_permanently(): void
    {
        $employee = $this->employee();

        $this->removeThroughTheUi($employee)
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }

    /**
     * The three keys that would have thrown. Each is created, the
     * employee is deleted, and the request must succeed -- on SQLite and
     * on MySQL alike, which is why the erasure is done in application
     * code rather than by changing twenty-four delete rules in a
     * migration SQLite cannot even apply.
     */
    public function test_a_daily_report_does_not_block_the_deletion_and_keeps_the_officers_name(): void
    {
        $employee = $this->employee();

        $project = Project::create([
            'company_id' => $this->yard->id, 'name' => 'Docking', 'status' => 'ongoing',
            'start_date' => now()->subMonth()->toDateString(),
        ]);

        $report = DailyReport::withoutGlobalScopes()->create([
            'project_id' => $project->id,
            'report_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        /*
         * Set through the query builder on purpose. `hse_officer_id` is
         * NOT in `DailyReport::$fillable` -- nothing has created a report
         * through that relation since v1.5.1 -- and `create()` drops a
         * non-fillable key SILENTLY, which is its own known pitfall. The
         * rows this protects are historical, so the fixture has to be
         * written the way history wrote it.
         */
        DB::table('daily_reports')->where('id', $report->id)->update(['hse_officer_id' => $employee->id]);

        $this->removeThroughTheUi($employee)->assertSessionHas('success');

        $report->refresh();

        $this->assertNull($report->hse_officer_id, 'The link must be cleared.');
        $this->assertSame('Budi Santoso', $report->hse_officer_name, 'The report must keep who filed it.');
        $this->assertDatabaseHas('daily_reports', ['id' => $report->id]);
    }

    public function test_toolbox_meeting_attendance_does_not_block_the_deletion(): void
    {
        $employee = $this->employee();

        $meeting = TbmMeeting::withoutGlobalScopes()->create([
            'tbm_number' => 'TBM-1', 'company_id' => $this->yard->id,
            'topic' => 'Bekerja di ketinggian', 'meeting_date' => now()->toDateString(),
            'conducted_by' => $this->admin->id,
        ]);

        $meeting->attendees()->attach($employee->id);

        $this->assertSame(1, DB::table('tbm_attendees')->count());

        $this->removeThroughTheUi($employee)->assertSessionHas('success');

        // The meeting itself -- the topic, the date, who gave it -- stays.
        // The attendance row goes, because attendance by somebody who no
        // longer exists is not a record of anything.
        $this->assertDatabaseHas('tbm_meetings', ['id' => $meeting->id]);
        $this->assertSame(0, DB::table('tbm_attendees')->count());
    }

    public function test_a_leave_request_does_not_block_the_deletion(): void
    {
        $employee = $this->employee();

        $leave = LeaveRequest::withoutGlobalScopes()->create([
            'leave_number' => 'LV-1',
            'employee_id' => $employee->id,
            'company_id' => $this->yard->id,
            'leave_type' => 'annual',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'days' => 3,
            'status' => 'pending',
            'requested_by' => $this->admin->id,
        ]);

        $this->removeThroughTheUi($employee)->assertSessionHas('success');

        $this->assertDatabaseMissing('leave_requests', ['id' => $leave->id]);
    }

    // --- 2. the documents still read --------------------------------------

    public function test_a_permit_to_work_keeps_its_accountable_person_as_text(): void
    {
        $employee = $this->employee();

        $permit = PermitToWork::withoutGlobalScopes()->create([
            'ptw_number' => 'PTW-1',
            'company_id' => $this->yard->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Pengelasan lambung.',
            'start_datetime' => now(),
            'end_datetime' => now()->addHours(8),
            'requested_by' => $this->admin->id,
            'pic_employee_id' => $employee->id,
            'status' => 'draft',
        ]);

        $this->removeThroughTheUi($employee)->assertSessionHas('success');

        $permit->refresh();

        $this->assertDatabaseHas('permits_to_work', ['id' => $permit->id]);
        $this->assertNull($permit->pic_employee_id);
        $this->assertSame('Budi Santoso', $permit->pic_name);
        $this->assertSame('Budi Santoso', $permit->picDisplayName());

        // And the document itself still prints it.
        $permit->load('company', 'project', 'requester', 'gasTests', 'pic', 'personnel');

        $html = view('pdf.permit-to-work', [
            'permit' => $permit,
            'company' => $this->yard,
            'documentTemplate' => null,
            'branding' => ['company_name' => 'Yard Utama', 'logo_url' => null, 'address' => null],
            'rejectionReason' => null,
        ])->render();

        $this->assertStringContainsString('Budi Santoso', $html, 'The permit PDF lost its Penanggung Jawab.');
    }

    /**
     * While the employee exists, the RELATION answers -- so a rename
     * still follows them everywhere and the stored text cannot drift.
     */
    public function test_a_live_pic_is_read_from_the_employee_record_not_the_tombstone(): void
    {
        $employee = $this->employee();

        $permit = PermitToWork::withoutGlobalScopes()->create([
            'ptw_number' => 'PTW-2', 'company_id' => $this->yard->id, 'permit_type' => 'hot_work',
            'work_description' => 'Kerja panas.', 'start_datetime' => now(), 'end_datetime' => now()->addHours(4),
            'requested_by' => $this->admin->id, 'pic_employee_id' => $employee->id, 'status' => 'draft',
        ]);

        $employee->update(['full_name' => 'Budi Santoso Wijaya']);

        $this->assertSame('Budi Santoso Wijaya', $permit->fresh()->load('pic')->picDisplayName());
    }

    /**
     * `pic_name` has to reach the client too, or the three React pages
     * that render the PIC lose the name while the PDF keeps it -- one
     * document disagreeing with itself, which is the exact failure
     * v2.112.0 was written up for.
     */
    public function test_the_permit_payload_carries_the_name_the_client_falls_back_to(): void
    {
        $employee = $this->employee();

        PermitToWork::withoutGlobalScopes()->create([
            'ptw_number' => 'PTW-3', 'company_id' => $this->yard->id, 'permit_type' => 'hot_work',
            'work_description' => 'Kerja panas.', 'start_datetime' => now(), 'end_datetime' => now()->addHours(4),
            'requested_by' => $this->admin->id, 'pic_employee_id' => $employee->id, 'status' => 'draft',
        ]);

        $this->removeThroughTheUi($employee);

        $props = $this->actingAs($this->admin)->get(route('permits-to-work.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $row = collect($props['permits']['data'])->firstWhere('ptw_number', 'PTW-3');

        $this->assertNotNull($row);
        $this->assertNull($row['pic'] ?? null);
        $this->assertSame('Budi Santoso', $row['pic_name'] ?? null);
    }

    public function test_an_incident_report_keeps_the_injured_persons_name(): void
    {
        $employee = $this->employee();

        $incident = Incident::withoutGlobalScopes()->create([
            'incident_number' => 'INC-1',
            'company_id' => $this->yard->id,
            'title' => 'Terjatuh dari perancah',
            'incident_date' => now()->toDateString(),
            'severity' => 'minor',
            'category' => 'fall',
            'reported_by' => $this->admin->id,
            'injured_employee_id' => $employee->id,
            // Deliberately NOT set: the server allows it to be blank, so
            // this is the case where the erasure has to fill the gap.
        ]);

        $this->removeThroughTheUi($employee)->assertSessionHas('success');

        $incident->refresh();

        $this->assertDatabaseHas('incidents', ['id' => $incident->id]);
        $this->assertNull($incident->injured_employee_id);
        $this->assertSame('Budi Santoso', $incident->injured_person_name);
    }

    /** A name a human typed is never overwritten by the erasure. */
    public function test_a_name_recorded_by_hand_is_not_replaced(): void
    {
        $employee = $this->employee();

        $incident = Incident::withoutGlobalScopes()->create([
            'incident_number' => 'INC-2', 'company_id' => $this->yard->id,
            'title' => 'Tangan tergores', 'incident_date' => now()->toDateString(),
            'severity' => 'minor', 'category' => 'other', 'reported_by' => $this->admin->id,
            'injured_employee_id' => $employee->id,
            'injured_person_name' => 'Budi S. (kontraktor PT Maju)',
        ]);

        $this->removeThroughTheUi($employee);

        $this->assertSame('Budi S. (kontraktor PT Maju)', $incident->fresh()->injured_person_name);
    }

    // --- 3. the audit trail still answers ---------------------------------

    public function test_the_audit_entry_names_who_was_deleted_after_the_record_is_gone(): void
    {
        $employee = $this->employee('EMP-77', 'Siti Aminah');

        $this->removeThroughTheUi($employee);

        $entry = ActivityLog::withoutGlobalScopes()
            ->where('action', 'deleted')
            ->where('subject_id', $employee->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertStringContainsString('Siti Aminah', $entry->description);
        $this->assertStringContainsString('EMP-77', $entry->description);
        $this->assertStringContainsString('permanently deleted', $entry->description);

        $snapshot = $entry->meta['employee'] ?? [];

        $this->assertSame('EMP-77', $snapshot['employee_id'] ?? null);
        $this->assertSame('Siti Aminah', $snapshot['full_name'] ?? null);
        $this->assertSame('Produksi', $snapshot['department'] ?? null);
        $this->assertSame('Welder', $snapshot['position'] ?? null);
        $this->assertSame('2024-03-01', $snapshot['join_date'] ?? null);

        // The record it describes is gone, which is exactly why the
        // snapshot has to be there.
        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }

    // --- the confirmation is told the truth -------------------------------

    /**
     * The dialog states what will be removed, and the numbers come from
     * the database rather than from a sentence the component made up. The
     * old copy promised that "Riwayat KPI, man-hour, PPE dan persetujuan
     * mereka tetap tersimpan", which a permanent deletion makes false.
     */
    public function test_the_profile_page_reports_what_a_deletion_would_remove(): void
    {
        $employee = $this->employee();

        $leave = LeaveRequest::withoutGlobalScopes()->create([
            'leave_number' => 'LV-9', 'employee_id' => $employee->id, 'company_id' => $this->yard->id,
            'leave_type' => 'annual', 'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(), 'days' => 2,
            'status' => 'pending', 'requested_by' => $this->admin->id,
        ]);

        $props = $this->actingAs($this->admin)->get(route('employees.show', $employee->id))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(1, $props['erasureImpact']['dependents']['leave_requests'] ?? null);
        $this->assertSame(1, $props['erasureImpact']['total_dependents']);

        // And it is a count, not a deletion.
        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id]);
    }

    // --- authorization and isolation --------------------------------------

    public function test_a_manager_may_not_delete_an_employee(): void
    {
        $employee = $this->employee();

        $manager = User::create([
            'name' => 'Manajer', 'email' => 'm-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_MANAGER,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->delete(route('employees.destroy', $employee->id))
            ->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }

    public function test_another_tenants_employee_cannot_be_deleted(): void
    {
        $other = Tenant::create(['name' => 'Tetangga', 'slug' => 't-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);

        $otherYard = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Tetangga', 'code' => 'YT', 'tenant_id' => $other->id, 'is_active' => true,
        ]);

        $theirs = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-X', 'full_name' => 'Orang Lain',
            'company_id' => $otherYard->id, 'status' => 'active',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('employees.destroy', $theirs->id))
            ->assertNotFound();

        $this->assertDatabaseHas('employees', ['id' => $theirs->id]);
    }

    // --- the engine, directly ---------------------------------------------

    /**
     * `impact()` must not change anything. It is called on every admin's
     * profile page view, so a version of it that wrote would be the
     * single worst bug this file could miss.
     */
    public function test_counting_the_impact_removes_nothing(): void
    {
        $employee = $this->employee();

        LeaveRequest::withoutGlobalScopes()->create([
            'leave_number' => 'LV-7', 'employee_id' => $employee->id, 'company_id' => $this->yard->id,
            'leave_type' => 'annual', 'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(), 'days' => 2,
            'status' => 'pending', 'requested_by' => $this->admin->id,
        ]);

        $eraser = app(EmployeeEraser::class);

        $first = $eraser->impact([$employee->id]);
        $second = $eraser->impact([$employee->id]);

        $this->assertSame($first, $second);
        $this->assertSame(1, $first['total_dependents']);
        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
        $this->assertSame(1, DB::table('leave_requests')->count());
    }
}
