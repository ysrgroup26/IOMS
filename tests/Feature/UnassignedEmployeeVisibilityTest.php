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
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * v2.113.0 -- AN EMPLOYEE WITH NO DEPARTMENT IS STILL AN EMPLOYEE.
 *
 * `Employee::scopeOrderedForDisplay()` INNER JOINed `departments`, so it
 * did not order the roster -- it FILTERED it, dropping everyone whose
 * `department_id` was null. That is a documented, supported state: it is
 * why `profile_status` exists and reports `needs_completion`.
 *
 * Nine call sites read that scope. The visible symptom was an import
 * reporting "N imported successfully" and then showing nothing, with the
 * records sitting in the database the whole time.
 *
 * These tests assert the rows REACH each surface. They deliberately check
 * the list, both exports and the library register separately, because the
 * scope is shared and a future change could reintroduce the filter in one
 * place.
 */
class UnassignedEmployeeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Company $yard;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => 'Galangan Aliran Jaya', 'slug' => 'gaj-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->yard = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Utama', 'code' => 'YU', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($tenant->fresh());

        $this->admin = User::create([
            'name' => 'Admin Utama', 'email' => 'admin-'.uniqid().'@gaj.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);

        // One assigned, one not. The unassigned one is the whole point.
        $department = Department::create(['company_id' => $this->yard->id, 'name' => 'Produksi']);

        Employee::create([
            'employee_id' => '0010001', 'full_name' => 'Budi Santoso',
            'company_id' => $this->yard->id, 'department_id' => $department->id,
            'status' => 'active', 'join_date' => '2026-01-15',
        ]);

        Employee::create([
            'employee_id' => '0010002', 'full_name' => 'Nurul Hidayah',
            'company_id' => $this->yard->id, 'department_id' => null,
            'status' => 'active', 'join_date' => '2026-02-01',
        ]);
    }

    public function test_the_employee_list_shows_a_person_with_no_department(): void
    {
        $props = $this->actingAs($this->admin)->get(route('employees.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(2, $props['employees']['total'], 'An employee without a department was filtered out of the list.');

        $names = array_column($props['employees']['data'], 'full_name');
        $this->assertContains('Nurul Hidayah', $names);

        // Assigned first, unassigned last -- a roster must not open with
        // everybody who is missing a department.
        $this->assertSame(['Budi Santoso', 'Nurul Hidayah'], $names);
    }

    public function test_searching_finds_a_person_with_no_department(): void
    {
        $props = $this->actingAs($this->admin)->get(route('employees.index', ['search' => 'Nurul']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(1, $props['employees']['total']);
    }

    /**
     * The filter that exists SPECIFICALLY for these people could only
     * ever have returned nothing.
     */
    public function test_the_needs_completion_filter_returns_the_people_it_names(): void
    {
        $props = $this->actingAs($this->admin)->get(route('employees.index', ['profile_status' => 'needs_completion']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(1, $props['employees']['total'], 'The needs-completion filter returned nobody.');
        $this->assertSame('Nurul Hidayah', $props['employees']['data'][0]['full_name']);
    }

    public function test_the_excel_export_contains_a_person_with_no_department(): void
    {
        $response = $this->actingAs($this->admin)->get(route('employees.export'))->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'ioms-vis-').'.xlsx';
        file_put_contents($path, $this->bytes($response));

        $text = '';
        foreach (IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false) as $row) {
            $text .= implode(' | ', array_map(fn ($v) => (string) $v, $row))."\n";
        }
        @unlink($path);

        $this->assertStringContainsString('Nurul Hidayah', $text, 'The Excel export dropped an employee with no department.');
        $this->assertStringContainsString('Budi Santoso', $text);
    }

    public function test_the_printed_register_contains_a_person_with_no_department(): void
    {
        $text = $this->pdfText($this->bytes(
            $this->actingAs($this->admin)->get(route('employees.export-pdf'))->assertOk()
        ));

        $this->assertStringContainsString('Nurul Hidayah', $text, 'The printed register dropped an employee with no department.');
    }

    public function test_the_document_library_register_contains_a_person_with_no_department(): void
    {
        $text = $this->pdfText($this->bytes(
            $this->actingAs($this->admin)
                ->get(route('documents.export', ['key' => 'employee-register', 'format' => 'pdf']))
                ->assertOk()
        ));

        $this->assertStringContainsString('Nurul Hidayah', $text, 'The library register dropped an employee with no department.');
    }

    /**
     * The end-to-end shape of the reported failure: import people with no
     * department, and find them on the list afterwards.
     */
    public function test_an_import_without_departments_is_visible_afterwards(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Employee ID', 'Full Name'],
            ['0010003', 'Dimas Prakoso'],
            ['0010004', 'Rizky Maulana'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms-vis-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $response = $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => new UploadedFile($path, 'roster.xlsx', null, null, true),
            'company_id' => $this->yard->id,
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('imported'));

        $props = $this->actingAs($this->admin)->get(route('employees.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $names = array_column($props['employees']['data'], 'full_name');

        $this->assertContains('Dimas Prakoso', $names, 'The import reported success and the list showed nothing.');
        $this->assertContains('Rizky Maulana', $names);
        $this->assertSame(4, $props['employees']['total']);
    }

    private function bytes(TestResponse $response): string
    {
        return $response->baseResponse instanceof BinaryFileResponse
            ? file_get_contents($response->baseResponse->getFile()->getPathname())
            : $response->getContent();
    }

    private function pdfText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ioms-vis-').'.pdf';
        file_put_contents($path, $bytes);
        $text = (new Parser)->parseFile($path)->getText();
        @unlink($path);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
