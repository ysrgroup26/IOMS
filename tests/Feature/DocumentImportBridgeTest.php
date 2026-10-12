<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * v2.110.0 -- THE FOUR FORMATS REACH THE SAME IMPORTER.
 *
 * The claim under test is not "we can parse a CSV". It is that a CSV, a
 * Word table and a PDF table arrive at the EXISTING employee importer --
 * the one with the day-first date parser, the NIK registry, the
 * employment-type aliases and the row-accurate error reporting -- rather
 * than at a second, parallel import path that would slowly disagree with
 * it.
 *
 * So each test imports a real file of that format and then asserts on
 * the DATABASE: the employee exists, the identifiers survived, and the
 * date was read day-first. A test that only checked the parse would pass
 * on a system where the import silently wrote nothing.
 */
class DocumentImportBridgeTest extends TestCase
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
    }

    /** The one roster every fixture below expresses, in its own format. */
    private const HEADINGS = ['Employee ID', 'Full Name', 'NIK (KTP)', 'Department', 'Position', 'Join Date'];

    private const ROWS = [
        ['0012345', 'Budi Santoso', '3201010101900001', 'Produksi', 'Welder', '15/01/2026'],
        ['0012346', 'Siti Aminah', '3201010101900002', 'QA/QC', 'Inspector', '03/02/2026'],
    ];

    private function csvFixture(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, self::HEADINGS);

        foreach (self::ROWS as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return new UploadedFile($path, 'roster.csv', 'text/csv', null, true);
    }

    private function docxFixture(): UploadedFile
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addText('Daftar Tenaga Kerja');

        $table = $section->addTable();
        $table->addRow();

        foreach (self::HEADINGS as $heading) {
            $table->addCell()->addText($heading);
        }

        foreach (self::ROWS as $row) {
            $table->addRow();

            foreach ($row as $cell) {
                $table->addCell()->addText($cell);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return new UploadedFile(
            $path,
            'roster.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true
        );
    }

    /**
     * A digital PDF of the same roster. Produced with the same dompdf
     * IOMS itself prints with, so the fixture is the shape of a document
     * a customer actually receives rather than one built to be easy to
     * parse.
     */
    private function pdfFixture(): UploadedFile
    {
        $cells = '';

        foreach (self::ROWS as $row) {
            $cells .= '<tr>';

            foreach ($row as $value) {
                $cells .= '<td>'.$value.'</td>';
            }

            $cells .= '</tr>';
        }

        $html = '<html><body><h3>Daftar Tenaga Kerja</h3><table width="100%">'
            .'<tr>'.implode('', array_map(fn ($h) => '<th>'.$h.'</th>', self::HEADINGS)).'</tr>'
            .$cells
            .'</table></body></html>';

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.pdf';
        file_put_contents($path, Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output());

        return new UploadedFile($path, 'roster.pdf', 'application/pdf', null, true);
    }

    private function import(UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $file,
            'company_id' => $this->yard->id,
        ]);
    }

    private function assertRosterLanded(): void
    {
        $budi = Employee::where('full_name', 'Budi Santoso')->first();

        $this->assertNotNull($budi, 'The import wrote nothing.');

        // The two values a careless pipeline destroys.
        $this->assertSame('0012345', $budi->employee_id, 'The leading zero was lost.');
        $this->assertSame('3201010101900001', $budi->nik, 'The 16-digit NIK was altered.');

        // Day-first, as the rest of IOMS reads dates. An American-first
        // reading would make this 1 March 2026.
        $this->assertSame('2026-01-15', $budi->join_date->format('Y-m-d'));

        $this->assertNotNull(Employee::where('full_name', 'Siti Aminah')->first());
    }

    // --- the formats -------------------------------------------------------

    public function test_a_csv_roster_imports_through_the_existing_employee_importer(): void
    {
        $response = $this->import($this->csvFixture());
        $response->assertOk();

        $this->assertSame(2, $response->json('imported'));
        $this->assertRosterLanded();
    }

    public function test_a_word_table_imports_through_the_existing_employee_importer(): void
    {
        $response = $this->import($this->docxFixture());
        $response->assertOk();

        $this->assertSame(2, $response->json('imported'));
        $this->assertRosterLanded();
    }

    /**
     * A PDF has no table object -- what comes back is reconstructed from
     * where the glyphs were drawn. So the test asserts BOTH halves of the
     * platform's contract: the roster does arrive, and the extraction is
     * reported with a confidence below certainty and a note saying where
     * the structure came from, so a UI can ask for confirmation rather
     * than import it silently.
     */
    public function test_a_pdf_table_is_recovered_and_reported_as_a_reconstruction(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('employees.import.inspect'), [
            'file' => $this->pdfFixture(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('format', 'pdf');

        $this->assertSame(self::HEADINGS, $response->json('headings'));

        $this->assertLessThan(
            1.0,
            $response->json('confidence'),
            'A PDF reconstruction was reported as certain, which it cannot be.'
        );

        $this->assertNotEmpty($response->json('notes'), 'The reconstruction was reported without a note.');
    }

    public function test_a_pdf_roster_imports_through_the_existing_employee_importer(): void
    {
        $response = $this->import($this->pdfFixture());
        $response->assertOk();

        $this->assertSame(2, $response->json('imported'));
        $this->assertRosterLanded();
    }

    /** The audit trail names the format the rows actually came from. */
    public function test_the_activity_log_names_the_source_format(): void
    {
        $this->import($this->csvFixture())->assertOk();

        $this->assertNotNull(
            ActivityLog::where('description', 'like', '%CSV%')->first(),
            'The import was recorded without naming the format it came from.'
        );
    }

    // --- inspection, which must write nothing --------------------------------

    public function test_inspecting_a_word_document_suggests_a_mapping_and_writes_nothing(): void
    {
        $response = $this->actingAs($this->admin)->postJson(route('employees.import.inspect'), [
            'file' => $this->docxFixture(),
        ]);

        $response->assertOk();
        $response->assertJsonPath('format', 'docx');

        $this->assertSame(self::HEADINGS, $response->json('headings'));
        $this->assertSame('Full Name', $response->json('mapping.full_name.heading'));
        $this->assertSame('NIK (KTP)', $response->json('mapping.nik.heading'));

        $this->assertSame(0, Employee::count(), 'Inspection wrote to the database.');
    }

    /**
     * A roster with a title block above the table: the heading row is
     * LOCATED, not assumed to be row 1. This is the file that used to be
     * rejected outright.
     */
    public function test_a_spreadsheet_with_rows_above_the_table_still_finds_its_headings(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'PT Galangan Aliran Jaya');
        $sheet->setCellValue('A2', 'Daftar Tenaga Kerja Produksi');
        $sheet->setCellValue('A3', 'Periode Januari 2026');

        foreach (self::HEADINGS as $index => $heading) {
            $sheet->setCellValueByColumnAndRow($index + 1, 5, $heading);
        }

        foreach (self::ROWS as $offset => $row) {
            foreach ($row as $index => $value) {
                $sheet->getCellByColumnAndRow($index + 1, 6 + $offset)
                    ->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $response = $this->actingAs($this->admin)->postJson(route('employees.import.inspect'), [
            'file' => new UploadedFile($path, 'messy.xlsx', null, null, true),
        ]);

        $response->assertOk();
        $this->assertSame(5, $response->json('header_row'), 'The heading row was not located.');
        $this->assertSame(self::HEADINGS, $response->json('headings'));
    }

    // --- refusals ------------------------------------------------------------

    /** A macro-enabled workbook is refused outright, never opened. */
    public function test_a_macro_enabled_workbook_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.xlsm';

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.ms-excel.sheet.macroEnabled.main+xml"/>'
            .'</Types>');
        $zip->addFromString('xl/vbaProject.bin', 'not-really-vba');
        $zip->close();

        $response = $this->actingAs($this->admin)->postJson(route('employees.import.inspect'), [
            'file' => new UploadedFile($path, 'macro.xlsm', null, null, true),
        ]);

        $response->assertStatus(422);
    }
}
