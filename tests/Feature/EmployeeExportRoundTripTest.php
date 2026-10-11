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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * WHAT THE EXCEL EXPORT ACTUALLY CONTAINS, AND WHETHER IOMS CAN READ IT
 * BACK.
 *
 * The suite already asserted that the export DOWNLOADS. It never opened
 * the file. Two things therefore went unchecked:
 *
 *  1. The tenant boundary, at the level of rows in a delivered file
 *     rather than of a query in isolation. This is the artefact that
 *     leaves the building -- v1.10.5 shipped a version of this export
 *     that put every tenant's roster in one spreadsheet, so the
 *     assertion belongs on the bytes.
 *
 *  2. The round trip. Exporting one operating unit and importing it into
 *     another is a real migration move, and the export writes a join date
 *     as "15 Jan 2026" and an empty cell as "-". Under the old date
 *     handling, re-importing its own export turned every blank date into
 *     1 January 1970 -- the export was a defect carrier. Pinned here so
 *     the two formats cannot drift apart again.
 */
class EmployeeExportRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private Company $dock;

    private User $admin;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $this->tenant = $this->makeTenant('Galangan');
        $this->yard = $this->makeCompany($this->tenant, 'Yard Utama', 'YU');
        $this->dock = $this->makeCompany($this->tenant, 'Dock Kedua', 'DK');

        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->department = Department::create(['company_id' => $this->yard->id, 'name' => 'HSE']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    private function makeTenant(string $name): Tenant
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        return $tenant;
    }

    private function makeCompany(Tenant $tenant, string $name, string $code): Company
    {
        return Company::withoutGlobalScopes()->create([
            'name' => $name, 'code' => $code.'-'.uniqid(), 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);
    }

    /** @return array<int, array<int, mixed>> the exported sheet, as rows */
    private function exportedRows(array $query = []): array
    {
        $response = $this->actingAs($this->admin)->get(route('employees.export', $query));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'ioms-export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path)->getActiveSheet()->toArray();
    }

    public function test_the_export_contains_the_tenants_own_employees(): void
    {
        Employee::create([
            'employee_id' => 'EMP-1', 'full_name' => 'Budi Santoso', 'company_id' => $this->yard->id,
            'department_id' => $this->department->id, 'status' => 'active', 'join_date' => '2026-01-15',
        ]);

        $rows = $this->exportedRows();

        $this->assertSame(
            ['Employee ID', 'Full Name', 'Company', 'Department', 'Position', 'Status', 'Join Date', 'Phone'],
            $rows[0]
        );
        $this->assertSame('EMP-1', $rows[1][0]);
        $this->assertSame('Budi Santoso', $rows[1][1]);
        $this->assertSame('15 Jan 2026', $rows[1][6]);
    }

    /**
     * The row-level version of the v1.10.5 fix: the delivered file must
     * not contain another customer's people.
     */
    public function test_another_tenants_employees_are_not_in_the_file(): void
    {
        $otherTenant = $this->makeTenant('Pesaing');
        $otherCompany = $this->makeCompany($otherTenant, 'Yard Lain', 'YL');
        $otherDepartment = Department::withoutGlobalScopes()->create([
            'company_id' => $otherCompany->id, 'name' => 'HSE',
        ]);

        Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-RAHASIA', 'full_name' => 'Orang Pesaing', 'company_id' => $otherCompany->id,
            'department_id' => $otherDepartment->id, 'status' => 'active',
        ]);
        Employee::create([
            'employee_id' => 'EMP-1', 'full_name' => 'Budi Santoso', 'company_id' => $this->yard->id,
            'department_id' => $this->department->id, 'status' => 'active',
        ]);

        $rows = $this->exportedRows();
        $flat = json_encode($rows);

        $this->assertStringNotContainsString('EMP-RAHASIA', $flat);
        $this->assertStringNotContainsString('Orang Pesaing', $flat);
        $this->assertCount(2, $rows, 'Header plus exactly one employee.');
    }

    /**
     * THE ROUND TRIP. Export one operating unit, import it into another:
     * the dates must survive and a "-" placeholder must not become a
     * date at all.
     */
    public function test_an_exported_file_can_be_imported_into_another_operating_unit(): void
    {
        Employee::create([
            'employee_id' => 'EMP-1', 'full_name' => 'Budi Santoso', 'company_id' => $this->yard->id,
            'department_id' => $this->department->id, 'status' => 'active', 'join_date' => '2026-01-15',
        ]);
        Employee::create([
            'employee_id' => 'EMP-2', 'full_name' => 'Siti Aminah', 'company_id' => $this->yard->id,
            'department_id' => $this->department->id, 'status' => 'active', 'join_date' => null,
        ]);

        $response = $this->actingAs($this->admin)->get(route('employees.export'));
        $path = tempnam(sys_get_temp_dir(), 'ioms-export').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        Department::create(['company_id' => $this->dock->id, 'name' => 'HSE']);

        $imported = $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => new UploadedFile($path, 'employees.xlsx', null, null, true),
            'company_id' => $this->dock->id,
        ]);

        $imported->assertOk();
        $imported->assertJson(['total_rows' => 2, 'imported' => 2]);

        $moved = Employee::withoutGlobalScopes()
            ->where('company_id', $this->dock->id)->where('employee_id', 'EMP-1')->sole();
        $this->assertSame('2026-01-15', $moved->join_date?->toDateString(), 'The exported "15 Jan 2026" must read back as the same day.');

        $blank = Employee::withoutGlobalScopes()
            ->where('company_id', $this->dock->id)->where('employee_id', 'EMP-2')->sole();
        $this->assertNull($blank->join_date, 'The export writes "-" for an empty date; it must not become 1970.');
    }

    /**
     * The printable register is the export's counterpart and must answer
     * on the same data. Dompdf renders it for real here -- no mock.
     */
    public function test_the_printable_register_renders_for_the_same_rows(): void
    {
        Employee::create([
            'employee_id' => 'EMP-1', 'full_name' => 'Budi Santoso', 'company_id' => $this->yard->id,
            'department_id' => $this->department->id, 'status' => 'active', 'join_date' => '2026-01-15',
        ]);

        $response = $this->actingAs($this->admin)->get(route('employees.export-pdf'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent(), 'Dompdf must have produced a real PDF, not an error page.');
    }
}
