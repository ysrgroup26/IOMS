<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompetencyType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompetency;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * THE PDF PATHS THAT ACTUALLY EXIST FOR AN EMPLOYEE, AND THE ONE THAT
 * DOES NOT.
 *
 * IOMS does two separable things with PDFs, and conflating them is how
 * scope creeps:
 *
 *  1. GENERATION — the employee register, rendered by dompdf from IOMS's
 *     own data. Exists, tenant-scoped, covered here and in
 *     EmployeeExportRoundTripTest.
 *
 *  2. ATTACHMENT — a scanned certificate filed against a person. Exists
 *     for EmployeeCompetency.
 *
 * What does NOT exist, and is deliberately not being built: reading
 * employee DATA out of a PDF. Nothing in IOMS parses a PDF into fields;
 * the import pipeline is Excel only and refuses a PDF outright. That
 * refusal is asserted below so "the PDF import" cannot be assumed to
 * exist by anybody reading only the test names.
 *
 * Until v2.108.1 the attachment path was half-built: the file was
 * accepted, written to the PUBLIC disk, and then offered through no route
 * at all. It could be uploaded and never read back.
 */
class EmployeeDocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $admin;

    private Employee $employee;

    private CompetencyType $type;

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

        $department = Department::create(['company_id' => $this->yard->id, 'name' => 'HSE']);

        $this->employee = Employee::create([
            'employee_id' => 'EMP-1', 'full_name' => 'Budi Santoso',
            'company_id' => $this->yard->id, 'department_id' => $department->id, 'status' => 'active',
        ]);

        $this->type = CompetencyType::create([
            'company_id' => $this->yard->id, 'name' => 'Working at Height', 'type' => 'certification',
        ]);

        $this->admin = $this->user(User::ROLE_SUPER_ADMIN);
    }

    private function user(string $role, ?Tenant $tenant = null, ?Company $company = null): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'tenant_id' => ($tenant ?? $this->tenant)->id,
            'company_id' => ($company ?? $this->yard)->id,
            'is_active' => true,
        ]);
    }

    private function pdf(string $name = 'sertifikat.pdf', int $kb = 40): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->admin)->post(
            route('employees.competencies.store', $this->employee->id),
            [
                'competency_type_id' => $this->type->id,
                'achieved_date' => now()->subMonth()->toDateString(),
                'attachment' => $file,
            ]
        );
    }

    // --- 1. attachment: upload, storage, association ----------------------

    public function test_a_certificate_pdf_is_accepted_and_filed_against_the_employee(): void
    {
        $this->upload($this->pdf())->assertSessionHasNoErrors();

        $competency = EmployeeCompetency::withoutGlobalScopes()->sole();

        $this->assertSame($this->employee->id, $competency->employee_id);
        $this->assertNotNull($competency->attachment_path);
    }

    /**
     * `config/filesystems.php` names competency certificates as private
     * documents. They were being written to the public disk, reachable by
     * URL with no authentication at all.
     */
    public function test_a_certificate_is_stored_privately_not_on_the_public_disk(): void
    {
        $this->upload($this->pdf());

        $path = EmployeeCompetency::withoutGlobalScopes()->sole()->attachment_path;

        $this->assertTrue(Storage::disk('private')->exists($path), 'The certificate must land on the private disk.');
        $this->assertFalse(Storage::disk('public')->exists($path), 'It must not be reachable through /storage.');
    }

    public function test_an_oversized_or_wrong_type_file_is_refused(): void
    {
        $this->actingAs($this->admin)->post(
            route('employees.competencies.store', $this->employee->id),
            [
                'competency_type_id' => $this->type->id,
                'achieved_date' => now()->toDateString(),
                'attachment' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
            ]
        )->assertSessionHasErrors('attachment');

        $this->assertSame(0, EmployeeCompetency::withoutGlobalScopes()->count());
    }

    // --- 2. download: authorization and the tenant boundary ---------------

    public function test_the_owner_tenant_can_download_the_certificate(): void
    {
        $this->upload($this->pdf());
        $competency = EmployeeCompetency::withoutGlobalScopes()->sole();

        $response = $this->actingAs($this->admin)->get(route('secure-documents.show', [
            'type' => 'employee-competency', 'id' => $competency->id,
        ]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_a_guest_cannot_download_a_certificate(): void
    {
        $this->upload($this->pdf());
        $competency = EmployeeCompetency::withoutGlobalScopes()->sole();

        /*
         * `actingAs()` inside the upload helper persists for the whole
         * test, so this has to put the guards back before it can claim to
         * be testing a guest -- otherwise the request is still the admin's
         * and a 200 reads as a security hole that is not there.
         */
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->get(route('secure-documents.show', [
            'type' => 'employee-competency', 'id' => $competency->id,
        ]))->assertRedirect(route('login'));
    }

    /** 404, not 403: a foreign id must not confirm the record exists. */
    public function test_another_tenant_cannot_download_the_certificate(): void
    {
        $this->upload($this->pdf());
        $competency = EmployeeCompetency::withoutGlobalScopes()->sole();

        $otherTenant = Tenant::create([
            'name' => 'Pesaing', 'slug' => 'p-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Lain', 'code' => 'YL', 'tenant_id' => $otherTenant->id, 'is_active' => true,
        ]);
        Subscription::create([
            'tenant_id' => $otherTenant->id, 'package_id' => Package::where('slug', 'business')->sole()->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);
        $otherTenant->workspaces()->sync(Workspace::pluck('id'));

        $intruder = $this->user(User::ROLE_SUPER_ADMIN, $otherTenant, $otherCompany);

        app(CurrentTenant::class)->set($otherTenant->fresh());

        $this->actingAs($intruder)->get(route('secure-documents.show', [
            'type' => 'employee-competency', 'id' => $competency->id,
        ]))->assertNotFound();
    }

    public function test_an_unknown_document_type_is_not_served(): void
    {
        $this->actingAs($this->admin)->get('/secure-documents/employee-salary/1')->assertNotFound();
    }

    public function test_a_record_with_no_attachment_is_not_served(): void
    {
        $competency = EmployeeCompetency::create([
            'employee_id' => $this->employee->id,
            'competency_type_id' => $this->type->id,
            'achieved_date' => now()->subMonth()->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('secure-documents.show', [
            'type' => 'employee-competency', 'id' => $competency->id,
        ]))->assertNotFound();
    }

    /** The profile has to offer the link, or the file is still unreachable. */
    public function test_the_profile_carries_a_download_url_for_the_certificate(): void
    {
        $this->upload($this->pdf());

        $response = $this->actingAs($this->admin)->get(route('employees.show', $this->employee->id));
        $response->assertOk();

        $competencies = $response->viewData('page')['props']['employee']['competencies'];

        $this->assertNotEmpty($competencies);
        $this->assertStringContainsString(
            '/secure-documents/employee-competency/',
            $competencies[0]['attachment_url'] ?? ''
        );
    }

    // --- 3. generation ----------------------------------------------------

    public function test_the_employee_register_is_generated_as_a_real_pdf(): void
    {
        $response = $this->actingAs($this->admin)->get(route('employees.export-pdf'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_register_cannot_be_generated_by_a_guest(): void
    {
        $this->get(route('employees.export-pdf'))->assertRedirect(route('login'));
    }

    // --- 4. what is NOT supported ----------------------------------------

    /**
     * No PDF is ever parsed into employee fields. The import refuses one,
     * and that refusal is the documented boundary of the feature.
     */
    public function test_a_pdf_is_refused_by_the_employee_import(): void
    {
        $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $this->pdf('karyawan.pdf'),
            'company_id' => $this->yard->id,
        ])->assertStatus(422);

        $this->assertSame(1, Employee::withoutGlobalScopes()->count(), 'Only the fixture employee should exist.');
    }

    public function test_a_pdf_is_refused_by_the_import_preview_too(): void
    {
        $this->actingAs($this->admin)->postJson(route('employees.import.preview'), [
            'file' => $this->pdf('karyawan.pdf'),
            'company_id' => $this->yard->id,
        ])->assertStatus(422);
    }
}
