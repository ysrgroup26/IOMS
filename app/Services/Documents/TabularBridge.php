<?php

namespace App\Services\Documents;

use App\Services\Documents\Support\ExtractedTable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * v2.110.0 -- THE BRIDGE FROM "ANY DOCUMENT" TO "THE IMPORTER WE ALREADY HAVE".
 *
 * IOMS's employee importer is the most carefully built thing in the
 * repository: day-first date parsing, a NIK registry, employment-type
 * aliases, master-data detection, row-accurate error reporting. All of it
 * is reached through Maatwebsite Excel, which reads spreadsheets and
 * nothing else.
 *
 * The temptation was to write a second import path for CSV, Word and PDF.
 * That would have meant a second copy of every validation rule, and the
 * two would have drifted -- which is exactly the failure this project has
 * written up twice already.
 *
 * So instead: an extracted table from ANY supported format is written out
 * as a CLEAN, minimal .xlsx -- headings on row 1, values beneath, nothing
 * else -- and that file is handed to the importer that already exists.
 * One set of rules, one set of error messages, four input formats.
 *
 * EVERY CELL IS WRITTEN AS AN EXPLICIT STRING. The values arriving here
 * came out of a CSV, a Word table or a PDF's glyph positions, so they are
 * text by nature; letting the writer re-interpret them is how an employee
 * number loses its leading zero and a 16-digit NIK loses its last digit
 * between two files that were supposed to be the same data.
 */
class TabularBridge
{
    /** Guards against an extraction that recovered a runaway grid. */
    private const MAX_COLUMNS = 128;

    /**
     * Writes one extracted table to a normalised .xlsx and returns its path.
     *
     * The caller owns the temporary file and must delete it; this class
     * deliberately does not register a shutdown handler, because the
     * importer that reads the file outlives the call that made it.
     */
    public function toSpreadsheet(ExtractedTable $table, ?string $path = null): string
    {
        if ($table->headings === []) {
            throw new RuntimeException(
                'Tabel yang dipilih tidak memiliki baris judul kolom, sehingga tidak dapat diimpor.'
            );
        }

        $headings = array_slice($table->headings, 0, self::MAX_COLUMNS);
        $path ??= tempnam(sys_get_temp_dir(), 'ioms-bridge-').'.xlsx';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        foreach ($headings as $index => $heading) {
            $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).'1')
                ->setValueExplicit((string) $heading, DataType::TYPE_STRING);
        }

        $rowNumber = 2;

        foreach ($table->rows as $row) {
            foreach ($headings as $index => $ignored) {
                $value = $row[$index] ?? null;

                if ($value === null || $value === '') {
                    // Left genuinely empty. A blank cell and a cell
                    // containing an empty string read differently to the
                    // importer's "required" rules, and "absent" is the
                    // truth here.
                    continue;
                }

                $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).$rowNumber)
                    ->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }

            $rowNumber++;
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
