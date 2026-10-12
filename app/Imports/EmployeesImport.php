<?php

namespace App\Imports;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectManpower;
use App\Services\MasterDataDetector;
use App\Support\NameFormatter;
use App\Support\SpreadsheetDate;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

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

    /**
     * Rows that DID import but carry something the customer should know
     * about -- today, a join date that could not be read and was left
     * empty. Deliberately separate from `$skipped`: a warning never
     * means a row was refused, and conflating the two would make a
     * successful import look like a partial one.
     *
     * @var list<array{row: int, reason: string}>
     */
    public array $warnings = [];

    /** Distinct, non-blank Department/Position names encountered while
     *  scanning -- what MasterDataDetector classifies as existing/new.
     *  Collected in both modes (negligible overhead either way), but
     *  only actually consumed by the preview flow today. */
    public array $departmentNames = [];

    public array $positionNames = [];

    private array $seenEmployeeIds = [];

    /**
     * @param  array<string,string>|null  $columnKeys  Milestone 3 (Import
     *                                                 Mapping, Task #67): target field => actual heading-row key
     *                                                 to read it from, resolved by
     *                                                 `App\Services\FieldMappingService::importColumnKeys()`.
     *                                                 Null (or a field missing from the map) falls back to the
     *                                                 field's own name -- byte-for-byte the original behavior,
     *                                                 so an unconfigured tenant's import is unaffected.
     */
    public function __construct(
        private readonly int $companyId,
        private readonly int $userId,
        private readonly bool $previewOnly = false,
        private readonly ?array $columnKeys = null,
        /*
         * v2.111.0 -- HOW FAR THE FILE WE ARE READING SITS FROM THE ONE
         * THE CUSTOMER HAS.
         *
         * Zero for a workbook read directly, which is every import this
         * class has ever done. It is non-zero only when the Document
         * Platform has normalised a source whose table did not start at
         * row 1 -- a roster with a company header above it, say -- into a
         * clean spreadsheet with its headings on row 1.
         *
         * Without it, every row number this class reports is a row in a
         * temporary file the customer has never seen. On that roster the
         * dialog said "Row 5 -- Missing Full Name" and told them to fix
         * their file, where row 5 is the heading row. A wrong line
         * reference beside the words "fix this in your file" is worse
         * than no line reference.
         *
         * It shifts only what is REPORTED. Nothing about reading,
         * validating or writing a row depends on it.
         */
        private readonly int $rowOffset = 0,
    ) {}

    private function col(array $data, string $field): mixed
    {
        $key = $this->columnKeys[$field] ?? $field;

        return $data[$key] ?? $data[$field] ?? null;
    }

    /**
     * v2.101.0 -- built once, not per row. Besides being the only way to
     * compare on a normalised key without a `whereRaw`, this turns two
     * queries per row into two per import.
     *
     * Rebuilt lazily on first use, which matters because
     * `createMissingMasterDataAndImport()` creates master data and then
     * runs the import inside the same transaction -- the map has to be
     * read after those inserts, not before.
     *
     * @var array<string, int>|null
     */
    private ?array $departmentMap = null;

    /** @var array<string, list<array{id: int, department_id: int|null}>>|null */
    private ?array $positionMap = null;

    /**
     * Employee numbers already taken in this company, keyed case-
     * insensitively because the column's collation is and a spreadsheet is
     * not consistent about it.
     *
     * @var array<string, true>|null
     */
    private ?array $existingEmployeeIds = null;

    /** @return array<string, true> */
    private function existingEmployeeIds(): array
    {
        return $this->existingEmployeeIds ??= Employee::withTrashed()
            ->where('company_id', $this->companyId)
            ->pluck('employee_id')
            ->reduce(function (array $carry, ?string $id) {
                $key = mb_strtolower(trim((string) $id));

                if ($key !== '') {
                    $carry[$key] = true;
                }

                return $carry;
            }, []);
    }

    /**
     * National IDs already taken in this company, keyed case-insensitively
     * for the same reason employee numbers are. v2.108.1.
     *
     * @var array<string, true>|null
     */
    private ?array $existingNiks = null;

    /** @return array<string, true> */
    private function existingNiks(): array
    {
        return $this->existingNiks ??= Employee::withTrashed()
            ->where('company_id', $this->companyId)
            ->whereNotNull('nik')
            ->pluck('nik')
            ->reduce(function (array $carry, ?string $nik) {
                $key = mb_strtolower(trim((string) $nik));

                if ($key !== '') {
                    $carry[$key] = true;
                }

                return $carry;
            }, []);
    }

    /** @var array<string, int> */
    private array $seenNiks = [];

    /**
     * v2.108.1 -- WORKFORCE TYPE, IN THE WORDS A SPREADSHEET USES.
     *
     * The column stores `pkwtt`/`pkwt`/`daily`/`intern`/`pkl`/
     * `contractor`/`outsource`, but nobody types those into a workforce
     * list. The spellings people actually use map onto them here; anything
     * unrecognised falls back to the column's own default rather than
     * failing the row, exactly as Employment Status already does.
     *
     * @var array<string, string>
     */
    private const EMPLOYMENT_TYPE_ALIASES = [
        'tetap' => 'pkwtt', 'permanent' => 'pkwtt', 'karyawan tetap' => 'pkwtt', 'pegawai tetap' => 'pkwtt',
        'kontrak' => 'pkwt', 'contract' => 'pkwt', 'karyawan kontrak' => 'pkwt', 'pegawai kontrak' => 'pkwt',
        'harian' => 'daily', 'harian lepas' => 'daily', 'daily worker' => 'daily', 'buruh harian' => 'daily',
        'magang' => 'intern', 'internship' => 'intern', 'trainee' => 'intern',
        'siswa pkl' => 'pkl', 'praktek kerja lapangan' => 'pkl', 'praktik kerja lapangan' => 'pkl',
        'kontraktor' => 'contractor', 'subkon' => 'contractor', 'subkontraktor' => 'contractor', 'vendor' => 'contractor',
        'alih daya' => 'outsource', 'alihdaya' => 'outsource', 'outsourcing' => 'outsource',
    ];

    /** @return array<string, int> */
    private function departmentMap(): array
    {
        return $this->departmentMap ??= Department::where('company_id', $this->companyId)
            ->get(['id', 'name'])
            ->reduce(function (array $carry, Department $department) {
                $key = MasterDataDetector::comparisonKey($department->name);

                // First one wins, so a pre-existing duplicate left behind
                // by the old behaviour resolves predictably instead of
                // depending on row order.
                $carry[$key] ??= $department->id;

                return $carry;
            }, []);
    }

    /** @return array<string, list<array{id: int, department_id: int|null}>> */
    private function positionMap(): array
    {
        return $this->positionMap ??= Position::where('company_id', $this->companyId)
            ->get(['id', 'name', 'department_id'])
            ->reduce(function (array $carry, Position $position) {
                $carry[MasterDataDetector::comparisonKey($position->name)][] = [
                    'id' => $position->id,
                    'department_id' => $position->department_id,
                ];

                return $carry;
            }, []);
    }

    /**
     * The database's complaint, in a form a customer can act on.
     *
     * Only the shapes that are genuinely recognisable are named; anything
     * else keeps the driver's own message rather than being relabelled as
     * something this code did not actually observe. A message that guesses
     * wrong is worse than one that is merely terse.
     */
    private function readableFailure(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'Duplicate entry') || str_contains($message, 'UNIQUE constraint')) {
            return 'Employee ID atau NIK sudah dipakai oleh karyawan lain.';
        }

        if (str_contains($message, 'Data too long')) {
            return 'Ada isi kolom yang melebihi panjang maksimum, misalnya nomor telepon atau email.';
        }

        if (str_contains($message, 'Incorrect date') || str_contains($message, 'Invalid datetime')) {
            return 'Format tanggal pada baris ini tidak dapat dibaca.';
        }

        return 'Baris ini ditolak database: '.mb_substr($message, 0, 160);
    }

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
        /*
         * v2.108.0 -- THE ROW NUMBER THE CUSTOMER'S SPREADSHEET SHOWS.
         *
         * This was `getIndex() + 1`. `Row::getIndex()` already returns the
         * real sheet row (the header is row 1, so the first record is row
         * 2), and the extra `+ 1` sent every customer one row past the
         * one they had to fix. On a 141-row file that is 141 wrong
         * line references.
         */
        $rowNumber = $row->getIndex() + $this->rowOffset;

        $employeeId = trim((string) ($this->col($data, 'employee_id') ?? ''));
        // v2.105.0: written the way the product displays a person --
        // "budi santoso" and "BUDI SANTOSO" both become "Budi Santoso".
        $fullName = NameFormatter::title($this->col($data, 'full_name'));

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

        /*
         * v2.105.0 -- CHECKED AT THE GRANULARITY THE CONSTRAINT ACTUALLY HAS.
         *
         * This was `Employee::withTrashed()->where('employee_id', $id)->exists()`:
         * one query per row, and scoped by the company scope to whatever
         * companies the CURRENT USER can see. That is the wrong granularity
         * in both directions. It refused a number another company in the
         * same tenant happened to use, and it could not see the one case
         * that actually broke production -- a collision with ANOTHER
         * TENANT's employee number, which tenant isolation correctly hides
         * from this query but which the old GLOBAL unique index still
         * refused at insert.
         *
         * That is the 141-row report: the preview said every row was valid,
         * because within this customer's own data every row WAS valid, and
         * the insert then threw against numbers belonging to a different
         * customer entirely.
         *
         * The index is now per company (see the migration), and this
         * predicts exactly that: one query per import, keyed case-
         * insensitively, including soft-deleted rows because a soft-deleted
         * employee still occupies its number in the index.
         */
        if (isset($this->existingEmployeeIds()[mb_strtolower($employeeId)])) {
            $this->skip($rowNumber, "Duplicate Employee ID \"{$employeeId}\" (already used in this Operating Unit)");

            return;
        }

        $this->seenEmployeeIds[$employeeId] = $rowNumber;

        /*
         * v2.108.1 -- THE NATIONAL ID, CHECKED AT ITS OWN UNIQUE INDEX.
         *
         * `employees` carries unique(company_id, nik), so a repeated NIK
         * is refused by the database exactly as a repeated employee
         * number is. Checked here instead of being left to throw, so the
         * customer gets the row number and the reason rather than the
         * driver's message.
         *
         * BLANK MUST BECOME NULL, NEVER "". The index treats two empty
         * strings as a collision but ignores NULLs, so an empty cell
         * written as "" would let exactly one employee have no national ID
         * and refuse every one after it.
         */
        $nik = trim((string) ($this->col($data, 'nik') ?? ''));
        $nikKey = mb_strtolower($nik);

        if ($nik !== '') {
            if (isset($this->seenNiks[$nikKey])) {
                $this->skip($rowNumber, "Duplicate NIK (also row {$this->seenNiks[$nikKey]})");

                return;
            }

            if (isset($this->existingNiks()[$nikKey])) {
                $this->skip($rowNumber, "Duplicate NIK \"{$nik}\" (already used in this Operating Unit)");

                return;
            }

            $this->seenNiks[$nikKey] = $rowNumber;
        }

        // Department is critical in the sense that it's tracked for
        // completion status, but NOT required to import the row --
        // "Unassigned" is genuinely supported (department_id is
        // nullable), matching the spec's "(or Unassigned if supported)".
        $departmentName = trim((string) ($this->col($data, 'department') ?? ''));
        if ($departmentName !== '') {
            $this->departmentNames[] = $departmentName;
        }

        $positionName = trim((string) ($this->col($data, 'position') ?? ''));
        if ($positionName !== '') {
            $this->positionNames[] = $positionName;
        }

        /*
         * v2.108.0 -- AN UNREADABLE DATE IS EMPTY, NOT 1970.
         *
         * This was `date('Y-m-d', strtotime($cell))`. `strtotime()`
         * answers `false` for anything it cannot read, `date()` casts
         * `false` to 0, and 0 is the Unix epoch -- so every unreadable
         * cell became a join date of 1 January 1970. Nothing threw, so
         * the per-row `catch` below never saw it, and the row imported
         * carrying a date no human had typed.
         *
         * It also read a slashed date month-first, which is the wrong way
         * round for this product's customers: `15/01/2026` was month 15
         * (so, 1970 again) and `01/02/2026` -- 1 February -- was stored
         * as 2 January. See App\Support\SpreadsheetDate for the rules.
         *
         * A join date is not a critical field, so an unreadable one does
         * not cost the row. It is recorded as a WARNING instead: the row
         * lands, and the customer is told which rows arrived without a
         * date they will need to fill in. Silently dropping it would be
         * its own kind of lie.
         */
        $rawJoinDate = $this->col($data, 'join_date');
        $joinDate = SpreadsheetDate::parse($rawJoinDate);

        if ($joinDate === null && trim((string) ($rawJoinDate ?? '')) !== '') {
            $this->warnings[] = [
                'row' => $rowNumber,
                'reason' => 'Tanggal masuk "'.mb_substr(trim((string) $rawJoinDate), 0, 40).'" tidak dapat dibaca, '
                    .'jadi dikosongkan. Gunakan format 15/01/2026 atau 2026-01-15.',
            ];
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

        /*
          * v2.101.0 -- RESOLVED FROM A NORMALISED MAP, not a per-row query
          * against the raw cell.
          *
          * `where('name', $cell)` asked the database to decide whether
          * "hse" is "HSE", and MySQL and SQLite answer differently, so the
          * behaviour belonged to the connection. It also never forgave the
          * stray spaces a spreadsheet column collects.
          *
          * A POSITION IS NOW FOUND BY NAME WITHIN THE COMPANY, preferring
          * one that belongs to the row's department. It used to require
          * `department_id`, which meant the positions
          * `createMissingMasterDataAndImport()` had just created -- company
          * scoped, with no department -- could never be found by the import
          * that ran in the same request. The flow whose entire purpose is
          * to let blocked rows land was leaving them without a position.
          * Company scoping is also what MasterDataDetector documents and
          * classifies against, so all three agree again.
          */
        $departmentId = $departmentName !== ''
            ? ($this->departmentMap()[MasterDataDetector::comparisonKey($departmentName)] ?? null)
            : null;

        $positionId = null;
        if ($positionName !== '') {
            $candidates = $this->positionMap()[MasterDataDetector::comparisonKey($positionName)] ?? [];

            foreach ($candidates as $candidate) {
                if ($departmentId !== null && $candidate['department_id'] === $departmentId) {
                    $positionId = $candidate['id'];
                    break;
                }
            }

            $positionId ??= $candidates[0]['id'] ?? null;
        }

        // Employment Status is validated against the real enum
        // (active/inactive/resigned) rather than trusted blindly -- an
        // unrecognized value falls back to the column's own default
        // ('active') instead of failing the row, since this was never
        // listed as a critical field that should block import.
        $status = strtolower(trim((string) ($this->col($data, 'employment_status') ?? '')));
        if (! in_array($status, ['active', 'inactive', 'resigned'], true)) {
            $status = 'active';
        }

        /*
         * v2.108.1 -- Workforce Type. Previously unreachable from an
         * import at all: every imported employee silently took the
         * column's `pkwtt` default, so a yard whose workforce is mostly
         * PKWT and daily labour imported as if everyone were permanent.
         * Same forgiving rule as Employment Status above -- an
         * unrecognised value falls back to the default rather than
         * costing the row.
         */
        $rawType = mb_strtolower(trim((string) ($this->col($data, 'employment_type') ?? '')));
        $employmentType = self::EMPLOYMENT_TYPE_ALIASES[$rawType] ?? $rawType;

        if (! in_array($employmentType, Employee::EMPLOYMENT_TYPES, true)) {
            $employmentType = Employee::EMPLOYMENT_TYPE_PKWTT;
        }

        /*
          * v2.105.0 -- ONE BAD ROW MUST NOT COST THE OTHER HUNDRED AND FORTY.
          *
          * This class's own doc comment says it never stops on one bad row,
          * and that was true of every check it performs itself -- but a
          * failure raised by the DATABASE (a value too long for its column,
          * a constraint this code did not anticipate) threw straight out of
          * here. Running inside the caller's transaction, that discarded
          * every row already imported and every department created with
          * them.
          *
          * Caught per row and recorded with the row number, so the import
          * finishes and the customer is told exactly which rows did not
          * land and why. The reason is the database's own message, trimmed:
          * invented wording would describe a failure nobody observed.
          */
        try {
            DB::transaction(function () use ($data, $employeeId, $nik, $employmentType, $fullName, $departmentId, $positionId, $joinDate, $status) {
                $employee = Employee::create([
                    'employee_id' => $employeeId,
                    // Null, never '' -- see the unique-index note above.
                    'nik' => $nik !== '' ? $nik : null,
                    'employment_type' => $employmentType,
                    'full_name' => $fullName,
                    'company_id' => $this->companyId,
                    'department_id' => $departmentId,
                    'position_id' => $positionId,
                    'status' => $status,
                    'join_date' => $joinDate,
                    'phone' => trim((string) ($this->col($data, 'phone') ?? '')) ?: null,
                    'email' => trim((string) ($this->col($data, 'email') ?? '')) ?: null,
                    'address' => trim((string) ($this->col($data, 'address') ?? '')) ?: null,
                    'emergency_contact_name' => trim((string) ($this->col($data, 'emergency_contact_name') ?? '')) ?: null,
                    'emergency_contact_phone' => trim((string) ($this->col($data, 'emergency_contact_phone') ?? '')) ?: null,
                ]);

                // Project is optional and never blocks the import -- if the
                // name doesn't match an existing project (or is blank),
                // the employee is simply not assigned to one yet.
                $projectName = trim((string) ($this->col($data, 'project') ?? ''));
                if ($projectName !== '') {
                    $projectId = Project::where('company_id', $this->companyId)
                        ->whereRaw('LOWER(TRIM(name)) = ?', [MasterDataDetector::comparisonKey($projectName)])
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
        } catch (\Throwable $e) {
            $this->skipped[] = [
                'row' => $rowNumber,
                'reason' => $this->readableFailure($e),
            ];

            return;
        }

        // Taken now, so a later row in the same file cannot reuse it.
        $this->existingEmployeeIds[mb_strtolower($employeeId)] = true;

        if ($nik !== '') {
            $this->existingNiks[$nikKey] = true;
        }

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
