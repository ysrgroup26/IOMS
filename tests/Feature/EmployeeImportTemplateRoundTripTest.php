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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * THE ROUND TRIP: THE FILE WE HAND OUT, FED BACK IN UNCHANGED.
 *
 * The suite already checked the template's headings against the import's
 * field names, and separately that the template downloads. Neither of
 * those is the thing a customer actually does, which is: press Download
 * Template, type into it, press Import. Nothing asserted that the bytes
 * IOMS generates are bytes IOMS can read back -- so a change to either
 * side could have broken the golden path with the whole suite still green.
 *
 * Every test here starts from the REAL route, over HTTP, as the real
 * downloaded file. No hand-built spreadsheet stands in for it.
 */
class EmployeeImportTemplateRoundTripTest extends TestCase
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

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    /**
     * The official template exactly as a customer receives it: fetched
     * over HTTP from the real route and written to disk as the browser
     * would have written it.
     */
    private function downloadedTemplate(): string
    {
        $response = $this->actingAs($this->admin)->get(route('employees.import-template'));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'ioms-template').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return $path;
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, 'employee-import-template.xlsx', null, null, true);
    }

    public function test_the_template_downloads_as_a_readable_spreadsheet(): void
    {
        $path = $this->downloadedTemplate();

        $this->assertGreaterThan(0, filesize($path));

        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('Employee ID', $sheet->getCell('A1')->getValue());
        $this->assertSame('Join Date', $sheet->getCell('K1')->getValue());
    }

    /**
     * The golden path, start to finish: download, import, employee exists.
     */
    public function test_the_untouched_template_imports_its_example_row(): void
    {
        Department::create(['company_id' => $this->yard->id, 'name' => 'HSE']);
        Position::create(['company_id' => $this->yard->id, 'name' => 'HSE Officer']);

        $response = $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $this->upload($this->downloadedTemplate()),
            'company_id' => $this->yard->id,
        ]);

        $response->assertOk();
        $response->assertJson(['total_rows' => 1, 'imported' => 1, 'skipped' => [], 'warnings' => []]);

        $employee = Employee::withoutGlobalScopes()->where('employee_id', 'EMP-0001')->sole();

        $this->assertSame('John Doe', $employee->full_name);
        $this->assertSame($this->yard->id, $employee->company_id);
        $this->assertSame('active', $employee->status);
        $this->assertSame('john.doe@example.com', $employee->email);
        $this->assertSame('081234567890', $employee->phone);
    }

    /**
     * The example row's own date is the format the template teaches. If
     * this is ever wrong, the template is teaching customers a format the
     * importer mishandles -- which is how the 1970 defect reached real
     * data in the first place.
     */
    public function test_the_example_rows_join_date_is_read_as_the_template_prints_it(): void
    {
        Department::create(['company_id' => $this->yard->id, 'name' => 'HSE']);

        $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $this->upload($this->downloadedTemplate()),
            'company_id' => $this->yard->id,
        ])->assertOk();

        $employee = Employee::withoutGlobalScopes()->where('employee_id', 'EMP-0001')->sole();

        $this->assertSame('2026-01-15', $employee->join_date?->toDateString());
    }

    /**
     * The template's own example must not arrive needing repair: its
     * department is a name a real tenant has to already have, and the
     * preview is what tells them so before anything is written.
     */
    public function test_the_template_previews_cleanly_and_names_the_master_data_it_needs(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('employees.import.preview'), [
            'file' => $this->upload($this->downloadedTemplate()),
            'company_id' => $this->yard->id,
        ]);

        $response->assertOk();
        $response->assertJson([
            'total_rows' => 1,
            'valid_rows' => 1,
            'invalid_rows' => 0,
            'skipped' => [],
            'warnings' => [],
            'has_missing_master_data' => true,
        ]);

        $this->assertSame(['HSE'], $response->json('departments.new'));
        $this->assertSame(['HSE Officer'], $response->json('positions.new'));
        $this->assertDatabaseCount('employees', 0);
    }

    /**
     * And the flow a first-time customer actually takes: the template's
     * department and position do not exist yet, so they are created from
     * the preview and the row lands in the same request.
     */
    public function test_a_first_time_tenant_can_create_the_master_data_and_import_in_one_step(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('employees.import.create-missing-and-import'), [
            'file' => $this->upload($this->downloadedTemplate()),
            'company_id' => $this->yard->id,
            'new_departments' => ['HSE'],
            'new_positions' => ['HSE Officer'],
        ]);

        $response->assertOk();
        $response->assertJson(['imported' => 1]);

        $employee = Employee::withoutGlobalScopes()->where('employee_id', 'EMP-0001')->sole();

        $this->assertNotNull($employee->department_id, 'The department created in this request must be assigned.');
        $this->assertNotNull($employee->position_id, 'The position created in this request must be assigned.');
        $this->assertSame('2026-01-15', $employee->join_date?->toDateString());
    }

    /**
     * The template is the import's own contract. Asserted against the
     * generated FILE rather than the class's `headings()` array, because
     * the file is what a customer has.
     */
    public function test_every_heading_in_the_generated_file_is_a_field_the_import_reads(): void
    {
        $sheet = IOFactory::load($this->downloadedTemplate())->getActiveSheet();

        $headings = [];
        foreach ($sheet->getRowIterator(1, 1) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() !== null) {
                    $headings[] = \Illuminate\Support\Str::snake(strtolower((string) $cell->getValue()));
                }
            }
        }

        $readByTheImport = [
            'employee_id', 'full_name', 'department', 'position', 'project', 'phone', 'email',
            'address', 'emergency_contact_name', 'emergency_contact_phone', 'join_date', 'employment_status',
        ];

        $this->assertSame($readByTheImport, $headings);
    }

    /**
     * A tenant may not pull another tenant's rows in by naming their
     * operating unit on the import request.
     */
    public function test_the_import_refuses_an_operating_unit_outside_the_tenant(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Pesaing', 'slug' => 'p-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Lain', 'code' => 'YL', 'tenant_id' => $otherTenant->id, 'is_active' => true,
        ]);

        $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $this->upload($this->downloadedTemplate()),
            'company_id' => $otherCompany->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('employees', 0);
    }
}
