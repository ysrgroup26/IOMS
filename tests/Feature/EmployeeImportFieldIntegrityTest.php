<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * FIELD-LEVEL INTEGRITY: THE VALUE THAT ARRIVES IS THE VALUE THAT WAS IN
 * THE CELL, OR THE ROW IS REFUSED.
 *
 * The failure this file exists to catch is the quiet one. A row that is
 * rejected is visible — it is counted, named and given a reason. A row
 * that imports with a phone number in the address column, a truncated
 * national ID, or a value from the row above is indistinguishable from a
 * correct one, and it is found months later by somebody trying to call an
 * employee's next of kin.
 *
 * So: columns are read by NAME, never by position; unknown columns are
 * ignored rather than consumed; absent optional columns leave their
 * fields empty rather than shifting the ones after them; and importing
 * the same file twice changes nothing the second time.
 */
class EmployeeImportFieldIntegrityTest extends TestCase
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

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());

        Department::create(['company_id' => $this->yard->id, 'name' => 'HSE']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    /**
     * @param  list<string>  $headings
     * @param  list<array<int, mixed>>  $rows
     */
    private function importFile(array $headings, array $rows): array
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$headings, ...$rows], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $response = $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => new UploadedFile($path, 'karyawan.xlsx', null, null, true),
            'company_id' => $this->yard->id,
        ]);

        $response->assertOk();

        return $response->json();
    }

    private function employee(string $id): Employee
    {
        return Employee::withoutGlobalScopes()->where('employee_id', $id)->sole();
    }

    /**
     * Columns in a different order must land in the same fields. If the
     * import ever read by position this is the test that fails.
     */
    public function test_the_column_order_does_not_decide_the_meaning(): void
    {
        $this->importFile(
            ['Email', 'Join Date', 'Full Name', 'Phone', 'Department', 'Employee ID', 'Address'],
            [['budi@yard.test', '2026-01-15', 'Budi Santoso', '081234567890', 'HSE', 'EMP-1', 'Jl. Merdeka 1']]
        );

        $employee = $this->employee('EMP-1');

        $this->assertSame('Budi Santoso', $employee->full_name);
        $this->assertSame('budi@yard.test', $employee->email);
        $this->assertSame('081234567890', $employee->phone);
        $this->assertSame('Jl. Merdeka 1', $employee->address);
        $this->assertSame('2026-01-15', $employee->join_date?->toDateString());
    }

    /**
     * A customer's file routinely carries columns IOMS knows nothing
     * about. They must be ignored, not consumed by the field next to them.
     */
    public function test_columns_ioms_does_not_know_are_ignored_not_consumed(): void
    {
        $result = $this->importFile(
            ['Employee ID', 'Gaji Pokok', 'Full Name', 'Nomor Rekening', 'Phone', 'Catatan HRD'],
            [['EMP-1', '9500000', 'Budi Santoso', '1234567890', '081234567890', 'rahasia']]
        );

        $this->assertSame(1, $result['imported']);

        $employee = $this->employee('EMP-1');

        $this->assertSame('Budi Santoso', $employee->full_name);
        $this->assertSame('081234567890', $employee->phone);
        $this->assertNull($employee->email, 'An unknown column must not leak into an unrelated field.');
        $this->assertNull($employee->address);
    }

    /**
     * Optional columns simply absent: the fields are empty, and nothing
     * shifts into them.
     */
    public function test_absent_optional_columns_leave_their_fields_empty(): void
    {
        $this->importFile(
            ['Employee ID', 'Full Name', 'Department'],
            [['EMP-1', 'Budi Santoso', 'HSE']]
        );

        $employee = $this->employee('EMP-1');

        $this->assertSame('Budi Santoso', $employee->full_name);
        $this->assertNull($employee->phone);
        $this->assertNull($employee->email);
        $this->assertNull($employee->address);
        $this->assertNull($employee->join_date);
        $this->assertNull($employee->nik);
        $this->assertSame('active', $employee->status);
        $this->assertSame('pkwtt', $employee->employment_type);
    }

    /**
     * A blank line in the middle of a register is extremely common. It
     * must be reported, not imported as a nameless employee.
     */
    public function test_a_blank_row_is_skipped_and_counted(): void
    {
        $result = $this->importFile(
            ['Employee ID', 'Full Name', 'Department'],
            [
                ['EMP-1', 'Budi Santoso', 'HSE'],
                ['', '', ''],
                ['EMP-2', 'Siti Aminah', 'HSE'],
            ]
        );

        $this->assertSame(2, $result['imported']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(3, $result['skipped'][0]['row']);
    }

    public function test_surrounding_whitespace_is_not_part_of_a_value(): void
    {
        $this->importFile(
            ['Employee ID', 'Full Name', 'Department', 'Email', 'Phone'],
            [['  EMP-1  ', '  budi santoso  ', ' HSE ', '  budi@yard.test  ', ' 081234567890 ']]
        );

        $employee = $this->employee('EMP-1');

        $this->assertSame('Budi Santoso', $employee->full_name);
        $this->assertSame('budi@yard.test', $employee->email);
        $this->assertSame('081234567890', $employee->phone);
        $this->assertNotNull($employee->department_id, 'A padded department name must still match.');
    }

    /**
     * A 16-digit national ID held in a NUMERIC cell must survive as
     * digits — not rounded, and not rendered in scientific notation.
     */
    public function test_a_numeric_national_id_cell_keeps_every_digit(): void
    {
        $this->importFile(
            ['Employee ID', 'NIK (KTP)', 'Full Name', 'Department'],
            [['EMP-1', 3201010101900001, 'Budi Santoso', 'HSE']]
        );

        $this->assertSame('3201010101900001', $this->employee('EMP-1')->nik);
    }

    /**
     * A number-shaped employee number must not arrive as "1001.0" or in
     * exponent form either.
     */
    public function test_a_numeric_employee_number_is_stored_as_written(): void
    {
        $this->importFile(
            ['Employee ID', 'Full Name', 'Department'],
            [[1001, 'Budi Santoso', 'HSE']]
        );

        $this->assertSame('1001', $this->employee('1001')->employee_id);
    }

    // --- re-importing the same file --------------------------------------

    /**
     * NO UNINTENDED OVERWRITES. The import only ever creates; running the
     * same file again must not duplicate anybody and must not quietly
     * rewrite the records a human has since corrected.
     */
    public function test_importing_the_same_file_twice_creates_nothing_and_overwrites_nothing(): void
    {
        $headings = ['Employee ID', 'Full Name', 'Department', 'Phone', 'Join Date'];
        $rows = [['EMP-1', 'Budi Santoso', 'HSE', '081234567890', '2026-01-15']];

        $this->importFile($headings, $rows);

        // A human corrects the record after the first import.
        $employee = $this->employee('EMP-1');
        $employee->update(['phone' => '089999999999', 'full_name' => 'Budi Santoso Jr']);

        $second = $this->importFile($headings, $rows);

        $this->assertSame(0, $second['imported']);
        $this->assertCount(1, $second['skipped']);
        $this->assertStringContainsString('already used', $second['skipped'][0]['reason']);

        $this->assertSame(1, Employee::withoutGlobalScopes()->count(), 'No duplicate record.');

        $employee->refresh();
        $this->assertSame('089999999999', $employee->phone, 'A re-import must not undo a human correction.');
        $this->assertSame('Budi Santoso Jr', $employee->full_name);
    }

    /**
     * The same file imported into a DIFFERENT operating unit of the same
     * tenant is a legitimate move, and must not be blocked by the first.
     */
    public function test_the_same_register_can_be_imported_into_a_second_operating_unit(): void
    {
        $dock = Company::withoutGlobalScopes()->create([
            'name' => 'Dock Kedua', 'code' => 'DK', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
        Department::create(['company_id' => $dock->id, 'name' => 'HSE']);

        $headings = ['Employee ID', 'Full Name', 'Department'];
        $rows = [['EMP-1', 'Budi Santoso', 'HSE']];

        $this->importFile($headings, $rows);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$headings, ...$rows], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => new UploadedFile($path, 'karyawan.xlsx', null, null, true),
            'company_id' => $dock->id,
        ])->assertOk()->assertJson(['imported' => 1]);

        $this->assertSame(2, Employee::withoutGlobalScopes()->count());
    }

    /**
     * Two columns with the SAME heading. Maatwebsite keys the row by
     * heading, so one of them necessarily wins — what must not happen is
     * the value landing in a different field, or the row being imported
     * with a silently blended value under a different name.
     */
    public function test_a_repeated_heading_does_not_scatter_values_into_other_fields(): void
    {
        $result = $this->importFile(
            ['Employee ID', 'Full Name', 'Phone', 'Phone', 'Department'],
            [['EMP-1', 'Budi Santoso', '081111111111', '082222222222', 'HSE']]
        );

        $this->assertSame(1, $result['imported']);

        $employee = $this->employee('EMP-1');

        $this->assertContains($employee->phone, ['081111111111', '082222222222'], 'The phone must come from a Phone column.');
        $this->assertNull($employee->email, 'A duplicated heading must not spill into another field.');
        $this->assertNull($employee->address);
        $this->assertSame('Budi Santoso', $employee->full_name);
    }

    /**
     * More values than headings — a stray cell past the last column. The
     * row must still be read by name and the extra value dropped.
     */
    public function test_a_stray_cell_past_the_last_heading_is_not_adopted(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Employee ID', 'Full Name', 'Department']], null, 'A1');
        $sheet->fromArray([['EMP-1', 'Budi Santoso', 'HSE', 'nilai nyasar']], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => new UploadedFile($path, 'karyawan.xlsx', null, null, true),
            'company_id' => $this->yard->id,
        ])->assertOk()->assertJson(['imported' => 1]);

        $employee = $this->employee('EMP-1');

        $this->assertSame('Budi Santoso', $employee->full_name);
        $this->assertNull($employee->email);
        $this->assertNull($employee->address);
        $this->assertNull($employee->phone);
    }
}
