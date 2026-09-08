<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Company;
use App\Models\DailyReport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\KpiCategory;
use App\Models\KpiRecord;
use App\Models\MaterialRequest;
use App\Models\Milestone;
use App\Models\Position;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalEngine;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * v2.63.0 -- THE GAP v2.62.0 LEFT, AND HOW IT WAS FOUND.
 *
 * v2.62.0 made ownership structural for every table carrying a
 * `company_id`, and asserted that with a coverage test. The coverage test
 * only looked at models WITH a `company_id`, so it passed vacuously for
 * the tables that have none — and those tables are owned too, one join
 * away.
 *
 * The follow-up audit found three live bypasses in that blind spot:
 *
 *  1. `kpi_records` has an `employee_id`, not a `company_id`, so it got no
 *     scope. `KpiInputController::create()` ran
 *     `KpiRecord::latest()->limit(10)->get()` and returned the ten most
 *     recent KPI records IN THE INSTALLATION — 426 foreign rows were
 *     readable in the development database. Same shape as the original
 *     incident, one join away.
 *
 *  2. `daily_reports` is owned through its project. `DailyReportPolicy`
 *     checked only a role capability (`canManageDailyReports()`) and
 *     `show()` had no guard at all, so `GET /daily-reports/{id}` returned
 *     any tenant's report and `PUT` could modify it — a cross-tenant
 *     WRITE.
 *
 *  3. `approvals` has no owner column and is route-bound by id.
 *     `ApprovalEngine::authorize()` asked only "does this person hold the
 *     deciding role", never "whose record is this", so a Super Admin of
 *     one tenant could approve or reject another tenant's material
 *     request, purchase order or permit.
 *
 * The fix is `BelongsToCompanyThrough` (ownership resolved through the
 * relation the model already declares) plus a tenancy check in the
 * approval engine. These tests pin all three, and the coverage test at
 * the bottom is the one that would have caught them the first time.
 */
class TransitiveTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private User $adminA;

    private Employee $employeeA;

    private Project $projectA;

    private Company $companyB;

    private User $adminB;

    private Employee $employeeB;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->companyA, $this->adminA, $this->employeeA, $this->projectA] = $this->tenant('Alpha', 'ALPHA');
        [$this->companyB, $this->adminB, $this->employeeB, $this->projectB] = $this->tenant('Beta', 'BETA');
    }

    /** @return array{0: Company, 1: User, 2: Employee, 3: Project} */
    private function tenant(string $name, string $code): array
    {
        $tenant = Tenant::create([
            'name' => $name, 'slug' => strtolower($code).'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'code' => $code, 'is_active' => true,
        ]);

        $admin = User::create([
            'name' => "$name Admin",
            'email' => strtolower($code).'-'.uniqid().'@example.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $department = Department::create(['company_id' => $company->id, 'name' => "$name Ops", 'is_active' => true]);
        $position = Position::create([
            'company_id' => $company->id, 'department_id' => $department->id,
            'name' => "$name Fitter", 'is_active' => true,
        ]);

        $employee = Employee::create([
            'company_id' => $company->id, 'department_id' => $department->id, 'position_id' => $position->id,
            'employee_id' => "$code-0001", 'full_name' => "$name Worker", 'status' => 'active',
        ]);

        $project = Project::create([
            'company_id' => $company->id, 'name' => "$name Drydocking", 'status' => 'ongoing',
        ]);

        return [$company, $admin, $employee, $project];
    }

    private function asTenantOf(User $user): void
    {
        $this->be($user);
        app(CurrentTenant::class)->set($user->tenant);
    }

    private function kpiRecordFor(Employee $employee, User $creator): KpiRecord
    {
        $category = KpiCategory::firstOrCreate(
            ['code' => 'lti'],
            ['name' => 'Lost Time Injury', 'short_label' => 'LTI', 'is_active' => true]
        );

        return KpiRecord::withoutGlobalScopes()->create([
            'employee_id' => $employee->id,
            'department_id' => $employee->department_id,
            'kpi_category_id' => $category->id,
            'record_date' => now()->toDateString(),
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'quantity' => 1,
            'remarks' => "{$employee->full_name} confidential incident note",
            'created_by' => $creator->id,
        ]);
    }

    /* ================================================================
     * 1. KPI RECORDS -- the live leak, with real rows behind it
     * ================================================================ */

    public function test_kpi_records_do_not_cross_tenants(): void
    {
        $foreign = $this->kpiRecordFor($this->employeeB, $this->adminB);
        $own = $this->kpiRecordFor($this->employeeA, $this->adminA);

        $this->asTenantOf($this->adminA);

        $this->assertSame([$own->id], KpiRecord::pluck('id')->all());
        $this->assertNull(KpiRecord::find($foreign->id), 'KpiRecord::find() reached another tenant\'s record.');
    }

    /** The exact statement KpiInputController::create() shipped. */
    public function test_the_kpi_input_recent_list_cannot_show_another_tenants_records(): void
    {
        $foreign = $this->kpiRecordFor($this->employeeB, $this->adminB);

        $this->asTenantOf($this->adminA);

        $recent = KpiRecord::with('employee', 'kpiCategory', 'department')
            ->latest('id')
            ->limit(10)
            ->get();

        $this->assertNotContains($foreign->id, $recent->pluck('id'));
        $this->assertStringNotContainsString(
            'confidential incident note',
            $recent->toJson(),
            'A foreign KPI record\'s free-text remarks reached another tenant.'
        );
    }

    /** Aggregates are the same query layer, so they are closed by the same fix. */
    public function test_kpi_aggregates_cannot_cross_tenants(): void
    {
        $this->kpiRecordFor($this->employeeB, $this->adminB);
        $this->kpiRecordFor($this->employeeB, $this->adminB);
        $this->kpiRecordFor($this->employeeA, $this->adminA);

        $this->asTenantOf($this->adminA);

        $this->assertSame(1, KpiRecord::count(), 'A count() aggregated another tenant\'s rows.');
        $this->assertEquals(1, KpiRecord::sum('quantity'), 'A sum() aggregated another tenant\'s rows.');
    }

    /** Both directions -- the owning tenant must still see everything of its own. */
    public function test_each_tenant_still_sees_its_own_kpi_records(): void
    {
        $ownA = $this->kpiRecordFor($this->employeeA, $this->adminA);
        $ownB = $this->kpiRecordFor($this->employeeB, $this->adminB);

        $this->asTenantOf($this->adminA);
        $this->assertSame([$ownA->id], KpiRecord::pluck('id')->all());

        $this->asTenantOf($this->adminB);
        $this->assertSame([$ownB->id], KpiRecord::pluck('id')->all());
    }

    /**
     * Soft deletion is not an ownership question. Scoping through the
     * employee must not quietly erase the history of a departed one --
     * that would change historical HSE totals as a side effect of a
     * security fix.
     */
    public function test_a_soft_deleted_employees_kpi_history_is_still_visible_to_its_own_tenant(): void
    {
        $record = $this->kpiRecordFor($this->employeeA, $this->adminA);
        $this->employeeA->delete();

        $this->asTenantOf($this->adminA);

        $this->assertSame([$record->id], KpiRecord::pluck('id')->all());

        $this->asTenantOf($this->adminB);
        $this->assertCount(0, KpiRecord::all(), 'A soft-deleted employee\'s records became visible to another tenant.');
    }

    /* ================================================================
     * 2. DAILY REPORTS -- a cross-tenant READ and WRITE
     * ================================================================ */

    public function test_daily_reports_do_not_cross_tenants(): void
    {
        $foreign = DailyReport::withoutGlobalScopes()->create([
            'project_id' => $this->projectB->id,
            'report_date' => now()->toDateString(),
            'created_by' => $this->adminB->id,
        ]);

        $this->asTenantOf($this->adminA);

        $this->assertNull(DailyReport::find($foreign->id));
        $this->assertCount(0, DailyReport::all());
    }

    /**
     * The route binds by id before any controller code runs, and
     * DailyReportPolicy checked only a role. The 404 now comes from the
     * binding itself, which is the layer that cannot be forgotten.
     */
    public function test_a_foreign_daily_report_cannot_be_read_or_written_over_http(): void
    {
        $foreign = DailyReport::withoutGlobalScopes()->create([
            'project_id' => $this->projectB->id,
            'report_date' => now()->toDateString(),
            'created_by' => $this->adminB->id,
        ]);

        $this->be($this->adminA);

        $this->get("/daily-reports/{$foreign->id}")->assertNotFound();

        $this->put("/daily-reports/{$foreign->id}", [
            'project_id' => $this->projectA->id,
            'report_date' => now()->toDateString(),
            'report_type' => 'progress',
        ])->assertNotFound();

        $this->assertSame(
            $this->projectB->id,
            DailyReport::withoutGlobalScopes()->find($foreign->id)->project_id,
            'A foreign daily report was modified.'
        );
    }

    /* ================================================================
     * 3. APPROVALS -- a cross-tenant WRITE, reachable by guessing an int
     * ================================================================ */

    public function test_a_super_admin_cannot_decide_another_tenants_approval(): void
    {
        $foreignRequest = MaterialRequest::withoutGlobalScopes()->create([
            'company_id' => $this->companyB->id,
            'request_number' => 'MR-TEST-0001',
            'status' => 'submitted',
            'requested_by' => $this->adminB->id,
            'request_date' => now()->toDateString(),
        ]);

        $approval = Approval::create([
            'approvable_type' => MaterialRequest::class,
            'approvable_id' => $foreignRequest->id,
            'status' => Approval::STATUS_PENDING,
            'step_number' => 1,
            'requested_by' => $this->adminB->id,
        ]);

        $this->asTenantOf($this->adminA);

        $this->assertFalse(
            app(ApprovalEngine::class)->authorize($approval, $this->adminA),
            'A Super Admin of another tenant was authorized to decide this approval.'
        );

        $this->post("/approvals/{$approval->id}/approve", ['comments' => 'ok'])->assertForbidden();

        $this->assertSame(
            Approval::STATUS_PENDING,
            Approval::withoutGlobalScopes()->find($approval->id)->status,
            'A foreign approval was decided.'
        );
    }

    /** And the owning tenant's Super Admin still can. */
    public function test_a_super_admin_can_still_decide_their_own_tenants_approval(): void
    {
        $ownRequest = MaterialRequest::withoutGlobalScopes()->create([
            'company_id' => $this->companyA->id,
            'request_number' => 'MR-TEST-0002',
            'status' => 'submitted',
            'requested_by' => $this->adminA->id,
            'request_date' => now()->toDateString(),
        ]);

        $approval = Approval::create([
            'approvable_type' => MaterialRequest::class,
            'approvable_id' => $ownRequest->id,
            'status' => Approval::STATUS_PENDING,
            'step_number' => 1,
            'requested_by' => $this->adminB->id,
        ]);

        $this->asTenantOf($this->adminA);

        $this->assertTrue(app(ApprovalEngine::class)->authorize($approval, $this->adminA));
    }

    /* ================================================================
     * 4. A SPREAD OF THE OTHER TRANSITIVELY-OWNED TABLES
     * ================================================================ */

    public function test_project_children_do_not_cross_tenants(): void
    {
        $foreignMilestone = Milestone::withoutGlobalScopes()->create([
            'project_id' => $this->projectB->id,
            'title' => 'Beta hull blasting',
            'target_date' => now()->addMonth()->toDateString(),
            'created_by' => $this->adminB->id,
            'status' => 'pending',
        ]);

        $this->asTenantOf($this->adminA);

        $this->assertNull(Milestone::find($foreignMilestone->id));
        $this->assertCount(0, Milestone::all());
    }

    /* ================================================================
     * 5. THE COVERAGE TEST THAT WOULD HAVE CAUGHT ALL OF THIS
     * ================================================================ */

    /**
     * Every model is either isolated or explicitly recorded as global
     * reference data. v2.62.0's version of this test only examined models
     * carrying a `company_id`, which is exactly why `kpi_records`,
     * `daily_reports` and `approvals` slipped through it.
     *
     * A new model appearing here means a decision has to be made and
     * written down, rather than defaulting to "unscoped, and nobody
     * noticed".
     */
    public function test_every_model_is_either_isolated_or_declared_global(): void
    {
        // Genuinely installation-wide data. Each entry is a decision, and
        // the reason is stated so the next reader does not have to guess.
        $declaredGlobal = [
            // Platform catalogues -- identical for every customer, edited
            // by the Platform Super Admin, never tenant data.
            'Package', 'Module', 'Workspace', 'PpeType', 'StorageLocation',
            // Billing and the pre-tenant public onboarding flow. These are
            // owned by `tenant_id` and every tenant-facing read constrains
            // it explicitly; a registration exists BEFORE its tenant does,
            // so it cannot be scoped by one.
            'Invoice', 'Subscription', 'TenantRegistration', 'PaymentTransaction', 'PaymentWebhookEvent',
            // The approval engine walks these across tenants from a
            // scheduled command; the tenancy check lives in
            // ApprovalEngine::authorize(), asserted above.
            'Approval', 'ApprovalFlowStep',
            // Owned by a user, and guarded by user_id at both call sites.
            'Notification',
            // The tenant itself, and its settings, which have their own
            // two-tier scope (CompanySettingScope).
            'Tenant', 'CompanySetting',
        ];

        $unprotected = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $name = basename($file, '.php');
            $class = 'App\\Models\\'.$name;

            if (! class_exists($class) || in_array($name, $declaredGlobal, true)) {
                continue;
            }

            $model = new $class;

            if (! Schema::hasTable($model->getTable())) {
                continue;
            }

            $scopes = array_keys($model->getGlobalScopes());
            $isolating = array_filter(
                $scopes,
                fn ($s) => $s !== SoftDeletingScope::class
            );

            if ($isolating === []) {
                $unprotected[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unprotected,
            'These models carry no isolation scope and are not declared global reference data. '
            .'Either give them one (BelongsToCompany, or BelongsToCompanyThrough for a table owned '
            .'one join away) or add them to $declaredGlobal with a reason: '.implode(', ', $unprotected)
        );
    }
}
