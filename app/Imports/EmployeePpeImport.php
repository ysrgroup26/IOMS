<?php

namespace App\Imports;

use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\PpeType;
use App\Services\MasterDataDetector;
use App\Support\SpreadsheetDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

/**
 * v2.121.0 -- ONE ROW PER PERSON, ONE DATED COLUMN PER KIND OF PPE.
 *
 * v2.120.0 read a long file: one row per issuance, with a `PPE Type`
 * column. That matches the database exactly and is wrong for the people
 * filling it in -- a yard of 300 workers with four items each is a
 * 1,200-row file nobody can read across, and the question an HSE officer
 * actually asks is "what does this person have", which is a ROW.
 *
 * So the file is now WIDE: Employee ID, Employee Name, then one column per
 * PPE type, each holding the date that item was last handed over. One row
 * becomes up to N issuances. An EMPTY CELL IS NOT A FACT -- it means there
 * is no record, and it produces no transaction. Nothing here invents a
 * date to make a row look complete.
 *
 * The PPE columns are not hardcoded. Each heading is resolved against the
 * company's own effective catalogue (ADR 050/058), so a customer whose
 * override renamed a type, or who added "Work Uniform" themselves, gets a
 * template and a reader that already know about it. A heading that matches
 * nothing is reported once, by name, rather than silently ignored --
 * silently ignoring a column is how a file imports "successfully" and
 * leaves half the equipment unrecorded.
 *
 * WHAT IT STILL WILL NOT DO, unchanged from v2.120.0: create an Employee,
 * create a PpeType, or issue a second item of a kind somebody already
 * holds. The duplicate guard resolves a type FAMILY, because a built-in
 * and a company's override of it are one kind of equipment wearing two
 * ids and `EmployeePpe::creating()` remaps between them. See ADR 059.
 */
class EmployeePpeImport implements OnEachRow, WithChunkReading, WithHeadingRow
{
    public int $totalRows = 0;

    /** Employee rows that produced at least one issuance. */
    public int $employeesTouched = 0;

    /** Individual PPE items issued (or that would be, in preview). */
    public int $imported = 0;

    /** @var list<array{row: int, reason: string}> */
    public array $skipped = [];

    /** @var list<array{row: int, reason: string}> */
    public array $warnings = [];

    public array $unknownEmployees = [];

    /** Headings that match no PPE type in this company's catalogue. */
    public array $unknownColumns = [];

    public int $alreadyHeld = 0;

    /** Cells left blank -- reported as a figure, never as a problem. */
    public int $blankCells = 0;

    private array $seenEmployees = [];

    private array $seenPairs = [];

    private ?array $employeesByNumber = null;

    private ?array $ppeTypesByName = null;

    private ?array $typeFamilies = null;

    private ?array $heldFamilies = null;

    /** Heading key => ppe type id, resolved once from the first row seen. */
    private ?array $columnTypes = null;

    public function __construct(
        private readonly int $companyId,
        private readonly int $userId,
        private readonly bool $previewOnly = false,
        private readonly ?array $columnKeys = null,
        /** @see EmployeesImport — the same reported-row correction, same reason. */
        private readonly int $rowOffset = 0,
    ) {}

    public function chunkSize(): int
    {
        return 500;
    }

    /** The identity columns, which a tenant may have mapped to its own headings. */
    private function col(array $data, string $field): mixed
    {
        $key = $this->columnKeys[$field] ?? $field;

        return $data[$key] ?? $data[$field] ?? null;
    }

    /** @see EmployeesImport — an identifier read as text, whatever Excel decided it was. */
    private function identifier(mixed $raw): string
    {
        if (is_float($raw) || is_int($raw)) {
            return rtrim(rtrim(number_format((float) $raw, 0, '.', ''), '0'), '.') ?: '0';
        }

        return trim((string) ($raw ?? ''));
    }

    private function employeesByNumber(): array
    {
        return $this->employeesByNumber ??= Employee::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->get(['id', 'employee_id', 'full_name'])
            ->mapWithKeys(fn (Employee $e) => [mb_strtolower((string) $e->employee_id) => [
                'id' => $e->id, 'name' => $e->full_name,
            ]])
            ->all();
    }

