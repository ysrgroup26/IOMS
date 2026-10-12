<?php

namespace App\Services\Documents\Export;

use App\Services\DocumentEngine;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * v2.110.0 -- A PROFESSIONAL, BRANDED XLSX, BUILT THE SAME WAY EVERY TIME.
 *
 * IOMS already had `IomsSheetFormatting`, which freezes the header row,
 * adds an auto-filter and sets print options on a Maatwebsite export.
 * This is the layer above it: a workbook that opens with the TENANT's
 * own letterhead -- company name, address, contact, document title,
 * reference and date -- and then the table, with each column given a real
 * Excel number format rather than a pre-formatted string.
 *
 * WHY FORMATS AND NOT STRINGS. A date written as "15/01/2026" is text:
 * it cannot be sorted, filtered by month, or used in a formula. The same
 * goes for a quantity written as "1.234 kg". Columns here declare a TYPE
 * and the value is written natively, so the file behaves like a
 * spreadsheet instead of a picture of one.
 *
 * IDENTIFIERS ARE WRITTEN AS TEXT, EXPLICITLY. An employee number or a
 * 16-digit NIK written as a number loses its leading zeros and, past 15
 * digits, its last digits -- the same defect in the other direction from
 * the import side. `text` columns are written with an explicit string
 * data type so Excel cannot help.
 *
 * BRANDING IS RESOLVED PER CALL, never baked in: `DocumentEngine::
 * identity()` reads the tenant-scoped company settings, so a workbook can
 * only ever carry the identity of the tenant that generated it, and a
 * tenant who has updated their company name sees it on the next export.
 * The logo is deliberately NOT embedded: see `writeLetterhead()`.
 */
class BrandedWorkbook
{
    /** @var array{name: string, columns: list<array<string,mixed>>, rows: iterable}|null */
    private ?array $pending = null;

    private Spreadsheet $spreadsheet;

    private bool $hasSheet = false;

    public function __construct(private readonly DocumentEngine $documents)
    {
        $this->spreadsheet = new Spreadsheet;
    }

    /**
     * Adds one worksheet: letterhead, title block, table.
     *
     * @param  string  $sheetName  Excel caps this at 31 chars and forbids []:*?/\
     * @param  list<array{key: string, label: string, type?: string, width?: int}>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, string|null>  $refs  document reference lines (label => value)
     */
    public function addSheet(string $title, string $sheetName, array $columns, iterable $rows, array $refs = []): self
    {
        $sheet = $this->hasSheet
            ? $this->spreadsheet->createSheet()
            : $this->spreadsheet->getActiveSheet();

        $this->hasSheet = true;
        $sheet->setTitle($this->safeSheetName($sheetName));

        $headerRow = $this->writeLetterhead($sheet, $title, $refs, count($columns));
        $this->writeTable($sheet, $headerRow, $columns, $rows);

        return $this;
    }

