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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * NIK AND WORKFORCE TYPE: TWO REAL COLUMNS THE IMPORT COULD NOT REACH.
 *
 * `employees.nik` (the national ID, unique per company) and
 * `employees.employment_type` (Workforce Type) are both first-class
 * fields — the manual form REQUIRES Workforce Type, and the schema puts a
 * unique index on NIK. Neither appeared in the official template, the
 * field catalogue, the alias list or `EmployeesImport`.
 *
 * So every employee an import created silently took `pkwtt` — permanent —
 * whatever the customer's workforce actually is, and a national ID could
 * only ever be typed in by hand afterwards, one person at a time. For a
 * yard whose register is mostly PKWT and daily labour, an import produced
 * a workforce that was wrong about every single person.
 *
 * Both columns are OPTIONAL: a file saved from the older template, with
 * neither column present, must still import exactly as it did before.
 */
class EmployeeImportIdentityAndWorkforceTypeTest extends TestCase
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

        Department::create(['company_id' => $this->yard->id, 'name' => 'HSE']);
        Department::create(['company_id' => $this->dock->id, 'name' => 'HSE']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    /** The current official template's full heading row. */
    private const HEADINGS = [
        'Employee ID', 'NIK (KTP)', 'Full Name', 'Department', 'Position', 'Workforce Type', 'Project',
        'Phone', 'Email', 'Address', 'Emergency Contact Name', 'Emergency Contact Phone',
        'Join Date', 'Employment Status',
    ];

    /** @param list<array<int, string>> $rows */
    private function upload(array $rows, ?array $headings = null): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([$headings ?? self::HEADINGS, ...$rows], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'karyawan.xlsx', null, null, true);
    }

    /** One template row: id, nik, name, type. */
    private function row(string $id, string $nik, string $name, string $type): array
    {
        return [$id, $nik, $name, 'HSE', '', $type, '', '', '', '', '', '', '2026-01-15', 'active'];
    }

    private function import(array $rows, ?Company $company = null, ?array $headings = null): array
    {
        $response = $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $this->upload($rows, $headings),
            'company_id' => ($company ?? $this->yard)->id,
        ]);

        $response->assertOk();

        return $response->json();
    }

    private function employee(string $id): Employee
    {
        return Employee::withoutGlobalScopes()->where('employee_id', $id)->sole();
    }

    // --- Workforce Type ---------------------------------------------------

    public function test_the_stored_keys_are_accepted_as_written(): void
    {
        $this->import([
            $this->row('E-1', '', 'A Satu', 'pkwt'),
            $this->row('E-2', '', 'B Dua', 'daily'),
            $this->row('E-3', '', 'C Tiga', 'outsource'),
        ]);

        $this->assertSame('pkwt', $this->employee('E-1')->employment_type);
        $this->assertSame('daily', $this->employee('E-2')->employment_type);
        $this->assertSame('outsource', $this->employee('E-3')->employment_type);
    }

    public function test_the_words_a_spreadsheet_actually_uses_are_understood(): void
    {
        $this->import([
            $this->row('E-1', '', 'A Satu', 'Kontrak'),
            $this->row('E-2', '', 'B Dua', 'HARIAN'),
            $this->row('E-3', '', 'C Tiga', 'Magang'),
            $this->row('E-4', '', 'D Empat', 'Tetap'),
            $this->row('E-5', '', 'E Lima', ' Alih Daya '),
        ]);

        $this->assertSame('pkwt', $this->employee('E-1')->employment_type);
        $this->assertSame('daily', $this->employee('E-2')->employment_type);
        $this->assertSame('intern', $this->employee('E-3')->employment_type);
        $this->assertSame('pkwtt', $this->employee('E-4')->employment_type);
        $this->assertSame('outsource', $this->employee('E-5')->employment_type);
    }

    public function test_an_unknown_workforce_type_falls_back_without_costing_the_row(): void
    {
        $result = $this->import([$this->row('E-1', '', 'A Satu', 'sesuatu yang lain')]);

        $this->assertSame(1, $result['imported']);
        $this->assertSame('pkwtt', $this->employee('E-1')->employment_type);
    }

    public function test_a_file_without_the_column_still_imports_as_permanent(): void
    {
        $oldHeadings = [
            'Employee ID', 'Full Name', 'Department', 'Position', 'Project', 'Phone', 'Email',
            'Address', 'Emergency Contact Name', 'Emergency Contact Phone', 'Join Date', 'Employment Status',
        ];

        $result = $this->import(
            [['E-1', 'A Satu', 'HSE', '', '', '', '', '', '', '', '2026-01-15', 'active']],
            null,
            $oldHeadings
        );

        $this->assertSame(1, $result['imported']);
        $this->assertSame('pkwtt', $this->employee('E-1')->employment_type);
        $this->assertNull($this->employee('E-1')->nik);
        $this->assertSame('2026-01-15', $this->employee('E-1')->join_date?->toDateString());
    }

    // --- NIK --------------------------------------------------------------

    public function test_a_national_id_is_stored(): void
    {
        $this->import([$this->row('E-1', '3201010101900001', 'A Satu', 'pkwtt')]);

        $this->assertSame('3201010101900001', $this->employee('E-1')->nik);
    }

    /**
     * THE EMPTY-STRING TRAP. `unique(company_id, nik)` ignores NULLs but
     * treats two empty strings as a collision, so a blank cell written as
     * "" would let exactly one employee have no national ID and refuse
     * every one after.
     */
    public function test_many_employees_may_have_no_national_id(): void
    {
        $result = $this->import([
            $this->row('E-1', '', 'A Satu', 'pkwtt'),
            $this->row('E-2', '', 'B Dua', 'pkwtt'),
            $this->row('E-3', '   ', 'C Tiga', 'pkwtt'),
        ]);

        $this->assertSame(3, $result['imported']);
        $this->assertSame([], $result['skipped']);

        foreach (['E-1', 'E-2', 'E-3'] as $id) {
            $this->assertNull($this->employee($id)->nik, "{$id} must store NULL, not an empty string.");
        }
    }

    public function test_a_national_id_repeated_inside_the_file_is_reported(): void
    {
        $result = $this->import([
            $this->row('E-1', '3201010101900001', 'A Satu', 'pkwtt'),
            $this->row('E-2', '3201010101900001', 'B Dua', 'pkwtt'),
        ]);

        $this->assertSame(1, $result['imported']);
        $this->assertStringContainsString('Duplicate NIK', $result['skipped'][0]['reason']);
        $this->assertSame(3, $result['skipped'][0]['row'], 'Header 1, first record 2, so the repeat is row 3.');
    }

    public function test_a_national_id_already_in_this_company_is_reported_not_thrown(): void
    {
        Employee::create([
            'employee_id' => 'EXISTING', 'nik' => '3201010101900001', 'full_name' => 'Sudah Ada',
            'company_id' => $this->yard->id, 'status' => 'active',
        ]);

        $result = $this->import([$this->row('E-1', '3201010101900001', 'A Satu', 'pkwtt')]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('already used in this Operating Unit', $result['skipped'][0]['reason']);
    }

    /**
     * The index is per company, so the same person's national ID may
     * legitimately appear in a second operating unit of the same tenant.
     */
    public function test_the_same_national_id_may_exist_in_another_operating_unit(): void
    {
        $this->import([$this->row('E-1', '3201010101900001', 'A Satu', 'pkwtt')]);
        $result = $this->import([$this->row('E-9', '3201010101900001', 'A Satu', 'pkwtt')], $this->dock);

        $this->assertSame(1, $result['imported']);
        $this->assertSame('3201010101900001', $this->employee('E-9')->nik);
    }

    /**
     * A NIK long enough to be a real KTP number must survive as typed —
     * 16 digits, not rounded, not turned into scientific notation.
     */
    public function test_a_sixteen_digit_national_id_is_not_mangled(): void
    {
        $this->import([$this->row('E-1', '3201010101900001', 'A Satu', 'pkwtt')]);

        $this->assertSame('3201010101900001', $this->employee('E-1')->nik);
        $this->assertSame(16, strlen($this->employee('E-1')->nik));
    }

    // --- the template itself ---------------------------------------------

    public function test_the_official_template_carries_both_columns(): void
    {
        $response = $this->actingAs($this->admin)->get(route('employees.import-template'));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray();

        $this->assertSame(self::HEADINGS, array_slice($rows[0], 0, count(self::HEADINGS)));
        $this->assertSame('3201010101900001', (string) $rows[1][1], 'The example NIK must be shown as digits.');
        $this->assertSame('pkwtt', $rows[1][5]);
    }

    public function test_the_official_template_still_imports_end_to_end(): void
    {
        $response = $this->actingAs($this->admin)->get(route('employees.import-template'));
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => new UploadedFile($path, 'employee-import-template.xlsx', null, null, true),
            'company_id' => $this->yard->id,
        ])->assertOk()->assertJson(['total_rows' => 1, 'imported' => 1, 'skipped' => [], 'warnings' => []]);

        $john = $this->employee('EMP-0001');

        $this->assertSame('John Doe', $john->full_name);
        $this->assertSame('3201010101900001', $john->nik);
        $this->assertSame('pkwtt', $john->employment_type);
        $this->assertSame('2026-01-15', $john->join_date?->toDateString());
    }

    /**
     * The customer's own column names for these two fields have to be
     * recognised as well, or Smart Import would silently leave them out.
     */
    public function test_the_inspector_recognises_indonesian_names_for_both_fields(): void
    {
        $file = $this->upload(
            [['E-1', '3201010101900001', 'A Satu', 'HSE', 'Jenis']],
            ['ID Karyawan', 'No. KTP', 'Nama Lengkap', 'Departemen', 'Jenis Karyawan']
        );

        $response = $this->actingAs($this->admin)->postJson(route('employees.import.inspect'), ['file' => $file]);
        $response->assertOk();

        $mapping = collect($response->json('mapping'))->map(fn ($m) => $m['heading'])->all();

        $this->assertSame('No. KTP', $mapping['nik'] ?? null);
        $this->assertSame('Jenis Karyawan', $mapping['employment_type'] ?? null);
        $this->assertSame('ID Karyawan', $mapping['employee_id'] ?? null, 'Bare "NIK" still belongs to the staff number.');
    }
}
