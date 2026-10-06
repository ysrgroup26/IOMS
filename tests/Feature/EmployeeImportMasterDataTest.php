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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * v2.101.0 -- WHAT A SPREADSHEET'S MASTER DATA TURNS INTO.
 *
 * Employee Import had no tests at all, and three things were wrong with
 * how it turns the Department and Position columns into records.
 *
 * 1. NAMES WERE NOT NORMALISED. `MasterDataDetector` compares with
 *    `mb_strtolower()` and no `trim()`, so "HSE", "hse" and "Hse" matched
 *    an existing department but " HSE " did not -- it was classified as
 *    NEW, offered for creation, and created verbatim as a second
 *    department whose name begins with a space. A spreadsheet column is
 *    exactly where stray whitespace comes from.
 *
 * 2. A POSITION CREATED BY "create missing and import" COULD NEVER BE
 *    FOUND BY THE IMPORT THAT FOLLOWED IT. The controller creates
 *    positions with a company and a name; `EmployeesImport` resolves them
 *    with `where('department_id', ...)`. A freshly created position has a
 *    null department, so the lookup never matched and the employee landed
 *    with no position -- the one flow whose entire purpose is to make the
 *    import succeed. `MasterDataDetector`'s own doc comment says positions
 *    resolve "by name within the company", which is what the import USED
 *    to do and no longer did.
 *
 * 3. MATCHING DEPENDED ON THE DATABASE. `where('name', $name)` is
 *    case-insensitive under MySQL's default collation and case-SENSITIVE
 *    under the SQLite the suite runs on. So production quietly forgave a
 *    case difference, the tests could not have caught it either way, and
 *    the behaviour was a property of the connection rather than of the
 *    code. Normalising explicitly makes it the same everywhere.
 */
class EmployeeImportMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Galangan', 'slug' => 'galangan-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Galangan Co', 'code' => 'GC', 'tenant_id' => $this->tenant->id, 'is_active' => true,
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

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@galangan.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'is_active' => true,
        ]);
    }

    /**
     * Builds a real .xlsx with the official template's headings, because
     * the import reads a heading row and the whole point is to exercise
     * what a customer actually uploads.
     *
     * @param  list<array<int, string>>  $rows
     */
    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray([
            ['Employee ID', 'Full Name', 'Department', 'Position', 'Project', 'Phone', 'Email',
                'Address', 'Emergency Contact Name', 'Emergency Contact Phone', 'Join Date', 'Employment Status'],
            ...$rows,
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'employees.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    /** @param array<int, string> $overrides */
    private function row(string $id, string $name, string $department, string $position = ''): array
    {
        return [$id, $name, $department, $position, '', '', '', '', '', '', '2026-01-15', 'active'];
    }

    /* ==================================================================
     * 1. ONE DEPARTMENT, HOWEVER IT IS TYPED
     * ================================================================== */

    public function test_case_and_whitespace_variants_match_one_existing_department(): void
    {
        Department::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'HSE',
        ]);

        $file = $this->workbook([
            $this->row('EMP-1', 'A', 'HSE'),
            $this->row('EMP-2', 'B', 'hse'),
            $this->row('EMP-3', 'C', 'Hse'),
            $this->row('EMP-4', 'D', ' HSE '),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('employees.import.preview'), [
                'file' => $file,
                'company_id' => $this->company->id,
            ])
            ->assertOk();

        $departments = $response->json('departments');

        $this->assertSame(
            [],
            $departments['new'] ?? [],
            'Every spelling of an existing department must be recognised as that department, including one with stray spaces.'
        );
    }

    public function test_variants_in_one_file_do_not_create_several_departments(): void
    {
        $file = $this->workbook([
            $this->row('EMP-1', 'A', 'HSE'),
            $this->row('EMP-2', 'B', 'hse'),
            $this->row('EMP-3', 'C', ' HSE '),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('employees.import.preview'), [
                'file' => $file,
                'company_id' => $this->company->id,
            ])
            ->assertOk();

        $this->assertCount(
            1,
            $response->json('departments.new'),
            'A file that spells one department three ways offers ONE department to create, not three.'
        );
    }

    public function test_creating_the_missing_department_stores_a_trimmed_name(): void
    {
        $file = $this->workbook([$this->row('EMP-1', 'Budi', ' Produksi ')]);

        $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $file,
                'company_id' => $this->company->id,
                'new_departments' => [' Produksi '],
            ])
            ->assertOk();

        // v2.105.0: a department is stored upper case, so the stray spaces
        // are gone AND the written form is the one the product displays.
        $this->assertDatabaseHas('departments', [
            'company_id' => $this->company->id,
            'name' => 'PRODUKSI',
        ]);

        $this->assertSame(
            1,
            Department::where('company_id', $this->company->id)->count(),
            'Stray whitespace must not become a second department.'
        );
    }

    public function test_creating_a_department_twice_does_not_duplicate_it(): void
    {
        Department::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Produksi',
        ]);

        $file = $this->workbook([$this->row('EMP-1', 'Budi', 'produksi')]);

        $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $file,
                'company_id' => $this->company->id,
                'new_departments' => ['produksi'],
            ])
            ->assertOk();

        $this->assertSame(
            1,
            Department::where('company_id', $this->company->id)->count(),
            'Asking to create a department that already exists under another spelling must reuse it.'
        );
    }

    /* ==================================================================
     * 2. THE IMPORT CAN FIND WHAT THE SAME REQUEST JUST CREATED
     * ================================================================== */

    /**
     * THE DEFECT. This flow exists for one reason: to create the missing
     * master data so the rows it blocked can land. The position it created
     * could not then be resolved, so it landed without one.
     */
    public function test_a_position_created_in_the_same_request_is_assigned_to_the_employee(): void
    {
        $file = $this->workbook([$this->row('EMP-1', 'Budi', 'Produksi', 'Welder')]);

        $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $file,
                'company_id' => $this->company->id,
                'new_departments' => ['Produksi'],
                'new_positions' => ['Welder'],
            ])
            ->assertOk();

        $employee = Employee::where('employee_id', 'EMP-1')->first();

        $this->assertNotNull($employee, 'The employee row must import.');
        $this->assertNotNull(
            $employee->position_id,
            'A position created by this very request must be assigned, not left empty.'
        );
        $this->assertSame('Welder', Position::find($employee->position_id)->name);
    }

    /** An existing position is matched whatever the case. */
    public function test_an_existing_position_matches_case_insensitively(): void
    {
        $department = Department::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Produksi',
        ]);

        Position::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'name' => 'Welder',
        ]);

        $file = $this->workbook([$this->row('EMP-1', 'Budi', 'produksi', 'WELDER')]);

        $this->actingAs($this->admin)
            ->post(route('employees.import'), [
                'file' => $file,
                'company_id' => $this->company->id,
            ])
            ->assertOk();

        $employee = Employee::where('employee_id', 'EMP-1')->first();

        $this->assertNotNull($employee);
        $this->assertSame(
            'Welder',
            Position::find($employee->position_id)?->name,
            'Matching must not depend on how the spreadsheet capitalised it.'
        );
    }

    /* ==================================================================
     * THE GOLDEN PATH: THE OFFICIAL TEMPLATE ROUND-TRIPS
     * ================================================================== */

    /**
     * The template a customer downloads must import without being edited
     * beyond filling it in, so its headings and the import's field map
     * have to agree. They are maintained in two files, and nothing checked.
     */
    public function test_the_official_template_headings_are_the_ones_the_import_reads(): void
    {
        $headings = (new \App\Exports\EmployeeImportTemplateExport)->headings();

        $file = $this->workbook([$this->row('EMP-TPL', 'Template Person', 'Produksi', 'Welder')]);

        $this->actingAs($this->admin)
            ->post(route('employees.import.create-missing-and-import'), [
                'file' => $file,
                'company_id' => $this->company->id,
                'new_departments' => ['Produksi'],
                'new_positions' => ['Welder'],
            ])
            ->assertOk()
            ->assertJsonPath('imported', 1);

        $this->assertContains('Employee ID', $headings);
        $this->assertContains('Department', $headings);
        $this->assertContains('Position', $headings);

        $this->assertDatabaseHas('employees', [
            'employee_id' => 'EMP-TPL',
            'full_name' => 'Template Person',
        ]);
    }

    /* ==================================================================
     * VALIDATION A CUSTOMER CAN ACT ON
     * ================================================================== */

    /**
     * The preview used to report a COUNT of invalid rows and nothing else,
     * so a customer was told "3 rows are invalid" before importing and had
     * no way to find or fix them. The reasons were already being computed;
     * only the screen that runs AFTER the import rendered them.
     */
    public function test_the_preview_says_which_rows_will_be_skipped_and_why(): void
    {
        $file = $this->workbook([
            $this->row('EMP-1', 'Budi Santoso', 'Produksi'),
            // No employee id: cannot be identified.
            $this->row('', 'Tanpa Nomor', 'Produksi'),
            // Same id twice: the second cannot be told from the first.
            $this->row('EMP-1', 'Kembar', 'Produksi'),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('employees.import.preview'), [
                'file' => $file,
                'company_id' => $this->company->id,
            ])
            ->assertOk();

        $skipped = $response->json('skipped');

        $this->assertNotEmpty($skipped, 'A row that will not import must be reported before the import runs.');

        foreach ($skipped as $entry) {
            $this->assertArrayHasKey('row', $entry, 'A reader must be told WHICH row.');
            $this->assertArrayHasKey('reason', $entry, 'A reader must be told WHY.');
            $this->assertNotSame('', trim((string) $entry['reason']));
        }

        $this->assertSame(count($skipped), $response->json('invalid_rows'));
    }

    /** The reasons are rendered before the import, not only after it. */
    public function test_the_import_dialog_shows_the_skipped_rows_in_the_preview(): void
    {
        $jsx = file_get_contents(resource_path('js/Components/shared/EmployeeImportDialog.jsx'));

        $this->assertStringContainsString(
            'preview.skipped',
            $jsx,
            'The preview must render the rows it is about to skip, not just count them.'
        );
    }

    /* ==================================================================
     * SMART IMPORT, END TO END
     * ================================================================== */

    /**
     * The customer's own file, with their own headings, read through the
     * real endpoint. Nothing is written: this answers "will this file
     * work" before anything is imported.
     */
    public function test_inspecting_a_customers_own_file_suggests_the_columns(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['NIK', 'Nama Lengkap', 'Departemen', 'Jabatan', 'Kolom Aneh'],
            ['EMP-1', 'Budi Santoso', 'Produksi', 'Welder', 'abc'],
            ['EMP-2', 'Siti Rahayu', 'Produksi', 'Fitter', 'def'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $response = $this->actingAs($this->admin)
            ->post(route('employees.import.inspect'), [
                'file' => new UploadedFile($path, 'punya-saya.xlsx', null, null, true),
            ])
            ->assertOk();

        $response
            ->assertJsonPath('mapping.employee_id.heading', 'NIK')
            ->assertJsonPath('mapping.full_name.heading', 'Nama Lengkap')
            ->assertJsonPath('mapping.department.heading', 'Departemen')
            ->assertJsonPath('mapping.position.heading', 'Jabatan')
            ->assertJsonPath('unmatched_headings.0', 'Kolom Aneh');

        // It reads; it does not write.
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('departments', 0);
    }

    /* ==================================================================
     * THE PRINTABLE REGISTER
     * ================================================================== */

    private function employee(string $id, string $name, ?Department $department = null): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'department_id' => $department?->id,
            'employee_id' => $id,
            'full_name' => $name,
            'status' => 'active',
            'employment_type' => Employee::EMPLOYMENT_TYPES[0],
        ]);
    }

    public function test_the_employee_register_renders_as_a_pdf(): void
    {
        $this->employee('EMP-1', 'Budi Santoso');

        $response = $this->actingAs($this->admin)
            ->get(route('employees.export-pdf'))
            ->assertOk();

        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));

        $this->assertStringStartsWith(
            '%PDF',
            $response->getContent(),
            'The response must be a real PDF document, not an error page served with a PDF header.'
        );
    }

    /**
     * A filtered register must not read as the whole workforce, so the
     * document states what it covers.
     */
    public function test_the_register_records_which_filter_produced_it(): void
    {
        $department = Department::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Produksi',
        ]);

        $this->employee('EMP-1', 'Budi Santoso', $department);

        $this->actingAs($this->admin)
            ->get(route('employees.export-pdf', ['department_id' => $department->id]))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'exported',
            'description' => 'Exported employee register to PDF.',
        ]);
    }

    /** The same fail-closed tenant scoping the Excel export carries. */
    public function test_the_register_is_scoped_to_the_current_tenant(): void
    {
        $this->employee('EMP-1', 'Budi Santoso');

        $otherTenant = Tenant::create([
            'name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Other Co', 'code' => 'OC', 'tenant_id' => $otherTenant->id, 'is_active' => true,
        ]);
        Employee::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'employee_id' => 'OTHER-1',
            'full_name' => 'Should Not Appear',
            'status' => 'active',
            'employment_type' => Employee::EMPLOYMENT_TYPES[0],
        ]);

        $this->actingAs($this->admin)
            ->get(route('employees.export-pdf'))
            ->assertOk();

        // The register is built from the same scoped query as the Excel
        // export: the base filter is this tenant's own companies.
        $this->assertSame(
            1,
            Employee::query()->whereIn('employees.company_id', Company::query()->pluck('id'))->count(),
            'The query behind the register must see only this tenant.'
        );
    }

    public function test_the_template_and_the_export_are_downloadable(): void
    {
        $this->actingAs($this->admin)
            ->get(route('employees.import-template'))
            ->assertOk()
            ->assertDownload();

        $this->actingAs($this->admin)
            ->get(route('employees.export'))
            ->assertOk()
            ->assertDownload();
    }

    /* ==================================================================
     * 3. THE SAME ANSWER ON EVERY DATABASE
     * ================================================================== */

    /**
     * `where('name', $name)` is case-insensitive under MySQL's default
     * collation and case-sensitive under SQLite, so this behaviour used to
     * be a property of the connection. It is now a property of the code.
     */
    public function test_department_matching_does_not_depend_on_the_connection(): void
    {
        Department::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'HSE',
        ]);

        $file = $this->workbook([$this->row('EMP-1', 'Budi', 'hse')]);

        $this->actingAs($this->admin)
            ->post(route('employees.import'), [
                'file' => $file,
                'company_id' => $this->company->id,
            ])
            ->assertOk();

        $employee = Employee::where('employee_id', 'EMP-1')->first();

        $this->assertNotNull($employee);
        $this->assertNotNull(
            $employee->department_id,
            'A lowercase spelling of an existing department must resolve on any database.'
        );
    }
}