    /**
     * The tenant's identity block.
     *
     * THE LOGO IS NOT EMBEDDED, DELIBERATELY. A tenant logo lives on a
     * disk this process can read, but embedding it means decoding an
     * arbitrary customer-supplied image inside the export request -- the
     * one place a malformed upload becomes a failed download. The company
     * NAME is always rendered, which is the text fallback the brief asks
     * for, and the PDF and Word documents carry the logo instead.
     *
     * @return int the row the table's header will occupy
     */
    private function writeLetterhead(Worksheet $sheet, string $title, array $refs, int $columnCount): int
    {
        $identity = $this->documents->identity();
        $lastColumn = Coordinate::stringFromColumnIndex(max($columnCount, 3));

        $row = 1;

        $sheet->setCellValue('A'.$row, $identity['name'] ?? config('ioms.name'));
        $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(14);
        $row++;

        $addressLine = trim(implode(' - ', array_filter([
            $identity['address'] ?? null,
            $identity['locality'] ?? null,
        ])));

        if ($addressLine !== '') {
            $sheet->setCellValue('A'.$row, $addressLine);
            $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
            $sheet->getStyle('A'.$row)->getFont()->setSize(9);
            $row++;
        }

        $contact = implode(' | ', array_filter([
            ($identity['phone'] ?? null) ? 'T '.$identity['phone'] : null,
            $identity['email'] ?? null,
            $identity['website'] ?? null,
        ]));

        if ($contact !== '') {
            $sheet->setCellValue('A'.$row, $contact);
            $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
            $sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setARGB('FF666666');
            $row++;
        }

        // A rule under the letterhead, the paper equivalent of the PDF's.
        $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getBorders()->getBottom()
            ->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setARGB('FF1E3A8A');
        $row += 2;

        $sheet->setCellValue('A'.$row, mb_strtoupper($title));
        $sheet->mergeCells('A'.$row.':'.$lastColumn.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
        $row++;

        foreach (array_filter($refs, fn ($v) => $v !== null && $v !== '') as $label => $value) {
            $sheet->setCellValue('A'.$row, $label.': '.$value);
            $sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setARGB('FF666666');
            $row++;
        }

        return $row + 1;
    }

    /**
     * @param  list<array{key: string, label: string, type?: string, width?: int}>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     */
    private function writeTable(Worksheet $sheet, int $headerRow, array $columns, iterable $rows): void
    {
        foreach ($columns as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);

            $sheet->setCellValue($letter.$headerRow, $column['label']);
            $sheet->getColumnDimension($letter)->setWidth($column['width'] ?? 18);
        }

        $lastColumn = Coordinate::stringFromColumnIndex(max(count($columns), 1));
        $headerRange = 'A'.$headerRow.':'.$lastColumn.$headerRow;

        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E3A8A');
        $sheet->getStyle($headerRange)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        $row = $headerRow + 1;

        foreach ($rows as $record) {
            foreach ($columns as $index => $column) {
                $letter = Coordinate::stringFromColumnIndex($index + 1);
                $this->writeCell($sheet, $letter.$row, $record[$column['key']] ?? null, $column['type'] ?? 'text');
            }

            $row++;
        }

        $lastRow = max($row - 1, $headerRow);
        $tableRange = 'A'.$headerRow.':'.$lastColumn.$lastRow;

        $sheet->getStyle($tableRange)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFD1D5DB');

        // Freeze under the header, filter on it, repeat it on every
        // printed page: the three things that make a long register usable.
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter($headerRange);
        $sheet->setPrintGridlines(false);

        $setup = $sheet->getPageSetup();
        $setup->setOrientation(count($columns) > 6 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);
        $setup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $setup->setPrintArea('A1:'.$lastColumn.$lastRow);

        $sheet->getHeaderFooter()->setOddFooter('&L&"Arial,Italic"&8'.
            str_replace('&', '&&', (string) ($this->documents->identity()['name'] ?? '')).
            '&R&"Arial,Italic"&8Page &P of &N');
    }

    private function writeCell(Worksheet $sheet, string $coordinate, mixed $value, string $type): void
    {
        if ($value === null || $value === '') {
            // An empty cell, not a zero and not a dash: "no value" must
            // stay distinguishable from a real one.
            return;
        }

        $cell = $sheet->getCell($coordinate);

        switch ($type) {
            case 'text':
                // Explicitly string: this is what protects leading zeros
                // and long identifiers from Excel's own helpfulness.
                $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
                break;

            case 'date':
                $timestamp = strtotime((string) $value);

                if ($timestamp === false) {
                    $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
                    break;
                }

                $cell->setValue(Date::PHPToExcel($timestamp));
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                break;

            case 'number':
                $cell->setValue(0 + $value);
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('#,##0');
                break;

            case 'decimal':
                $cell->setValue(0 + $value);
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('#,##0.00');
                break;

            case 'currency':
                $cell->setValue(0 + $value);
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('"Rp"#,##0');
                break;

            case 'percent':
                $cell->setValue(0 + $value);
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_PERCENTAGE_00);
                break;

            default:
                $cell->setValue($value);
        }
    }

    /** Excel refuses []:*?/\ and anything over 31 characters. */
    private function safeSheetName(string $name): string
    {
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '-', $name) ?? 'Sheet';

        return mb_substr(trim($clean) ?: 'Sheet', 0, 31);
    }

    public function save(string $path): string
    {
        $this->spreadsheet->setActiveSheetIndex(0);

        $properties = $this->spreadsheet->getProperties();
        $identity = $this->documents->identity();
        $properties->setCreator((string) ($identity['name'] ?? config('ioms.name')));
        $properties->setCompany((string) ($identity['name'] ?? ''));

        (new Xlsx($this->spreadsheet))->save($path);

        $this->spreadsheet->disconnectWorksheets();

        return $path;
    }

    /** Streams the workbook as a download, cleaning the temp file after. */
    public function download(string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'ioms-xlsx-').'.xlsx';
        $this->save($path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
