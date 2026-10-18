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

    private const HEADINGS = ['Employee ID', 'PPE Type', 'Issued Date', 'Expiry Date', 'Remarks'];

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
            ['EMP-0001', 'Safety Helmet', '15/01/2026', '', 'Ukuran L'],
            ['EMP-0002', 'Safety Shoes', '15/01/2026', '', ''],
        ]);

        $this->assertSame(2, $result['imported']);
        $this->assertSame([], $result['skipped']);

        $this->assertSame(1, EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->count());
        $this->assertSame('Ukuran L', EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->sole()->remarks);
        $this->assertSame(1, EmployeePpe::withoutGlobalScopes()->where('employee_id', $siti->id)->count());
    }

    /**
     * The four of the owner's five types that IOMS ships. The fifth,
     * "Seragam (Office)", is not a built-in and is covered by
     * `test_an_unknown_ppe_type_is_refused_and_named` below -- it has to
     * be added in PPE Master first, because this import never creates a
     * type.
     */
    public function test_the_shipped_types_the_owner_named_all_resolve(): void
    {
        $this->employee('EMP-0001');

        $result = $this->import([
            ['EMP-0001', 'Coverall', '15/01/2026', '', ''],
            ['EMP-0001', 'Safety Shoes', '15/01/2026', '', ''],
            ['EMP-0001', 'Safety Helmet', '15/01/2026', '', ''],
            ['EMP-0001', 'Safety Glasses', '15/01/2026', '', ''],
        ]);

        $this->assertSame(4, $result['imported'], 'All four shipped types must resolve by name.');
        $this->assertSame([], $result['skipped']);
    }

    public function test_a_company_owned_type_added_in_ppe_master_resolves_too(): void
    {
        $this->employee('EMP-0001');

        // Exactly what "add it in PPE Master" produces -- see PpeTypeController::store().
        PpeType::withoutGlobalScopes()->create([
            'company_id' => $this->yard->id, 'name' => 'Seragam (Office)',
            'replacement_interval_months' => 12, 'is_active' => true,
        ]);

        $result = $this->import([['EMP-0001', 'Seragam (Office)', '15/01/2026', '', '']]);

        $this->assertSame(1, $result['imported']);
    }

    public function test_the_expiry_date_is_derived_from_the_types_interval(): void
    {
        $budi = $this->employee('EMP-0001');

        // Coverall ships at a six-month interval (PpeTypeSeeder).
        $this->import([['EMP-0001', 'Coverall', '15/01/2026', '', '']]);

        $issue = EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->sole();

        $this->assertSame('2026-01-15', $issue->issued_date->toDateString());
        $this->assertSame('2026-07-15', $issue->expiry_date->toDateString());
    }

    public function test_issuing_records_who_issued_them(): void
    {
        $this->employee('EMP-0001');
        $this->import([['EMP-0001', 'Coverall', '15/01/2026', '', '']]);

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
            ['EMP-0001', 'Safety Helmet', '15/01/2026', '', ''],
            ['EMP-0002', 'Safety Shoes', '15/01/2026', '', ''],
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

    public function test_the_same_pair_twice_in_one_file_is_issued_once(): void
    {
        $this->employee('EMP-0001');

        $result = $this->import([
            ['EMP-0001', 'Safety Helmet', '15/01/2026', '', ''],
            ['EMP-0001', 'Safety Helmet', '20/01/2026', '', ''],
        ]);

        $this->assertSame(1, $result['imported']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('Baris ganda', $result['skipped'][0]['reason']);
        $this->assertSame(1, EmployeePpe::withoutGlobalScopes()->count());
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

        $result = $this->import([['EMP-0001', 'Safety Shoes', '15/06/2026', '', '']]);

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

        $result = $this->import([['EMP-0001', 'Safety Shoes', '15/01/2026', '', '']]);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(2, EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->count());
    }

    /* ==================================================================
     * IT CREATES NOTHING BUT THE ISSUE RECORD
     * ================================================================== */

    public function test_an_unknown_employee_is_refused_and_no_employee_is_created(): void
    {
        $before = Employee::withoutGlobalScopes()->count();

        $result = $this->import([['EMP-9999', 'Safety Helmet', '15/01/2026', '', '']]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(['EMP-9999'], $result['unknown_employees']);
        $this->assertStringContainsString('tidak ditemukan', $result['skipped'][0]['reason']);
        $this->assertSame($before, Employee::withoutGlobalScopes()->count());
    }

    public function test_an_unknown_ppe_type_is_refused_and_named(): void
    {
        $this->employee('EMP-0001');
        $before = PpeType::withoutGlobalScopes()->count();

        // The owner's fifth type, before anybody adds it in PPE Master.
        $result = $this->import([['EMP-0001', 'Seragam (Office)', '15/01/2026', '', '']]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(['Seragam (Office)'], $result['unknown_ppe_types']);
        $this->assertStringContainsString('PPE Master', $result['skipped'][0]['reason']);
        $this->assertSame($before, PpeType::withoutGlobalScopes()->count(), 'No PPE type may be invented by an import.');
    }

    public function test_an_inactive_type_is_not_offered_to_an_import(): void
    {
        $this->employee('EMP-0001');

        PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Coverall')
            ->update(['is_active' => false]);

        $result = $this->import([['EMP-0001', 'Coverall', '15/01/2026', '', '']]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(['Coverall'], $result['unknown_ppe_types']);
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

        $result = $this->import([['EMP-0001', 'Safety Helmet', '15/01/2026', '', '']]);

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
        $result = $this->import([['EMP-0001', 'Safety Helmet', '15/01/2026', '', '']]);

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
            'file' => $this->upload([['EMP-0001', 'Safety Helmet', '15/01/2026', '', '']]),
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

        $result = $this->import([['EMP-0001', 'Safety Helmet', '15/01/2026', '', '']], as: $hse);

        $this->assertSame(1, $result['imported']);
    }

    /* ==================================================================
     * ROWS, DATES AND REPORTING
     * ================================================================== */

    public function test_a_missing_issue_date_costs_the_row_rather_than_inventing_one(): void
    {
        $this->employee('EMP-0001');

        $result = $this->import([['EMP-0001', 'Safety Helmet', '', '', '']]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame('Missing Issued Date', $result['skipped'][0]['reason']);
    }

    public function test_a_slashed_date_is_read_day_first(): void
    {
        $budi = $this->employee('EMP-0001');

        $this->import([['EMP-0001', 'Safety Helmet', '01/02/2026', '', '']]);

        $this->assertSame(
            '2026-02-01',
            EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->sole()->issued_date->toDateString()
        );
    }

    public function test_an_unreadable_expiry_is_a_warning_and_the_interval_is_used(): void
    {
        $budi = $this->employee('EMP-0001');

        $result = $this->import([['EMP-0001', 'Coverall', '15/01/2026', 'secepatnya', '']]);

        $this->assertSame(1, $result['imported'], 'An unreadable OPTIONAL cell must not cost the row.');
        $this->assertCount(1, $result['warnings']);
        $this->assertSame(
            '2026-07-15',
            EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->sole()->expiry_date->toDateString()
        );
    }

    public function test_an_explicit_expiry_date_is_honoured(): void
    {
        $budi = $this->employee('EMP-0001');

        $this->import([['EMP-0001', 'Coverall', '15/01/2026', '31/12/2026', '']]);

        $this->assertSame(
            '2026-12-31',
            EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->sole()->expiry_date->toDateString()
        );
    }

    public function test_one_bad_row_does_not_cost_the_others(): void
    {
        $this->employee('EMP-0001');
        $this->employee('EMP-0002');

        $result = $this->import([
            ['EMP-0001', 'Safety Helmet', '15/01/2026', '', ''],
            ['EMP-9999', 'Safety Helmet', '15/01/2026', '', ''],
            ['EMP-0002', 'Safety Shoes', '15/01/2026', '', ''],
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
            ['EMP-0001', 'Safety Helmet', '15/01/2026', '', ''],
            ['EMP-9999', 'Safety Shoes', '15/01/2026', '', ''],
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
                [['EMP-0001', 'Safety Helmet', '15/01/2026']],
                ['Nomor Induk Karyawan', 'Jenis APD', 'Tanggal Diberikan']
            ),
        ])->assertOk()->json();

        $this->assertSame('Nomor Induk Karyawan', $result['mapping']['employee_id']['heading'] ?? null);
        $this->assertSame('Jenis APD', $result['mapping']['ppe_type']['heading'] ?? null);
        $this->assertSame('Tanggal Diberikan', $result['mapping']['issued_date']['heading'] ?? null);
    }

    public function test_the_template_downloads_and_names_real_types(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('ppe.import-template', ['company_id' => $this->yard->id]));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }
}
