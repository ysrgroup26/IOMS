<?php

namespace App\Imports;

use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\PpeType;
use App\Services\MasterDataDetector;
use App\Support\SpreadsheetDate;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

/**
 * v2.120.0 -- BULK PPE ISSUANCE, AGAINST EMPLOYEES THAT ALREADY EXIST.
 *
 * Deliberately a SECOND importer rather than more columns on
 * `EmployeesImport`, and the reason is in that class at line 415: a row
 * whose Employee ID already exists is SKIPPED ENTIRELY. Teaching it to
 * also attach PPE would mean changing what a duplicate row does -- a
 * behaviour pinned by `EmployeeReimportAfterDeletionTest` and surrounded
 * by ~3,800 lines of regression tests written after four production
 * incidents (v2.105, v2.113, v2.114, v2.115). This class issues PPE to
 * people who are already in the register and creates NOTHING ELSE, so
 * the employee importer keeps every behaviour it has.
 *
 * It follows `EmployeesImport`'s shape on purpose -- `OnEachRow`, the
 * critical/optional field split, a per-row try/catch so one bad row
 * never costs the rest, and the same `$previewOnly` mode so the preview
 * and the import cannot disagree about what a file contains.
 *
 * WHAT IT WILL NOT DO: create an Employee, and create a PpeType. Both
 * are matched or the row is refused with a reason naming where to fix
 * it. An import that invents master data is how a catalogue fills up
 * with "Safety Shoes", "safety shoes " and "Sepatu Safety".
 */
class EmployeePpeImport implements OnEachRow, WithChunkReading, WithHeadingRow
{
    public int $totalRows = 0;

    public int $imported = 0;

    /** @var list<array{row: int, reason: string}> */
    public array $skipped = [];

    /** @var list<array{row: int, reason: string}> */
    public array $warnings = [];

    /** Employee numbers in the file that match nobody in this company. */
    public array $unknownEmployees = [];

    /** PPE type names in the file that match nothing in this company's catalogue. */
    public array $unknownPpeTypes = [];

    /** How many rows were refused because the person already holds that kind of PPE. */
    public int $alreadyHeld = 0;

    /** employee_id|type_family => row number, so one FILE cannot issue twice. */
    private array $seenPairs = [];

    /** Lazily built lookups, one query each rather than one per row. */
    private ?array $employeesByNumber = null;

    private ?array $ppeTypesByName = null;

    private ?array $typeFamilies = null;

    private ?array $heldFamilies = null;

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

    /** @see EmployeesImport::mappedField() — the same tenant column mapping. */
    private function col(array $data, string $field): mixed
    {
        $key = $this->columnKeys[$field] ?? $field;

        return $data[$key] ?? $data[$field] ?? null;
    }

    /**
     * An identifier read as text, whatever Excel decided it was.
     *
     * The same correction `EmployeesImport` carries since v2.113.0: an
     * employee number typed as digits arrives as a float, and the default
     * precision renders long ones in scientific notation. A number that
     * does not survive being read cannot match anybody.
     */
    private function identifier(mixed $raw): string
    {
        if (is_float($raw) || is_int($raw)) {
            return rtrim(rtrim(number_format((float) $raw, 0, '.', ''), '0'), '.') ?: '0';
        }

        return trim((string) ($raw ?? ''));
    }

    /** employee number (lowercased) => id, for THIS company only. */
    private function employeesByNumber(): array
    {
        return $this->employeesByNumber ??= Employee::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->pluck('id', 'employee_id')
            ->mapWithKeys(fn ($id, $number) => [mb_strtolower((string) $number) => $id])
            ->all();
    }

