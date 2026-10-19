<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Documents\DocumentIngestionService;
use App\Services\Documents\FormatDetector;
use App\Services\Documents\Import\WorkbookGrid;
use App\Services\Documents\Support\ExtractedDocument;
use App\Services\Documents\Support\ExtractedTable;
use App\Services\Documents\TabularBridge;
use App\Services\ImportHeaderMatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelFacadeType;

/**
 * v2.120.0 -- THE DOCUMENT PLATFORM'S FRONT DOOR, SHARED BY EVERY IMPORT.
 *
 * Every method here was `private` on `EmployeeController` and is moved
 * VERBATIM, with two deliberate exceptions noted below. It is extracted
 * rather than copied for the reason ADR 053 gives for the bridge itself:
 * a second copy of "which formats we accept, when the bridge is taken,
 * and how a row number maps back to the customer's own file" would drift
 * from the first, and the row-number half of that has already cost a
 * release (v2.111.0).
 *
 * The two changes:
 *
 *  - `private` became `protected`, so a second controller can use it.
 *  - `mappableHeadings()` asked `ImportHeaderMatcher` about the
 *    `employees` module by name. It now asks about `importModuleKey()`,
 *    which `EmployeeController` answers with `employees` -- byte-for-byte
 *    its previous behaviour -- and the PPE assignment import answers with
 *    its own.
 *
 * Nothing else about the ingestion path moved, and no caller's behaviour
 * changes.
 */
trait ReadsImportedSpreadsheets
{
    /**
     * Which `config/mapping_fields.php` module this controller imports.
     *
     * Declared rather than inferred: a wrong answer here silently sends
     * the bridge decision to another module's field catalogue, and a
     * silent wrong answer in the ingestion path is the failure mode this
     * trait exists to stop repeating.
     */
    abstract protected function importModuleKey(): string;

    /**
     * v2.110.0 -- EVERY IMPORT ENDPOINT ACCEPTS THE SAME FOUR FORMATS.
     *
     * The validation rule for an uploaded workforce file. Widened from
     * xlsx/xls to the formats the document platform can genuinely READ,
     * and no wider: a format listed here must have a working extractor,
     * or the customer is offered a door that does not open.
     *
     * `txt` is present only because a .csv frequently arrives with a
     * text/plain MIME type; the real decision is made by content, in
     * `FormatDetector`, not by this list.
     *
     * The size cap is the document platform's own, so one number governs
     * every upload rather than each endpoint inventing a limit.
     *
     * @return list<string>
     */
    protected function importFileRules(): array
    {
        return ['required', 'file', 'mimes:xlsx,xls,csv,txt,docx,pdf', 'max:'.((int) config('documents.max_upload_mb') * 1024)];
    }

