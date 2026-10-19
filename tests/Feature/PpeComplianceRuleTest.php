<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\Package;
use App\Models\PpeType;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PpeComplianceService;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PpeTypeSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.121.0 -- IS THIS WORKER ACTUALLY EQUIPPED.
 *
 * The approved rule: work clothing (Work Uniform OR Coverall -- either
 * satisfies the group) plus Safety Shoes plus Safety Helmet. Safety
 * Glasses, Harness and Headlamp are recorded and never required.
 *
 * Three states, which was the owner's decision and not a convenience:
 * `compliant`, `needs_replacement` (everything held, something expired)
 * and `incomplete` (a group has no record at all). The middle one exists
 * because somebody who was never issued boots and somebody whose boots are
 * simply due are different problems with different actions.
 *
 * The most important test in this file is
 * `a_renamed_override_still_satisfies_its_requirement`. The rule reads a
 * type's DECLARED ROLE rather than its name, because ADR 050 lets a
 * customer rename a type -- and a safety status that depends on spelling
 * would report that customer's whole workforce as non-compliant.
 */
class PpeComplianceRuleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        $this->seed(PpeTypeSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'c-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit', 'code' => 'U1', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($this->tenant->fresh());

        Department::create(['company_id' => $this->company->id, 'name' => 'HSE']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'comp-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'is_active' => true,
        ]);
    }

    private function employee(string $number = 'EMP-0001'): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'employee_id' => $number, 'full_name' => 'Pekerja '.$number,
            'company_id' => $this->company->id, 'status' => 'active', 'join_date' => '2026-01-15',
        ]);
    }

    private function type(string $name): PpeType
    {
        return PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', $name)->sole();
    }

    /** Issues one item, with an expiry chosen explicitly so the test controls time. */
    private function issue(Employee $employee, PpeType $type, string $issued = '2026-01-01', ?string $expiry = null): EmployeePpe
    {
        return EmployeePpe::withoutGlobalScopes()->create([
            'employee_id' => $employee->id,
            'ppe_type_id' => $type->id,
            'issued_date' => $issued,
            'expiry_date' => $expiry ?? now()->addYear()->toDateString(),
            'status' => EmployeePpe::STATUS_ISSUED,
            'issued_by' => $this->admin->id,
        ]);
    }

    private function verdict(Employee $employee): string
    {
        return app(PpeComplianceService::class)->evaluate($employee->fresh())['status'];
    }

    /* ==================================================================
     * THE RULE, EXACTLY AS APPROVED
     * ================================================================== */

    public function test_coverall_plus_shoes_plus_helmet_is_compliant(): void
    {
        $budi = $this->employee();

        $this->issue($budi, $this->type('Coverall'));
        $this->issue($budi, $this->type('Safety Shoes'));
        $this->issue($budi, $this->type('Safety Helmet'));

        $this->assertSame(PpeComplianceService::COMPLIANT, $this->verdict($budi));
    }

    public function test_work_uniform_plus_shoes_plus_helmet_is_compliant_without_a_coverall(): void
    {
        $budi = $this->employee();

        // A type the customer added themselves and marked as work clothing.
        $uniform = PpeType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Work Uniform',
            'compliance_role' => PpeType::ROLE_WORK_CLOTHING,
            'replacement_interval_months' => 12, 'is_active' => true,
        ]);

        $this->issue($budi, $uniform);
        $this->issue($budi, $this->type('Safety Shoes'));
        $this->issue($budi, $this->type('Safety Helmet'));

        $this->assertSame(
            PpeComplianceService::COMPLIANT,
            $this->verdict($budi),
            'Work Uniform alone must satisfy the clothing group -- nobody needs both.'
        );
    }

    public function test_neither_uniform_nor_coverall_is_incomplete(): void
    {
        $budi = $this->employee();

        $this->issue($budi, $this->type('Safety Shoes'));
        $this->issue($budi, $this->type('Safety Helmet'));

        $evaluation = app(PpeComplianceService::class)->evaluate($budi->fresh());

        $this->assertSame(PpeComplianceService::INCOMPLETE, $evaluation['status']);
        $this->assertFalse($evaluation['groups'][PpeType::ROLE_WORK_CLOTHING]['held']);
        $this->assertTrue($evaluation['groups'][PpeType::ROLE_SAFETY_SHOES]['held']);
    }

    public function test_missing_shoes_is_incomplete(): void
    {
        $budi = $this->employee();

        $this->issue($budi, $this->type('Coverall'));
        $this->issue($budi, $this->type('Safety Helmet'));

        $this->assertSame(PpeComplianceService::INCOMPLETE, $this->verdict($budi));
    }

    public function test_missing_helmet_is_incomplete(): void
    {
        $budi = $this->employee();

        $this->issue($budi, $this->type('Coverall'));
        $this->issue($budi, $this->type('Safety Shoes'));

        $this->assertSame(PpeComplianceService::INCOMPLETE, $this->verdict($budi));
    }

    public function test_additional_equipment_alone_is_not_compliance(): void
    {
        $budi = $this->employee();

        // Four items, none of them a requirement.
        $this->issue($budi, $this->type('Safety Glasses'));
        $this->issue($budi, $this->type('Harness'));
        $this->issue($budi, $this->type('Headlamp'));

        $this->assertSame(
            PpeComplianceService::INCOMPLETE,
            $this->verdict($budi),
            'A count of assigned items is not a verdict about being equipped.'
        );
    }

    public function test_additional_equipment_never_blocks_compliance(): void
    {
        $budi = $this->employee();

        $this->issue($budi, $this->type('Coverall'));
        $this->issue($budi, $this->type('Safety Shoes'));
        $this->issue($budi, $this->type('Safety Helmet'));
        // Expired glasses: recorded, never required, so they must not count against.
        $this->issue($budi, $this->type('Safety Glasses'), '2025-01-01', '2025-06-01');

        $this->assertSame(PpeComplianceService::COMPLIANT, $this->verdict($budi));
    }

    /* ==================================================================
     * THE MIDDLE STATE
     * ================================================================== */

    public function test_everything_held_but_something_expired_needs_replacement(): void
    {
        $budi = $this->employee();

        $this->issue($budi, $this->type('Coverall'));
        $this->issue($budi, $this->type('Safety Shoes'), '2024-01-01', '2025-01-01'); // past
        $this->issue($budi, $this->type('Safety Helmet'));

        $this->assertSame(PpeComplianceService::NEEDS_REPLACEMENT, $this->verdict($budi));
    }

    public function test_an_expired_group_is_not_the_same_as_a_missing_one(): void
    {
        $expiredShoes = $this->employee('EMP-0001');
        $this->issue($expiredShoes, $this->type('Coverall'));
        $this->issue($expiredShoes, $this->type('Safety Shoes'), '2024-01-01', '2025-01-01');
        $this->issue($expiredShoes, $this->type('Safety Helmet'));

        $noShoes = $this->employee('EMP-0002');
        $this->issue($noShoes, $this->type('Coverall'));
        $this->issue($noShoes, $this->type('Safety Helmet'));

        // The whole reason the middle state exists: two different problems.
        $this->assertSame(PpeComplianceService::NEEDS_REPLACEMENT, $this->verdict($expiredShoes));
        $this->assertSame(PpeComplianceService::INCOMPLETE, $this->verdict($noShoes));
    }

    /** A valid record must not be shadowed by an expired one issued later. */
    public function test_a_group_is_satisfied_by_its_best_record_not_its_latest(): void
    {
        $budi = $this->employee();

        $this->issue($budi, $this->type('Coverall'));
        $this->issue($budi, $this->type('Safety Helmet'));
        $this->issue($budi, $this->type('Safety Shoes'), '2024-01-01', '2025-01-01'); // expired
        $this->issue($budi, $this->type('Safety Shoes'), '2026-01-01', now()->addYear()->toDateString());

        $this->assertSame(PpeComplianceService::COMPLIANT, $this->verdict($budi));
    }

    public function test_an_archived_item_does_not_keep_somebody_equipped(): void
    {
        $budi = $this->employee();

        $this->issue($budi, $this->type('Coverall'));
        $this->issue($budi, $this->type('Safety Helmet'));
        $shoes = $this->issue($budi, $this->type('Safety Shoes'));
        $shoes->update(['status' => EmployeePpe::STATUS_ARCHIVED]);

        $this->assertSame(
            PpeComplianceService::INCOMPLETE,
            $this->verdict($budi),
            'History does not keep a worker equipped.'
        );
    }

    /* ==================================================================
     * THE ROLE IS READ, NOT THE NAME
     * ================================================================== */

    public function test_a_renamed_override_still_satisfies_its_requirement(): void
    {
        $budi = $this->employee();
        $shared = $this->type('Safety Shoes');

        // ADR 050: the customer adapts the built-in AND renames it.
        $override = PpeType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'source_default_id' => $shared->id,
            'name' => 'Sepatu Safety', 'replacement_interval_months' => 6, 'is_active' => true,
        ]);

        $this->issue($budi, $this->type('Coverall'));
        $this->issue($budi, $this->type('Safety Helmet'));
        $this->issue($budi, $override);

        $this->assertSame(
            PpeComplianceService::COMPLIANT,
            $this->verdict($budi),
            'A rename must not report the whole workforce as non-compliant.'
        );
    }

    public function test_an_override_may_be_declassified_only_explicitly(): void
    {
        $shared = $this->type('Safety Shoes');

        $inheriting = PpeType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'source_default_id' => $shared->id,
            'name' => 'Sepatu A', 'replacement_interval_months' => 6, 'is_active' => true,
        ]);

        // Null means "never classified", which INHERITS -- so adapting an
        // interval cannot quietly remove footwear from the rule.
        $this->assertSame(PpeType::ROLE_SAFETY_SHOES, $inheriting->effectiveComplianceRole());

        $inheriting->update(['compliance_role' => PpeType::ROLE_ADDITIONAL]);

        // Saying it explicitly is a deliberate act, and it is honoured.
        $this->assertNull($inheriting->fresh()->effectiveComplianceRole());
    }

    public function test_the_service_and_the_model_agree_about_every_role(): void
    {
        $shared = $this->type('Safety Shoes');

        $override = PpeType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'source_default_id' => $shared->id,
            'name' => 'Sepatu B', 'replacement_interval_months' => 6, 'is_active' => true,
        ]);

        $own = PpeType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Work Uniform',
            'compliance_role' => PpeType::ROLE_WORK_CLOTHING, 'is_active' => true,
        ]);

        $map = app(PpeComplianceService::class)->roleMapForCompany($this->company->id);

        // Two implementations of one rule (per-model and over a loaded set);
        // they must never disagree about a role.
        foreach ([$shared, $override, $own, $this->type('Headlamp')] as $type) {
            $this->assertSame(
                $type->effectiveComplianceRole(),
                $map[$type->id] ?? null,
                "Disagreement about {$type->name}"
            );
        }
    }

    /* ==================================================================
     * A CATALOGUE THAT CANNOT ANSWER THE QUESTION SAYS SO
     * ================================================================== */

    public function test_a_company_with_no_work_clothing_type_is_reported_as_unconfigured(): void
    {
        /*
         * v2.126.0: the shipped catalogue now carries TWO work-clothing
         * types -- Coverall and Work Uniform -- so deactivating one no
         * longer creates a gap, and this test went green for the wrong
         * reason until both were switched off.
         *
         * Which is itself the rule worth stating: work clothing is
         * satisfied by EITHER (ADR 060), so a gap means NEITHER is
         * available. Two types sharing a role was always the designed
         * case; this is the first release in which the shipped catalogue
         * exercises it.
         */
        PpeType::withoutGlobalScopes()->whereNull('company_id')
            ->whereIn('name', ['Coverall', 'Work Uniform'])
            ->update(['is_active' => false]);

        $gaps = app(PpeComplianceService::class)->unconfiguredRoles($this->company->id);

        $this->assertSame([PpeType::ROLE_WORK_CLOTHING], $gaps);
    }

    /**
     * And the other half of that: ONE of the two is enough.
     *
     * The failure this guards against is a yard that issues uniforms
     * rather than coveralls being told its whole workforce is
     * non-compliant because the type it does not use is switched off.
     */
    public function test_either_work_clothing_type_on_its_own_satisfies_the_group(): void
    {
        foreach ([['Coverall', 'Work Uniform'], ['Work Uniform', 'Coverall']] as [$keep, $drop]) {
            PpeType::withoutGlobalScopes()->whereNull('company_id')
                ->whereIn('name', ['Coverall', 'Work Uniform'])->update(['is_active' => true]);
            PpeType::withoutGlobalScopes()->whereNull('company_id')
                ->where('name', $drop)->update(['is_active' => false]);

            $this->assertSame(
                [],
                app(PpeComplianceService::class)->unconfiguredRoles($this->company->id),
                "A company carrying only {$keep} must still be able to satisfy work clothing.",
            );
        }
    }

    public function test_a_fully_configured_company_reports_no_gaps(): void
    {
        $this->assertSame([], app(PpeComplianceService::class)->unconfiguredRoles($this->company->id));
    }

    /* ==================================================================
     * THE SCREENS SHOW WHAT THE SERVICE COMPUTES
     * ================================================================== */

    public function test_the_employee_ppe_list_carries_the_status_for_every_row(): void
    {
        $compliant = $this->employee('EMP-0001');
        $this->issue($compliant, $this->type('Coverall'));
        $this->issue($compliant, $this->type('Safety Shoes'));
        $this->issue($compliant, $this->type('Safety Helmet'));

        $this->employee('EMP-0002'); // nothing issued

        $props = $this->actingAs($this->admin)->get(route('ppe.employees'))
            ->assertOk()->viewData('page')['props'];

        $byNumber = collect($props['employees']['data'])->keyBy('employee_id');

        $this->assertSame(PpeComplianceService::COMPLIANT, $byNumber['EMP-0001']['ppe_compliance']);
        $this->assertSame(PpeComplianceService::INCOMPLETE, $byNumber['EMP-0002']['ppe_compliance']);

        // The verdict is ADDED to each row, never at the cost of what the
        // row already carried: attaching an attribute to a paginated
        // collection is exactly where a `withCount` quietly goes missing.
        $this->assertSame(3, $byNumber['EMP-0001']['total_ppe_count']);
        $this->assertSame(0, $byNumber['EMP-0002']['total_ppe_count']);
    }

    public function test_the_list_can_be_filtered_by_completeness(): void
    {
        $compliant = $this->employee('EMP-0001');
        $this->issue($compliant, $this->type('Coverall'));
        $this->issue($compliant, $this->type('Safety Shoes'));
        $this->issue($compliant, $this->type('Safety Helmet'));

        $this->employee('EMP-0002');

        $props = $this->actingAs($this->admin)
            ->get(route('ppe.employees', ['ppe_compliance' => PpeComplianceService::COMPLIANT]))
            ->assertOk()->viewData('page')['props'];

        // Resolved BEFORE pagination, so the total must agree with the rows.
        $this->assertSame(1, $props['employees']['total']);
        $this->assertSame('EMP-0001', $props['employees']['data'][0]['employee_id']);
    }

    public function test_the_profile_shows_which_group_is_missing(): void
    {
        $budi = $this->employee();
        $this->issue($budi, $this->type('Coverall'));
        $this->issue($budi, $this->type('Safety Helmet'));

        $props = $this->actingAs($this->admin)->get(route('ppe.employees.show', $budi))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(PpeComplianceService::INCOMPLETE, $props['compliance']['status']);
        $this->assertFalse($props['compliance']['groups'][PpeType::ROLE_SAFETY_SHOES]['held']);
        $this->assertTrue($props['compliance']['groups'][PpeType::ROLE_WORK_CLOTHING]['held']);
    }

    public function test_ppe_master_shows_the_role_in_force_including_an_inherited_one(): void
    {
        $shared = $this->type('Safety Shoes');

        PpeType::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'source_default_id' => $shared->id,
            'name' => 'Sepatu Safety', 'replacement_interval_months' => 6, 'is_active' => true,
        ]);

        $props = $this->actingAs($this->admin)->get(route('ppe.master', ['company_id' => $this->company->id]))
            ->assertOk()->viewData('page')['props'];

        $renamed = collect($props['ppeTypes'])->firstWhere('name', 'Sepatu Safety');

        $this->assertSame(PpeType::ROLE_SAFETY_SHOES, $renamed['effective_compliance_role']);
    }

    /* ==================================================================
     * THE IMPORT AND THE STATUS AGREE
     * ================================================================== */

    public function test_an_import_moves_an_employee_to_compliant(): void
    {
        $budi = $this->employee();

        $this->assertSame(PpeComplianceService::INCOMPLETE, $this->verdict($budi));

        // Dates close to today on purpose: a Coverall issued six months ago
        // would ALREADY be expired (it ships at a six-month interval), which
        // would be `needs_replacement` and a different test.
        $recent = now()->subDays(10)->format('d/m/Y');

        $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->wideFile([['EMP-0001', 'Pekerja EMP-0001', $recent, $recent, $recent]]),
            'company_id' => $this->company->id,
        ])->assertOk();

        $this->assertSame(
            PpeComplianceService::COMPLIANT,
            $this->verdict($budi),
            'The import result and the displayed status must be the same fact.'
        );
    }

    public function test_an_import_that_leaves_a_group_blank_does_not_reach_compliant(): void
    {
        $budi = $this->employee();

        $recent = now()->subDays(10)->format('d/m/Y');

        // Shoes and helmet only -- the clothing cell is deliberately blank.
        $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->wideFile([['EMP-0001', 'Pekerja EMP-0001', '', $recent, $recent]]),
            'company_id' => $this->company->id,
        ])->assertOk();

        $this->assertSame(
            PpeComplianceService::INCOMPLETE,
            $this->verdict($budi),
            'A blank cell must not be able to produce a compliant status.'
        );
        $this->assertSame(2, EmployeePpe::withoutGlobalScopes()->count());
    }

    /**
     * A FILE OF HISTORICAL DATES DOES NOT PRODUCE A SAFE WORKFORCE.
     *
     * This is the case the three-state rule was chosen for: importing last
     * year's register is honest record-keeping, and it must read as
     * equipment that is DUE rather than as Aman.
     */
    public function test_importing_old_dates_reports_replacement_rather_than_compliant(): void
    {
        $budi = $this->employee();

        $old = now()->subMonths(14)->format('d/m/Y');

        $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->wideFile([['EMP-0001', 'Pekerja EMP-0001', $old, $old, $old]]),
            'company_id' => $this->company->id,
        ])->assertOk();

        $this->assertSame(
            PpeComplianceService::NEEDS_REPLACEMENT,
            $this->verdict($budi),
            'Every group is held, and every one of them is out of date.'
        );
    }

    /** Employee ID, Employee Name, Coverall, Safety Shoes, Safety Helmet. */
    private function wideFile(array $rows): \Illuminate\Http\UploadedFile
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Employee ID', 'Employee Name', 'Coverall', 'Safety Shoes', 'Safety Helmet'],
            ...$rows,
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'ppe.xlsx', null, null, true);
    }
}
