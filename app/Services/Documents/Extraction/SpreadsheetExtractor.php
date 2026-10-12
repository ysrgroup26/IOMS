<?php

namespace App\Services\Documents\Extraction;

use App\Services\Documents\Contracts\DocumentExtractor;
use App\Services\Documents\FormatDetector;
use App\Services\Documents\Support\ExtractedDocument;
use App\Services\Documents\Support\ExtractedTable;
use App\Services\Documents\Support\TableLocator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * v2.110.0 -- XLSX, XLS AND CSV, VIA THE LIBRARY THIS STACK ALREADY HAS.
 *
 * PhpSpreadsheet is already installed (maatwebsite/excel depends on it),
 * is MIT, is pure PHP, and reads all three formats. Adding SheetJS beside
 * it would have meant a second spreadsheet engine, in a language the
 * production host does not have, to do a job this one already does.
 *
 * EVERY SHEET IS EXTRACTED, not just the first. A workbook whose register
 * sits on sheet 3 behind two summary tabs is ordinary, and reading only
 * the active sheet is how such a file imports as empty.
 *
 * TWO CELL RULES THAT PROTECT REAL DATA:
 *
 * 1. A DATE CELL IS READ AS A DATE. Excel stores dates as numbers, so a
 *    raw read of 15/01/2026 returns 46037. The cell's own number format
 *    is what says it is a date, and that is what is consulted.
 *
 * 2. AN IDENTIFIER KEEPS ITS LEADING ZEROS. `0012345` and NIK values are
 *    text in the file and must stay text: reading them as numbers drops
 *    the zeros, and a 16-digit NIK read as a float loses its last digits
 *    entirely. The cell's stored data type decides, not the value's
 *    appearance.
 *
 * `setReadDataOnly(false)` is required for rule 1 -- formats are not
 * loaded in data-only mode -- and is the deliberate cost of reading dates
 * correctly.
 */
class SpreadsheetExtractor implements DocumentExtractor
{
    /** Hard ceiling per sheet, so one upload cannot exhaust memory. */
    public const MAX_ROWS_PER_SHEET = 20000;

    public const MAX_COLUMNS = 128;

    public function __construct(private readonly TableLocator $locator) {}

    public function supports(): array
    {
        return [FormatDetector::XLSX, FormatDetector::XLS, FormatDetector::CSV];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function extract(string $path): ExtractedDocument
    {
        $reader = IOFactory::createReaderForFile($path);

        // Formatting IS data here: it is how a date is distinguishable
        // from the integer Excel actually stores.
        $reader->setReadDataOnly(false);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $spreadsheet = $reader->load($path);

        $tables = [];
        $warnings = [];

        try {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $grid = $this->readGrid($sheet, $warnings);

                if ($grid === []) {
                    continue;
                }

                $located = $this->locator->locate($grid);
                $split = $this->locator->split($grid, $located['header_row']);

                if ($split['headings'] === [] || $split['rows'] === []) {
                    continue;
                }

                $tables[] = new ExtractedTable(
                    headings: $split['headings'],
                    rows: $split['rows'],
                    source: $sheet->getTitle(),
                    confidence: $located['confidence'],
                    headerRowNumber: $located['header_row'],
                    notes: array_merge($located['notes'], $split['notes']),
                );
            }
        } finally {
            // PhpSpreadsheet holds the whole workbook in memory; on a
            // shared host that matters more than it reads.
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return new ExtractedDocument(
            format: 'spreadsheet',
            tables: $tables,
            warnings: $warnings,
        );
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array<int, scalar|null>>
     */
    private function readGrid(Worksheet $sheet, array &$warnings): array
    {
        $highestRow = min($sheet->getHighestDataRow(), self::MAX_ROWS_PER_SHEET);
        $highestColumnIndex = min(
            Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
            self::MAX_COLUMNS
        );

        if ($sheet->getHighestDataRow() > self::MAX_ROWS_PER_SHEET) {
            $warnings[] = sprintf(
                'Sheet "%s" berisi %d baris; hanya %d baris pertama yang dibaca.',
                $sheet->getTitle(),
                $sheet->getHighestDataRow(),
                self::MAX_ROWS_PER_SHEET
            );
        }

        $grid = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $line = [];

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $line[] = $this->readCell($sheet, $col, $row);
            }

            $grid[] = $line;
        }

        return $grid;
    }

    private function readCell(Worksheet $sheet, int $col, int $row): string|float|int|null
    {
        $cell = $sheet->getCellByColumnAndRow($col, $row, false);

        if ($cell === null) {
            return null;
        }

        $value = $cell->getValue();

        if ($value === null || $value === '') {
            return null;
        }

        // A formula is read for its result, not its source: the customer
        // sees the result in their own file.
        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
            try {
                $value = $cell->getCalculatedValue();
            } catch (\Throwable) {
                // An unsupported function must not fail the whole import.
                return (string) $cell->getOldCalculatedValue();
            }
        }

        // RULE 1 -- a date cell becomes a date string, not its serial.
        if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        // RULE 2 -- text stays text, so `0012345` keeps its zeros and a
        // 16-digit NIK keeps every digit.
        if ($cell->getDataType() === DataType::TYPE_STRING
            || $cell->getDataType() === DataType::TYPE_INLINE
            || $cell->getDataType() === DataType::TYPE_STRING2) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        return is_numeric($value) ? $value + 0 : (string) $value;
    }
}
