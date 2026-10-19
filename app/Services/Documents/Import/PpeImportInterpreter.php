<?php

namespace App\Services\Documents\Import;

use App\Models\Employee;
use App\Models\PpeType;
use App\Services\MasterDataDetector;
use App\Support\SpreadsheetDate;

/**
 * v2.126.0 -- READ THE CUSTOMER'S WORKBOOK, AND SAY WHAT YOU THINK IT SAYS.
 *
 * v2.125.0 added a sheet picker and a column-mapping panel and the owner's
 * reply was that, in use, the import was unchanged. It was right: the
 * mappable fields for this import are `employee_id` and `employee_name`,
 * so the panel offered two rows, and everything that actually matters in a
 * company's PPE register -- which column is which equipment, which is a
 * date, which is a size, which rows are even people -- was still decided
 * silently by matching a heading against a catalogue name in English.
 *
 * This class is the missing half. It reads a sheet and produces a full,
 * INSPECTABLE interpretation:
 *
 *   - every column, classified, with a confidence and the reason for it;
 *   - every row, classified as a person or as something else (a title, a
 *     department separator, a subtotal, a blank);
 *   - every identity, matched against the Employee Register with the match
 *     method stated;
 *   - every cell that will become an issuance, with its date and its size.
 *
 * NOTHING HERE WRITES ANYTHING. It is the screening step the owner asked
 * for -- look, match, correct or reject, check, then confirm -- and the
 * confirmation step hands the plan it produced to the one importer that
 * has always owned the business rules.
 *
 * THREE RULES IT WILL NOT BREAK, all three from ADR 059/060:
 *   1. A PPE type is resolved against THE COMPANY'S CATALOGUE. A synonym
 *      proposes a name; the catalogue decides whether that name exists.
 *      Nothing here creates a type, and a concept with no catalogue row is
 *      reported by name for the person to deal with.
 *   2. An empty date cell is NOT a fact and produces no issuance.
 *   3. Tenant isolation: employees are read for ONE company id, the one
 *      the caller is importing into.
 */
class PpeImportInterpreter
{
    public const ROLE_EMPLOYEE_ID = 'employee_id';
    public const ROLE_EMPLOYEE_NAME = 'employee_name';
    public const ROLE_NIK = 'nik';
    public const ROLE_DEPARTMENT = 'department';
    public const ROLE_PPE_DATE = 'ppe_date';
    public const ROLE_PPE_SIZE = 'ppe_size';
    public const ROLE_IGNORED = 'ignored';

    /** How sure the reading is, and therefore whether it is offered or asserted. */
    public const CONFIDENCE_MATCHED = 'matched';
    public const CONFIDENCE_REVIEW = 'review';
    public const CONFIDENCE_UNMATCHED = 'unmatched';

    /** A row is a person, or it is furniture. */
    public const ROW_DATA = 'data';
    public const ROW_BLANK = 'blank';
    public const ROW_SECTION = 'section';
    public const ROW_SUBTOTAL = 'subtotal';

    /** Words that make a row a subtotal rather than a person. */
    private const SUBTOTAL_WORDS = ['total', 'subtotal', 'sub total', 'jumlah', 'grand total', 'rekap'];

    private ?array $catalogue = null;

    private ?array $employees = null;

    public function __construct(private readonly int $companyId) {}

