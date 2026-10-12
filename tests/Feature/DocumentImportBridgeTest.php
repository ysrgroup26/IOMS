<?php

namespace Tests\Feature;

use App\Http\Controllers\EmployeeController;
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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
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

    /**
     * The roster as a real customer workbook: a company name, a report
     * title and a blank line ABOVE the table, which is what a roster
     * exported from a payroll system or typed by an admin actually looks
     * like.
     */
    private function messyXlsxFixture(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'PT Galangan Aliran Jaya');
        $sheet->setCellValue('A2', 'Daftar Tenaga Kerja Produksi');
        $sheet->setCellValue('A3', 'Periode Januari 2026');

        foreach (self::HEADINGS as $index => $heading) {
            $sheet->setCellValue(
                Coordinate::stringFromColumnIndex($index + 1).'5',
                $heading
            );
        }

        foreach (self::ROWS as $offset => $row) {
            foreach ($row as $index => $value) {
                $sheet->getCell(
                    Coordinate::stringFromColumnIndex($index + 1).(6 + $offset)
                )->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'daftar-karyawan.xlsx', null, null, true);
    }

    /** The same roster, but on the SECOND sheet behind a summary tab. */
    private function secondSheetXlsxFixture(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Ringkasan');
        $summary->setCellValue('A1', 'Total Karyawan');
        $summary->setCellValue('B1', '2');

        $data = $spreadsheet->createSheet();
        $data->setTitle('Data Karyawan');

        foreach (self::HEADINGS as $index => $heading) {
            $data->setCellValue(
                Coordinate::stringFromColumnIndex($index + 1).'1',
                $heading
            );
        }

        foreach (self::ROWS as $offset => $row) {
            foreach ($row as $index => $value) {
                $data->getCell(
                    Coordinate::stringFromColumnIndex($index + 1).(2 + $offset)
                )->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'rekap-karyawan.xlsx', null, null, true);
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

    // --- XLSX must reach the importer the way INSPECT described it ------

    /**
     * THE DIVERGENCE THIS TEST EXISTS FOR.
     *
     * `inspect` locates the heading row; `import` used to hand the raw
     * workbook to a reader that assumes row 1. So a customer could be
     * shown a correct mapping from row 5 and then import nothing at all
     * — the worst possible shape of failure, because the preview said it
     * would work.
     */
    public function test_a_spreadsheet_with_rows_above_the_table_actually_imports(): void
    {
        $response = $this->import($this->messyXlsxFixture());
        $response->assertOk();

        $this->assertSame(2, $response->json('imported'), 'The located heading row was not the one the import read.');
        $this->assertRosterLanded();
    }

    /** The same divergence, in the other shape: data behind a summary tab. */
    public function test_a_roster_on_the_second_sheet_actually_imports(): void
    {
        $response = $this->import($this->secondSheetXlsxFixture());
        $response->assertOk();

        $this->assertSame(2, $response->json('imported'), 'Only the first sheet was read.');
        $this->assertRosterLanded();
    }

    /**
     * A ROW NUMBER MUST NAME A LINE THE CUSTOMER CAN GO AND LOOK AT.
     *
     * The skipped-row list is shown beside the words "fix this in your
     * file", so a row number counted in the temporary spreadsheet IOMS
     * built is worse than none: on a roster whose headings are on row 5,
     * it pointed at the heading row.
     */
    public function test_a_skipped_row_is_reported_at_its_position_in_the_customers_own_file(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'PT Galangan Aliran Jaya');
        $sheet->setCellValue('A2', 'Daftar Tenaga Kerja');
        $sheet->setCellValue('A3', 'Periode Januari 2026');

        foreach (self::HEADINGS as $index => $heading) {
            $sheet->setCellValue(
                Coordinate::stringFromColumnIndex($index + 1).'5',
                $heading
            );
        }

        // Row 6 is good; row 7 has no name and must be skipped.
        foreach ([
            ['0012345', 'Budi Santoso', '3201010101900001', 'Produksi', 'Welder', '15/01/2026'],
            ['0012346', '', '3201010101900002', 'Produksi', 'Helper', '03/02/2026'],
        ] as $offset => $row) {
            foreach ($row as $index => $value) {
                if ($value === '') {
                    continue;
                }

                $sheet->getCell(
                    Coordinate::stringFromColumnIndex($index + 1).(6 + $offset)
                )->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $response = $this->import(new UploadedFile($path, 'daftar.xlsx', null, null, true));
        $response->assertOk();

        $skipped = $response->json('skipped');

        $this->assertCount(1, $skipped);
        $this->assertSame(
            7,
            $skipped[0]['row'],
            'The nameless record is on row 7 of the uploaded workbook; any other number points at a file the customer has never seen.'
        );
    }

    /** An ordinary workbook's row numbers are unchanged by the offset. */
    public function test_an_ordinary_spreadsheet_still_reports_its_own_row_numbers(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach (self::HEADINGS as $index => $heading) {
            $sheet->setCellValue(
                Coordinate::stringFromColumnIndex($index + 1).'1',
                $heading
            );
        }

        // Row 2 good, row 3 nameless.
        $sheet->getCell('A2')->setValueExplicit('0012345', DataType::TYPE_STRING);
        $sheet->setCellValue('B2', 'Budi Santoso');
        $sheet->getCell('A3')->setValueExplicit('0012346', DataType::TYPE_STRING);

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $response = $this->import(new UploadedFile($path, 'roster.xlsx', null, null, true));
        $response->assertOk();

        $this->assertSame(3, $response->json('skipped.0.row'));
    }

    /**
     * THE REGRESSION THIS GUARD EXISTS FOR.
     *
     * A twelve-column template whose records fill only five columns: the
     * heading row is FULLER than its data, which is the commonest shape
     * of all and the one the locator originally misread, picking the
     * first data row as the headings. A workbook that imported perfectly
     * before the Document Platform must still import perfectly after it.
     */
    public function test_a_wide_template_with_sparse_rows_is_not_broken_by_the_locator(): void
    {
        $headings = [
            'Employee ID', 'Full Name', 'Department', 'Position', 'Project', 'Phone',
            'Email', 'Address', 'Emergency Contact Name', 'Emergency Contact Phone',
            'Join Date', 'Employment Status',
        ];

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            $headings,
            // Only five of twelve columns carry anything.
            ['0012345', 'Budi Santoso', 'Produksi', '', '', '', '', '', '', '', '15/01/2026', 'active'],
            ['0012346', 'Siti Aminah', 'QA/QC', '', '', '', '', '', '', '', '03/02/2026', 'active'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $response = $this->import(new UploadedFile($path, 'template.xlsx', null, null, true));
        $response->assertOk();

        $this->assertSame(2, $response->json('imported'));
        $this->assertNotNull(Employee::where('full_name', 'Budi Santoso')->first());

        // Row 1 really is the heading row, and the import said so.
        $this->assertSame(0, $response->json('skipped') === null ? 0 : count($response->json('skipped')));
    }

    /**
     * And the belt-and-braces half of it: even if the locator DOES
     * misread a workbook, the bridge is refused unless the headings it
     * found map to something, so the file the customer uploaded is the
     * one the importer reads.
     */
    public function test_a_spreadsheet_whose_located_headings_map_to_nothing_is_read_as_uploaded(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Employee ID', 'Full Name', 'Department'],
            ['0012345', 'Budi Santoso', 'Produksi'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $controller = new EmployeeController;
        $reflection = new \ReflectionMethod($controller, 'mappableHeadings');

        $this->assertTrue($reflection->invoke($controller, ['Employee ID', 'Full Name', 'Department']));
        // A row of DATA, which is what a misread picks up.
        $this->assertFalse($reflection->invoke($controller, ['0012345', 'Budi Santoso', 'Produksi']));
        // One accidental match is noise, not a column layout.
        $this->assertFalse($reflection->invoke($controller, ['Status', '12', 'xyz']));
    }

    /** Preview must agree with the import it is a preview OF. */
    public function test_the_preview_of_a_messy_spreadsheet_counts_the_same_rows_the_import_writes(): void
    {
        $preview = $this->actingAs($this->admin)->postJson(route('employees.import.preview'), [
            'file' => $this->messyXlsxFixture(),
            'company_id' => $this->yard->id,
        ]);

        $preview->assertOk();
        $this->assertSame(2, $preview->json('total_rows'));
        $this->assertSame(2, $preview->json('valid_rows'));
        $this->assertSame(0, Employee::count(), 'Preview wrote to the database.');
    }

    // --- the plain spreadsheet path must not have changed ----------------

    public function test_an_ordinary_spreadsheet_still_imports_exactly_as_before(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach (self::HEADINGS as $index => $heading) {
            $sheet->setCellValue(
                Coordinate::stringFromColumnIndex($index + 1).'1',
                $heading
            );
        }

        foreach (self::ROWS as $offset => $row) {
            foreach ($row as $index => $value) {
                $sheet->getCell(
                    Coordinate::stringFromColumnIndex($index + 1).(2 + $offset)
                )->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $response = $this->import(new UploadedFile($path, 'roster.xlsx', null, null, true));
        $response->assertOk();

        $this->assertSame(2, $response->json('imported'));
        $this->assertRosterLanded();
    }

    /**
     * A REAL Excel date cell -- a serial number, not text -- must survive
     * whatever path the workbook takes. This is what "converting the file
     * could lose something" meant, so it is pinned rather than assumed.
     */
    public function test_a_real_excel_date_cell_survives_a_messy_workbook(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'PT Galangan Aliran Jaya');
        $sheet->setCellValue('A2', 'Daftar Tenaga Kerja');

        foreach (self::HEADINGS as $index => $heading) {
            $sheet->setCellValue(
                Coordinate::stringFromColumnIndex($index + 1).'4',
                $heading
            );
        }

        $sheet->getCell('A5')->setValueExplicit('0012345', DataType::TYPE_STRING);
        $sheet->setCellValue('B5', 'Budi Santoso');
        $sheet->getCell('C5')->setValueExplicit('3201010101900001', DataType::TYPE_STRING);
        $sheet->setCellValue('D5', 'Produksi');
        $sheet->setCellValue('E5', 'Welder');

        // 15 January 2026 as a genuine Excel serial, formatted day-first.
        $sheet->setCellValue('F5', Date::PHPToExcel(strtotime('2026-01-15')));
        $sheet->getStyle('F5')->getNumberFormat()->setFormatCode('dd/mm/yyyy');

        $path = tempnam(sys_get_temp_dir(), 'ioms-fx-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->import(new UploadedFile($path, 'tanggal.xlsx', null, null, true))->assertOk();

        $budi = Employee::where('full_name', 'Budi Santoso')->first();
        $this->assertNotNull($budi);
        $this->assertSame('2026-01-15', $budi->join_date->format('Y-m-d'));
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
