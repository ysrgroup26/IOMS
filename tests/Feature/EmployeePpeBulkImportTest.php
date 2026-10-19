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
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PpeTypeSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * v2.121.0 -- BULK PPE ISSUANCE, WIDE FORMAT.
 *
 * The file is now one row per EMPLOYEE with one dated column per kind of
 * PPE. An empty cell is not a fact: it means no record, and it produces no
 * transaction. Nothing invents a date to make a row look complete.
 *
 * v2.120.0 -- BULK PPE ISSUANCE.
 *
 * A second importer rather than more columns on `EmployeesImport`,
 * because a row whose Employee ID already exists is SKIPPED there
 * (`EmployeesImport.php:415`) and changing that would move a behaviour
 * pinned by `EmployeeReimportAfterDeletionTest` and surrounded by the
 * regression suite four production incidents produced.
 *
 * The rule that makes this safe to re-run is the owner's: a person still
 * holding PPE of that kind (`issued` / `in_use`) is reported, not issued
 * again. `employee_ppe` has no unique index to fall back on -- checked:
 * (employee_id, ppe_type_id) is a plain index -- so idempotency lives in
 * the importer or nowhere, and that is what most of this file asserts.
 */
class EmployeePpeBulkImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        $this->seed(PpeTypeSeeder::class);

        [$this->tenant, $this->yard, $this->admin] = $this->makeTenant('a');
    }

    /** @return array{0: Tenant, 1: Company, 2: User} */
    private function makeTenant(string $suffix): array
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => 'Yard '.$suffix, 'slug' => 'p-'.$suffix.'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit '.$suffix, 'code' => strtoupper($suffix).'U',
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($tenant->fresh());

        Department::create(['company_id' => $company->id, 'name' => 'HSE']);

        $admin = User::create([
            'name' => 'Admin '.$suffix, 'email' => 'ppe-'.$suffix.'-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true,
        ]);

        return [$tenant, $company, $admin];
    }

    private function employee(string $number, ?Company $company = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'employee_id' => $number,
            'full_name' => 'Pekerja '.$number,
            'company_id' => ($company ?? $this->yard)->id,
            'status' => 'active',
            'join_date' => '2026-01-15',
        ]);
    }

    /**
     * The wide template: identity, then one column per kind of PPE. The
     * PPE headings are resolved against the company's own catalogue, so
     * these are the shipped built-in names.
     */
    private const HEADINGS = [
        'Employee ID', 'Employee Name', 'Coverall', 'Safety Shoes', 'Safety Helmet',
        'Safety Glasses', 'Harness', 'Headlamp',
    ];

    /**
     * One wide row. Keys are PPE column names; anything omitted is left
     * BLANK, which is the whole point of the format.
     *
     * @param  array<string, string>  $dates
     */
    private function row(string $employeeNumber, array $dates = [], string $name = '', ?array $headings = null): array
    {
        $row = [$employeeNumber, $name];

        foreach (array_slice($headings ?? self::HEADINGS, 2) as $column) {
            $row[] = $dates[$column] ?? '';
        }

        return $row;
    }

    /** @param list<array<int, string>> $rows */
    private function upload(array $rows, ?array $headings = null): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$headings ?? self::HEADINGS, ...$rows], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'ppe.xlsx', null, null, true);
    }

    private function import(array $rows, ?Company $company = null, ?User $as = null, ?array $headings = null): array
    {
        $response = $this->actingAs($as ?? $this->admin)->postJson(route('ppe.import'), [
            'file' => $this->upload($rows, $headings),
            'company_id' => ($company ?? $this->yard)->id,
        ]);

        $response->assertOk();

        return $response->json();
    }

    private function preview(array $rows, ?Company $company = null): array
    {
        return $this->actingAs($this->admin)->postJson(route('ppe.import.preview'), [
            'file' => $this->upload($rows),
            'company_id' => ($company ?? $this->yard)->id,
        ])->assertOk()->json();
    }

    /* ==================================================================
     * THE HAPPY PATH, AND THE FIVE TYPES THE OWNER NAMED
     * ================================================================== */

    public function test_it_issues_ppe_to_employees_that_already_exist(): void
    {
        $budi = $this->employee('EMP-0001');
        $siti = $this->employee('EMP-0002');

        $result = $this->import([
            $this->row('EMP-0001', ['Safety Helmet' => '15/01/2026']),
            $this->row('EMP-0002', ['Safety Shoes' => '15/01/2026']),
        ]);

        $this->assertSame(2, $result['imported']);
        $this->assertSame([], $result['skipped']);

        $this->assertSame(1, EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->count());
        $this->assertSame(1, EmployeePpe::withoutGlobalScopes()->where('employee_id', $siti->id)->count());
        // Every other cell on both rows was blank, and a blank cell is not a fact.
        $this->assertSame(2, EmployeePpe::withoutGlobalScopes()->count());
        $this->assertGreaterThan(0, $result['blank_cells']);
    }

    /**
     * One row, four items: the shape the whole format exists for.
     *
     * "Work Uniform" is not shipped and is covered by
     * `test_an_unrecognised_column_is_reported_by_name` below -- it is
     * added once in PPE Master, because this import never creates a type.
     */
    public function test_one_row_issues_every_dated_column_on_it(): void
    {
        $this->employee('EMP-0001');

        $result = $this->import([
            $this->row('EMP-0001', [
                'Coverall' => '15/01/2026',
                'Safety Shoes' => '15/01/2026',
                'Safety Helmet' => '15/01/2026',
                'Safety Glasses' => '15/01/2026',
            ]),
        ]);

        $this->assertSame(4, $result['imported'], 'Every dated column on the row must be issued.');
        $this->assertSame(1, $result['employees_touched']);
        $this->assertSame([], $result['skipped']);
    }

    public function test_a_company_owned_type_added_in_ppe_master_resolves_too(): void
    {
        $this->employee('EMP-0001');

        // Exactly what "add it in PPE Master" produces -- see
        // PpeTypeController::store() -- including the role that makes it
        // satisfy the work-clothing requirement.
        PpeType::withoutGlobalScopes()->create([
            'company_id' => $this->yard->id, 'name' => 'Work Uniform',
            'compliance_role' => PpeType::ROLE_WORK_CLOTHING,
            'replacement_interval_months' => 12, 'is_active' => true,
        ]);

        $headings = [...self::HEADINGS, 'Work Uniform'];

        $result = $this->import(
            [$this->row('EMP-0001', ['Work Uniform' => '15/01/2026'], headings: $headings)],
            headings: $headings,
        );

        $this->assertSame(1, $result['imported']);
        $this->assertSame([], $result['unknown_columns']);
    }

    public function test_the_expiry_date_is_derived_from_the_types_interval(): void
    {
        $budi = $this->employee('EMP-0001');

        // Coverall ships at a six-month interval (PpeTypeSeeder).
        $this->import([$this->row('EMP-0001', ['Coverall' => '15/01/2026'])]);

        $issue = EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->sole();

        $this->assertSame('2026-01-15', $issue->issued_date->toDateString());
        $this->assertSame('2026-07-15', $issue->expiry_date->toDateString());
    }

    public function test_issuing_records_who_issued_them(): void
    {
        $this->employee('EMP-0001');
        $this->import([$this->row('EMP-0001', ['Coverall' => '15/01/2026'])]);

        $this->assertSame(
            $this->admin->id,
            EmployeePpe::withoutGlobalScopes()->sole()->issued_by
        );
    }

    /* ==================================================================
     * THE DUPLICATE RULE -- THE REASON RE-RUNNING A FILE IS SAFE
     * ================================================================== */

    public function test_re_running_the_same_file_issues_nothing_a_second_time(): void
    {
        $this->employee('EMP-0001');
        $this->employee('EMP-0002');

        $rows = [
            $this->row('EMP-0001', ['Safety Helmet' => '15/01/2026']),
            $this->row('EMP-0002', ['Safety Shoes' => '15/01/2026']),
        ];

        $first = $this->import($rows);
        $second = $this->import($rows);

        $this->assertSame(2, $first['imported']);
        $this->assertSame(0, $second['imported'], 'A re-run must not issue a second set.');
        $this->assertSame(2, $second['already_held']);
        $this->assertCount(2, $second['skipped']);
        $this->assertStringContainsString('masih memegang', $second['skipped'][0]['reason']);

        $this->assertSame(2, EmployeePpe::withoutGlobalScopes()->count());
    }

    /**
     * THE CASE A SINGLE-ID GUARD WOULD HAVE MISSED.
     *
     * A built-in and this company's override of it are one kind of
     * equipment wearing two ids (ADR 050), and `EmployeePpe::creating()`
     * remaps a template to the override on the way in. Somebody issued the
     * template BEFORE the override existed therefore holds the other id --
     * and must still not be handed a second pair.
     */
    public function test_an_override_and_its_template_count_as_the_same_kind(): void
    {
        $budi = $this->employee('EMP-0001');
        $shared = PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Safety Shoes')->sole();

        // Issued while only the built-in existed.
        EmployeePpe::withoutGlobalScopes()->create([
            'employee_id' => $budi->id, 'ppe_type_id' => $shared->id,
            'issued_date' => '2026-01-01', 'status' => EmployeePpe::STATUS_IN_USE,
            'issued_by' => $this->admin->id,
        ]);

        // The customer later adapts the built-in (ADR 050 adoption).
        PpeType::withoutGlobalScopes()->create([
            'company_id' => $this->yard->id, 'source_default_id' => $shared->id,
            'name' => 'Safety Shoes', 'replacement_interval_months' => 6, 'is_active' => true,
        ]);

        $result = $this->import([$this->row('EMP-0001', ['Safety Shoes' => '15/06/2026'])]);

        $this->assertSame(0, $result['imported'], 'The override is the same kind as the template it replaces.');
        $this->assertSame(1, $result['already_held']);
        $this->assertSame(1, EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->count());
    }

    /** A returned or replaced item does not block a reissue -- the lifecycle still works. */
    public function test_an_archived_item_does_not_block_a_reissue(): void
    {
        $budi = $this->employee('EMP-0001');
        $shoes = PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Safety Shoes')->sole();

        EmployeePpe::withoutGlobalScopes()->create([
            'employee_id' => $budi->id, 'ppe_type_id' => $shoes->id,
            'issued_date' => '2025-01-01', 'status' => EmployeePpe::STATUS_ARCHIVED,
            'issued_by' => $this->admin->id,
        ]);

        $result = $this->import([$this->row('EMP-0001', ['Safety Shoes' => '15/01/2026'])]);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(2, EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->count());
    }

    /* ==================================================================
     * IT CREATES NOTHING BUT THE ISSUE RECORD
     * ================================================================== */

    public function test_an_unknown_employee_is_refused_and_no_employee_is_created(): void
    {
        $before = Employee::withoutGlobalScopes()->count();

        $result = $this->import([$this->row('EMP-9999', ['Safety Helmet' => '15/01/2026'])]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(['EMP-9999'], $result['unknown_employees']);
        $this->assertStringContainsString('tidak ditemukan', $result['skipped'][0]['reason']);
        $this->assertSame($before, Employee::withoutGlobalScopes()->count());
    }

    /**
     * A COLUMN OF DATES THAT MATCHES NOTHING IS REPORTED, NOT DROPPED.
     *
     * On a wide file this is the dangerous failure: silently ignoring one
     * heading loses a whole column of equipment while the import still
     * reports success. "Work Uniform" is the real case -- it is not in the
     * shipped catalogue until somebody adds it.
     */
    public function test_an_unrecognised_column_is_reported_by_name(): void
    {
        $this->employee('EMP-0001');
        $before = PpeType::withoutGlobalScopes()->count();

        /*
         * v2.126.0: this fixture used to say "Work Uniform", which stopped
         * being an unrecognised column the moment the uniform joined the
         * shared catalogue. The RULE under test is unchanged -- a heading
         * the catalogue has no row for is reported by name and invents
         * nothing -- so only the example moved, to something no catalogue
         * will ever carry.
         */
        $headings = [...self::HEADINGS, 'Exoskeleton Rig'];

        $result = $this->import(
            [$this->row('EMP-0001', ['Exoskeleton Rig' => '15/01/2026'], headings: $headings)],
            headings: $headings,
        );

        $this->assertSame(0, $result['imported']);
        $this->assertSame(['Exoskeleton Rig'], $result['unknown_columns']);
        $this->assertSame($before, PpeType::withoutGlobalScopes()->count(), 'No PPE type may be invented by an import.');
    }

    public function test_a_blank_cell_never_issues_anything(): void
    {
        $this->employee('EMP-0001');

        // Every PPE cell blank: a row that says "we have no records yet".
        $result = $this->import([$this->row('EMP-0001')]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, $result['employees_touched']);
        $this->assertSame([], $result['skipped'], 'A blank row is not an error, it is an absence.');
        $this->assertSame(6, $result['blank_cells']);
        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->count());
    }

    public function test_one_employee_may_not_appear_on_two_rows(): void
    {
        $this->employee('EMP-0001');

        $result = $this->import([
            $this->row('EMP-0001', ['Safety Shoes' => '15/01/2026']),
            $this->row('EMP-0001', ['Safety Helmet' => '15/01/2026']),
        ]);

        $this->assertSame(1, $result['imported']);
        $this->assertStringContainsString('satu karyawan satu baris', $result['skipped'][0]['reason']);
    }

    public function test_a_name_that_disagrees_with_the_id_is_reported_but_not_refused(): void
    {
        $this->employee('EMP-0001'); // full_name is "Pekerja EMP-0001"

        $result = $this->import([
            $this->row('EMP-0001', ['Safety Shoes' => '15/01/2026'], name: 'Orang Lain'),
        ]);

        $this->assertSame(1, $result['imported'], 'The Employee ID decides who a row is.');
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('berbeda dari nama di IOMS', $result['warnings'][0]['reason']);
    }

    public function test_an_inactive_type_is_not_offered_to_an_import(): void
    {
        $this->employee('EMP-0001');

        PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Coverall')
            ->update(['is_active' => false]);

        $result = $this->import([$this->row('EMP-0001', ['Coverall' => '15/01/2026'])]);

        $this->assertSame(0, $result['imported']);
        // A deactivated type is not in the effective catalogue, so its
        // column matches nothing and is reported as unrecognised.
        $this->assertSame(['Coverall'], $result['unknown_columns']);
    }

    /* ==================================================================
     * TENANT AND COMPANY ISOLATION
     * ================================================================== */

    public function test_another_tenants_employee_number_matches_nobody(): void
    {
        [, $otherCompany] = $this->makeTenant('b');
        $theirs = $this->employee('EMP-0001', $otherCompany);

        // Back to tenant A, which has nobody by that number.
        app(CurrentTenant::class)->set($this->tenant);

        $result = $this->import([$this->row('EMP-0001', ['Safety Helmet' => '15/01/2026'])]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(['EMP-0001'], $result['unknown_employees']);
        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->where('employee_id', $theirs->id)->count());
    }

    public function test_an_employee_in_another_company_of_the_same_tenant_is_not_matched(): void
    {
        $second = Company::withoutGlobalScopes()->create([
            'name' => 'Unit Kedua', 'code' => 'UK', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
        $theirs = $this->employee('EMP-0001', $second);

        // Imported INTO the first company; the number belongs to the second.
        $result = $this->import([$this->row('EMP-0001', ['Safety Helmet' => '15/01/2026'])]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->where('employee_id', $theirs->id)->count());
    }

    public function test_a_user_who_may_not_issue_ppe_cannot_import(): void
    {
        $this->employee('EMP-0001');

        $manager = User::create([
            'name' => 'Manager', 'email' => 'mgr-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_MANAGER,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);

        $this->actingAs($manager)->postJson(route('ppe.import'), [
            'file' => $this->upload([$this->row('EMP-0001', ['Safety Helmet' => '15/01/2026'])]),
            'company_id' => $this->yard->id,
        ])->assertForbidden();

        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->count());
    }

    public function test_hse_may_import_because_hse_issues_ppe(): void
    {
        $this->employee('EMP-0001');

        $hse = User::create([
            'name' => 'HSE', 'email' => 'hse-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_HSE,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);

        $result = $this->import([$this->row('EMP-0001', ['Safety Helmet' => '15/01/2026'])], as: $hse);

        $this->assertSame(1, $result['imported']);
    }

    /* ==================================================================
     * ROWS, DATES AND REPORTING
     * ================================================================== */

    public function test_a_slashed_date_is_read_day_first(): void
    {
        $budi = $this->employee('EMP-0001');

        $this->import([$this->row('EMP-0001', ['Safety Helmet' => '01/02/2026'])]);

        $this->assertSame(
            '2026-02-01',
            EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->sole()->issued_date->toDateString()
        );
    }

    /**
     * An unreadable date costs its CELL and names the column.
     *
     * On a wide file "row 7 is wrong" does not tell anybody which of eight
     * dates to fix, and the other seven are still perfectly good.
     */
    public function test_an_unreadable_date_costs_its_cell_and_names_the_column(): void
    {
        $this->employee('EMP-0001');

        $result = $this->import([
            $this->row('EMP-0001', ['Coverall' => 'secepatnya', 'Safety Shoes' => '15/01/2026']),
        ]);

        $this->assertSame(1, $result['imported'], 'One bad cell must not cost the good ones.');
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('Coverall', $result['skipped'][0]['reason']);
        $this->assertStringContainsString('tidak dapat dibaca', $result['skipped'][0]['reason']);
    }

    public function test_one_bad_row_does_not_cost_the_others(): void
    {
        $this->employee('EMP-0001');
        $this->employee('EMP-0002');

        $result = $this->import([
            $this->row('EMP-0001', ['Safety Helmet' => '15/01/2026']),
            $this->row('EMP-9999', ['Safety Helmet' => '15/01/2026']),
            $this->row('EMP-0002', ['Safety Shoes' => '15/01/2026']),
        ]);

        $this->assertSame(2, $result['imported']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(3, $result['skipped'][0]['row'], 'The row number must be the one in the customer\'s file.');
    }

    public function test_the_preview_writes_nothing_and_predicts_the_import(): void
    {
        $this->employee('EMP-0001');
        $this->employee('EMP-0002');

        $rows = [
            $this->row('EMP-0001', ['Safety Helmet' => '15/01/2026']),
            $this->row('EMP-9999', ['Safety Shoes' => '15/01/2026']),
        ];

        $preview = $this->preview($rows);

        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->count(), 'Preview must write nothing.');
        $this->assertSame(1, $preview['imported']);
        $this->assertCount(1, $preview['skipped']);

        $real = $this->import($rows);

        $this->assertSame($preview['imported'], $real['imported'], 'The preview is a promise the import keeps.');
        $this->assertSame(count($preview['skipped']), count($real['skipped']));
    }

    public function test_a_customers_own_indonesian_headings_are_recognised(): void
    {
        $this->employee('EMP-0001');

        $result = $this->actingAs($this->admin)->postJson(route('ppe.import.inspect'), [
            'file' => $this->upload(
                [['EMP-0001', 'Budi', '15/01/2026']],
                ['Nomor Induk Karyawan', 'Nama Karyawan', 'Safety Helmet']
            ),
        ])->assertOk()->json();

        $this->assertSame('Nomor Induk Karyawan', $result['mapping']['employee_id']['heading'] ?? null);
        $this->assertSame('Nama Karyawan', $result['mapping']['employee_name']['heading'] ?? null);
    }

    public function test_the_template_downloads_and_names_real_types(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('ppe.import-template', ['company_id' => $this->yard->id]));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }
}
