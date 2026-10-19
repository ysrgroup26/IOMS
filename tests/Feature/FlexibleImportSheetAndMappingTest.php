<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PpeTypeSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * v2.125.0 -- A COMPANY'S OWN WORKBOOK, NOT A REARRANGED ONE.
 *
 * The owner's brief on the PPE import is specific about what must stop
 * being asked of the customer: "Jangan mengharuskan perusahaan menyusun
 * ulang seluruh file agar sesuai template IOMS." Most of what that needs
 * already existed (free column order, a catalogue-matched PPE heading, an
 * empty cell meaning nothing, a per-row report) -- see ADR 059/060. Two
 * things did not, and this class pins them:
 *
 *   1. WHICH SHEET. `withImportSpreadsheet()` took the table the locator
 *      was most confident about and never said which. A workbook with
 *      Data / Rekap / Template tabs therefore imported whichever one won
 *      a tie on confidence and row count, and a summary tab winning that
 *      tie reported eight rows as the whole file.
 *
 *   2. WHICH COLUMN IS WHICH. The suggestion was posted straight through
 *      without being shown, so a customer could not correct a confident
 *      wrong guess and was told to go and edit their file instead.
 *
 * The fixtures here are deliberately AWKWARD on purpose -- reversed
 * column order, the customer's own heading words, a decoy sheet that
 * looks more table-like than the real one -- because a fixture shaped
 * like the IOMS template proves only that the template still works.
 */
