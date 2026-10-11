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
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * THE JOIN DATE A SPREADSHEET ACTUALLY CARRIES.
 *
 * `join_date` had no test of any kind, and the import parsed it with
 * `date('Y-m-d', strtotime($cell))`. That expression has two failure modes
 * and both put WRONG DATA in an employee record rather than refusing it:
 *
 *  1. `strtotime()` returns `false` for anything it cannot read, `date()`
 *     casts `false` to 0, and 0 is the Unix epoch -- so an unreadable cell
 *     silently became a join date of 1 JANUARY 1970. No exception was
 *     thrown, so the import's own `catch` never saw it. A fabricated date
 *     is worse than an empty one: it reads as fact, it is nobody's real
 *     start date, and it feeds service-length and contract reporting.
 *
 *  2. `strtotime()` reads a slashed date AMERICAN-FIRST. IOMS is an
 *     Indonesian product whose own template prints `2026-01-15`, and an
 *     Excel installed in id-ID writes `15/01/2026`. Under the old
 *     expression `15/01/2026` was unreadable (month 15) and became
 *     1970-01-01, while `01/02/2026` -- 1 February to every Indonesian
 *     customer -- was silently stored as 2 January. The second is the
 *     dangerous one: it is a plausible date, so nothing looks wrong.
 *
 * The rule asserted here: DAY COMES FIRST unless the value starts with a
 * four-digit year, and anything genuinely unreadable becomes NULL and is
 * REPORTED to the customer, never guessed.
 */
class EmployeeImportDateParsingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

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

        Department::create(['company_id' => $this->yard->id, 'name' => 'HSE']);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @param  array<int, string>  $dateFormattedColumns  cells to mark as real Excel dates, e.g. ['K2']
     */
    private function workbook(array $rows, array $dateFormattedColumns = []): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Employee ID', 'Full Name', 'Department', 'Position', 'Project', 'Phone', 'Email',
                'Address', 'Emergency Contact Name', 'Emergency Contact Phone', 'Join Date', 'Employment Status'],
            ...$rows,
        ], null, 'A1');

        foreach ($dateFormattedColumns as $cell) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        }

        $path = tempnam(sys_get_temp_dir(), 'ioms').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'karyawan.xlsx', null, null, true);
    }

    /** @param list<array{0: string, 1: mixed}> $idsAndDates */
    private function importRows(array $idsAndDates, array $dateFormattedColumns = []): array
    {
        $rows = [];
        foreach ($idsAndDates as [$id, $date]) {
            $rows[] = [$id, 'Budi Santoso', 'HSE', '', '', '', '', '', '', '', $date, 'active'];
        }

        $response = $this->actingAs($this->admin)->postJson(route('employees.import'), [
            'file' => $this->workbook($rows, $dateFormattedColumns),
            'company_id' => $this->yard->id,
        ]);

        $response->assertOk();

        return $response->json();
    }

    private function joinDateOf(string $employeeId): ?string
    {
        $employee = Employee::withoutGlobalScopes()->where('employee_id', $employeeId)->sole();

        return $employee->join_date?->toDateString();
    }

    public function test_an_iso_date_is_read_as_written(): void
    {
        $this->importRows([['EMP-1', '2026-01-15']]);

        $this->assertSame('2026-01-15', $this->joinDateOf('EMP-1'));
    }

    /**
     * The id-ID Excel default, and the format the old expression could not
     * read at all: month 15 does not exist, so it produced 1970-01-01.
     */
    public function test_a_slashed_date_is_read_day_first(): void
    {
        $this->importRows([['EMP-1', '15/01/2026']]);

        $this->assertSame('2026-01-15', $this->joinDateOf('EMP-1'));
    }

    /**
     * The dangerous one. Both readings are real dates, so nothing looks
     * wrong -- it is simply the wrong month. 1 February, not 2 January.
     */
    public function test_an_ambiguous_slashed_date_is_not_read_american_first(): void
    {
        $this->importRows([['EMP-1', '01/02/2026']]);

        $this->assertSame('2026-02-01', $this->joinDateOf('EMP-1'));
    }

    public function test_a_dashed_and_a_dotted_date_are_also_read_day_first(): void
    {
        $this->importRows([['EMP-1', '15-01-2026'], ['EMP-2', '15.01.2026']]);

        $this->assertSame('2026-01-15', $this->joinDateOf('EMP-1'));
        $this->assertSame('2026-01-15', $this->joinDateOf('EMP-2'));
    }

    /**
     * A cell the customer formatted as a date carries an Excel serial
     * number, not text. This already worked and must keep working -- it is
     * the one path the old code handled deliberately.
     */
    public function test_a_real_excel_date_cell_is_read_from_its_serial(): void
    {
        $serial = ExcelDate::stringToExcel('2026-01-15');

        $this->importRows([['EMP-1', $serial]], ['K2']);

        $this->assertSame('2026-01-15', $this->joinDateOf('EMP-1'));
    }

    /**
     * THE FABRICATED DATE. Unreadable must mean empty, never the epoch.
     */
    public function test_an_unreadable_date_becomes_empty_and_is_never_the_epoch(): void
    {
        $this->importRows([['EMP-1', 'belum tahu']]);

        $this->assertNull($this->joinDateOf('EMP-1'), 'An unreadable join date must be left empty.');
    }

    public function test_an_impossible_date_is_refused_rather_than_rolled_over(): void
    {
        $this->importRows([['EMP-1', '31/02/2026']]);

        $this->assertNull($this->joinDateOf('EMP-1'), '31 February must not roll over into March.');
    }

    public function test_a_blank_date_is_simply_empty(): void
    {
        $this->importRows([['EMP-1', '']]);

        $this->assertNull($this->joinDateOf('EMP-1'));
    }

    /**
     * A join date is not a critical field, so the row still lands -- but
     * the customer is told which rows arrived without one, because a date
     * that silently vanished is as misleading as one that was invented.
     */
    public function test_the_row_still_imports_and_the_unreadable_date_is_reported(): void
    {
        $result = $this->importRows([['EMP-1', 'belum tahu']]);

        $this->assertSame(1, $result['imported']);
        $this->assertNotEmpty($result['warnings'] ?? [], 'The import should report the unreadable date.');
        $this->assertSame(2, $result['warnings'][0]['row']);
        $this->assertStringContainsString('tanggal', mb_strtolower($result['warnings'][0]['reason']));
    }

    public function test_a_readable_date_raises_no_warning(): void
    {
        $result = $this->importRows([['EMP-1', '15/01/2026']]);

        $this->assertSame([], $result['warnings'] ?? []);
    }

    /**
     * The row number a customer is told to go and fix must be the row
     * number their spreadsheet shows, header included.
     */
    public function test_the_reported_row_number_is_the_spreadsheet_row_number(): void
    {
        $result = $this->importRows([
            ['EMP-1', '2026-01-15'],
            ['', '2026-01-15'],
        ]);

        $this->assertSame('Missing Employee ID', $result['skipped'][0]['reason']);
        $this->assertSame(3, $result['skipped'][0]['row'], 'Header is row 1, first record row 2, so this is row 3.');
    }
}
