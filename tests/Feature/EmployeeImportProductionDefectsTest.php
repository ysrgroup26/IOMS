<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Package;
use App\Models\Position;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use App\Support\NameFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * v2.105.0 -- THE TWO FAULTS REPORTED FROM PRODUCTION, AND THE NAMING RULES.
 *
 * REPORTED: "an account created by the Admin detects master data, the Admin
 * account sometimes detects nothing at all", and "the preview says 141 rows
 * and 141 valid, then Create Missing Master Data & Import says Import
 * failed".
 *
 * Neither was a reporting problem.
 *
 * 1. THE EMPLOYEE NUMBER WAS UNIQUE ACROSS THE ENTIRE INSTALLATION.
 *    `employees.employee_id` carried a global unique index from the
 *    single-tenant schema of 2024. One customer's EMP-001 stopped every
 *    other customer from ever having one -- and EMP-001 is exactly what
 *    every yard starts with. The preview only ever checked for duplicates
 *    WITHIN the file, so it promised 141 valid rows; the insert then threw,
 *    and because the whole import ran in one transaction, all 141 rows and
 *    the freshly created departments went with it.
 *
 * 2. NOTHING WAS PRESELECTED WHEN MORE THAN ONE OPERATING UNIT WAS VISIBLE.
 *    `CompanyAuthorizationScope` means two accounts legitimately see
 *    different lists: an unrestricted user in a single-unit tenant gets one
 *    (auto-selected, everything works), while an account that can see
 *    several got none selected -- and `runPreview()` returned silently. Two
 *    accounts, two behaviours, no message.
 *
 * The naming rules are asserted here too, because the whole reason they
 * exist is to stop one department becoming three.
 */
class EmployeeImportProductionDefectsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private Company $dock;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->yard = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Utama', 'code' => 'YU', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        $this->dock = Company::withoutGlobalScopes()->create([
            'name' => 'Dock Kedua', 'code' => 'DK', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->admin = $this->user(User::ROLE_SUPER_ADMIN, $this->yard);
    }

    private function user(string $role, Company $company): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }

    /** @param list<array<int, string>> $rows */
    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Employee ID', 'Full Name', 'Department', 'Position', 'Project', 'Phone', 'Email',
                'Address', 'Emergency Contact Name', 'Emergency Contact Phone', 'Join Date', 'Employment Status'],
            ...$rows,
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'karyawan.xlsx', null, null, true);
    }

    private function row(string $id, string $name, string $department, string $position = ''): array
    {
        return [$id, $name, $department, $position, '', '', '', '', '', '', '2026-01-15', 'active'];
    }

    /**
     * The reported file: 141 rows, with the department spelled four ways
     * the way a real spreadsheet does it.
     *
     * @return list<array<int, string>>
     */
    private function realisticRows(int $count = 141): array
    {
        $departments = ['Mechanic', 'mechanic', ' MECHANIC ', 'Mechanic Vessel'];
        $positions = ['mechanic senior', 'HSE officer', 'welder', 'Mechanic  Junior'];
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = $this->row(
                sprintf('EMP-%03d', $i),
                'karyawan nomor '.$i,
                $departments[$i % 4],
                $positions[$i % 4],
            );
        }

        return $rows;
    }

    /* ==================================================================
     * 1. THE 141-ROW IMPORT
     * ================================================================== */

    public function test_a_141_row_file_previews_as_entirely_valid(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('employees.import.preview'), [
                'file' => $this->workbook($this->realisticRows()),
                'company_id' => $this->yard->id,
            ])
            ->assertOk();

        $this->assertSame(141, $response->json('total_rows'));
        $this->assertSame(141, $response->json('valid_rows'));
        $this->assertSame(0, $response->json('invalid_rows'));
    }

    /** THE DEFECT: the preview promised 141 and the import lost all of them. */
    public function test_a_141_row_file_actually_imports(): void
    {
        $preview = $this->actingAs($this->admin)
            ->post(route('employees.import.preview'), [
                'file' => $this->workbook($this->realisticRows()),
                'company_id' => $this->yard->id,
            ])
            ->assertOk();

        $response = $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $this->workbook($this->realisticRows()),
                'company_id' => $this->yard->id,
                'new_departments' => $preview->json('departments.new'),
                'new_positions' => $preview->json('positions.new'),
            ])
            ->assertOk();

        $this->assertSame(141, $response->json('imported'), 'Every row the preview called valid must land.');
        $this->assertSame([], $response->json('skipped'));
        $this->assertSame(141, Employee::where('company_id', $this->yard->id)->count());
    }

    /**
     * THE ROOT CAUSE, isolated: another company's EMP-001 must not block
     * this one. Before the fix the unique index was global.
     */
    public function test_another_companys_employee_number_does_not_block_this_one(): void
    {
        Employee::create([
            'company_id' => $this->dock->id,
            'employee_id' => 'EMP-001',
            'full_name' => 'Orang Lain',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $this->workbook([$this->row('EMP-001', 'Budi Santoso', 'Mechanic')]),
                'company_id' => $this->yard->id,
                'new_departments' => ['MECHANIC'],
            ])
            ->assertOk();

        $this->assertSame([], $response->json('skipped'), 'Nothing about another company should block this row.');
        $this->assertSame(1, $response->json('imported'));
        $this->assertSame(2, Employee::withoutGlobalScopes()->where('employee_id', 'EMP-001')->count());
    }

    /**
     * THE PRODUCTION SCENARIO, exactly.
     *
     * Another CUSTOMER already uses EMP-001..EMP-010. Tenant isolation
     * correctly hides those rows from this customer's preview, so every row
     * is reported valid -- and under the old GLOBAL unique index the insert
     * then threw against data this customer can neither see nor do anything
     * about. The preview was not wrong; the constraint was.
     */
    public function test_another_tenants_employee_numbers_do_not_break_this_import(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Pelanggan Lain', 'slug' => 'o-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Lain', 'code' => 'YL', 'tenant_id' => $otherTenant->id, 'is_active' => true,
        ]);

        for ($i = 1; $i <= 10; $i++) {
            Employee::withoutGlobalScopes()->create([
                'company_id' => $otherCompany->id,
                'employee_id' => sprintf('EMP-%03d', $i),
                'full_name' => 'Karyawan Pelanggan Lain '.$i,
                'status' => 'active',
            ]);
        }

        app(CurrentTenant::class)->set($this->tenant->fresh());

        $preview = $this->actingAs($this->admin)
            ->post(route('employees.import.preview'), [
                'file' => $this->workbook($this->realisticRows(10)),
                'company_id' => $this->yard->id,
            ])
            ->assertOk();

        // Correct, and it always was: another customer's data is none of
        // this customer's business.
        $this->assertSame(10, $preview->json('valid_rows'));

        $response = $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $this->workbook($this->realisticRows(10)),
                'company_id' => $this->yard->id,
                'new_departments' => $preview->json('departments.new'),
                'new_positions' => $preview->json('positions.new'),
            ])
            ->assertOk();

        $this->assertSame([], $response->json('skipped'));
        $this->assertSame(
            10,
            $response->json('imported'),
            'What the preview promised must land, whatever another customer happens to be using.'
        );
    }

    /** And a number already used in THIS company is reported, not thrown. */
    public function test_a_number_already_used_here_is_reported_as_a_skipped_row(): void
    {
        Employee::create([
            'company_id' => $this->yard->id,
            'employee_id' => 'EMP-001',
            'full_name' => 'Sudah Ada',
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('employees.import.preview'), [
                'file' => $this->workbook([
                    $this->row('EMP-001', 'Budi Santoso', 'Mechanic'),
                    $this->row('EMP-002', 'Siti Rahayu', 'Mechanic'),
                ]),
                'company_id' => $this->yard->id,
            ])
            ->assertOk();

        // The preview now predicts the import instead of promising more.
        $this->assertSame(1, $response->json('valid_rows'));
        $this->assertSame(1, $response->json('invalid_rows'));
        $this->assertStringContainsString('EMP-001', $response->json('skipped.0.reason'));
    }

    /**
     * One row that the database refuses must not cost the others. A phone
     * number longer than its column is the easiest way to prove it.
     */
    public function test_one_rejected_row_does_not_discard_the_rest(): void
    {
        $rows = $this->realisticRows(10);
        $rows[4][5] = str_repeat('0', 60); // phone is varchar(30)

        $response = $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $this->workbook($rows),
                'company_id' => $this->yard->id,
                'new_departments' => ['MECHANIC', 'MECHANIC VESSEL'],
            ])
            ->assertOk();

        $this->assertGreaterThanOrEqual(9, $response->json('imported'));
        $this->assertGreaterThan(0, Employee::where('company_id', $this->yard->id)->count());
    }

    /* ==================================================================
     * 2. THE ACCOUNT ASYMMETRY
     * ================================================================== */

    /** Both accounts reach the same answer for the same file and unit. */
    public function test_an_admin_and_a_user_they_created_detect_the_same_master_data(): void
    {
        $member = $this->user('hse', $this->yard);
        $file = fn () => $this->workbook($this->realisticRows(20));

        $asAdmin = $this->actingAs($this->admin)
            ->post(route('employees.import.preview'), ['file' => $file(), 'company_id' => $this->yard->id])
            ->assertOk();

        $asMember = $this->actingAs($member)
            ->post(route('employees.import.preview'), ['file' => $file(), 'company_id' => $this->yard->id])
            ->assertOk();

        $this->assertSame(
            $asAdmin->json('departments.new'),
            $asMember->json('departments.new'),
            'The same file and the same operating unit must detect the same master data.'
        );
    }

    /** The viewer's own operating unit is sent, so a form can default to it. */
    public function test_the_shared_prop_carries_the_users_own_operating_unit(): void
    {
        $this->actingAs($this->admin)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.user.company_id', $this->yard->id));
    }

    /** The dialog must not fall silent when nothing is chosen. */
    public function test_the_dialog_explains_a_missing_operating_unit_instead_of_doing_nothing(): void
    {
        $jsx = file_get_contents(resource_path('js/Components/shared/EmployeeImportDialog.jsx'));

        $this->assertStringContainsString('Pilih Operating Unit terlebih dahulu', $jsx);
        $this->assertStringContainsString('defaultCompanyId', $jsx);
    }

    /* ==================================================================
     * 3. THE NAMING RULES
     * ================================================================== */

    public function test_a_department_is_stored_in_upper_case_however_it_was_typed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $this->workbook([
                    $this->row('EMP-1', 'a', 'Mechanic'),
                    $this->row('EMP-2', 'b', 'mechanic'),
                    $this->row('EMP-3', 'c', ' MECHANIC '),
                ]),
                'company_id' => $this->yard->id,
                'new_departments' => ['Mechanic', 'mechanic', ' MECHANIC '],
            ])
            ->assertOk();

        $names = Department::where('company_id', $this->yard->id)->pluck('name')->all();

        $this->assertSame(['MECHANIC'], $names, 'Four spellings are one department, stored as MECHANIC.');
    }

    /** A genuinely different name stays a different department. */
    public function test_a_longer_name_is_not_merged_into_a_shorter_one(): void
    {
        $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $this->workbook([
                    $this->row('EMP-1', 'a', 'Mechanic'),
                    $this->row('EMP-2', 'b', 'Mechanic Vessel'),
                ]),
                'company_id' => $this->yard->id,
                'new_departments' => ['Mechanic', 'Mechanic Vessel'],
            ])
            ->assertOk();

        $names = Department::where('company_id', $this->yard->id)->orderBy('name')->pluck('name')->all();

        $this->assertSame(['MECHANIC', 'MECHANIC VESSEL'], $names);
    }

    public function test_a_position_is_stored_in_title_case_with_acronyms_intact(): void
    {
        $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $this->workbook([$this->row('EMP-1', 'a', 'Mechanic', 'mechanic senior')]),
                'company_id' => $this->yard->id,
                'new_departments' => ['Mechanic'],
                'new_positions' => ['mechanic senior', 'hse officer'],
            ])
            ->assertOk();

        $names = Position::where('company_id', $this->yard->id)->orderBy('name')->pluck('name')->all();

        $this->assertSame(['HSE Officer', 'Mechanic Senior'], $names);
    }

    public function test_an_employee_name_is_stored_in_title_case(): void
    {
        $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $this->workbook([
                    $this->row('EMP-1', 'budi   santoso', 'Mechanic'),
                    $this->row('EMP-2', 'SITI RAHAYU', 'Mechanic'),
                ]),
                'company_id' => $this->yard->id,
                'new_departments' => ['Mechanic'],
            ])
            ->assertOk();

        $names = Employee::where('company_id', $this->yard->id)->orderBy('employee_id')->pluck('full_name')->all();

        $this->assertSame(['Budi Santoso', 'Siti Rahayu'], $names);
    }

    /** The formatter itself, including the shapes a name actually takes. */
    public function test_the_name_formatter_rules(): void
    {
        $this->assertSame('MECHANIC VESSEL', NameFormatter::department('  mechanic   vessel '));
        $this->assertSame('MECHANIC', NameFormatter::department('Mechanic'));

        $this->assertSame('Mechanic Senior', NameFormatter::title('mechanic senior'));
        $this->assertSame('HSE Officer', NameFormatter::title('hse officer'));
        $this->assertSame('Supervisor HRD', NameFormatter::title('supervisor hrd'));
        $this->assertSame('Budi Santoso', NameFormatter::title('BUDI   SANTOSO'));
        $this->assertSame('Abdul-Rahman', NameFormatter::title('abdul-rahman'));
        $this->assertSame("O'Brien", NameFormatter::title("o'brien"));

        // The same key, so they are the same name.
        $this->assertSame(NameFormatter::key('Mechanic'), NameFormatter::key(' MECHANIC '));
        $this->assertSame(NameFormatter::key('Mechanic'), NameFormatter::key('mechanic'));

        // Different keys, so they stay different names.
        $this->assertNotSame(NameFormatter::key('Mechanic'), NameFormatter::key('Mechanic Vessel'));
    }

    /**
     * MASTER DATA ARRIVES BY TWO DOORS. A department typed by hand in
     * Settings must obey the same rules as one created by the import, or
     * the two produce competing spellings of the same thing.
     */
    public function test_a_department_typed_in_settings_follows_the_same_rules(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.departments.store'), [
                'name' => '  mechanic  vessel ',
                'company_id' => $this->yard->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['MECHANIC VESSEL'],
            Department::where('company_id', $this->yard->id)->pluck('name')->all()
        );
    }

    /** And it is then recognised by the import rather than duplicated. */
    public function test_a_hand_typed_department_is_matched_by_a_later_import(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.departments.store'), [
                'name' => 'Mechanic',
                'company_id' => $this->yard->id,
            ])
            ->assertSessionHasNoErrors();

        $preview = $this->actingAs($this->admin)
            ->post(route('employees.import.preview'), [
                'file' => $this->workbook([$this->row('EMP-1', 'budi', ' MECHANIC ')]),
                'company_id' => $this->yard->id,
            ])
            ->assertOk();

        $this->assertSame([], $preview->json('departments.new'), 'It already exists, however it was typed.');
        $this->assertSame(1, Department::where('company_id', $this->yard->id)->count());
    }

    public function test_a_position_typed_in_settings_follows_the_same_rules(): void
    {
        $department = Department::create([
            'company_id' => $this->yard->id,
            'name' => 'MECHANIC',
        ]);

        $this->actingAs($this->admin)
            ->post(route('settings.positions.store'), [
                'name' => '  hse   officer ',
                'company_id' => $this->yard->id,
                'department_id' => $department->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['HSE Officer'],
            Position::where('company_id', $this->yard->id)->pluck('name')->all()
        );
    }

    /** Importing twice must not produce a second copy of the same department. */
    public function test_importing_the_same_file_twice_does_not_duplicate_master_data(): void
    {
        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->admin)
                ->post(route('employees.import.create-missing-and-import'), [
                    'file' => $this->workbook([$this->row('EMP-'.$attempt, 'orang '.$attempt, 'mechanic', 'welder')]),
                    'company_id' => $this->yard->id,
                    'new_departments' => ['mechanic'],
                    'new_positions' => ['welder'],
                ])
                ->assertOk();
        }

        $this->assertSame(1, Department::where('company_id', $this->yard->id)->count());
        $this->assertSame(1, Position::where('company_id', $this->yard->id)->count());
    }
}
