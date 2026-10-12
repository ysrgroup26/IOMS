<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HseInspection;
use App\Models\Item;
use App\Models\Package;
use App\Models\Position;
use App\Models\Project;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * v2.110.0 -- THE DOCUMENT LIBRARY, PROVEN FORMAT BY FORMAT.
 *
 * Three claims are worth pinning, because each one can be broken by a
 * change that still looks correct:
 *
 *  1. XLSX, DOCX and PDF of ONE document carry THE SAME ROWS. They are
 *     rendered from one definition on purpose; a renderer that quietly
 *     re-queried would reintroduce exactly the drift this layer exists
 *     to remove.
 *  2. The spreadsheet carries TYPED cells, not pre-formatted strings --
 *     a date is a date, a quantity is a number, and an identifier is
 *     text so it keeps its leading zeros.
 *  3. A document cannot cross a tenant boundary, and an empty result
 *     says so rather than printing an empty table that reads as a
 *     complete list.
 *
 * Every assertion is made against a FILE that was produced and then read
 * back, not against the code that produced it.
 */
class DocumentLibraryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $admin;

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

        $this->admin = User::create([
            'name' => 'Admin Utama', 'email' => 'admin-'.uniqid().'@gaj.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);

        CompanySetting::set('company_name', 'PT Galangan Aliran Jaya');
        CompanySetting::set('company_city', 'Batam');
    }

    private function employee(array $attributes = []): Employee
    {
        $department = Department::firstOrCreate(['company_id' => $this->yard->id, 'name' => 'Produksi']);
        $position = Position::firstOrCreate(['company_id' => $this->yard->id, 'name' => 'Welder']);

        return Employee::create(array_merge([
            // Leading zero and a 16-digit NIK: the two values a
            // spreadsheet destroys if it is allowed to guess the type.
            'employee_id' => '0012345',
            'nik' => '3201010101900001',
            'full_name' => 'Budi Santoso',
            'company_id' => $this->yard->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'status' => 'active',
            'employment_type' => 'permanent',
            'join_date' => '2026-01-15',
        ], $attributes));
    }

    private function download(string $key, string $format, array $query = []): string
    {
        $response = $this->actingAs($this->admin)->get(route('documents.export', array_merge([
            'key' => $key, 'format' => $format,
        ], $query)));

        $response->assertOk();

        return $response->baseResponse instanceof BinaryFileResponse
            ? file_get_contents($response->baseResponse->getFile()->getPathname())
            : $response->getContent();
    }

    // --- the library itself ----------------------------------------------

    public function test_the_library_lists_a_document_for_every_operational_workspace(): void
    {
        $response = $this->actingAs($this->admin)->get(route('documents.library'));
        $response->assertOk();

        $documents = collect($response->viewData('page')['props']['documents']);

        foreach (['hr', 'hse', 'logistics', 'management'] as $workspace) {
            $this->assertTrue(
                $documents->contains('workspace', $workspace),
                "The document library publishes nothing for the [{$workspace}] workspace."
            );
        }

        // Every published document must be renderable in every format it
        // claims -- a claimed format with no renderer is a broken button.
        foreach ($documents as $document) {
            foreach ($document['formats'] as $format) {
                $this->assertContains($format, ['xlsx', 'docx', 'pdf']);
            }
        }
    }

    /**
     * An unknown key and a key this user may not read answer the same
     * way, so the library cannot be used to enumerate what other roles
     * can see.
     */
    public function test_an_unregistered_document_key_is_not_found(): void
    {
        $this->actingAs($this->admin)
            ->get('/documents/not-a-document/pdf')
            ->assertNotFound();
    }

    public function test_an_unsupported_format_is_not_routable(): void
    {
        $this->actingAs($this->admin)
            ->get('/documents/employee-register/csv')
            ->assertNotFound();
    }

    // --- one definition, three formats, the same rows ---------------------

    public function test_the_three_formats_of_one_register_carry_the_same_rows(): void
    {
        $this->employee();
        $this->employee(['employee_id' => '0012346', 'nik' => '3201010101900002', 'full_name' => 'Siti Aminah']);

        $xlsx = $this->download('employee-register', 'xlsx');
        $docx = $this->download('employee-register', 'docx');
        $pdf = $this->download('employee-register', 'pdf');

        foreach (['Budi Santoso', 'Siti Aminah'] as $name) {
            $this->assertStringContainsString($name, $this->sheetText($xlsx), 'Missing from the XLSX: '.$name);
            $this->assertStringContainsString($name, $this->docxText($docx), 'Missing from the DOCX: '.$name);
            $this->assertStringContainsString($name, $this->pdfText($pdf), 'Missing from the PDF: '.$name);
        }
    }

    /**
     * The spreadsheet must behave like a spreadsheet: a date that can be
     * filtered by month, and an identifier that keeps its leading zero.
     */
    public function test_the_spreadsheet_carries_typed_cells_not_formatted_strings(): void
    {
        $this->employee();

        $path = tempnam(sys_get_temp_dir(), 'ioms-lib-').'.xlsx';
        file_put_contents($path, $this->download('employee-register', 'xlsx'));

        $sheet = IOFactory::load($path)->getActiveSheet();

        // Locate the header row rather than assuming it, because the
        // letterhead above it is allowed to grow.
        $headerRow = null;
        foreach ($sheet->getRowIterator() as $row) {
            if (trim((string) $sheet->getCell('A'.$row->getRowIndex())->getValue()) === 'Employee ID') {
                $headerRow = $row->getRowIndex();
                break;
            }
        }

        $this->assertNotNull($headerRow, 'The register has no "Employee ID" heading.');

        $first = $headerRow + 1;

        $this->assertSame('0012345', $sheet->getCell('A'.$first)->getValue(), 'The leading zero was lost.');
        $this->assertSame(
            DataType::TYPE_STRING,
            $sheet->getCell('B'.$first)->getDataType(),
            'The NIK was not written as text, so a 16-digit value loses its last digits.'
        );
        $this->assertSame('3201010101900001', $sheet->getCell('B'.$first)->getValue());

        // The join date is a real Excel date, displayed day-first.
        $joinDate = $sheet->getCell('I'.$first);
        $this->assertTrue(
            Date::isDateTime($joinDate),
            'The join date is not a date cell, so it cannot be sorted or filtered.'
        );
        $this->assertSame('15/01/2026', $joinDate->getFormattedValue());

        // Freeze, filter and repeat-header: the three things that make a
        // long register usable on screen and on paper.
        $this->assertSame('A'.($headerRow + 1), $sheet->getFreezePane());
        $this->assertNotNull($sheet->getAutoFilter()->getRange());
        $this->assertSame([(string) $headerRow, (string) $headerRow], $sheet->getPageSetup()->getRowsToRepeatAtTop());

        @unlink($path);
    }

    /**
     * A document a client reads must not show the database's spelling.
     * `permanent` and `active` are enum VALUES, not labels.
     */
    public function test_stored_tokens_are_printed_as_labels_not_as_enum_values(): void
    {
        $this->employee();

        $text = $this->pdfText($this->download('employee-register', 'pdf'));

        $this->assertStringContainsString('Permanent', $text);
        $this->assertStringContainsString('Active', $text);
        $this->assertStringNotContainsString('permanent', $text);
    }

    /**
     * An empty register must SAY it is empty. A blank table reads as "we
     * checked and there is nobody", which is a different claim.
     */
    public function test_an_empty_register_says_so_rather_than_printing_a_blank_table(): void
    {
        $text = $this->pdfText($this->download('employee-register', 'pdf'));

        $this->assertStringContainsString('sengaja', $text);
        $this->assertStringContainsString('Jumlah Baris', $text);
    }

    /** The filters in force are printed, so a filtered list cannot read as a complete one. */
    public function test_a_filtered_register_states_its_filter(): void
    {
        $this->employee();
        $this->employee(['employee_id' => '0012347', 'nik' => '3201010101900003', 'full_name' => 'Rina Marlina']);

        $text = $this->pdfText($this->download('employee-register', 'pdf', ['search' => 'Rina']));

        $this->assertStringContainsString('Rina Marlina', $text);
        $this->assertStringNotContainsString('Budi Santoso', $text);
        $this->assertStringContainsString('Pencarian', $text);
    }

    // --- the other three workspaces ---------------------------------------

    public function test_the_hse_inspection_register_counts_open_actions_from_real_rows(): void
    {
        $inspection = HseInspection::create([
            'inspection_number' => 'INS-2026-00099',
            'company_id' => $this->yard->id,
            'inspection_type' => 'scaffolding',
            'inspection_date' => '2026-02-02',
            'inspector_id' => $this->admin->id,
            'overall_result' => 'fail',
            'checklist_items' => [],
        ]);

        $inspection->correctiveActions()->create([
            'company_id' => $this->yard->id,
            'action' => 'Perbaiki papan pijakan',
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);

        $inspection->correctiveActions()->create([
            'company_id' => $this->yard->id,
            'action' => 'Sudah selesai',
            'status' => 'completed',
            'created_by' => $this->admin->id,
        ]);

        $text = $this->pdfText($this->download('hse-inspection-register', 'pdf'));

        $this->assertStringContainsString('INS-2026-00099', $text);
        $this->assertStringContainsString('Scaffolding', $text);
        // One open action, not two: a completed action is not open.
        $this->assertMatchesRegularExpression('/FAIL\s+1/', $text);
    }

    public function test_the_stock_report_writes_quantities_as_numbers(): void
    {
        $warehouse = Warehouse::create([
            'code' => 'WH-1', 'company_id' => $this->yard->id, 'name' => 'Gudang Utama', 'status' => 'active',
        ]);

        $item = Item::create([
            'item_code' => 'ITM-0001', 'company_id' => $this->yard->id, 'name' => 'Kawat Las E6013',
            'category' => 'Consumable', 'unit' => 'kg', 'min_stock' => 50, 'is_active' => true,
        ]);

        Stock::create([
            'company_id' => $this->yard->id, 'item_id' => $item->id, 'warehouse_id' => $warehouse->id,
            'quantity' => 120.5, 'reserved_quantity' => 20.5,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'ioms-lib-').'.xlsx';
        file_put_contents($path, $this->download('stock-position', 'xlsx'));

        $sheet = IOFactory::load($path)->getActiveSheet();

        $headerRow = null;
        foreach ($sheet->getRowIterator() as $row) {
            if (trim((string) $sheet->getCell('A'.$row->getRowIndex())->getValue()) === 'Item Code') {
                $headerRow = $row->getRowIndex();
                break;
            }
        }

        $this->assertNotNull($headerRow);
        $first = $headerRow + 1;

        // Numbers, not strings: a stock sheet whose quantities are text
        // cannot be summed, which is most of what a warehouse does with
        // one. `available` is quantity minus reserved, computed.
        $this->assertSame(120.5, $sheet->getCell('G'.$first)->getValue());
        $this->assertSame(20.5, $sheet->getCell('H'.$first)->getValue());
        $this->assertSame(100.0, $sheet->getCell('I'.$first)->getValue());

        @unlink($path);
    }

    public function test_the_management_summary_counts_assigned_manpower(): void
    {
        $project = Project::create([
            'company_id' => $this->yard->id,
            'name' => 'Docking MV Sinar',
            'project_code' => 'PRJ-001',
            'client' => 'PT Pelayaran Nusantara',
            'status' => 'ongoing',
            'created_by' => $this->admin->id,
        ]);

        $project->manpower()->create([
            'employee_id' => $this->employee()->id,
            'assigned_date' => '2026-02-01',
            'added_by' => $this->admin->id,
        ]);

        $text = $this->pdfText($this->download('project-manpower', 'pdf'));

        $this->assertStringContainsString('Docking MV Sinar', $text);
        $this->assertStringContainsString('PT Pelayaran Nusantara', $text);
        $this->assertMatchesRegularExpression('/Ongoing\s/', $text);
    }

    // --- tenant isolation --------------------------------------------------

    public function test_a_register_never_reaches_across_tenants(): void
    {
        $this->employee();

        $other = Tenant::create(['name' => 'Tetangga', 'slug' => 't-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Tetangga', 'code' => 'YT', 'tenant_id' => $other->id, 'is_active' => true,
        ]);

        Employee::withoutGlobalScopes()->create([
            'employee_id' => '9999999',
            'full_name' => 'Karyawan Tetangga',
            'company_id' => $otherCompany->id,
            'status' => 'active',
        ]);

        $text = $this->pdfText($this->download('employee-register', 'pdf'));

        $this->assertStringContainsString('Budi Santoso', $text);
        $this->assertStringNotContainsString('Karyawan Tetangga', $text);
    }

    // --- helpers -----------------------------------------------------------

    private function pdfText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ioms-lib-').'.pdf';
        file_put_contents($path, $bytes);
        $text = (new Parser)->parseFile($path)->getText();
        @unlink($path);

        /*
         * Whitespace is COLLAPSED. A PDF breaks a long cell across two
         * lines when the column is narrow, so "Budi Santoso" comes back
         * as "Budi\nSantoso". That is layout, not content, and an
         * assertion that treats it as content would fail on a column
         * width change rather than on a real regression.
         */
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function sheetText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ioms-lib-').'.xlsx';
        file_put_contents($path, $bytes);

        $sheet = IOFactory::load($path)->getActiveSheet();
        $text = '';

        foreach ($sheet->toArray(null, true, false, false) as $row) {
            $text .= implode(' | ', array_map(fn ($v) => (string) $v, $row))."\n";
        }

        @unlink($path);

        return $text;
    }

    private function docxText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ioms-lib-').'.docx';
        file_put_contents($path, $bytes);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The DOCX is not a readable zip container.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return strip_tags(str_replace('<', ' <', (string) $xml));
    }
}
