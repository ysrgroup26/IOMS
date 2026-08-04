<?php

namespace App\Imports;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectManpower;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Employee Import Engine (v1.6.8) -- built as the foundation a future
 * generic Import Engine would extend (Department, Project, PPE Master,
 * Vendor, Contractor imports, per the spec). What makes this reusable
 * rather than a one-off: the row-by-row processing loop, the
 * critical/optional field split, and the "never stop on one bad row"
 * behavior are all patterns any future importer would need identically
 * -- only the field mapping and the target model differ per import
 * type. A future ImportEngine base class would extract this class's
 * `processRow()` loop structure; deliberately not doing that extraction
 * now, since this is the first of its kind and abstracting a pattern
 * from a single example tends to guess the wrong shape.
 *
 * Uses `OnEachRow` (processes one row at a time, not `ToModel`'s
 * batch-return style) specifically because per-row error handling needs
 * to happen mid-loop -- a duplicate Employee ID or missing critical
 * field must be recorded as a skipped row and then continue past it,
 * not throw and abort the whole file.
 *
 * Smart Master Data Detection (v1.6.10) reuses this same class in a
 * `$previewOnly` mode, rather than a separate parallel scanner --
 * exactly the same row parsing, critical-field checks, and duplicate
 * detection run either way; only the point where a row would actually
 * write to the database is skipped. This is deliberately a mode on the
 * existing engine, not a rewrite of it.
 */
class EmployeesImport implements OnEachRow, WithChunkReading, WithHeadingRow
{
    public int $totalRows = 0;

    public int $imported = 0;

    public int $needsCompletion = 0;

    public array $skipped = []; // [['row' => 5, 'reason' => 'Duplicate Employee ID'], ...]

    /** Distinct, non-blank Department/Position names encountered while
     *  scanning -- what MasterDataDetector classifies as existing/new.
     *  Collected in both modes (negligible overhead either way), but
     *  only actually consumed by the preview flow today. */
    public array $departmentNames = [];

    public array $positionNames = [];

    private array $seenEmployeeIds = [];

    public function __construct(
        private readonly int $companyId,
        private readonly int $userId,
        private readonly bool $previewOnly = false
    ) {}

    public function chunkSize(): int
    {
        // Comfortably handles "hundreds or thousands" per the spec --
        // chunked reading means the whole file is never held in memory
        // at once regardless of how large it is.
        return 200;
    }

    public function onRow(Row $row): void
    {
        $this->totalRows++;
        $data = $row->toArray();
        $rowNumber = $row->getIndex() + 1;

        $employeeId = trim((string) ($data['employee_id'] ?? ''));
        $fullName = trim((string) ($data['full_name'] ?? ''));

        // Critical fields: a row missing either of these can't become a
        // usable employee record at all (both are NOT NULL columns), so
        // it's skipped rather than partially imported.
        if ($employeeId === '') {
            $this->skip($rowNumber, 'Missing Employee ID');

            return;
        }

        if ($fullName === '') {
            $this->skip($rowNumber, 'Missing Full Name');

            return;
        }

        if (isset($this->seenEmployeeIds[$employeeId])) {
            $this->skip($rowNumber, "Duplicate Employee ID (also row {$this->seenEmployeeIds[$employeeId]})");

            return;
        }

        if (Employee::withTrashed()->where('employee_id', $employeeId)->exists()) {
            $this->skip($rowNumber, 'Duplicate Employee ID (already exists)');

            return;
        }

        $this->seenEmployeeIds[$employeeId] = $rowNumber;

        // Department is critical in the sense that it's tracked for
        // completion status, but NOT required to import the row --
        // "Unassigned" is genuinely supported (department_id is
        // nullable), matching the spec's "(or Unassigned if supported)".
        $departmentName = trim((string) ($data['department'] ?? ''));
        if ($departmentName !== '') {
            $this->departmentNames[] = $departmentName;
        }

        $positionName = trim((string) ($data['position'] ?? ''));
        if ($positionName !== '') {
            $this->positionNames[] = $positionName;
        }

        // Preview mode stops here -- every check above (critical fields,
        // duplicates) has already run, which is exactly what the
        // preview's "Invalid Rows"/"Duplicate Employee IDs" counts need.
        // What it deliberately skips is resolving department/position
        // IDs and writing anything to the database.
        if ($this->previewOnly) {
            $this->imported++;
            if ($departmentName === '') {
                $this->needsCompletion++;
            }

            return;
        }

        $departmentId = null;
        if ($departmentName !== '') {
            $departmentId = Department::where('company_id', $this->companyId)
                ->where('name', $departmentName)
                ->value('id');
        }

        $positionId = null;
        if ($positionId === null && $positionName !== '' && $departmentId) {
            $positionId = Position::where('department_id', $departmentId)
                ->where('name', $positionName)
                ->value('id');
        }

        $joinDate = null;
        $rawJoinDate = $data['join_date'] ?? null;
        if ($rawJoinDate) {
            try {
                $joinDate = is_numeric($rawJoinDate)
                    ? Date::excelToDateTimeObject($rawJoinDate)->format('Y-m-d')
                    : date('Y-m-d', strtotime((string) $rawJoinDate));
            } catch (\Throwable) {
                $joinDate = null;
            }
        }

        // Employment Status is validated against the real enum
        // (active/inactive/resigned) rather than trusted blindly -- an
        // unrecognized value falls back to the column's own default
        // ('active') instead of failing the row, since this was never
        // listed as a critical field that should block import.
        $status = strtolower(trim((string) ($data['employment_status'] ?? '')));
        if (! in_array($status, ['active', 'inactive', 'resigned'], true)) {
            $status = 'active';
        }

        DB::transaction(function () use ($data, $employeeId, $fullName, $departmentId, $positionId, $joinDate, $status) {
            $employee = Employee::create([
                'employee_id' => $employeeId,
                'full_name' => $fullName,
                'company_id' => $this->companyId,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'status' => $status,
                'join_date' => $joinDate,
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'email' => trim((string) ($data['email'] ?? '')) ?: null,
                'address' => trim((string) ($data['address'] ?? '')) ?: null,
                'emergency_contact_name' => trim((string) ($data['emergency_contact_name'] ?? '')) ?: null,
                'emergency_contact_phone' => trim((string) ($data['emergency_contact_phone'] ?? '')) ?: null,
            ]);

            // Project is optional and never blocks the import -- if the
            // name doesn't match an existing project (or is blank),
            // the employee is simply not assigned to one yet.
            $projectName = trim((string) ($data['project'] ?? ''));
            if ($projectName !== '') {
                $projectId = Project::where('company_id', $this->companyId)
                    ->where('name', $projectName)
                    ->value('id');

                if ($projectId) {
                    ProjectManpower::create([
                        'project_id' => $projectId,
                        'employee_id' => $employee->id,
                        'assigned_date' => now()->toDateString(),
                        'added_by' => $this->userId,
                    ]);
                }
            }
        });

        $this->imported++;
        if ($departmentId === null) {
            $this->needsCompletion++;
        }
    }

    private function skip(int $rowNumber, string $reason): void
    {
        $this->skipped[] = ['row' => $rowNumber, 'reason' => $reason];
    }
}