    /**
     * Comparison key => PPE type id, from the catalogue IN FORCE for this
     * company.
     *
     * `effectiveForCompany()` is the same scope PPE Master and the manual
     * issue form read (ADR 050/058), so a type this import accepts is one
     * the customer can actually see, and a built-in they have overridden
     * resolves to THEIR version rather than appearing twice.
     */
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
     * THE SAME KIND OF PPE, WHICHEVER ROW HAPPENS TO REPRESENT IT.
     *
     * A built-in and this company's override of it are ONE kind of
     * equipment wearing two ids (ADR 050). Three facts make that matter
     * here rather than being a detail:
     *
     *  - `EmployeePpe::creating()` silently remaps a shared template to
     *    this company's active override, so the id this import resolves
     *    is not always the id that gets stored;
     *  - an employee issued the built-in BEFORE the override existed
     *    holds the template id; and
     *  - the duplicate rule the customer asked for is "still holds PPE of
     *    this kind", not "still holds this row".
     *
     * Guarding on a single id would therefore issue a second pair of
     * boots to somebody already wearing the first, which is the exact
     * outcome this import is supposed to prevent. So every id in the
     * family -- the template, its overrides in this company, and the
     * template an override points at -- answers as one.
     *
     * @return array<int, int> type id => family key (the template's id)
     */
    private function typeFamilies(): array
    {
        if ($this->typeFamilies !== null) {
            return $this->typeFamilies;
        }

        $families = [];

        /*
         * The built-ins plus THIS company's rows, and nothing else. A
         * family only ever spans a template and the overrides of it owned
         * by the company being imported into, so another tenant's rows
         * cannot widen one -- and are not read at all.
         */
        $relevant = PpeType::withoutGlobalScopes()
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $this->companyId))
            ->get(['id', 'company_id', 'source_default_id']);

        foreach ($relevant as $type) {
            // A row's family is the template it replaces, or itself.
            $families[$type->id] = $type->source_default_id ?? $type->id;
        }