class FlexibleImportSheetAndMappingTest extends TestCase
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
        $this->seed(PpeTypeSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'flex-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->yard = Company::withoutGlobalScopes()->create([
            'name' => 'Unit A', 'code' => 'AU', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($this->tenant->fresh());

        Department::create(['company_id' => $this->yard->id, 'name' => 'HSE']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'flex-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    private function employee(string $number, string $name = 'Budi Santoso'): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'employee_id' => $number, 'full_name' => $name,
            'company_id' => $this->yard->id, 'status' => 'active',
        ]);
    }

    /**
     * A workbook with named sheets, each one `[headings, ...rows]`.
     *
     * @param  array<string, list<list<string>>>  $sheets
     */
    private function workbook(array $sheets): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $name => $grid) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($name);
            $sheet->fromArray($grid, null, 'A1');
        }

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'rekap-apd.xlsx', null, null, true);
    }

    /*
    |--------------------------------------------------------------------------
    | Sheet selection
    |--------------------------------------------------------------------------
    */

    /** The inspection must say what the file contains, for every sheet. */
    public function test_the_inspection_reports_every_sheet_in_the_workbook(): void
    {
        $body = $this->actingAs($this->admin)->postJson(route('ppe.import.inspect'), [
            'file' => $this->workbook([
                'Petunjuk' => [['Cara mengisi'], ['Isi tanggal terakhir']],
                'Rekap APD' => [['Employee ID', 'Employee Name', 'Safety Shoes'], ['EMP-0001', 'Budi Santoso', '2026-03-01']],
            ]),
        ])->assertOk()->json();

        $names = array_column($body['sheets'], 'name');

        $this->assertContains('Rekap APD', $names);
        $this->assertNotNull($body['chosen'], 'The customer must be told which sheet would be read by default.');
    }

    /**
     * THE DEFECT. A decoy sheet that out-scores the real one used to be
     * imported silently; naming the real one must now win.
     */
    public function test_a_named_sheet_is_the_sheet_that_is_read(): void
    {
        $this->employee('EMP-0001');
        $this->employee('EMP-0002', 'Siti Wijaya');

        $file = $this->workbook([
            // A plausible decoy: more rows, equally clean headings, and
            // employee numbers that do not exist in this company.
            'Template' => [
                ['Employee ID', 'Employee Name', 'Safety Shoes'],
                ['ZZZ-0001', 'Contoh Satu', '2026-01-01'],
                ['ZZZ-0002', 'Contoh Dua', '2026-01-02'],
                ['ZZZ-0003', 'Contoh Tiga', '2026-01-03'],
            ],
            'Rekap' => [
                ['Employee ID', 'Employee Name', 'Safety Shoes'],
                ['EMP-0001', 'Budi Santoso', '2026-03-01'],
            ],
        ]);

        $body = $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $file,
            'company_id' => $this->yard->id,
            'sheet' => 'Rekap',
        ])->assertOk()->json();

        $this->assertSame(1, $body['imported'], 'The named sheet has exactly one issuance in it.');
        $this->assertSame(
            1,
            EmployeePpe::withoutGlobalScopes()->whereHas('employee', fn ($q) => $q->where('employee_id', 'EMP-0001'))->count(),
            'The row from the NAMED sheet is the row that landed.'
        );
    }

    /**
     * A sheet name that is not in the file is REFUSED, never quietly
     * replaced with another one -- falling back is how a customer comes to
     * believe they chose something.
     */
    public function test_a_sheet_name_that_is_not_in_the_file_is_refused(): void
    {
        $this->employee('EMP-0001');

        $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->workbook([
                'Rekap' => [['Employee ID', 'Employee Name', 'Safety Shoes'], ['EMP-0001', 'Budi Santoso', '2026-03-01']],
            ]),
            'company_id' => $this->yard->id,
            'sheet' => 'Sheet Yang Tidak Ada',
        ])->assertStatus(422)->assertJsonValidationErrors('sheet');

        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->count(), 'A refused sheet writes nothing.');
    }

    /** Sending no sheet behaves exactly as it did before this feature. */
    public function test_sending_no_sheet_reads_the_file_as_before(): void
    {
        $this->employee('EMP-0001');

        $body = $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->workbook([
                'Sheet1' => [['Employee ID', 'Employee Name', 'Safety Shoes'], ['EMP-0001', 'Budi Santoso', '2026-03-01']],
            ]),
            'company_id' => $this->yard->id,
        ])->assertOk()->json();

        $this->assertSame(1, $body['imported']);
    }

    /*
    |--------------------------------------------------------------------------
    | Column mapping the customer controls
    |--------------------------------------------------------------------------
    */

    /**
     * The customer's own heading words, in their own order, mapped by the
     * confirmed mapping rather than by them editing the file.
     */
    public function test_a_confirmed_mapping_reads_the_customers_own_headings(): void
    {
        $this->employee('EMP-0001');

        $body = $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->workbook([
                // Reversed order, and neither identity heading is the
                // IOMS wording.
                'Data' => [
                    ['Safety Shoes', 'Nama Pekerja', 'No. Induk'],
                    ['2026-03-01', 'Budi Santoso', 'EMP-0001'],
                ],
            ]),
            'company_id' => $this->yard->id,
            'column_mapping' => [
                'employee_id' => 'No. Induk',
                'employee_name' => 'Nama Pekerja',
            ],
        ])->assertOk()->json();

        $this->assertSame(1, $body['imported'], 'A mapped identity column is as good as a template one.');
        $this->assertSame([], $body['unknown_employees'] ?? []);
    }

    /**
     * AND THE PREVIEW RAN THE SAME WAY. v2.108.1's rule -- the preview is
     * a promise the import keeps -- has to survive a mapping the customer
     * edited, not only one the matcher suggested.
     */
    public function test_the_preview_and_the_import_read_a_confirmed_mapping_identically(): void
    {
        $this->employee('EMP-0001');

        $payload = fn () => [
            'file' => $this->workbook([
                'Data' => [
                    ['Safety Shoes', 'Nama Pekerja', 'No. Induk'],
                    ['2026-03-01', 'Budi Santoso', 'EMP-0001'],
                ],
            ]),
            'company_id' => $this->yard->id,
            'column_mapping' => ['employee_id' => 'No. Induk', 'employee_name' => 'Nama Pekerja'],
        ];

        $preview = $this->actingAs($this->admin)->postJson(route('ppe.import.preview'), $payload())->assertOk()->json();
        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->count(), 'A preview writes nothing.');

        $import = $this->actingAs($this->admin)->postJson(route('ppe.import'), $payload())->assertOk()->json();

        $this->assertSame($preview['imported'], $import['imported']);
        $this->assertSame($preview['total_rows'], $import['total_rows']);
    }

    /**
     * A WRONG mapping must fail loudly, as a row report, rather than
     * importing something plausible. Here the two identity columns are
     * mapped the wrong way round: the badge number reads as a name, so
     * nobody resolves.
     */
    public function test_a_mapping_pointed_at_the_wrong_column_reports_rather_than_invents(): void
    {
        $this->employee('EMP-0001');

        $body = $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->workbook([
                'Data' => [
                    ['Safety Shoes', 'Nama Pekerja', 'No. Induk'],
                    ['2026-03-01', 'Budi Santoso', 'EMP-0001'],
                ],
            ]),
            'company_id' => $this->yard->id,
            'column_mapping' => [
                'employee_id' => 'Nama Pekerja',
                'employee_name' => 'No. Induk',
            ],
        ])->assertOk()->json();

        $this->assertSame(0, $body['imported']);
        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->count());
        $this->assertNotSame([], $body['unknown_employees'] ?? [], 'The customer must be told nobody matched.');
    }

    /** Tenant isolation is not negotiable, whatever the mapping says. */
    public function test_a_mapping_cannot_reach_another_tenants_employees(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'flex-other-'.uniqid()]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Other Unit', 'code' => 'OU', 'tenant_id' => $other->id, 'is_active' => true,
        ]);
        Employee::withoutGlobalScopes()->create([
            'employee_id' => 'OTHER-1', 'full_name' => 'Elsewhere',
            'company_id' => $otherCompany->id, 'status' => 'active',
        ]);

        $body = $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->workbook([
                'Data' => [['No. Induk', 'Safety Shoes'], ['OTHER-1', '2026-03-01']],
            ]),
            'company_id' => $this->yard->id,
            'column_mapping' => ['employee_id' => 'No. Induk'],
        ])->assertOk()->json();

        $this->assertSame(0, $body['imported']);
        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->count());
    }
}