    /** Comparison key => type id, from the catalogue IN FORCE for this company. */
    private function ppeTypesByName(): array
    {
        return $this->ppeTypesByName ??= PpeType::withoutGlobalScopes()
            ->effectiveForCompany($this->companyId)
            ->where('is_active', true)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (PpeType $type) => [MasterDataDetector::comparisonKey($type->name) => $type->id])
            ->all();
    }

    /**
     * @see ADR 059 — a built-in and this company's override of it are ONE
     * kind of equipment wearing two ids, so the duplicate guard asks about
     * a family rather than a row.
     *
     * @return array<int, int>
     */
    private function typeFamilies(): array
    {
        if ($this->typeFamilies !== null) {
            return $this->typeFamilies;
        }

        $families = [];

        $relevant = PpeType::withoutGlobalScopes()
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $this->companyId))
            ->get(['id', 'company_id', 'source_default_id']);

        foreach ($relevant as $type) {
            $families[$type->id] = $type->source_default_id ?? $type->id;
        }

        return $this->typeFamilies = $families;
    }

    private function familyOf(int $typeId): int
    {
        return $this->typeFamilies()[$typeId] ?? $typeId;
    }

    /** @see ADR 059 — only equipment still in the person's possession blocks a reissue. */
    private function heldFamilies(): array
    {
        if ($this->heldFamilies !== null) {
            return $this->heldFamilies;
        }

        $held = [];

        $rows = EmployeePpe::withoutGlobalScopes()
            ->whereIn('employee_id', array_column($this->employeesByNumber(), 'id'))
            ->whereIn('status', EmployeePpe::IN_SERVICE_STATUSES)
            ->get(['employee_id', 'ppe_type_id']);

        foreach ($rows as $row) {
            $held[$row->employee_id][$this->familyOf((int) $row->ppe_type_id)] = true;
        }

        return $this->heldFamilies = $held;
    }

    /**
     * Which heading is which PPE type, decided ONCE from the first row's keys.
     *
     * Maatwebsite hands every row the same slugged heading keys, so this is
     * stable for the file. The identity columns are excluded by name; every
     * other heading is either a type in this company's catalogue or an
     * unknown column that gets reported.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int> heading key => ppe type id
     */
    private function columnTypes(array $data): array
    {
        if ($this->columnTypes !== null) {
            return $this->columnTypes;
        }

        $identity = [];
        foreach (['employee_id', 'employee_name'] as $field) {
            $identity[$this->columnKeys[$field] ?? $field] = true;
            $identity[$field] = true;
        }

        $byName = $this->ppeTypesByName();
        $map = [];

        foreach (array_keys($data) as $key) {
            $heading = (string) $key;

            if ($heading === '' || isset($identity[$heading])) {
                continue;
            }

            /*
             * The heading arrives slugged ("safety_shoes"), while the
             * catalogue holds display names ("Safety Shoes"), so the slug
             * is turned back into words before comparing. Both sides then
             * go through the SAME normalisation the employee import uses
             * for department and position names, which is what lets
             * "SAFETY SHOES", "safety shoes" and "Safety  Shoes" all land.
             */
            $candidate = MasterDataDetector::comparisonKey(str_replace('_', ' ', $heading));

            if (isset($byName[$candidate])) {
                $map[$heading] = $byName[$candidate];

                continue;
            }

            /*
             * A column a human clearly meant as a PPE date but which this
             * company's catalogue has no type for. Reported BY NAME: the
             * customer either adds the type in PPE Master or removes the
             * column, and both are better than the import quietly
             * dropping a column of dates.
             */
            $this->unknownColumns[] = Str::headline($heading);
        }

        return $this->columnTypes = $map;
    }

    public function onRow(Row $row): void
    {
        $this->totalRows++;

        // Raw, not formatted -- see identifier() and EmployeesImport v2.113.0.
        $data = $row->toArray(null, false, false);
        $rowNumber = $row->getIndex() + $this->rowOffset;

        $columns = $this->columnTypes($data);
        $employeeNumber = $this->identifier($this->col($data, 'employee_id'));

        if ($employeeNumber === '') {
            $this->skip($rowNumber, 'Missing Employee ID');

            return;
        }

        if ($columns === []) {
            $this->skip($rowNumber, 'Tidak ada kolom jenis PPE yang dikenali pada berkas ini. '
                .'Judul kolom harus sama dengan nama jenis PPE di PPE Master.');

            return;
        }

        $match = $this->employeesByNumber()[mb_strtolower($employeeNumber)] ?? null;

        if ($match === null) {
            $this->unknownEmployees[] = $employeeNumber;
            $this->skip($rowNumber, "Employee \"{$employeeNumber}\" tidak ditemukan di unit operasi ini. "
                .'Impor ini tidak pernah membuat karyawan baru.');

            return;
        }

        $employeeId = $match['id'];

        if (isset($this->seenEmployees[$employeeId])) {
            $this->skip($rowNumber, "Karyawan ini sudah ada di baris {$this->seenEmployees[$employeeId]}. "
                .'Gabungkan menjadi satu baris: satu karyawan satu baris.');

            return;
        }

        $this->seenEmployees[$employeeId] = $rowNumber;

        /*
         * The name column is for the HUMAN reading the file, not for
         * matching -- the Employee ID decides who this is. A mismatch is
         * reported because it usually means the wrong ID was typed, and
         * issuing somebody else's boots against it is the expensive kind
         * of mistake. It is a warning, not a refusal: a nickname, a
         * married name or a different transliteration is not an error.
         */
        $fileName = trim((string) ($this->col($data, 'employee_name') ?? ''));

        if ($fileName !== '' && MasterDataDetector::comparisonKey($fileName) !== MasterDataDetector::comparisonKey($match['name'])) {
            $this->warnings[] = [
                'row' => $rowNumber,
                'reason' => 'Nama di berkas ("'.mb_substr($fileName, 0, 40).'") berbeda dari nama di IOMS ("'
                    .mb_substr((string) $match['name'], 0, 40).'"). Dicocokkan berdasarkan Employee ID.',
            ];
        }

        $issuedHere = 0;

        foreach ($columns as $heading => $typeId) {
            $raw = $data[$heading] ?? null;

            // AN EMPTY CELL IS NOT A FACT. No record, no transaction.
            if (trim((string) ($raw ?? '')) === '') {
                $this->blankCells++;

                continue;
            }

            $issuedDate = SpreadsheetDate::parse($raw);

            if ($issuedDate === null) {
                /*
                 * Reported against its COLUMN as well as its row, because
                 * on a wide file "row 7 is wrong" does not tell anybody
                 * which of eight dates to fix.
                 */
                $this->skipped[] = [
                    'row' => $rowNumber,
                    'reason' => Str::headline($heading).': tanggal "'.mb_substr(trim((string) $raw), 0, 40)
                        .'" tidak dapat dibaca. Gunakan format 15/01/2026 atau 2026-01-15.',
                ];

                continue;
            }

            $family = $this->familyOf($typeId);
            $pairKey = $employeeId.'|'.$family;

            if (isset($this->seenPairs[$pairKey])) {
                // Two columns in one file resolving to the same kind of PPE.
                $this->skipped[] = [
                    'row' => $rowNumber,
                    'reason' => Str::headline($heading).': jenis PPE ini sudah terisi di kolom lain pada baris yang sama.',
                ];

                continue;
            }

            if (isset($this->heldFamilies()[$employeeId][$family])) {
                $this->alreadyHeld++;
                $this->skipped[] = [
                    'row' => $rowNumber,
                    'reason' => Str::headline($heading).": karyawan \"{$employeeNumber}\" masih memegang jenis ini "
                        .'(status issued/in use), jadi tidak diterbitkan ulang.',
                ];

                continue;
            }

            $this->seenPairs[$pairKey] = $rowNumber;

            if ($this->previewOnly) {
                $this->imported++;
                $issuedHere++;

                continue;
            }

            try {
                DB::transaction(function () use ($employeeId, $typeId, $issuedDate) {
                    /*
                     * `create()`, never a bulk insert: the model's
                     * `creating` hook resolves a shared template to this
                     * company's override and derives the expiry date from
                     * the type's replacement interval.
                     */
                    EmployeePpe::create([
                        'employee_id' => $employeeId,
                        'ppe_type_id' => $typeId,
                        'issued_date' => $issuedDate,
                        'issued_by' => $this->userId,
                        'status' => EmployeePpe::STATUS_ISSUED,
                    ]);
                });
            } catch (\Throwable $e) {
                // One bad cell must not cost the row -- EmployeesImport v2.105.0.
                $this->skipped[] = [
                    'row' => $rowNumber,
                    'reason' => Str::headline($heading).': '.$this->readableFailure($e),
                ];

                continue;
            }

            $this->heldFamilies[$employeeId][$family] = true;
            $this->imported++;
            $issuedHere++;
        }

        if ($issuedHere > 0) {
            $this->employeesTouched++;
        }
    }

    private function skip(int $rowNumber, string $reason): void
    {
        $this->skipped[] = ['row' => $rowNumber, 'reason' => $reason];
    }

    /** @see EmployeesImport::readableFailure() — the database's own words, trimmed. */
    private function readableFailure(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (preg_match('/^SQLSTATE\[[^\]]+\](?: \[\d+\])? (.+?)(?: \(Connection:|$)/s', $message, $matches)) {
            $message = $matches[1];
        }

        return mb_substr(trim($message), 0, 200);
    }
}
