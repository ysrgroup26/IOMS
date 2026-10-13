<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Package;
use App\Models\Position;
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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * THE REPORTED SEQUENCE, END TO END: IMPORT, DELETE, IMPORT AGAIN.
 *
 * A customer imported their workforce, deleted every employee, and then
 * could not import the file again: all 75 rows came back as "Duplicate
 * Employee ID" while the Employees page correctly showed nobody. The
 * duplicate check read soft-deleted rows; the list does not.
 *
 * v2.114.0 answered that by RESTORING the deleted record in place, which
 * worked but kept the underlying oddity: a deleted employee still existed
 * and still owned their number. v2.115.0 settled it at the source -- the
 * owner's decision is that deletion is PERMANENT, so a deleted employee
 * is gone, their number and NIK are free, and a re-import simply creates
 * employees again. This file now pins THAT, and the restore cases it used
 * to carry are deliberately gone rather than left as dead expectations.
 *
 * Their master data survived the deletion, as it should, so the second
 * failure mode is ruled out at the same time: existing departments and
 * positions must be REUSED, never duplicated and never a reason to refuse
 * a row.
 *
 * Every test here drives the real HTTP endpoints, and the headline one
 * reproduces the exact sequence with the exact row count.
 */
class EmployeeReimportAfterDeletionTest extends TestCase
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

    private const DEPARTMENTS = ['Produksi', 'QA/QC', 'Logistik', 'HSE', 'Maintenance'];

    private const POSITIONS = ['Welder', 'Fitter', 'Inspector', 'Storeman', 'Technician'];

    private const TYPES = ['Tetap', 'Kontrak', 'Harian', 'Magang', 'Subkon'];

    private const EXPECTED_TYPES = ['pkwtt', 'pkwt', 'daily', 'intern', 'contractor'];

    private const STATUSES = ['active', 'active', 'active', 'inactive', 'resigned'];

    /**
     * A 75-row roster with a different join date on every row, stored as
     * a GENUINE Excel date cell displayed as `mmm-yy` -- the shape that
     * collapses many dates into one if an importer reads the displayed
     * value rather than the raw one.
     */
    private function roster(int $rows = 75): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray([[
            'Employee ID', 'Full Name', 'NIK (KTP)', 'Department',
            'Position', 'Workforce Type', 'Employment Status', 'Join Date',
        ]], null, 'A1');

        for ($i = 1; $i <= $rows; $i++) {
            $row = $i + 1;

            $sheet->getCell('A'.$row)->setValueExplicit(sprintf('EMP-%04d', $i), DataType::TYPE_STRING);
            $sheet->setCellValue('B'.$row, 'Karyawan '.sprintf('%02d', $i));
            $sheet->getCell('C'.$row)->setValueExplicit(sprintf('32010101019%06d', $i), DataType::TYPE_STRING);
            $sheet->setCellValue('D'.$row, self::DEPARTMENTS[$i % 5]);
            $sheet->setCellValue('E'.$row, self::POSITIONS[$i % 5]);
            $sheet->setCellValue('F'.$row, self::TYPES[$i % 5]);
            $sheet->setCellValue('G'.$row, self::STATUSES[$i % 5]);

            $sheet->setCellValue('H'.$row, ExcelDate::PHPToExcel(strtotime($this->joinDateFor($i))));
            $sheet->getStyle('H'.$row)->getNumberFormat()->setFormatCode('mmm-yy');
        }

        $path = tempnam(sys_get_temp_dir(), 'ioms-roster-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'roster.xlsx', null, null, true);
    }

    private function joinDateFor(int $i): string
    {
        return sprintf('2026-%02d-%02d', (($i - 1) % 12) + 1, (($i - 1) % 27) + 1);
    }

    /** @return array<string, mixed> */
    private function import(UploadedFile $file, bool $createMasterData = false): array
    {
        $route = $createMasterData
            ? 'employees.import.create-missing-and-import'
            : 'employees.import';

        $payload = ['file' => $file, 'company_id' => $this->yard->id];

        if ($createMasterData) {
            $payload['new_departments'] = self::DEPARTMENTS;
            $payload['new_positions'] = self::POSITIONS;
        }

        $response = $this->actingAs($this->admin)->postJson(route($route), $payload);
        $response->assertOk();

        return $response->json();
    }

    private function listTotal(array $query = []): int
    {
        return $this->actingAs($this->admin)->get(route('employees.index', $query))
            ->assertOk()
            ->viewData('page')['props']['employees']['total'];
    }

    /**
     * Delete employees the way the owner does: the real bulk endpoint,
     * with the agreed count and the typed confirmation.
     *
     * Deliberately NOT `EmployeeEraser` directly -- the defect being
     * pinned here is about what the customer can do from the screens, so
     * the deletion half of the sequence has to go through the same
     * request the Employees page sends.
     *
     * @param  list<int>  $ids
     */
    private function deleteThroughTheUi(array $ids): void
    {
        $this->actingAs($this->admin)
            ->delete(route('employees.bulk-destroy'), [
                'ids' => $ids,
                'expected_count' => count($ids),
                'confirmation' => \App\Http\Requests\BulkDestroyEmployeesRequest::CONFIRMATION,
            ])
            ->assertSessionHasNoErrors();
    }

    /** @return list<int> */
    private function everyEmployeeId(): array
    {
        return Employee::withoutGlobalScopes()
            ->where('company_id', $this->yard->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // --- the reported sequence ---------------------------------------------

    public function test_seventy_five_employees_import_then_delete_then_import_again(): void
    {
        // 1. the first import, creating the master data as it goes
        $first = $this->import($this->roster(), createMasterData: true);

        $this->assertSame(75, $first['imported']);
        $this->assertSame([], $first['skipped']);
        $this->assertSame(75, $this->listTotal());

        $departments = Department::where('company_id', $this->yard->id)->count();
        $positions = Position::where('company_id', $this->yard->id)->count();

        // 2. the owner deletes every employee, through the real screen
        $this->deleteThroughTheUi($this->everyEmployeeId());

        $this->assertSame(0, $this->listTotal(), 'The list must show nobody after the deletion.');
        $this->assertSame(
            0,
            Employee::withoutGlobalScopes()->where('company_id', $this->yard->id)->count(),
            'Deletion is permanent: no row may be left holding those employee numbers.'
        );

        // The master data survives a deletion, as it should.
        $this->assertSame($departments, Department::where('company_id', $this->yard->id)->count());
        $this->assertSame($positions, Position::where('company_id', $this->yard->id)->count());

        // 3. re-importing the same file -- the reported failure
        $second = $this->import($this->roster());

        $this->assertSame(
            [],
            $second['skipped'],
            'Re-importing after a deletion refused rows. Every reason: '
            .implode(' | ', array_column($second['skipped'], 'reason'))
        );

        $this->assertSame(75, $second['imported'], 'Every row should have been imported as a new employee.');

        // 4. and they are back, visible, with no duplicates
        $this->assertSame(75, $this->listTotal(), 'The re-imported employees are not on the Employees page.');
        $this->assertSame(
            75,
            Employee::withoutGlobalScopes()->where('company_id', $this->yard->id)->count(),
            'The re-import created duplicate rows.'
        );

        // 5. master data was reused, not duplicated
        $this->assertSame($departments, Department::where('company_id', $this->yard->id)->count());
        $this->assertSame($positions, Position::where('company_id', $this->yard->id)->count());
    }

    /**
     * The deletion and the re-import are two acts, and the trail has to
     * show both -- including WHO was deleted, since the record itself is
     * gone and `subject_id` points at nothing afterwards.
     */
    public function test_the_audit_trail_records_the_deletion_and_the_re_import(): void
    {
        $this->import($this->roster(3), createMasterData: true);

        $deleted = Employee::where('employee_id', 'EMP-0001')->firstOrFail();
        $this->deleteThroughTheUi($this->everyEmployeeId());

        $deletion = ActivityLog::withoutGlobalScopes()
            ->where('action', 'deleted')
            ->where('subject_id', $deleted->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertStringContainsString('permanently deleted', $deletion->description);
        $this->assertSame('EMP-0001', $deletion->meta['employee']['employee_id'] ?? null);
        $this->assertSame($deleted->full_name, $deletion->meta['employee']['full_name'] ?? null);

        $this->import($this->roster(3));

        $entry = ActivityLog::where('action', 'imported')->latest('id')->firstOrFail();

        $this->assertStringContainsString('Imported 3 employee(s)', $entry->description);
    }

    /**
     * A re-imported employee is a NEW record. v2.114.0 asserted the
     * opposite -- that the row kept its primary key -- because a deleted
     * employee was still there to reinstate. There is nothing to reinstate
     * now, and the old id must not come back from anywhere.
     */
    public function test_a_re_imported_employee_is_a_fresh_record(): void
    {
        $this->import($this->roster(3), createMasterData: true);

        $before = Employee::where('employee_id', 'EMP-0001')->firstOrFail();
        $this->deleteThroughTheUi([$before->id]);

        $this->assertDatabaseMissing('employees', ['id' => $before->id]);

        $this->import($this->roster(3));

        $after = Employee::where('employee_id', 'EMP-0001')->firstOrFail();

        $this->assertNotSame($before->id, $after->id, 'The old row was resurrected instead of a new one created.');
        $this->assertSame('Karyawan 01', $after->full_name);
    }

    // --- accuracy -----------------------------------------------------------

    public function test_every_employee_keeps_its_own_join_date(): void
    {
        $this->import($this->roster(), createMasterData: true);

        $dates = Employee::where('company_id', $this->yard->id)
            ->pluck('join_date', 'employee_id')
            ->map(fn ($date) => $date?->toDateString());

        $this->assertCount(75, $dates->unique(), 'Join dates collapsed onto each other.');

        foreach ([1, 13, 40, 75] as $i) {
            $this->assertSame(
                $this->joinDateFor($i),
                $dates[sprintf('EMP-%04d', $i)],
                "EMP-{$i} did not keep the date its row carried."
            );
        }
    }

    public function test_workforce_type_and_status_follow_the_file(): void
    {
        $this->import($this->roster(), createMasterData: true);

        for ($i = 1; $i <= 10; $i++) {
            $employee = Employee::where('employee_id', sprintf('EMP-%04d', $i))->firstOrFail();

            $this->assertSame(self::EXPECTED_TYPES[$i % 5], $employee->employment_type, "row {$i} workforce type");
            $this->assertSame(self::STATUSES[$i % 5], $employee->status, "row {$i} status");
        }

        // And nobody was quietly stamped permanent.
        $this->assertSame(
            15,
            Employee::where('company_id', $this->yard->id)->where('employment_type', 'pkwtt')->count()
        );
    }

    public function test_identifiers_survive_the_round_trip(): void
    {
        $this->import($this->roster(3), createMasterData: true);

        $employee = Employee::where('employee_id', 'EMP-0001')->firstOrFail();

        $this->assertSame('EMP-0001', $employee->employee_id);
        $this->assertSame('32010101019000001', $employee->nik);
    }

    // --- duplicates that are genuinely duplicates ---------------------------

    public function test_a_live_employee_is_still_refused_as_a_duplicate(): void
    {
        $this->import($this->roster(3), createMasterData: true);

        $second = $this->import($this->roster(3));

        $this->assertSame(0, $second['imported']);
        $this->assertCount(3, $second['skipped']);
        $this->assertStringContainsString('already used in this Operating Unit', $second['skipped'][0]['reason']);

        $this->assertSame(3, $this->listTotal(), 'A refused duplicate must not change the roster.');
    }

    /**
     * One deleted and two live employees in the same file: the file must
     * not be refused wholesale, and each row must get its own answer.
     */
    public function test_a_mixed_file_imports_the_deleted_one_and_refuses_the_live_ones(): void
    {
        $this->import($this->roster(3), createMasterData: true);

        $gone = Employee::where('employee_id', 'EMP-0002')->firstOrFail();
        $this->deleteThroughTheUi([$gone->id]);

        $result = $this->import($this->roster(3));

        $this->assertSame(1, $result['imported'], 'The freed employee number was not importable again.');
        $this->assertCount(2, $result['skipped'], 'The two live employees should be the only refusals.');
        $this->assertStringContainsString('already used in this Operating Unit', $result['skipped'][0]['reason']);

        $this->assertSame(3, $this->listTotal());
    }

    /**
     * The NIK is freed too, and by somebody ELSE's employee number -- the
     * case v2.114.0 had to refuse with "masih dipakai oleh karyawan yang
     * sudah dihapus", because a deleted row still occupied the
     * `(company_id, nik)` index. Nothing occupies it now.
     */
    public function test_a_nik_freed_by_a_deletion_can_be_claimed_by_a_new_employee(): void
    {
        $this->import($this->roster(3), createMasterData: true);

        $holder = Employee::where('employee_id', 'EMP-0001')->firstOrFail();
        $this->assertSame('32010101019000001', $holder->nik);

        $this->deleteThroughTheUi([$holder->id]);

        // A NEW employee number claiming the deleted person's NIK.
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Employee ID', 'Full Name', 'NIK (KTP)'],
            ['EMP-9999', 'Orang Baru', '32010101019000001'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'ioms-nik-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $result = $this->import(new UploadedFile($path, 'nik.xlsx', null, null, true));

        $this->assertSame(1, $result['imported']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame('32010101019000001', Employee::where('employee_id', 'EMP-9999')->firstOrFail()->nik);
    }

    // --- existing master data ------------------------------------------------

    /**
     * The customer's departments and positions outlived the employees.
     * Importing against them must reuse them, and must not refuse a row.
     */
    public function test_existing_departments_and_positions_are_reused_not_duplicated(): void
    {
        foreach (self::DEPARTMENTS as $name) {
            Department::create(['company_id' => $this->yard->id, 'name' => mb_strtoupper($name)]);
        }

        foreach (self::POSITIONS as $name) {
            Position::create(['company_id' => $this->yard->id, 'name' => mb_strtolower($name)]);
        }

        $departmentsBefore = Department::where('company_id', $this->yard->id)->count();
        $positionsBefore = Position::where('company_id', $this->yard->id)->count();

        $result = $this->import($this->roster(20));

        $this->assertSame(20, $result['imported']);
        $this->assertSame([], $result['skipped']);

        $this->assertSame(
            $departmentsBefore,
            Department::where('company_id', $this->yard->id)->count(),
            'The import created a second copy of a department that already existed.'
        );
        $this->assertSame(
            $positionsBefore,
            Position::where('company_id', $this->yard->id)->count(),
            'The import created a second copy of a position that already existed.'
        );

        // And the employees were actually attached to them.
        $this->assertSame(
            20,
            Employee::where('company_id', $this->yard->id)->whereNotNull('department_id')->count()
        );
    }

    // --- the preview promises what the import does ---------------------------

    public function test_the_preview_counts_what_it_will_create_without_writing_it(): void
    {
        $this->import($this->roster(5), createMasterData: true);
        $this->deleteThroughTheUi($this->everyEmployeeId());

        $preview = $this->actingAs($this->admin)->postJson(route('employees.import.preview'), [
            'file' => $this->roster(5),
            'company_id' => $this->yard->id,
        ]);

        $preview->assertOk();

        $this->assertSame(5, $preview->json('total_rows'));
        $this->assertSame(5, $preview->json('valid_rows'), 'After a permanent deletion every row is a new employee again.');
        $this->assertSame(0, $preview->json('invalid_rows'));

        $this->assertSame(0, $this->listTotal(), 'The preview wrote to the database.');
    }

    // --- tenant isolation ----------------------------------------------------

    /**
     * Another tenant's employee with the SAME number is neither a
     * duplicate of ours nor ours to touch.
     */
    public function test_another_tenants_employee_is_never_touched_by_our_import(): void
    {
        $other = Tenant::create(['name' => 'Tetangga', 'slug' => 't-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);

        $otherYard = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Tetangga', 'code' => 'YT', 'tenant_id' => $other->id, 'is_active' => true,
        ]);

        $theirs = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-0001',
            'full_name' => 'Karyawan Tetangga',
            'company_id' => $otherYard->id,
            'status' => 'active',
        ]);

        $result = $this->import($this->roster(3), createMasterData: true);

        $this->assertSame(3, $result['imported'], 'Another tenant\'s row blocked this import.');

        $theirs->refresh();

        $this->assertSame('Karyawan Tetangga', $theirs->full_name, 'Another tenant\'s employee was overwritten by this import.');
        $this->assertSame($otherYard->id, $theirs->company_id);
    }
}
