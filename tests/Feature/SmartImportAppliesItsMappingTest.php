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
 * SMART IMPORT HAS TO *APPLY* THE MAPPING IT SHOWS, NOT JUST DRAW IT.
 *
 * `/employees/import/inspect` reads a customer's own file, works out which
 * of their columns feeds which IOMS field, and the dialog renders that as
 * "Column Mapping" under the words *"IOMS mencocokkan kolom pada berkas
 * Anda dengan kolom yang dipakai sistem. Periksa bagian di bawah ini
 * sebelum melanjutkan"* — IOMS has matched your columns; check them before
 * continuing.
 *
 * The import then read the columns by the TENANT'S CONFIGURED MAPPING
 * instead, which for every tenant that has not visited Settings is the
 * official template's own English labels. The suggestion was never sent
 * back to the server and no endpoint accepted one.
 *
 * So the panel described work that did not happen. Two outcomes, both bad
 * and both silent in their own way:
 *
 *  - If the customer's heading for a CRITICAL field differs ("Nama
 *    Lengkap"), every row is skipped for a field the preview just said it
 *    had found.
 *  - If only OPTIONAL headings differ ("Tanggal Masuk", "Telepon"), the
 *    rows import and those columns are silently EMPTY. The customer gets a
 *    clean "N imported" and a register missing data that was in the file
 *    they uploaded. That is the dropped-value case this suite exists to
 *    prevent.
 */
class SmartImportAppliesItsMappingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

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
        Position::create(['company_id' => $this->yard->id, 'name' => 'HSE Officer']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    /**
     * A workforce list as an Indonesian yard actually keeps one: the
     * company's own column names, in their own order.
     */
    private function customerFile(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['No. Induk Karyawan', 'Nama Lengkap', 'Departemen', 'Jabatan', 'Tanggal Masuk', 'No. Telepon', 'Alamat Email'],
            ['EMP-7001', 'budi santoso', 'HSE', 'HSE Officer', '15/01/2026', '081234567890', 'budi@yard.test'],
            ['EMP-7002', 'siti aminah', 'HSE', 'HSE Officer', '01/02/2026', '081234567891', 'siti@yard.test'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'daftar-karyawan.xlsx', null, null, true);
    }

    /** @return array<string, string> field => heading, as the dialog shows it */
    private function suggestedMapping(): array
    {
        $response = $this->actingAs($this->admin)->postJson(route('employees.import.inspect'), [
            'file' => $this->customerFile(),
        ]);

        $response->assertOk();

        return collect($response->json('mapping'))->map(fn ($m) => $m['heading'])->all();
    }

    public function test_the_inspector_does_recognise_the_customers_columns(): void
    {
        $mapping = $this->suggestedMapping();

        $this->assertSame('Nama Lengkap', $mapping['full_name'] ?? null);
        $this->assertSame('Tanggal Masuk', $mapping['join_date'] ?? null);
        $this->assertSame('No. Telepon', $mapping['phone'] ?? null);
        $this->assertSame('Alamat Email', $mapping['email'] ?? null);
    }

    /**
     * THE DEFECT. The mapping above is shown to the customer, so the
     * import must read the file through it.
     */
    public function test_the_import_reads_the_file_through_the_mapping_it_showed(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $this->customerFile(),
            'company_id' => $this->yard->id,
            'column_mapping' => $this->suggestedMapping(),
        ]);

        $response->assertOk();
        $response->assertJson(['total_rows' => 2, 'imported' => 2]);

        $budi = Employee::withoutGlobalScopes()->where('employee_id', 'EMP-7001')->sole();

        $this->assertSame('Budi Santoso', $budi->full_name);
        $this->assertSame('2026-01-15', $budi->join_date?->toDateString(), 'The mapped Tanggal Masuk column must be read.');
        $this->assertSame('081234567890', $budi->phone, 'A mapped optional column must not be silently empty.');
        $this->assertSame('budi@yard.test', $budi->email);
        $this->assertNotNull($budi->department_id);
        $this->assertNotNull($budi->position_id);
    }

    /**
     * The preview is the promise the import has to keep, so it must read
     * the file the same way.
     */
    public function test_the_preview_uses_the_same_mapping(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('employees.import.preview'), [
            'file' => $this->customerFile(),
            'company_id' => $this->yard->id,
            'column_mapping' => $this->suggestedMapping(),
        ]);

        $response->assertOk();
        $response->assertJson(['total_rows' => 2, 'valid_rows' => 2, 'invalid_rows' => 0]);
        $this->assertSame(['HSE'], $response->json('departments.existing'));
    }

    public function test_create_missing_master_data_and_import_uses_the_same_mapping(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('employees.import.create-missing-and-import'), [
            'file' => $this->customerFile(),
            'company_id' => $this->yard->id,
            'column_mapping' => $this->suggestedMapping(),
        ]);

        $response->assertOk();
        $response->assertJson(['imported' => 2]);

        $this->assertSame(
            '2026-02-01',
            Employee::withoutGlobalScopes()->where('employee_id', 'EMP-7002')->sole()->join_date?->toDateString()
        );
    }

    /**
     * Without a mapping the behaviour must not change: the official
     * template, and every tenant that has configured one in Settings,
     * keeps working exactly as before.
     */
    public function test_an_import_without_a_mapping_still_uses_the_configured_one(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Employee ID', 'Full Name', 'Department', 'Position', 'Project', 'Phone', 'Email',
                'Address', 'Emergency Contact Name', 'Emergency Contact Phone', 'Join Date', 'Employment Status'],
            ['EMP-8001', 'Agus Wijaya', 'HSE', 'HSE Officer', '', '0811', 'agus@x.test', '', '', '', '2026-03-04', 'active'],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => new UploadedFile($path, 'template.xlsx', null, null, true),
            'company_id' => $this->yard->id,
        ])->assertOk()->assertJson(['imported' => 1]);

        $agus = Employee::withoutGlobalScopes()->where('employee_id', 'EMP-8001')->sole();
        $this->assertSame('2026-03-04', $agus->join_date?->toDateString());
        $this->assertSame('0811', $agus->phone);
    }

    // --- the mapping is INPUT, so it is validated -------------------------

    public function test_a_mapping_naming_an_unknown_field_is_refused(): void
    {
        $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $this->customerFile(),
            'company_id' => $this->yard->id,
            'column_mapping' => ['password' => 'Nama Lengkap'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_a_mapping_whose_value_is_not_a_string_is_refused(): void
    {
        $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $this->customerFile(),
            'company_id' => $this->yard->id,
            'column_mapping' => ['full_name' => ['nested' => 'thing']],
        ])->assertStatus(422);

        $this->assertDatabaseCount('employees', 0);
    }

    /**
     * Two IOMS fields pointed at ONE column would copy the same value into
     * both — the silent field-shifting case. Refused outright.
     */
    public function test_two_fields_may_not_claim_the_same_column(): void
    {
        $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $this->customerFile(),
            'company_id' => $this->yard->id,
            'column_mapping' => ['full_name' => 'Nama Lengkap', 'email' => 'Nama Lengkap'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('employees', 0);
    }
}
