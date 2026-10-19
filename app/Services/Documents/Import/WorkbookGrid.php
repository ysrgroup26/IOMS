<?php

namespace App\Services\Documents\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * v2.126.0 -- ONE READER FOR THE RAW GRID, SO THE SCREENING AND THE IMPORT
 * CANNOT READ TWO DIFFERENT FILES.
 *
 * The screening shows a person which sheet and which header row a workbook
 * is being read from, and lets them change both. If the import then found
 * its own sheet and its own header row, everything the person approved
 * would be a description of something else -- which is the v2.108.1 defect
 * ("the preview is a promise the import keeps") in a larger and more
 * convincing disguise.
 *
 * So both call this. It exists for the same reason
 * `ReadsImportedSpreadsheets` exists: a second copy of "which rows are we
 * reading" would drift from the first, and this time the drift would be
 * invisible because both halves would look right on their own.
 *
 * WHY NOT `ExtractedTable`. The document platform's locator picks ONE
 * table out of a document, which is right for an import that has to guess
 * and wrong for a screen whose whole purpose is to let a person choose: on
 * the first real company register it settled on a sub-table starting ten
 * rows in and reported a worker's name as a column heading. The grid is
 * every row, including the title block and the separators, because those
 * are exactly what the person needs to see past.
 */
class WorkbookGrid
{
    /** Rows returned per sheet. A screening is READ by a person. */
    public const MAX_ROWS = 400;

    /**
     * Every worksheet, as a plain grid.
     *
     * @return array<string, list<array<int, mixed>>> sheet title => rows
     */
    public static function read(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        // Values only: formatting is irrelevant here and reading it is the
        // slow part on a large register. Dates arrive as serials and are
        // parsed by SpreadsheetDate -- the same parser the importer uses.
        $reader->setReadDataOnly(true);

        $grids = [];

        foreach ($reader->load($path)->getAllSheets() as $sheet) {
            $grids[$sheet->getTitle()] = $sheet->toArray(null, true, false, false);
        }

        return $grids;
    }

    /**
     * Which sheet to open on when the person has not said.
     *
     * The widest, then the longest. A register is wider than an
     * instructions tab, and the FIRST worksheet in a company's workbook is
     * frequently the latter -- "Petunjuk" before "Rekap APD" -- so opening
     * on sheet one is reliably wrong for exactly the files this feature
     * exists to read.
     *
     * @param  array<string, list<array<int, mixed>>>  $grids
     */
    public static function widestSheet(array $grids): ?string
    {
        $best = null;
        $bestScore = [-1, -1];

        foreach ($grids as $name => $grid) {
            $width = 0;

            foreach ($grid as $row) {
                $width = max($width, count($row));
            }

            $score = [$width, count($grid)];

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $name;
            }
        }

        return $best;
    }

    /**
     * The row most likely to hold the headings.
     *
     * The widest row in the first dozen, because a title ("REKAP PEMENUHAN
     * APD KARYAWAN") occupies one cell and a heading row occupies all of
     * them. It is a GUESS, the screen says so, and the person can move it
     * -- which is the only honest answer to a layout we cannot know.
     *
     * @param  list<array<int, mixed>>  $grid
     */
    public static function guessHeaderRow(array $grid): int
    {
        $best = 1;
        $bestFilled = 0;

        foreach (array_slice($grid, 0, 12) as $i => $row) {
            $filled = count(array_filter($row, fn ($v) => trim((string) ($v ?? '')) !== ''));

            if ($filled > $bestFilled) {
                $bestFilled = $filled;
                $best = $i + 1;
            }
        }

        return $best;
    }
}