        return $this->typeFamilies = $families;
    }

    private function familyOf(int $typeId): int
    {
        return $this->typeFamilies()[$typeId] ?? $typeId;
    }

    /**
     * employee id => [family key => true] for PPE still genuinely in the
     * person's possession.
     *
     * `IN_SERVICE_STATUSES` is the model's own answer to "does this person
     * still have it" -- a replaced, completed or archived item does not
     * block a reissue, which is what makes the replacement lifecycle still
     * work after this import exists.
     *
     * Read WITHOUT the global scopes deliberately: the scope resolves
     * ownership by walking to the employee, and these employee ids were
     * already resolved within `$this->companyId` one query earlier. Asking
     * again through the scope would re-answer a question already answered,
     * and would answer it against the REQUEST's company context rather
     * than the one being imported into.
     */
    private function heldFamilies(): array
    {
        if ($this->heldFamilies !== null) {
            return $this->heldFamilies;
        }

        $held = [];

        $rows = EmployeePpe::withoutGlobalScopes()
            ->whereIn('employee_id', array_values($this->employeesByNumber()))
            ->whereIn('status', EmployeePpe::IN_SERVICE_STATUSES)
            ->get(['employee_id', 'ppe_type_id']);

        foreach ($rows as $row) {
            $held[$row->employee_id][$this->familyOf((int) $row->ppe_type_id)] = true;
        }

        return $this->heldFamilies = $held;
    }

    public function onRow(Row $row): void
    {
        $this->totalRows++;

        // Raw, not formatted -- see identifier() and EmployeesImport v2.113.0.
        $data = $row->toArray(null, false, false);
        $rowNumber = $row->getIndex() + $this->rowOffset;

        $employeeNumber = $this->identifier($this->col($data, 'employee_id'));
        $typeName = trim((string) ($this->col($data, 'ppe_type') ?? ''));

        if ($employeeNumber === '') {
            $this->skip($rowNumber, 'Missing Employee ID');

            return;
        }

        if ($typeName === '') {
            $this->skip($rowNumber, 'Missing PPE Type');

            return;
        }

        $employeeId = $this->employeesByNumber()[mb_strtolower($employeeNumber)] ?? null;

        if ($employeeId === null) {
            $this->unknownEmployees[] = $employeeNumber;
            $this->skip($rowNumber, "Employee \"{$employeeNumber}\" tidak ditemukan di unit operasi ini. "
                .'Impor ini tidak pernah membuat karyawan baru.');

            return;
        }

        $typeId = $this->ppeTypesByName()[MasterDataDetector::comparisonKey($typeName)] ?? null;

        if ($typeId === null) {
            $this->unknownPpeTypes[] = $typeName;
            $this->skip($rowNumber, "Jenis PPE \"{$typeName}\" tidak ada di PPE Master. "
                .'Tambahkan lebih dulu di PPE Management > PPE Master, lalu impor ulang.');

            return;
        }

        $family = $this->familyOf($typeId);
        $pairKey = $employeeId.'|'.$family;

        if (isset($this->seenPairs[$pairKey])) {
            $this->skip($rowNumber, "Baris ganda untuk karyawan dan jenis PPE yang sama (lihat baris {$this->seenPairs[$pairKey]}).");

            return;
        }

        /*
         * THE RULE THE OWNER SET: still holding one of this kind means the
         * row is reported, not issued. It is what makes re-running the
         * same file safe -- `employee_ppe` has no unique index to fall
         * back on (checked: (employee_id, ppe_type_id) is a plain index),
         * so idempotency has to live here or nowhere.
         */
        if (isset($this->heldFamilies()[$employeeId][$family])) {
            $this->alreadyHeld++;
            $this->skip($rowNumber, "Karyawan \"{$employeeNumber}\" masih memegang {$typeName} "
                .'(status issued/in use), jadi tidak diterbitkan ulang.');

            return;
        }

        /*
         * A DATE NOBODY TYPED IS WORSE THAN NO DATE (v2.108.0). Unlike a
         * join date, this one is not optional: `employee_ppe.issued_date`
         * is NOT NULL and the expiry every replacement reminder depends on
         * is counted from it. Defaulting to today would silently date a
         * pair of boots to the day of the import.
         */
        $rawIssued = $this->col($data, 'issued_date');
        $issuedDate = SpreadsheetDate::parse($rawIssued);

        if ($issuedDate === null) {
            $this->skip($rowNumber, trim((string) ($rawIssued ?? '')) === ''
                ? 'Missing Issued Date'
                : 'Issued Date "'.mb_substr(trim((string) $rawIssued), 0, 40).'" tidak dapat dibaca. '
                    .'Gunakan format 15/01/2026 atau 2026-01-15.');

            return;
        }

        /*
         * Expiry is OPTIONAL and normally not supplied: the model derives
         * it from the type's replacement interval, which is the single
         * source of truth this product has had since v1.3. A value that
         * cannot be read, or that falls before the issue date, is reported
         * and the derived one is used -- the row still lands, because an
         * unreadable optional cell was never a reason to refuse somebody's
         * PPE record.
         */
        $rawExpiry = $this->col($data, 'expiry_date');
        $expiryDate = SpreadsheetDate::parse($rawExpiry);

        if ($expiryDate === null && trim((string) ($rawExpiry ?? '')) !== '') {
            $this->warnings[] = [
                'row' => $rowNumber,
                'reason' => 'Expiry Date "'.mb_substr(trim((string) $rawExpiry), 0, 40).'" tidak dapat dibaca, '
                    .'jadi dihitung dari interval penggantian jenis PPE tersebut.',
            ];
        }

        if ($expiryDate !== null && $expiryDate < $issuedDate) {
            $this->warnings[] = [
                'row' => $rowNumber,
                'reason' => 'Expiry Date lebih awal dari Issued Date, jadi dihitung dari interval penggantian.',
            ];
            $expiryDate = null;
        }

        $this->seenPairs[$pairKey] = $rowNumber;

        // Preview stops here: every refusal above has already been decided,
        // which is exactly what the preview's counts report. Only the write
        // is skipped.
        if ($this->previewOnly) {
            $this->imported++;

            return;
        }

        try {
            DB::transaction(function () use ($data, $employeeId, $typeId, $issuedDate, $expiryDate) {
                /*
                 * `EmployeePpe::create()`, never a bulk insert: the model's
                 * `creating` hook is what resolves a shared template to this
                 * company's override and derives the expiry date. A bulk
                 * insert would be faster and would quietly produce rows the
                 * manual form could never produce.
                 */
                EmployeePpe::create([
                    'employee_id' => $employeeId,
                    'ppe_type_id' => $typeId,
                    'issued_date' => $issuedDate,
                    'expiry_date' => $expiryDate,
                    'remarks' => trim((string) ($this->col($data, 'remarks') ?? '')) ?: null,
                    'issued_by' => $this->userId,
                    'status' => EmployeePpe::STATUS_ISSUED,
                ]);
            });
        } catch (\Throwable $e) {
            // One bad row must not cost the rest -- EmployeesImport v2.105.0.
            $this->skipped[] = ['row' => $rowNumber, 'reason' => $this->readableFailure($e)];

            return;
        }

        // Taken now, so a later row in the same file cannot reissue it.
        $this->heldFamilies[$employeeId][$family] = true;
        $this->imported++;
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