    /**
     * Runs `$work` against a SPREADSHEET, whatever the customer uploaded.
     *
     * A .xlsx or .xls is passed through untouched -- the existing
     * importer already reads it, and converting it would be a chance to
     * lose something for no gain.
     *
     * Anything else (CSV, Word, PDF) is extracted by the document
     * platform and written out as a clean, normalised .xlsx by
     * `TabularBridge`, and THAT is what the importer sees. The reason is
     * the one in TabularBridge's own doc comment: there is exactly one
     * set of employee validation rules in this codebase, and a second
     * import path would become a second, drifting copy of them.
     *
     * The temporary file is removed in `finally`, so a failed import does
     * not leave customer data in the system temp directory.
     *
     * @template T
     *
     * @param  \Closure(string, ?ExtractedDocument): T  $work
     * @return T
     */
    protected function withImportSpreadsheet(Request $request, \Closure $work)
    {
        $file = $request->file('file');
        $format = app(FormatDetector::class)->detectUploaded($file);

        if ($format === null) {
            throw ValidationException::withMessages([
                'file' => 'Format berkas ini tidak dikenali. Format yang diterima: Excel (.xlsx/.xls), CSV, Word (.docx) dan PDF.',
            ]);
        }

        $document = app(DocumentIngestionService::class)->extract($file->getRealPath(), $format);
        $isSpreadsheet = in_array($format, [FormatDetector::XLSX, FormatDetector::XLS], true);

        /*
         * v2.125.0 -- WHICH SHEET, WHEN THE CUSTOMER'S WORKBOOK HAS SEVERAL.
         *
         * This path took `primaryTable()` unconditionally: the table the
         * locator was most confident about, which for a real company
         * workbook ("Data", "Rekap", "Sheet1", "Template") is a coin toss
         * decided by confidence and row count. The import then either
         * failed on headings from the wrong sheet or -- worse -- succeeded
         * against a summary tab and reported a handful of rows as the whole
         * file. Neither told the customer which sheet had been read.
         *
         * An explicitly NAMED sheet is now honoured, and honoured through
         * the bridge even for a .xlsx: Maatwebsite reads the first worksheet
         * unless told otherwise, so handing it the original file would
         * silently ignore the choice -- the exact failure mode this is here
         * to remove. A name that matches no sheet is REFUSED rather than
         * quietly falling back, because falling back is how the customer
         * ends up believing they chose something.
         *
         * No name sent behaves exactly as before.
         */
        $requestedSheet = $request->input('sheet');
        $requestedHeaderRow = $request->integer('header_row');

        /*
         * v2.126.0 -- A SHEET **AND** A HEADER ROW THE PERSON CHOSE.
         *
         * v2.125.0 honoured a named sheet by finding the EXTRACTED TABLE
         * with that name -- the locator's table, with the locator's idea
         * of where the headings are. On a real company register that idea
         * was row 10 of a sheet whose headings are on row 4, so a person
         * who screened the file, corrected the header row and approved
         * what they saw would have had something else imported. The
         * screening would have been a description of a different file.
         *
         * So when a header row is named too, the file is read as the
         * screening read it: the RAW GRID, through `WorkbookGrid`, which
         * is the single reader both paths share. Bridged to .xlsx from
         * that row, because Maatwebsite reads worksheet one from row one
         * unless told otherwise.
         */
        if (is_string($requestedSheet) && trim($requestedSheet) !== '' && $requestedHeaderRow > 0
            && in_array($format, [FormatDetector::XLSX, FormatDetector::XLS], true)) {
            $grids = WorkbookGrid::read($file->getRealPath());

            if (! isset($grids[$requestedSheet])) {
                throw ValidationException::withMessages([
                    'sheet' => 'Sheet "'.$requestedSheet.'" tidak ada di dalam berkas ini. Pilih sheet lain lalu coba lagi.',
                ]);
            }

            $grid = $grids[$requestedSheet];
            $headings = array_map(fn ($v) => trim((string) ($v ?? '')), $grid[$requestedHeaderRow - 1] ?? []);

            if ($headings === []) {
                throw ValidationException::withMessages([
                    'header_row' => 'Baris '.$requestedHeaderRow.' pada sheet ini kosong, jadi tidak ada judul kolom untuk dibaca.',
                ]);
            }

            $table = new ExtractedTable(
                headings: array_values(array_map('strval', $headings)),
                rows: array_values(array_slice($grid, $requestedHeaderRow)),
                source: $requestedSheet,
                confidence: 1.0,
                headerRowNumber: $requestedHeaderRow,
            );

            $path = app(TabularBridge::class)->toSpreadsheet($table);

            try {
                // The bridge puts the headings on row 1, so a record that
                // sat on source row N is now on row N - (headerRow - 1):
                // the row numbers in the report match the customer's own
                // file, which is what v2.111.0 established.
                return $work($path, $requestedHeaderRow - 1, ExcelFacadeType::XLSX);
            } finally {
                @unlink($path);
            }
        }

        if (is_string($requestedSheet) && trim($requestedSheet) !== '') {
            $chosen = null;

            foreach ($document->tables as $candidate) {
                if ($candidate->source === $requestedSheet) {
                    $chosen = $candidate;
                    break;
                }
            }

            if ($chosen === null) {
                throw ValidationException::withMessages([
                    'sheet' => 'Sheet "'.$requestedSheet.'" tidak ada di dalam berkas ini. Pilih sheet lain lalu coba lagi.',
                ]);
            }

            $path = app(TabularBridge::class)->toSpreadsheet($chosen);

            try {
                return $work($path, $chosen->headerRowNumber - 1, ExcelFacadeType::XLSX);
            } finally {
                @unlink($path);
            }
        }

        if ($isSpreadsheet && $this->readableAsIs($document)) {
            return $work($file->getRealPath(), 0, $this->readerFor($format));
        }

        $table = $document->primaryTable();

        /*
         * v2.111.0 -- THE BRIDGE MUST EARN ITS PLACE.
         *
         * `readableAsIs()` trusts the locator, and the locator is a
         * heuristic. When it is wrong about a SPREADSHEET the cost is
         * total: the original file imported fine, and the normalisation
         * built from a misread heading row imports nothing. That is not
         * hypothetical -- a twelve-column template whose rows fill only
         * five columns made exactly that mistake and cost two passing
         * tests before the locator was corrected.
         *
         * So for a spreadsheet the bridge is taken only when the headings
         * it found MAP TO SOMETHING. Headings that match no IOMS field
         * cannot produce a usable import anyway, so the original file is
         * handed over and the path that worked before still works. The
         * locator stays an improvement on the old behaviour; it never
         * becomes a new way to lose.
         *
         * Word, CSV and PDF get no such fallback, because for them there
         * is no older path to fall back TO.
         */
        if ($isSpreadsheet && $table !== null && ! $this->mappableHeadings($table->headings)) {
            return $work($file->getRealPath(), 0, $this->readerFor($format));
        }

        if ($table === null) {
            if ($isSpreadsheet) {
                /*
                 * Our locator found nothing, but Maatwebsite might still
                 * read the file. Failing OPEN here keeps a workbook that
                 * imported yesterday importing today: the locator is an
                 * improvement on the old path, not a new gate in front of
                 * it.
                 */
                return $work($file->getRealPath(), 0, $this->readerFor($format));
            }

            throw ValidationException::withMessages([
                'file' => 'Tidak ada tabel yang dapat dibaca dari berkas ini. '
                    .($document->warnings[0] ?? 'Pastikan berkas memuat tabel dengan baris judul kolom.'),
            ]);
        }

        $path = app(TabularBridge::class)->toSpreadsheet($table);

        /*
         * The bridge puts the headings on row 1, so a record that sat on
         * source row N is now on row N - (headerRowNumber - 1).
         */
        $rowOffset = $table->headerRowNumber - 1;

        try {
            // The bridge always writes .xlsx, whatever came in.
            return $work($path, $rowOffset, ExcelFacadeType::XLSX);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The Maatwebsite reader for a detected format.
     *
     * Only the two spreadsheet formats reach this: everything else has
     * already been normalised to .xlsx by the bridge.
     */
    protected function readerFor(string $format): string
    {
        return $format === FormatDetector::XLS ? ExcelFacadeType::XLS : ExcelFacadeType::XLSX;
    }

    /**
     * Do these headings name at least two things IOMS can import?
     *
     * Two rather than one because a single accidental match ("Status",
     * say) is noise; two is a column layout. The same matcher the preview
     * shows the customer answers it, so the decision and the explanation
     * it is shown beside cannot disagree.
     *
     * @param  list<string>  $headings
     */
    protected function mappableHeadings(array $headings): bool
    {
        $suggestion = app(ImportHeaderMatcher::class)->suggest($headings, [], $this->importModuleKey());

        return count($suggestion['mapping'] ?? []) >= 2;
    }

    /**
     * v2.125.0 -- the sheets this file actually contains, for the customer
     * to choose from.
     *
     * Reported for EVERY upload, not only multi-sheet ones, so the dialog
     * can always say which sheet it is about to read -- "we read Sheet1"
     * is information the customer needs even when there was no choice to
     * make. `chosen` is what `withImportSpreadsheet()` would take with no
     * `sheet` named, computed by calling the same method rather than by
     * re-deriving the rule.
     *
     * @return array{sheets: list<array<string, mixed>>, chosen: ?string}
     */
    protected function describeImportSheets(Request $request): array
    {
        $file = $request->file('file');
        $format = app(FormatDetector::class)->detectUploaded($file);

        if ($format === null) {
            return ['sheets' => [], 'chosen' => null];
        }

        $document = app(DocumentIngestionService::class)->extract($file->getRealPath(), $format);

        return [
            'sheets' => array_map(fn ($table) => [
                'name' => $table->source,
                'row_count' => $table->rowCount(),
                'column_count' => $table->columnCount(),
                'headings' => $table->headings,
                'confidence' => round($table->confidence, 2),
                'header_row_number' => $table->headerRowNumber,
            ], $document->tables),
            'chosen' => $document->primaryTable()?->source,
        ];
    }

    protected function readableAsIs(ExtractedDocument $document): bool
    {
        if ($document->tables === []) {
            return true;
        }

        foreach ($document->tables as $table) {
            if ($table->headerRowNumber !== 1) {
                return false;
            }
        }

        return true;
    }
}
