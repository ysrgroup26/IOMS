<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\Package;
use App\Models\PpeType;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Documents\Import\PpeColumnVocabulary;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PpeTypeSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * v2.126.0 -- READING A COMPANY'S OWN PPE REGISTER.
 *
 * v2.125.0 added a sheet picker and a column-mapping panel, and the owner
 * reported that in use the import was unchanged. That was correct: the only
 * mappable fields for this import are the employee's number and name, so
 * the panel offered two rows, and everything that actually matters in a
 * company's register was still decided silently by matching a heading
 * against a catalogue name in English.
 *
 * The fixture here is deliberately AWKWARD, and every part of its
 * awkwardness is something a real register does: a title block above the
 * headings, a blank line, Indonesian column names, dates and sizes in
 * separate columns, department separator rows, a subtotal, one Excel date
 * serial beside text dates, Baju Kerja AND Coverall as two different
 * garments, and one employee who is not in the register at all.
 *
 * A fixture shaped like the IOMS template would prove only that the
 * template still works -- which the last test here checks separately,
 * because it must.
 */
class PpeImportScreeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

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
            'name' => 'Yard', 'slug' => 'screen-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
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

        Department::create(['company_id' => $this->company->id, 'name' => 'HSE']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'screen-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'is_active' => true,
        ]);
    }

    private function employee(string $number, string $name, ?string $nik = null): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'employee_id' => $number, 'full_name' => $name, 'nik' => $nik,
            'company_id' => $this->company->id, 'status' => 'active',
        ]);
    }

    /** The awkward register, as a real one is. */
    private function companyRegister(): UploadedFile
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        // An instructions tab FIRST, because that is where a company puts
        // it and opening on sheet one is therefore reliably wrong.
        $notes = $book->createSheet();
        $notes->setTitle('Petunjuk');
        $notes->fromArray([['PETUNJUK PENGISIAN'], ['Isi tanggal pemenuhan APD.']], null, 'A1');

        $data = $book->createSheet();
        $data->setTitle('Rekap APD');
        $data->fromArray([
            ['REKAP PEMENUHAN APD KARYAWAN'],                                     // 1 title
            ['Periode: 2026'],                                                    // 2
            [],                                                                   // 3 blank
            ['No. Induk', 'Nama Pekerja', 'Departemen', 'Tgl Sepatu Safety',      // 4 the real headings
                'Ukuran Sepatu', 'Tgl Helm Safety', 'Tgl Baju Kerja', 'Tgl Coverall'],
            ['FABRIKASI'],                                                        // 5 separator
            ['EMP-0001', 'Budi Santoso', 'Fabrication', '', '42', '15/01/2026', '20/01/2026', ''],
            ['EMP-0002', 'Siti Wijaya', 'Fabrication', '03/02/2026', '38', '', '', '03/02/2026'],
            [],                                                                   // 8 blank
            ['PRODUKSI'],                                                         // 9 separator
            ['ZZZ-9999', 'Bukan Karyawan', 'Production', '01/04/2026', '43', '', '', ''],
            ['Total', '3 orang'],                                                 // 11 subtotal
        ], null, 'A1');

        // One real Excel serial, so both date forms are exercised.
        $data->setCellValue('D6', XlsDate::PHPToExcel(new \DateTime('2026-01-15')));

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'rekap-apd.xlsx', null, null, true);
    }

    private function screen(array $extra = []): array
    {
        return $this->actingAs($this->admin)
            ->postJson(route('ppe.import.screen'), array_merge([
                'file' => $this->companyRegister(),
                'company_id' => $this->company->id,
            ], $extra))
            ->assertOk()
            ->json();
    }

    /*
    |--------------------------------------------------------------------------
    | The dictionary
    |--------------------------------------------------------------------------
    */

    /**
     * A WORK UNIFORM IS NOT A COVERALL, and this is the assertion that says
     * so. The owner's instruction is explicit, and the failure mode is a
     * yard being issued the wrong garment with the right record.
     */
    public function test_baju_kerja_and_coverall_are_never_the_same_equipment(): void
    {
        $this->assertSame('Work Uniform', PpeColumnVocabulary::ppeConceptFor('Baju Kerja'));
        $this->assertSame('Work Uniform', PpeColumnVocabulary::ppeConceptFor('Seragam Kerja'));
        $this->assertSame('Coverall', PpeColumnVocabulary::ppeConceptFor('Coverall'));
        $this->assertSame('Coverall', PpeColumnVocabulary::ppeConceptFor('Wearpack'));
    }

    /** A bare garment word names no garment, so it must ask. */
    public function test_an_ambiguous_garment_word_proposes_nothing(): void
    {
        $this->assertNull(PpeColumnVocabulary::ppeConceptFor('Baju'));
        $this->assertNull(PpeColumnVocabulary::ppeConceptFor('Ukuran Baju'));
        $this->assertNull(PpeColumnVocabulary::ppeConceptFor('APD'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('realHeadings')]
    public function test_a_heading_a_company_actually_writes_is_understood(string $heading, ?string $concept): void
    {
        $this->assertSame($concept, PpeColumnVocabulary::ppeConceptFor($heading));
    }

    public static function realHeadings(): array
    {
        return [
            ['Sepatu Safety', 'Safety Shoes'],
            ['Tgl Sepatu Safety', 'Safety Shoes'],
            ['Ukuran Sepatu', 'Safety Shoes'],
            ['SEPATU KESELAMATAN', 'Safety Shoes'],
            ['Helm Safety', 'Safety Helmet'],
            ['Tanggal Helm Keselamatan', 'Safety Helmet'],
            ['Kacamata Safety', 'Safety Glasses'],
            ['Full Body Harness', 'Harness'],
            ['Nama Pekerja', null],
            ['Departemen', null],
        ];
    }

    /**
     * `MasterDataDetector::comparisonKey()` keeps punctuation, so
     * "No. Induk" normalises to "no. induk" and missed the dictionary --
     * a real register's Employee ID column read as "not imported".
     */
    public function test_an_abbreviated_heading_is_still_an_identity_column(): void
    {
        $this->assertSame('employee_id', PpeColumnVocabulary::identityFieldFor('No. Induk'));
        $this->assertSame('employee_id', PpeColumnVocabulary::identityFieldFor('NO INDUK KARYAWAN'));
        $this->assertSame('employee_name', PpeColumnVocabulary::identityFieldFor('Nama Pekerja'));
        $this->assertSame('department', PpeColumnVocabulary::identityFieldFor('Departemen'));
    }

    /**
     * "No" is the first word of "No. Induk" far more often than it is a
     * shoe size, so it must not be a size word.
     */
    public function test_a_size_word_does_not_swallow_the_employee_id_column(): void
    {
        $this->assertFalse(PpeColumnVocabulary::mentionsSize('No. Induk'));
        $this->assertTrue(PpeColumnVocabulary::mentionsSize('Ukuran Sepatu'));
        $this->assertTrue(PpeColumnVocabulary::mentionsDate('Tgl Baju Kerja'));
    }

    /*
    |--------------------------------------------------------------------------
    | The screening
    |--------------------------------------------------------------------------
    */

    /** The register tab, not the instructions tab that comes before it. */
    public function test_it_opens_on_the_register_and_not_on_the_instructions_tab(): void
    {
        $read = $this->screen();

        $this->assertSame('Rekap APD', $read['sheet']);
        $this->assertContains('Petunjuk', array_column($read['sheets'], 'name'));
    }

    /**
     * THE HEADER ROW. The document platform's locator settled on a
     * sub-table ten rows in and reported a worker's name as a column
     * heading -- seen on the first real register. The screening reads the
     * raw grid so row 4 means row 4.
     */
    public function test_it_finds_the_heading_row_under_the_title_block(): void
    {
        $read = $this->screen();

        $this->assertSame(4, $read['header_row']);
        $this->assertContains('No. Induk', $read['headings']);
        $this->assertContains('Tgl Baju Kerja', $read['headings']);
    }

    /** And the person may move it, which is the real answer to a layout we cannot know. */
    public function test_the_person_can_move_the_heading_row(): void
    {
        $read = $this->screen(['header_row' => 1]);

        $this->assertSame(1, $read['header_row']);
        $this->assertSame('REKAP PEMENUHAN APD KARYAWAN', $read['headings'][0]);
    }

    public function test_every_column_is_classified_with_a_reason(): void
    {
        $byHeading = collect($this->screen()['columns'])->keyBy('heading');

        $this->assertSame('employee_id', $byHeading['No. Induk']['role']);
        $this->assertSame('employee_name', $byHeading['Nama Pekerja']['role']);
        $this->assertSame('department', $byHeading['Departemen']['role']);
        $this->assertSame('ppe_date', $byHeading['Tgl Sepatu Safety']['role']);
        $this->assertSame('ppe_size', $byHeading['Ukuran Sepatu']['role']);

        foreach ($byHeading as $column) {
            $this->assertNotSame('', $column['reason'], "Column \"{$column['heading']}\" was classified with no reason given.");
        }
    }

    /** The two garments land on two different catalogue rows. */
    public function test_the_uniform_column_and_the_coverall_column_resolve_to_different_types(): void
    {
        $byHeading = collect($this->screen()['columns'])->keyBy('heading');

        $uniform = PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Work Uniform')->sole();
        $coverall = PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Coverall')->sole();

        $this->assertSame($uniform->id, $byHeading['Tgl Baju Kerja']['ppe_type_id']);
        $this->assertSame($coverall->id, $byHeading['Tgl Coverall']['ppe_type_id']);
        $this->assertNotSame($byHeading['Tgl Baju Kerja']['ppe_type_id'], $byHeading['Tgl Coverall']['ppe_type_id']);
    }

    /**
     * A concept the catalogue has no row for is REPORTED, never created.
     * @see ADR 059 -- an import matches master data and never invents it.
     */
    public function test_a_recognised_type_the_catalogue_lacks_is_reported_not_created(): void
    {
        PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Harness')->update(['is_active' => false]);

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['No. Induk', 'Tgl Harness'], ['EMP-0001', '01/01/2026']], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($book))->save($path);

        $read = $this->actingAs($this->admin)->postJson(route('ppe.import.screen'), [
            'file' => new UploadedFile($path, 'h.xlsx', null, null, true),
            'company_id' => $this->company->id,
        ])->assertOk()->json();

        $column = collect($read['columns'])->firstWhere('heading', 'Tgl Harness');

        $this->assertNull($column['ppe_type_id']);
        $this->assertStringContainsString('belum ada di katalog', $column['reason']);
        $this->assertSame(0, PpeType::withoutGlobalScopes()->where('name', 'Harness')->where('is_active', true)->count());
    }

    /** Titles, separators, blanks and subtotals are not people. */
    public function test_rows_that_are_not_people_are_read_as_what_they_are(): void
    {
        $this->employee('EMP-0001', 'Budi Santoso');
        $this->employee('EMP-0002', 'Siti Wijaya');

        $rows = collect($this->screen()['rows'])->keyBy('source_row');

        $this->assertSame('section', $rows[5]['kind'], 'FABRIKASI is a department heading, not a welder.');
        $this->assertSame('blank', $rows[8]['kind']);
        $this->assertSame('section', $rows[9]['kind']);
        $this->assertSame('subtotal', $rows[11]['kind']);

        $this->assertSame('data', $rows[6]['kind']);
        $this->assertSame('data', $rows[7]['kind']);
    }

    public function test_each_identity_is_matched_and_the_method_is_named(): void
    {
        $budi = $this->employee('EMP-0001', 'Budi Santoso');
        $this->employee('EMP-0002', 'Siti Wijaya');

        $rows = collect($this->screen()['rows'])->keyBy('source_row');

        $this->assertSame('matched', $rows[6]['match']['status']);
        $this->assertSame('employee_id', $rows[6]['match']['matched_by']);
        $this->assertSame($budi->id, $rows[6]['match']['employee']['id']);

        $this->assertSame('not_found', $rows[10]['match']['status']);
        $this->assertStringContainsString('tidak pernah membuat karyawan baru', $rows[10]['match']['reason']);
    }

    /**
     * A name that resolves to two people is AMBIGUOUS, never resolved to
     * whichever row came first. Duplicate names are normal in an
     * industrial workforce.
     */
    public function test_a_duplicate_name_is_ambiguous_rather_than_resolved(): void
    {
        $this->employee('EMP-1001', 'Siti Wijaya');
        $this->employee('EMP-1002', 'Siti Wijaya');

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['Nama Pekerja', 'Tgl Sepatu Safety'],
            ['Siti Wijaya', '01/01/2026'],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($book))->save($path);

        $read = $this->actingAs($this->admin)->postJson(route('ppe.import.screen'), [
            'file' => new UploadedFile($path, 'dup.xlsx', null, null, true),
            'company_id' => $this->company->id,
        ])->assertOk()->json();

        $match = collect($read['rows'])->firstWhere('kind', 'data')['match'];

        $this->assertSame('ambiguous', $match['status']);
        $this->assertCount(2, $match['candidates']);
        $this->assertNull($match['employee']);
    }

    /** Tenant isolation holds whatever the file says. */
    public function test_the_screening_never_reaches_another_tenants_employees(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'screen-other-'.uniqid()]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Other Unit', 'code' => 'OU', 'tenant_id' => $other->id, 'is_active' => true,
        ]);
        Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-0001', 'full_name' => 'Elsewhere',
            'company_id' => $otherCompany->id, 'status' => 'active',
        ]);

        $rows = collect($this->screen()['rows'])->keyBy('source_row');

        $this->assertSame('not_found', $rows[6]['match']['status'],
            'EMP-0001 exists only in ANOTHER tenant and must not resolve here.');
    }

    /** A screening writes nothing, ever. */
    public function test_a_screening_issues_no_ppe(): void
    {
        $this->employee('EMP-0001', 'Budi Santoso');
        $this->screen();

        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | The import that follows it
    |--------------------------------------------------------------------------
    */

    /** @return array{0: array, 1: array} the screening, and the plan it yields */
    private function planFrom(array $read): array
    {
        $plan = collect($read['columns'])
            ->filter(fn ($c) => in_array($c['role'], ['ppe_date', 'ppe_size'], true))
            ->map(fn ($c) => ['index' => $c['index'], 'role' => $c['role'], 'ppe_type_id' => $c['ppe_type_id'], 'heading' => $c['heading']])
            ->values()->all();

        $identity = [];

        foreach ($read['columns'] as $c) {
            if (in_array($c['role'], ['employee_id', 'employee_name'], true)) {
                $identity[$c['role']] = $c['heading'];
            }
        }

        return [$plan, $identity];
    }

    /**
     * THE WHOLE POINT: the import reads the file the way the screening
     * showed it, including the sheet, the header row, the column roles and
     * the sizes.
     */
    public function test_the_import_issues_exactly_what_the_screening_showed(): void
    {
        $budi = $this->employee('EMP-0001', 'Budi Santoso');
        $siti = $this->employee('EMP-0002', 'Siti Wijaya');

        $read = $this->screen();
        [$plan, $identity] = $this->planFrom($read);
        $ignored = collect($read['rows'])->where('kind', '!=', 'data')->pluck('source_row')->all();

        $body = $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->companyRegister(),
            'company_id' => $this->company->id,
            'sheet' => $read['sheet'],
            'header_row' => $read['header_row'],
            'plan' => $plan,
            'ignored_rows' => $ignored,
            'column_mapping' => $identity,
        ])->assertOk()->json();

        $issued = EmployeePpe::withoutGlobalScopes()->get();

        /*
         * Budi: shoes (from the Excel DATE SERIAL in D6), helmet, uniform.
         * Siti: shoes, coverall. ZZZ-9999: nothing at all.
         *
         * Five, and the fifth is the interesting one: the fixture writes
         * D6 as a raw Excel serial rather than text, so this count is also
         * the assertion that a date stored the way Excel stores dates is
         * read -- the owner's brief asks for it by name ("Tangani tanggal
         * Excel, format tanggal berbeda, dan sel kosong dengan aman").
         */
        $this->assertSame(5, $issued->count(), 'Five dated cells across two registered people.');
        $this->assertSame(5, $body['imported']);
        $this->assertSame(2, $body['employees_touched']);

        $names = PpeType::withoutGlobalScopes()->whereIn('id', $issued->pluck('ppe_type_id'))->pluck('name', 'id');

        $this->assertEqualsCanonicalizing(
            ['Safety Shoes', 'Safety Helmet', 'Work Uniform'],
            $issued->where('employee_id', $budi->id)->map(fn ($r) => $names[$r->ppe_type_id])->values()->all(),
        );

        // The serial resolved to the date it encodes, not to a serial.
        $shoes = PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Safety Shoes')->sole();
        $this->assertSame(
            '2026-01-15',
            $issued->where('employee_id', $budi->id)->firstWhere('ppe_type_id', $shoes->id)->issued_date->format('Y-m-d'),
        );
        $this->assertEqualsCanonicalizing(
            ['Safety Shoes', 'Coverall'],
            $issued->where('employee_id', $siti->id)->map(fn ($r) => $names[$r->ppe_type_id])->values()->all(),
        );
    }

    /** The size travels WITH its issuance, not beside it. */
    public function test_a_size_is_recorded_against_the_equipment_it_belongs_to(): void
    {
        $siti = $this->employee('EMP-0002', 'Siti Wijaya');

        $read = $this->screen();
        [$plan, $identity] = $this->planFrom($read);

        $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->companyRegister(),
            'company_id' => $this->company->id,
            'sheet' => $read['sheet'],
            'header_row' => $read['header_row'],
            'plan' => $plan,
            'ignored_rows' => collect($read['rows'])->where('kind', '!=', 'data')->pluck('source_row')->all(),
            'column_mapping' => $identity,
        ])->assertOk();

        $shoes = PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Safety Shoes')->sole();
        $record = EmployeePpe::withoutGlobalScopes()
            ->where('employee_id', $siti->id)->where('ppe_type_id', $shoes->id)->sole();

        $this->assertSame('38', $record->size);
    }

    /**
     * A department heading is not a missing member of staff.
     *
     * Without `ignored_rows` the report read "Employee ID berikut tidak
     * ditemukan: FABRIKASI, PRODUKSI, Total" -- alarming and nonsense.
     */
    public function test_structural_rows_are_not_reported_as_missing_employees(): void
    {
        $this->employee('EMP-0001', 'Budi Santoso');
        $this->employee('EMP-0002', 'Siti Wijaya');

        $read = $this->screen();
        [$plan, $identity] = $this->planFrom($read);

        $body = $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->companyRegister(),
            'company_id' => $this->company->id,
            'sheet' => $read['sheet'],
            'header_row' => $read['header_row'],
            'plan' => $plan,
            'ignored_rows' => collect($read['rows'])->where('kind', '!=', 'data')->pluck('source_row')->all(),
            'column_mapping' => $identity,
        ])->assertOk()->json();

        foreach (['FABRIKASI', 'PRODUKSI', 'Total'] as $furniture) {
            $this->assertNotContains($furniture, $body['unknown_employees']);
        }

        $this->assertSame(['ZZZ-9999'], $body['unknown_employees']);
        $this->assertSame(4, $body['ignored_structural_rows']);
    }

    /** A rejected row writes nothing and says it was rejected. */
    public function test_a_rejected_row_is_honoured(): void
    {
        $this->employee('EMP-0001', 'Budi Santoso');
        $siti = $this->employee('EMP-0002', 'Siti Wijaya');

        $read = $this->screen();
        [$plan, $identity] = $this->planFrom($read);

        $body = $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->companyRegister(),
            'company_id' => $this->company->id,
            'sheet' => $read['sheet'],
            'header_row' => $read['header_row'],
            'plan' => $plan,
            'ignored_rows' => collect($read['rows'])->where('kind', '!=', 'data')->pluck('source_row')->all(),
            'rejected_rows' => [7],
            'column_mapping' => $identity,
        ])->assertOk()->json();

        $this->assertSame(1, $body['rejected']);
        $this->assertSame(3, $body['imported'], 'Only Budi\'s three items. Siti\'s row was rejected.');
        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->where('employee_id', $siti->id)->count());
    }

    /** A plan naming another tenant's PPE type resolves to nothing. */
    public function test_a_plan_cannot_name_another_tenants_ppe_type(): void
    {
        $this->employee('EMP-0001', 'Budi Santoso');

        $other = Tenant::create(['name' => 'Other', 'slug' => 'screen-type-'.uniqid()]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Other Unit', 'code' => 'OU2', 'tenant_id' => $other->id, 'is_active' => true,
        ]);
        $theirType = PpeType::withoutGlobalScopes()->create([
            'name' => 'Their Own Gear', 'company_id' => $otherCompany->id, 'is_active' => true,
        ]);

        $read = $this->screen();
        [, $identity] = $this->planFrom($read);
        $helmetColumn = collect($read['columns'])->firstWhere('heading', 'Tgl Helm Safety');

        $body = $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => $this->companyRegister(),
            'company_id' => $this->company->id,
            'sheet' => $read['sheet'],
            'header_row' => $read['header_row'],
            'plan' => [[
                'index' => $helmetColumn['index'],
                'role' => 'ppe_date',
                'ppe_type_id' => $theirType->id,
                'heading' => 'Tgl Helm Safety',
            ]],
            'ignored_rows' => collect($read['rows'])->where('kind', '!=', 'data')->pluck('source_row')->all(),
            'column_mapping' => $identity,
        ])->assertOk()->json();

        $this->assertSame(0, $body['imported']);
        $this->assertSame(0, EmployeePpe::withoutGlobalScopes()->where('ppe_type_id', $theirType->id)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | And the thing that must NOT change
    |--------------------------------------------------------------------------
    */

    /**
     * THE IOMS TEMPLATE PATH IS UNTOUCHED. "No regression to the existing
     * Employee Import" and to this one was an explicit requirement, and the
     * template import sends no plan at all -- so it must still resolve its
     * own headings exactly as it did before this release.
     */
    public function test_the_ioms_template_still_imports_with_no_plan_at_all(): void
    {
        $budi = $this->employee('EMP-0001', 'Budi Santoso');

        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['Employee ID', 'Employee Name', 'Coverall', 'Safety Shoes', 'Safety Helmet'],
            ['EMP-0001', 'Budi Santoso', '2026-02-01', '2026-02-01', ''],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($book))->save($path);

        $body = $this->actingAs($this->admin)->postJson(route('ppe.import'), [
            'file' => new UploadedFile($path, 'template.xlsx', null, null, true),
            'company_id' => $this->company->id,
        ])->assertOk()->json();

        $this->assertSame(2, $body['imported'], 'Two dated cells; the empty helmet cell is not a fact.');
        $this->assertSame(2, EmployeePpe::withoutGlobalScopes()->where('employee_id', $budi->id)->count());
    }

    /** Work Uniform is in the shared catalogue, and it is not Coverall. */
    public function test_the_catalogue_offers_a_work_uniform_distinct_from_a_coverall(): void
    {
        $uniform = PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Work Uniform')->sole();
        $coverall = PpeType::withoutGlobalScopes()->whereNull('company_id')->where('name', 'Coverall')->sole();

        $this->assertNotSame($uniform->id, $coverall->id);
        $this->assertSame(PpeType::ROLE_WORK_CLOTHING, $uniform->compliance_role);
        $this->assertSame(PpeType::ROLE_WORK_CLOTHING, $coverall->compliance_role,
            'Both satisfy work clothing -- ADR 060 says EITHER, which is why two rows sharing a role is the designed case.');
    }
}
