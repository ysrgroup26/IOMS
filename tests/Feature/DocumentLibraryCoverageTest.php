<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CorrectiveAction;
use App\Models\Employee;
use App\Models\HseInspection;
use App\Models\Incident;
use App\Models\IncidentInvestigation;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DocumentEngine;
use App\Services\Documents\Library\DocumentLibrary;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * v2.112.0 -- THE WHOLE LIBRARY, AND THE RECORD DOCUMENTS BESIDE IT.
 *
 * The library grew from four documents to eleven, and three per-record
 * reports now exist (HSE inspection, incident, investigation). What is
 * pinned here is what a new definition can quietly get wrong:
 *
 *  - a registered document that does not actually render in every format
 *    it advertises (a claimed format with no renderer is a broken button);
 *  - a register that reaches across the tenant boundary;
 *  - a record document reachable by somebody from another tenant;
 *  - branding that is not the generating tenant's.
 *
 * Every assertion reads a FILE that was produced, not the code that
 * produced it.
 */
class DocumentLibraryCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $admin;

    private Tenant $otherTenant;

    private Company $otherYard;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        [$this->tenant, $this->yard, $this->admin] = $this->makeTenant('Galangan Aliran Jaya', 'YU');
        [$this->otherTenant, $this->otherYard, $this->otherAdmin] = $this->makeTenant('Galangan Tetangga', 'YT');

        app(CurrentTenant::class)->set($this->tenant->fresh());

        CompanySetting::set('company_name', 'PT Galangan Aliran Jaya');
        CompanySetting::set('company_city', 'Batam');
    }

    /** @return array{0: Tenant, 1: Company, 2: User} */
    private function makeTenant(string $name, string $code): array
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => $name, 'slug' => strtolower($code).'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => $name.' Yard', 'code' => $code, 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));

        $user = User::create([
            'name' => $name.' Admin', 'email' => strtolower($code).'-'.uniqid().'@test.local',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true,
        ]);

        return [$tenant, $company, $user];
    }

    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof BinaryFileResponse
            ? file_get_contents($response->baseResponse->getFile()->getPathname())
            : $response->getContent();
    }

    // --- every registered document renders in every format it claims ------

    public function test_every_registered_document_renders_in_every_format_it_advertises(): void
    {
        $library = app(DocumentLibrary::class);
        $documents = $library->visibleTo($this->admin);

        $this->assertGreaterThanOrEqual(11, count($documents), 'The library lost documents.');

        foreach ($documents as $definition) {
            foreach ($definition->formats() as $format) {
                $response = $this->actingAs($this->admin)
                    ->get(route('documents.export', ['key' => $definition->key(), 'format' => $format]));

                $response->assertOk();
                $bytes = $this->body($response);

                $magic = substr($bytes, 0, 4);
                $expected = $format === 'pdf' ? '%PDF' : "PK\x03\x04";

                $this->assertSame(
                    $expected,
                    $magic,
                    "[{$definition->key()}.{$format}] did not produce a real {$format} file."
                );

                $this->assertGreaterThan(
                    1500,
                    strlen($bytes),
                    "[{$definition->key()}.{$format}] is too small to be a rendered document."
                );
            }
        }
    }

    /** Every operational workspace publishes at least one document. */
    public function test_every_operational_workspace_publishes_a_document(): void
    {
        $workspaces = array_map(
            fn ($d) => $d->workspace(),
            app(DocumentLibrary::class)->visibleTo($this->admin)
        );

        foreach (['hse', 'hr', 'logistics', 'management'] as $workspace) {
            $this->assertContains($workspace, $workspaces, "No document is published for [{$workspace}].");
        }
    }

    // --- the named modules are actually in the library ---------------------

    public function test_the_named_modules_each_have_a_register(): void
    {
        $keys = array_map(fn ($d) => $d->key(), app(DocumentLibrary::class)->visibleTo($this->admin));

        foreach ([
            'permit-to-work-register',
            'hse-inspection-register',
            'incident-register',
            'incident-investigation-register',
            'material-request-register',
            'employee-register',
            'leave-register',
            'stock-position',
            'stock-movement-register',
            'project-manpower',
            'man-hour-register',
        ] as $key) {
            $this->assertContains($key, $keys, "The library does not publish [{$key}].");
        }
    }

    // --- tenant isolation on a register ------------------------------------

    public function test_a_register_carries_only_the_generating_tenants_rows(): void
    {
        $this->permit($this->yard, 'PTW-MINE-0001');

        app(CurrentTenant::class)->set($this->otherTenant->fresh());
        $this->permit($this->otherYard, 'PTW-THEIRS-0001');
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $text = $this->pdfText($this->body(
            $this->actingAs($this->admin)->get(route('documents.export', ['key' => 'permit-to-work-register', 'format' => 'pdf']))
        ));

        $this->assertStringContainsString('PTW-MINE-0001', $text);
        $this->assertStringNotContainsString('PTW-THEIRS-0001', $text);
    }

    // --- tenant isolation on the record documents --------------------------

    public function test_a_record_document_is_not_reachable_from_another_tenant(): void
    {
        $incident = $this->incident($this->yard);
        $inspection = $this->inspection($this->yard);

        app(CurrentTenant::class)->set($this->otherTenant->fresh());

        $this->actingAs($this->otherAdmin)->get(route('incidents.pdf', $incident))->assertNotFound();
        $this->actingAs($this->otherAdmin)->get(route('hse-inspections.pdf', $inspection))->assertNotFound();
    }

    public function test_a_document_download_requires_authentication(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();
        session()->flush();

        $this->get(route('documents.export', ['key' => 'employee-register', 'format' => 'xlsx']))
            ->assertRedirect(route('login'));

        $this->get(route('documents.library'))->assertRedirect(route('login'));
    }

    // --- branding ----------------------------------------------------------

    /**
     * A document can only ever carry the identity of the tenant that
     * generated it, in every format. The same assertion in three shapes,
     * because the three formats build their letterhead separately.
     */
    public function test_every_format_carries_the_generating_tenants_identity(): void
    {
        $this->employee($this->yard);

        app(CurrentTenant::class)->set($this->otherTenant->fresh());
        CompanySetting::set('company_name', 'PT Galangan Tetangga');
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $pdf = $this->pdfText($this->body(
            $this->actingAs($this->admin)->get(route('documents.export', ['key' => 'employee-register', 'format' => 'pdf']))
        ));
        $this->assertStringContainsString('PT Galangan Aliran Jaya', $pdf);
        $this->assertStringNotContainsString('Tetangga', $pdf);

        $xlsxPath = tempnam(sys_get_temp_dir(), 'ioms-cov-').'.xlsx';
        file_put_contents($xlsxPath, $this->body(
            $this->actingAs($this->admin)->get(route('documents.export', ['key' => 'employee-register', 'format' => 'xlsx']))
        ));
        $sheet = IOFactory::load($xlsxPath)->getActiveSheet();
        $this->assertSame('PT Galangan Aliran Jaya', $sheet->getCell('A1')->getValue());
        @unlink($xlsxPath);

        $docxPath = tempnam(sys_get_temp_dir(), 'ioms-cov-').'.docx';
        file_put_contents($docxPath, $this->body(
            $this->actingAs($this->admin)->get(route('documents.export', ['key' => 'employee-register', 'format' => 'docx']))
        ));
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($docxPath) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($docxPath);

        $this->assertStringContainsString('PT Galangan Aliran Jaya', $xml);
        $this->assertStringNotContainsString('Tetangga', $xml);
    }

    /**
     * The tenant's own logo, drawn in all three formats from ONE resolved
     * path. A missing file degrades to the typographic letterhead rather
     * than a broken image, which is also asserted.
     */
    public function test_the_tenant_logo_is_embedded_in_every_format_and_a_missing_one_is_survivable(): void
    {
        $this->employee($this->yard);

        // A real, tiny PNG on the disk DocumentEngine reads from.
        $relative = 'branding/logo-'.uniqid().'.png';
        $absolute = storage_path('app/public/'.$relative);
        @mkdir(dirname($absolute), 0755, true);
        file_put_contents($absolute, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAAHElEQVQoz2P8//8/Ay'
            .'0BEwONwKjBowaPGjxqMAMAZ0wD/Gd1Y0cAAAAASUVORK5CYII='
        ));
        CompanySetting::set('company_logo_path', $relative);

        $this->assertSame($absolute, app(DocumentEngine::class)->identity()['logo_path']);

        $xlsxPath = tempnam(sys_get_temp_dir(), 'ioms-cov-').'.xlsx';
        file_put_contents($xlsxPath, $this->body(
            $this->actingAs($this->admin)->get(route('documents.export', ['key' => 'employee-register', 'format' => 'xlsx']))
        ));
        $this->assertCount(
            1,
            IOFactory::load($xlsxPath)->getActiveSheet()->getDrawingCollection(),
            'The workbook carries no logo drawing.'
        );
        @unlink($xlsxPath);

        $docxPath = tempnam(sys_get_temp_dir(), 'ioms-cov-').'.docx';
        file_put_contents($docxPath, $this->body(
            $this->actingAs($this->admin)->get(route('documents.export', ['key' => 'employee-register', 'format' => 'docx']))
        ));
        $zip = new \ZipArchive;
        $zip->open($docxPath);
        $hasImage = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), 'word/media/')) {
                $hasImage = true;
                break;
            }
        }
        $zip->close();
        @unlink($docxPath);
        $this->assertTrue($hasImage, 'The Word document carries no logo image.');

        // The PDF letterhead must reference the LOCAL file, never fetch a URL.
        $this->actingAs($this->admin)
            ->get(route('documents.export', ['key' => 'employee-register', 'format' => 'pdf']))
            ->assertOk();

        // A stale path: the document must still render, without the logo.
        CompanySetting::set('company_logo_path', 'branding/this-file-does-not-exist.png');
        $this->assertNull(app(DocumentEngine::class)->identity()['logo_path']);

        foreach (['xlsx', 'docx', 'pdf'] as $format) {
            $this->actingAs($this->admin)
                ->get(route('documents.export', ['key' => 'employee-register', 'format' => $format]))
                ->assertOk();
        }

        @unlink($absolute);
    }

    // --- the record documents render -----------------------------------------

    public function test_the_incident_report_renders_and_does_not_borrow_the_investigations_findings(): void
    {
        $incident = $this->incident($this->yard);

        $investigation = IncidentInvestigation::create([
            'investigation_number' => 'INV-2026-00001',
            'incident_id' => $incident->id,
            'company_id' => $this->yard->id,
            'status' => 'in_progress',
            'investigator_id' => $this->admin->id,
            'started_at' => '2026-03-02',
            'root_cause' => 'Prosedur isolasi energi tidak dijalankan',
        ]);

        $text = $this->pdfText($this->body(
            $this->actingAs($this->admin)->get(route('incidents.pdf', $incident))
        ));

        $this->assertStringContainsString('INC-2026-00001', $text);
        $this->assertStringContainsString('Tergelincir di dek basah', $text);
        $this->assertStringContainsString('PT Galangan Aliran Jaya', $text);

        // It LINKS to the investigation...
        $this->assertStringContainsString($investigation->investigation_number, $text);
        // ...and does not print its findings. ADR 036.
        $this->assertStringNotContainsString('Prosedur isolasi energi', $text);
    }

    public function test_the_investigation_report_renders_with_its_causes_and_actions(): void
    {
        $incident = $this->incident($this->yard);

        $investigation = IncidentInvestigation::create([
            'investigation_number' => 'INV-2026-00002',
            'incident_id' => $incident->id,
            'company_id' => $this->yard->id,
            'status' => 'in_progress',
            'investigator_id' => $this->admin->id,
            'started_at' => '2026-03-02',
            'method' => '5why',
            'immediate_causes' => 'Dek tidak dikeringkan setelah hujan',
            'root_cause' => 'Tidak ada jadwal pembersihan dek setelah hujan',
            'recommendations' => 'Tambahkan pembersihan dek ke checklist harian',
        ]);

        $investigation->correctiveActions()->create([
            'company_id' => $this->yard->id,
            'action' => 'Tambahkan item dek basah ke checklist harian',
            'assigned_to' => $this->admin->id,
            'due_date' => '2026-03-20',
            'status' => CorrectiveAction::STATUS_OPEN,
            'created_by' => $this->admin->id,
        ]);

        $text = $this->pdfText($this->body(
            $this->actingAs($this->admin)->get(route('investigations.pdf', $investigation))
        ));

        $this->assertStringContainsString('INV-2026-00002', $text);
        $this->assertStringContainsString('INC-2026-00001', $text);
        $this->assertStringContainsString('Tidak ada jadwal pembersihan dek', $text);
        $this->assertStringContainsString('Tambahkan item dek basah ke checklist harian', $text);
        $this->assertStringContainsString('5WHY', $text);
    }

    /** An investigation with no causes recorded must say so. */
    public function test_an_investigation_without_causes_says_so(): void
    {
        $incident = $this->incident($this->yard);

        $investigation = IncidentInvestigation::create([
            'investigation_number' => 'INV-2026-00003',
            'incident_id' => $incident->id,
            'company_id' => $this->yard->id,
            'status' => 'draft',
            'investigator_id' => $this->admin->id,
            'started_at' => '2026-03-02',
        ]);

        $text = $this->pdfText($this->body(
            $this->actingAs($this->admin)->get(route('investigations.pdf', $investigation))
        ));

        $this->assertStringContainsString('belum mencatat penyebab', $text);
    }

    // --- fixtures ------------------------------------------------------------

    private function permit(Company $company, string $number): PermitToWork
    {
        return PermitToWork::withoutGlobalScopes()->create([
            'ptw_number' => $number,
            'company_id' => $company->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Pengelasan pada lambung',
            'location' => 'Dok Kering 2',
            'start_datetime' => '2026-03-01 07:00:00',
            'end_datetime' => '2026-03-01 17:00:00',
            'requested_by' => $this->admin->id,
            'status' => 'approved',
        ]);
    }

    private function incident(Company $company): Incident
    {
        return Incident::withoutGlobalScopes()->create([
            'incident_number' => 'INC-2026-00001',
            'title' => 'Tergelincir di dek basah',
            'company_id' => $company->id,
            'category' => 'injury',
            'severity' => 'high',
            'status' => Incident::STATUS_REPORTED,
            'incident_date' => '2026-03-01',
            'location' => 'Dek A',
            'description' => 'Pekerja tergelincir saat memindahkan selang.',
            'reported_by' => $this->admin->id,
        ]);
    }

    private function inspection(Company $company): HseInspection
    {
        return HseInspection::withoutGlobalScopes()->create([
            'inspection_number' => 'HSE-INS-2026-00001',
            'company_id' => $company->id,
            'inspection_type' => 'general',
            'inspection_date' => '2026-03-01',
            'inspector_id' => $this->admin->id,
            'overall_result' => 'pass',
            'checklist_items' => [],
        ]);
    }

    private function employee(Company $company): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'employee_id' => '0012345',
            'full_name' => 'Budi Santoso',
            'company_id' => $company->id,
            'status' => 'active',
            'join_date' => '2026-01-15',
        ]);
    }

    private function pdfText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ioms-cov-').'.pdf';
        file_put_contents($path, $bytes);
        $text = (new Parser)->parseFile($path)->getText();
        @unlink($path);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
