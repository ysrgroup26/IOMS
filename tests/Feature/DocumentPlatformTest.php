<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CorrectiveAction;
use App\Models\HseInspection;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * v2.110.0 -- THE DOCUMENT PLATFORM, PROVEN ON REAL FILES.
 *
 * Everything here asserts against a FILE that was actually produced and
 * then READ BACK with a different library than the one that wrote it: the
 * PDFs are re-parsed with smalot/pdfparser, so "the document renders" is
 * a measured claim about bytes rather than a claim that the Blade view
 * compiled. A template can compile perfectly and draw a blank page.
 *
 * The artefacts are also written to disk when IOMS_DOC_ARTEFACTS is set,
 * so a human can open the same files these assertions describe.
 */
class DocumentPlatformTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $hse;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Galangan Aliran Jaya', 'slug' => 'gaj-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
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

        $this->hse = User::create([
            'name' => 'Rahmat Hidayat', 'email' => 'hse-'.uniqid().'@gaj.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_HSE,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);

        // A real tenant letterhead, so the document is asserted to carry
        // the TENANT's identity rather than the platform's default.
        CompanySetting::set('company_name', 'PT Galangan Aliran Jaya');
        CompanySetting::set('company_address', 'Jalan Bawal Kavling 12');
        CompanySetting::set('company_city', 'Batam');
        CompanySetting::set('company_province', 'Kepulauan Riau');
        CompanySetting::set('company_phone', '+62 778 1234567');
    }

    /**
     * One inspection with a deliberately MIXED checklist: a pass, a
     * failure with remarks, and a line with no verdict at all.
     */
    private function inspection(): HseInspection
    {
        $inspection = HseInspection::create([
            'inspection_number' => 'INS-2026-00042',
            'company_id' => $this->yard->id,
            'inspection_type' => 'fire_safety',
            'location' => 'Dok Kering 2',
            'inspection_date' => '2026-03-14',
            'inspector_id' => $this->hse->id,
            'overall_result' => 'fail',
            'notes' => "Area dok kering memerlukan penataan ulang jalur evakuasi.\nTindak lanjut dalam 7 hari.",
            'checklist_items' => [
                ['item' => 'Alat pemadam api ringan terpasang dan bertekanan', 'result' => 'ok', 'remarks' => ''],
                ['item' => 'Jalur evakuasi bebas hambatan', 'result' => 'not_ok', 'remarks' => 'Terhalang drum cat'],
                ['item' => 'Hydrant box lengkap', 'result' => 'na', 'remarks' => 'Tidak ada hydrant di area ini'],
                // No `result` key at all: must print as N/A, never as a pass.
                ['item' => 'Rambu larangan merokok terbaca'],
            ],
        ]);

        $inspection->correctiveActions()->create([
            'company_id' => $this->yard->id,
            'action' => 'Pindahkan drum cat dari jalur evakuasi',
            'assigned_to' => $this->hse->id,
            'due_date' => '2026-03-21',
            'status' => CorrectiveAction::STATUS_OPEN,
            'created_by' => $this->hse->id,
        ]);

        return $inspection;
    }

    /** Writes an artefact a human can open, when asked to. */
    private function keep(string $name, string $bytes): void
    {
        $dir = env('IOMS_DOC_ARTEFACTS');

        if ($dir && is_dir($dir)) {
            file_put_contents(rtrim($dir, '/').'/'.$name, $bytes);
        }
    }

    // --- the HSE inspection PDF ------------------------------------------

    public function test_the_hse_inspection_pdf_is_a_real_pdf_carrying_the_record(): void
    {
        $inspection = $this->inspection();

        $response = $this->actingAs($this->hse)->get(route('hse-inspections.pdf', $inspection));
        $response->assertOk();

        $bytes = $response->getContent();
        $this->keep('hse-inspection.pdf', $bytes);

        $this->assertStringStartsWith('%PDF-', $bytes, 'The endpoint did not return a PDF.');
        $this->assertGreaterThan(5000, strlen($bytes), 'The PDF is too small to carry a rendered page.');

        // Read back with a DIFFERENT library than the one that wrote it.
        $path = tempnam(sys_get_temp_dir(), 'ioms-assert-').'.pdf';
        file_put_contents($path, $bytes);
        $text = (new Parser)->parseFile($path)->getText();
        @unlink($path);

        // The tenant's own letterhead, not the platform's.
        $this->assertStringContainsString('PT Galangan Aliran Jaya', $text);
        $this->assertStringContainsString('Batam', $text);

        // The record itself.
        $this->assertStringContainsString('INS-2026-00042', $text);
        $this->assertStringContainsString('Dok Kering 2', $text);
        $this->assertStringContainsString('Rahmat Hidayat', $text);
        $this->assertStringContainsString('14 Mar 2026', $text);

        // Every checklist line, including the one with no verdict.
        $this->assertStringContainsString('Jalur evakuasi bebas hambatan', $text);
        $this->assertStringContainsString('Terhalang drum cat', $text);
        $this->assertStringContainsString('Rambu larangan merokok terbaca', $text);

        // The corrective action raised from it.
        $this->assertStringContainsString('Pindahkan drum cat dari jalur evakuasi', $text);

        // The overall result, as recorded.
        $this->assertStringContainsString('FAIL', $text);

        @unlink($path);
    }

    /**
     * A checklist line with no verdict must read N/A. A blank there would
     * be a safety record that looks answered and is not.
     */
    public function test_an_unanswered_checklist_line_prints_as_not_applicable(): void
    {
        $inspection = HseInspection::create([
            'inspection_number' => 'INS-2026-00043',
            'company_id' => $this->yard->id,
            'inspection_type' => 'general',
            'inspection_date' => '2026-03-15',
            'inspector_id' => $this->hse->id,
            'overall_result' => 'pass',
            'checklist_items' => [['item' => 'Butir tanpa jawaban']],
        ]);

        $text = $this->textOf(
            $this->actingAs($this->hse)->get(route('hse-inspections.pdf', $inspection))->getContent()
        );

        $this->assertStringContainsString('N/A', $text);
    }

    /**
     * An inspection with no checklist at all must say so, not print an
     * empty table that reads as "nothing wrong".
     */
    public function test_an_inspection_with_no_checklist_says_so(): void
    {
        $inspection = HseInspection::create([
            'inspection_number' => 'INS-2026-00044',
            'company_id' => $this->yard->id,
            'inspection_type' => 'general',
            'inspection_date' => '2026-03-16',
            'inspector_id' => $this->hse->id,
            'overall_result' => 'pass',
            'checklist_items' => [],
        ]);

        $text = $this->textOf(
            $this->actingAs($this->hse)->get(route('hse-inspections.pdf', $inspection))->getContent()
        );

        $this->assertStringContainsString('tidak memuat butir checklist', $text);

        // `overall_result` is NOT NULL in the schema, so a stored
        // inspection always carries one. The template's "BELUM DINILAI"
        // branch is defence against an unsaved or future-nullable record
        // and is deliberately NOT asserted here as a reachable state.
        $this->assertStringContainsString('PASS', $text);
    }

    /** The PDF endpoint carries the same tenant gate as `show()`. */
    public function test_another_tenants_inspection_is_not_printable(): void
    {
        $other = Tenant::create([
            'name' => 'Tetangga', 'slug' => 't-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Tetangga', 'code' => 'YT', 'tenant_id' => $other->id, 'is_active' => true,
        ]);

        $theirs = HseInspection::withoutGlobalScopes()->create([
            'inspection_number' => 'INS-OTHER-0001',
            'company_id' => $otherCompany->id,
            'inspection_type' => 'general',
            'inspection_date' => '2026-03-01',
            'inspector_id' => $this->hse->id,
            'overall_result' => 'pass',
            'checklist_items' => [],
        ]);

        $this->actingAs($this->hse)
            ->get(route('hse-inspections.pdf', $theirs))
            ->assertNotFound();
    }

    private function textOf(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ioms-assert-').'.pdf';
        file_put_contents($path, $bytes);
        $text = (new Parser)->parseFile($path)->getText();
        @unlink($path);

        return $text;
    }
}