    /**
     * The company's catalogue, keyed for comparison.
     *
     * `effectiveForCompany()` is what makes an override replace its
     * built-in rather than sit beside it (ADR 058), so a tenant that
     * renamed "Safety Shoes" to "Sepatu Safety" has exactly one row and it
     * is theirs.
     *
     * @return array<string, array{id: int, name: string, role: ?string}>
     */
    private function catalogue(): array
    {
        return $this->catalogue ??= PpeType::withoutGlobalScopes()
            ->effectiveForCompany($this->companyId)
            ->where('is_active', true)
            ->get(['id', 'name', 'compliance_role'])
            ->mapWithKeys(fn (PpeType $t) => [MasterDataDetector::comparisonKey($t->name) => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'role' => $t->compliance_role,
            ]])
            ->all();
    }

    /**
     * The Employee Register for THIS company, indexed three ways.
     *
     * Names are indexed as a LIST per key, not as one row, because
     * duplicate names are normal in an industrial workforce -- two "Siti
     * Wijaya" records selected at once is a defect this codebase has
     * already seen (v2.38.0). A name that resolves to more than one person
     * is reported as ambiguous and never silently resolved to the first.
     */
    private function employees(): array
    {
        if ($this->employees !== null) {
            return $this->employees;
        }

        $byNumber = [];
        $byNik = [];
        $byName = [];

        Employee::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->get(['id', 'employee_id', 'full_name', 'nik'])
            ->each(function (Employee $e) use (&$byNumber, &$byNik, &$byName) {
                $row = ['id' => (int) $e->id, 'employee_id' => $e->employee_id, 'full_name' => $e->full_name];

                if ($e->employee_id) {
                    $byNumber[mb_strtolower((string) $e->employee_id)] = $row;
                }

                if ($e->nik) {
                    $byNik[mb_strtolower((string) $e->nik)] = $row;
                }

                $byName[MasterDataDetector::comparisonKey((string) $e->full_name)][] = $row;
            });

        return $this->employees = ['number' => $byNumber, 'nik' => $byNik, 'name' => $byName];
    }

    /**
     * Classify the headings.
     *
     * @param  list<string>  $headings
     * @return list<array<string, mixed>>
     */
    public function interpretColumns(array $headings): array
    {
        $columns = [];
        $identityTaken = [];

        foreach ($headings as $index => $raw) {
            $heading = trim((string) $raw);

            if ($heading === '') {
                $columns[] = $this->column($index, $heading, self::ROLE_IGNORED, null, self::CONFIDENCE_UNMATCHED,
                    'Kolom tanpa judul. Tidak dibaca kecuali Anda menetapkannya sendiri.');

                continue;
            }

            // --- identity, department ---
            $identity = PpeColumnVocabulary::identityFieldFor($heading);

            if ($identity !== null && ! isset($identityTaken[$identity])) {
                $identityTaken[$identity] = true;

                $columns[] = $this->column($index, $heading, $identity, null, self::CONFIDENCE_MATCHED,
                    'Dikenali sebagai kolom identitas karyawan.');

                continue;
            }

            // --- a PPE column: which equipment, and is it the date or the size? ---
            $concept = PpeColumnVocabulary::ppeConceptFor($heading);

            if ($concept !== null) {
                $columns[] = $this->ppeColumn($index, $heading, $concept);

                continue;
            }

            /*
             * A heading that says "Tanggal" or "Ukuran" and names no
             * equipment. Common in a register whose equipment names live
             * in a MERGED cell on the row above -- which this reader
             * cannot see, so it says so instead of guessing. The person
             * assigns it, or leaves it out.
             */
            if (PpeColumnVocabulary::mentionsDate($heading) || PpeColumnVocabulary::mentionsSize($heading)) {
                $columns[] = $this->column(
                    $index,
                    $heading,
                    PpeColumnVocabulary::mentionsSize($heading) ? self::ROLE_PPE_SIZE : self::ROLE_PPE_DATE,
                    null,
                    self::CONFIDENCE_UNMATCHED,
                    'Kolom ini menyebut '.(PpeColumnVocabulary::mentionsSize($heading) ? 'ukuran' : 'tanggal')
                        .' tetapi tidak menyebut jenis APD. Pilih jenis APD-nya, atau abaikan kolom ini.',
                );

                continue;
            }

            $columns[] = $this->column($index, $heading, self::ROLE_IGNORED, null, self::CONFIDENCE_UNMATCHED,
                'Judul kolom ini tidak dikenali. Tetapkan sendiri bila kolom ini memang perlu diimpor.');
        }

        return $columns;
    }

    /** A PPE column, resolved against the catalogue -- never invented. */
    private function ppeColumn(int $index, string $heading, string $concept): array
    {
        $isSize = PpeColumnVocabulary::mentionsSize($heading);
        $role = $isSize ? self::ROLE_PPE_SIZE : self::ROLE_PPE_DATE;

        $match = $this->catalogue()[MasterDataDetector::comparisonKey($concept)] ?? null;

        if ($match === null) {
            /*
             * The dictionary recognised the equipment and this company's
             * catalogue has no row for it. REPORTED BY NAME, never
             * created: an import matches master data (ADR 059). The
             * customer adds the type in PPE Master, or maps the column to
             * something they do have, or leaves it out -- and all three
             * are better than the column being silently dropped.
             */
            return $this->column($index, $heading, $role, null, self::CONFIDENCE_UNMATCHED,
                'Dikenali sebagai "'.$concept.'", tetapi jenis itu belum ada di katalog PPE perusahaan ini. '
                .'Tambahkan di PPE Master, atau pilih jenis lain.');
        }

        $exact = MasterDataDetector::comparisonKey($heading) === MasterDataDetector::comparisonKey($match['name']);

        return $this->column(
            $index,
            $heading,
            $role,
            $match['id'],
            $exact ? self::CONFIDENCE_MATCHED : self::CONFIDENCE_REVIEW,
            $exact
                ? 'Sama persis dengan jenis "'.$match['name'].'" di katalog.'
                : 'Diperkirakan '.($isSize ? 'ukuran' : 'tanggal').' untuk "'.$match['name']
                    .'" berdasarkan istilah yang umum dipakai. Periksa sebelum melanjutkan.',
        );
    }

    private function column(int $index, string $heading, string $role, ?int $target, string $confidence, string $reason): array
    {
        return [
            'index' => $index,
            'heading' => $heading,
            'role' => $role,
            'ppe_type_id' => $target,
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }

    /**
     * Is this row a person, or is it furniture?
     *
     * The owner's instruction is explicit: "Jangan menganggap semua baris
     * sebagai karyawan karena workbook bisa memiliki judul, subtotal,
     * pemisah departemen, atau catatan administratif." Each verdict is
     * REPORTED with its reason, so a row this gets wrong is visible and
     * correctable rather than silently dropped.
     *
     * @param  array<int, mixed>  $cells
     * @param  list<array<string, mixed>>  $columns
     */
    public function classifyRow(array $cells, array $columns): array
    {
        $filled = array_filter($cells, fn ($v) => trim((string) ($v ?? '')) !== '');

        if ($filled === []) {
            return ['kind' => self::ROW_BLANK, 'reason' => 'Baris kosong.'];
        }

        $joined = MasterDataDetector::comparisonKey(implode(' ', array_map(fn ($v) => (string) ($v ?? ''), $cells)));

        foreach (self::SUBTOTAL_WORDS as $word) {
            if (str_starts_with($joined, $word) || str_contains($joined, ' '.$word.' ')) {
                return ['kind' => self::ROW_SUBTOTAL, 'reason' => 'Terlihat sebagai baris jumlah atau rekap, bukan karyawan.'];
            }
        }

        /*
         * A DEPARTMENT SEPARATOR: one or two cells filled, no identity
         * value among them. "FABRIKASI" written across an otherwise empty
         * row is a heading for the rows beneath it, and importing it as a
         * person is how a register grows a worker called "Fabrikasi".
         */
        $identityIndexes = [];

        foreach ($columns as $column) {
            if (in_array($column['role'], [self::ROLE_EMPLOYEE_ID, self::ROLE_NIK, self::ROLE_EMPLOYEE_NAME], true)) {
                $identityIndexes[] = $column['index'];
            }
        }

        $hasIdentity = false;

        foreach ($identityIndexes as $i) {
            if (trim((string) ($cells[$i] ?? '')) !== '') {
                $hasIdentity = true;
                break;
            }
        }

        if (! $hasIdentity) {
            return ['kind' => self::ROW_SECTION, 'reason' => 'Tidak ada identitas karyawan pada baris ini. '
                .'Biasanya judul bagian atau pemisah departemen.'];
        }

        if (count($filled) <= 1) {
            return ['kind' => self::ROW_SECTION, 'reason' => 'Hanya satu sel terisi. Biasanya judul bagian.'];
        }

        return ['kind' => self::ROW_DATA, 'reason' => ''];
    }

    /**
     * Who is this row about?
     *
     * Badge number first, then NIK, and NAME ONLY AS A LAST RESORT and
     * only when it is unambiguous -- the owner's instruction is explicit
     * that a near-match on a name must not attach a record to the wrong
     * person. An ambiguous name is returned as ambiguous, with the
     * candidates, for the person to settle.
     *
     * @param  array<int, mixed>  $cells
     * @param  list<array<string, mixed>>  $columns
     */
    public function matchEmployee(array $cells, array $columns): array
    {
        $value = function (string $role) use ($cells, $columns): string {
            foreach ($columns as $column) {
                if ($column['role'] === $role) {
                    return $this->identifier($cells[$column['index']] ?? null);
                }
            }

            return '';
        };

        $number = $value(self::ROLE_EMPLOYEE_ID);
        $nik = $value(self::ROLE_NIK);
        $name = $value(self::ROLE_EMPLOYEE_NAME);
        $index = $this->employees();

        if ($number !== '' && isset($index['number'][mb_strtolower($number)])) {
            return $this->matched($index['number'][mb_strtolower($number)], 'employee_id', $number, $name);
        }

        if ($nik !== '' && isset($index['nik'][mb_strtolower($nik)])) {
            return $this->matched($index['nik'][mb_strtolower($nik)], 'nik', $nik, $name);
        }

        if ($name !== '') {
            $candidates = $index['name'][MasterDataDetector::comparisonKey($name)] ?? [];

            if (count($candidates) === 1) {
                return [
                    'status' => 'review',
                    'employee' => $candidates[0],
                    'matched_by' => 'full_name',
                    'source' => $name,
                    'reason' => 'Dicocokkan berdasarkan NAMA saja, karena nomor induk tidak ada atau tidak cocok. Periksa sebelum melanjutkan.',
                    'candidates' => $candidates,
                ];
            }

            if (count($candidates) > 1) {
                return [
                    'status' => 'ambiguous',
                    'employee' => null,
                    'matched_by' => null,
                    'source' => $name,
                    'reason' => 'Ada '.count($candidates).' karyawan dengan nama ini. Pilih orang yang benar, atau tolak baris ini.',
                    'candidates' => $candidates,
                ];
            }
        }

        return [
            'status' => 'not_found',
            'employee' => null,
            'matched_by' => null,
            'source' => $number !== '' ? $number : ($nik !== '' ? $nik : $name),
            'reason' => 'Tidak ditemukan di Employee Register unit operasi ini. Impor ini tidak pernah membuat karyawan baru.',
            'candidates' => [],
        ];
    }

    private function matched(array $employee, string $by, string $source, string $fileName): array
    {
        $out = [
            'status' => 'matched',
            'employee' => $employee,
            'matched_by' => $by,
            'source' => $source,
            'reason' => '',
            'candidates' => [],
        ];

        /*
         * The name in the file disagreeing with the name in IOMS is a
         * WARNING, not a refusal: a nickname, a married name or another
         * transliteration is not an error. It is surfaced because it
         * usually means the wrong number was typed, and issuing somebody
         * else's boots against it is the expensive kind of mistake.
         */
        if ($fileName !== '' && MasterDataDetector::comparisonKey($fileName) !== MasterDataDetector::comparisonKey($employee['full_name'])) {
            $out['status'] = 'review';
            $out['reason'] = 'Nama di berkas ("'.mb_substr($fileName, 0, 40).'") berbeda dari nama di IOMS ("'
                .mb_substr((string) $employee['full_name'], 0, 40).'"). Dicocokkan berdasarkan '
                .($by === 'nik' ? 'NIK' : 'Employee ID').'.';
        }

        return $out;
    }

    /**
     * What this row would ISSUE: one entry per PPE column that holds a
     * readable date, carrying the size from its paired column.
     *
     * @param  array<int, mixed>  $cells
     * @param  list<array<string, mixed>>  $columns
     */
    public function interpretIssuances(array $cells, array $columns): array
    {
        $sizes = [];

        foreach ($columns as $column) {
            if ($column['role'] === self::ROLE_PPE_SIZE && $column['ppe_type_id'] !== null) {
                $size = trim((string) ($cells[$column['index']] ?? ''));

                if ($size !== '') {
                    $sizes[$column['ppe_type_id']] = mb_substr($size, 0, 40);
                }
            }
        }

        $out = [];

        foreach ($columns as $column) {
            if ($column['role'] !== self::ROLE_PPE_DATE || $column['ppe_type_id'] === null) {
                continue;
            }

            $raw = $cells[$column['index']] ?? null;

            // AN EMPTY CELL IS NOT A FACT. No record, no transaction.
            if (trim((string) ($raw ?? '')) === '') {
                continue;
            }

            // Already a `Y-m-d` string or null -- SpreadsheetDate::parse()
            // normalises, it does not hand back a date object. Calling
            // ->format() on it threw, which is what the first screening of
            // a real workbook did.
            $date = SpreadsheetDate::parse($raw);

            $out[] = [
                'ppe_type_id' => $column['ppe_type_id'],
                'heading' => $column['heading'],
                'issued_date' => $date,
                'raw_date' => mb_substr(trim((string) $raw), 0, 40),
                'size' => $sizes[$column['ppe_type_id']] ?? null,
                'problem' => $date === null
                    ? 'Tanggal "'.mb_substr(trim((string) $raw), 0, 40).'" tidak dapat dibaca. Gunakan format 15/01/2026 atau 2026-01-15.'
                    : null,
            ];
        }

        return $out;
    }

    /** @see EmployeePpeImport::identifier() -- an identifier read as text, whatever Excel decided it was. */
    private function identifier(mixed $raw): string
    {
        if (is_float($raw) || is_int($raw)) {
            return rtrim(rtrim(number_format((float) $raw, 0, '.', ''), '0'), '.') ?: '0';
        }

        return trim((string) ($raw ?? ''));
    }

    /** The catalogue as the UI needs it, for the person to choose from. */
    public function catalogueOptions(): array
    {
        return collect($this->catalogue())
            ->map(fn (array $t) => ['id' => $t['id'], 'name' => $t['name'], 'compliance_role' => $t['role']])
            ->sortBy('name')
            ->values()
            ->all();
    }
}
