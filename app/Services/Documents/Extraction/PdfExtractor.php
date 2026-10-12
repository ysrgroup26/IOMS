<?php

namespace App\Services\Documents\Extraction;

use App\Services\Documents\Contracts\DocumentExtractor;
use App\Services\Documents\FormatDetector;
use App\Services\Documents\Support\ExtractedDocument;
use App\Services\Documents\Support\ExtractedTable;
use App\Services\Documents\Support\TableLocator;
use Smalot\PdfParser\Parser;

/**
 * v2.110.0 -- TEXT OUT OF A DIGITAL PDF, VIA SMALOT/PDFPARSER.
 *
 * Pure PHP, LGPL-3.0, no binaries: it runs on the shared host as-is,
 * which `pdftotext` (poppler) and Tika (Java) do not.
 *
 * A PDF HAS NO TABLES. It has glyphs at coordinates. Any "table" read out
 * of one is reconstructed, and this class is deliberately conservative
 * about that: it recovers a table only from the shape PDF text extraction
 * actually preserves -- lines whose columns are separated by runs of
 * whitespace -- and it returns a LOW confidence so the preview presents
 * it for confirmation instead of importing it.
 *
 * WHAT IT WILL NOT DO:
 *  - It will not claim to reconstruct multi-line cells, merged columns or
 *    ruled grids. Those need glyph coordinates and a layout engine.
 *  - It will not OCR. A PDF whose pages carry no text layer is reported
 *    as scanned (see `OcrExtractor`), never silently returned as empty.
 *
 * The full text is always returned, because for most PDFs that IS the
 * usable content.
 */
class PdfExtractor implements DocumentExtractor
{
    /** Below this many characters a page is treated as having no text layer. */
    private const SCANNED_PAGE_THRESHOLD = 24;

    /** Two or more spaces is what a PDF text dump leaves between columns. */
    private const COLUMN_GAP = '/\s{2,}/u';

    /** Baseline wobble within one row, in PDF points. */
    private const ROW_TOLERANCE = 2.0;

    /** How far apart two x positions may be and still be one column. */
    private const COLUMN_TOLERANCE = 12.0;

    public function __construct(private readonly TableLocator $locator) {}

    public function supports(): array
    {
        return [FormatDetector::PDF];
    }

    public function isAvailable(): bool
    {
        return class_exists(Parser::class);
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'Pustaka pembaca PDF (smalot/pdfparser) belum terpasang pada instalasi ini.';
    }

    public function extract(string $path): ExtractedDocument
    {
        $parser = new Parser;
        $pdf = $parser->parseFile($path);

        $pages = $pdf->getPages();
        $warnings = [];
        $tables = [];
        $allText = [];
        $pagesWithoutText = 0;

        foreach ($pages as $index => $page) {
            $text = trim($page->getText());
            $allText[] = $text;

            if (mb_strlen($text) < self::SCANNED_PAGE_THRESHOLD) {
                $pagesWithoutText++;

                continue;
            }

            $table = $this->recoverTableByPosition($page, $index + 1)
                ?? $this->recoverTable($text, $index + 1);

            if ($table !== null) {
                $tables[] = $table;
            }
        }

        if ($pagesWithoutText > 0) {
            $warnings[] = sprintf(
                '%d dari %d halaman tidak memuat lapisan teks (kemungkinan hasil pindai). '
                .'Halaman tersebut memerlukan OCR, yang belum tersedia pada deployment ini.',
                $pagesWithoutText,
                count($pages)
            );
        }

        if ($tables === [] && $allText !== []) {
            $warnings[] = 'Tidak ada struktur tabel yang dapat dipulihkan dari PDF ini; '
                .'hanya teks yang diekstrak.';
        }

        return new ExtractedDocument(
            format: FormatDetector::PDF,
            tables: $tables,
            text: trim(implode("\n\n", $allText)),
            warnings: $warnings,
            meta: [
                'pages' => count($pages),
                'pages_without_text' => $pagesWithoutText,
                'likely_scanned' => $pagesWithoutText > 0 && $pagesWithoutText === count($pages),
            ],
        );
    }

    /**
     * RECONSTRUCTS A TABLE FROM GLYPH POSITIONS.
     *
     * `getDataTm()` gives each text fragment's transformation matrix, so
     * the x/y it was actually drawn at is known. That is a far better
     * signal than whitespace in the flattened text: fragments sharing a y
     * are a row, and the x values they share across rows are the columns.
     *
     * This is still a reconstruction -- a PDF has no table object -- so
     * the confidence stays below certainty and the note says where the
     * structure came from. But it recovers the real grid from documents
     * whose flattened text has no column gaps left in it at all, which is
     * most of them.
     *
     * Returns null when the page has no column structure, so the caller
     * can fall back to the whitespace reader for PDFs whose producer did
     * not emit usable matrices.
     */
    private function recoverTableByPosition(mixed $page, int $pageNumber): ?ExtractedTable
    {
        if (! method_exists($page, 'getDataTm')) {
            return null;
        }

        try {
            $items = $page->getDataTm();
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($items) || count($items) < 6) {
            return null;
        }

        // --- 1. group fragments into rows by their y coordinate --------
        $byRow = [];

        foreach ($items as $item) {
            $matrix = $item[0] ?? null;
            $text = trim((string) ($item[1] ?? ''));

            if (! is_array($matrix) || count($matrix) < 6 || $text === '') {
                continue;
            }

            $x = (float) $matrix[4];
            $y = (float) $matrix[5];

            // Baselines of one row wobble by a fraction of a point;
            // rounding to 2pt buckets keeps a row together without
            // merging adjacent lines.
            $key = (string) round($y / self::ROW_TOLERANCE);

            $byRow[$key][] = ['x' => $x, 'text' => $text];
        }

        if (count($byRow) < 3) {
            return null;
        }

        // PDF y grows upward, so a page reads from the highest y down.
        krsort($byRow, SORT_NUMERIC);

        // --- 2. the x positions rows agree on ARE the columns ----------
        $anchors = [];
        $widths = [];

        foreach ($byRow as $fragments) {
            if (count($fragments) < 2) {
                continue; // a prose line is one fragment, not a row
            }

            $widths[] = count($fragments);

            foreach ($fragments as $fragment) {
                $anchors[] = $fragment['x'];
            }
        }

        if ($anchors === []) {
            return null;
        }

        /*
         * HOW MANY COLUMNS THERE REALLY ARE.
         *
         * The width most rows agree on. This matters because a heading
         * cell is very often CENTRED while the data under it is left
         * aligned, so the heading and its own column sit at two
         * different x positions and a purely distance-based clustering
         * returns twice as many columns as the table has -- every real
         * column followed by an empty "Kolom N". That was not a
         * hypothetical: a landscape PDF printed from this very
         * application produced exactly that.
         */
        $counts = array_count_values($widths);
        arsort($counts);
        $expectedColumns = (int) array_key_first($counts);

        $columns = $this->clusterColumns($anchors, $expectedColumns, $byRow);

        if (count($columns) < 2) {
            return null;
        }

        // --- 3. place every fragment in its nearest column -------------
        $grid = [];

        foreach ($byRow as $fragments) {
            usort($fragments, fn (array $a, array $b) => $a['x'] <=> $b['x']);

            $line = array_fill(0, count($columns), null);

            foreach ($fragments as $fragment) {
                $index = $this->nearestColumn($columns, $fragment['x']);

                // Two fragments in one cell are parts of one value split
                // by a font change; joined with a space, not overwritten.
                $line[$index] = $line[$index] === null
                    ? $fragment['text']
                    : $line[$index].' '.$fragment['text'];
            }

            $filled = count(array_filter($line, fn ($c) => $c !== null && $c !== ''));

            if ($filled >= 2) {
                $grid[] = $line;
            }
        }

        if (count($grid) < 3) {
            return null;
        }

        $located = $this->locator->locate($grid);
        $split = $this->locator->split($grid, $located['header_row']);

        if ($split['headings'] === [] || $split['rows'] === []) {
            return null;
        }

        return new ExtractedTable(
            headings: $split['headings'],
            rows: $split['rows'],
            source: 'Halaman '.$pageNumber,
            /*
             * Higher than the whitespace reader because the geometry is
             * measured rather than inferred, and still capped: a PDF
             * carries no table object, so this is a reading of a drawing.
             */
            confidence: 0.75,
            headerRowNumber: $located['header_row'],
            notes: array_merge($located['notes'], $split['notes'], [
                'Tabel dipulihkan dari posisi teks pada halaman PDF. Periksa sebelum mengimpor.',
            ]),
        );
    }

    /**
     * Collapses x positions into column anchors.
     *
     * Two passes. The first groups positions that are within
     * COLUMN_TOLERANCE of each other, which handles the ordinary case.
     * The second runs only when that produced MORE columns than the rows
     * themselves agree on, and merges the closest remaining pairs until
     * the count matches -- the centred-heading case described in
     * `recoverTableByPosition()`.
     *
     * It never merges BELOW the expected count, and never splits: if the
     * first pass found fewer columns than expected, the table genuinely
     * has columns that share an x and no amount of arithmetic recovers
     * them.
     *
     * @param  list<float>  $positions
     * @return list<float>
     */
    private function clusterColumns(array $positions, ?int $expected = null, array $rows = []): array
    {
        sort($positions);

        /** @var list<list<float>> $clusters */
        $clusters = [];
        $current = [array_shift($positions)];

        foreach ($positions as $x) {
            if ($x - end($current) <= self::COLUMN_TOLERANCE) {
                $current[] = $x;

                continue;
            }

            $clusters[] = $current;
            $current = [$x];
        }

        $clusters[] = $current;

        if ($expected !== null && $expected >= 2) {
            $clusters = $this->mergeTo($clusters, $expected, $rows);
        }

        return array_map(fn (array $cluster) => array_sum($cluster) / count($cluster), $clusters);
    }

    /**
     * Agglomerative merge of adjacent clusters, closest pair first, down
     * to `$target` -- but ONLY where merging cannot destroy a real column.
     *
     * v2.112.0 -- THE GUARD, WHICH THE FIRST VERSION DID NOT HAVE.
     *
     * Merging purely by distance until the count matched the modal
     * fragment count was right for the case it was written for (a centred
     * heading sitting at a different x from its left-aligned data) and
     * wrong in general: when the modal count UNDERSTATES the number of
     * columns -- a row where two neighbouring cells' glyphs came out as
     * one fragment is enough -- it forced two genuine columns together. On
     * a landscape roster printed from this very application it merged
     * `Bagian` and `Jabatan` into one cell reading "Produksi Welder", and
     * the heading row was then mistaken for data.
     *
     * The exact test is co-occupancy: TWO CLUSTERS THAT SOME SINGLE ROW
     * USES AT THE SAME TIME ARE DIFFERENT COLUMNS, and no distance makes
     * them one. A heading at one x and its data at another are never both
     * present in one row, so the original case still merges; `Bagian` and
     * `Jabatan`, which every data row fills together, now cannot.
     *
     * When no pair is eligible the loop stops above `$target` rather than
     * forcing it. Reporting more columns than a page really has costs a
     * stray empty "Kolom N" the reader can see and ignore; silently
     * welding two columns together corrupts the data.
     *
     * @param  list<list<float>>  $clusters
     * @param  array<string, list<array{x: float, text: string}>>  $rows
     * @return list<list<float>>
     */
    private function mergeTo(array $clusters, int $target, array $rows = []): array
    {
        while (count($clusters) > $target) {
            $anchors = array_map(fn (array $c) => array_sum($c) / count($c), $clusters);
            $occupancy = $this->occupancy($anchors, $rows);

            $bestIndex = null;
            $bestGap = PHP_FLOAT_MAX;

            for ($i = 0; $i < count($clusters) - 1; $i++) {
                // Any row using both sides proves they are two columns.
                if (array_intersect_key($occupancy[$i] ?? [], $occupancy[$i + 1] ?? []) !== []) {
                    continue;
                }

                $gap = min($clusters[$i + 1]) - max($clusters[$i]);

                if ($gap < $bestGap) {
                    $bestGap = $gap;
                    $bestIndex = $i;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            array_splice($clusters, $bestIndex, 2, [array_merge($clusters[$bestIndex], $clusters[$bestIndex + 1])]);
        }

        return array_values($clusters);
    }

    /**
     * Which rows put a fragment in which column.
     *
     * @param  list<float>  $anchors
     * @param  array<string, list<array{x: float, text: string}>>  $rows
     * @return array<int, array<string, true>>
     */
    private function occupancy(array $anchors, array $rows): array
    {
        $occupancy = [];

        foreach ($rows as $key => $fragments) {
            foreach ($fragments as $fragment) {
                $occupancy[$this->nearestColumn($anchors, (float) $fragment['x'])][(string) $key] = true;
            }
        }

        return $occupancy;
    }

    /** @param list<float> $columns */
    private function nearestColumn(array $columns, float $x): int
    {
        $best = 0;
        $distance = PHP_FLOAT_MAX;

        foreach ($columns as $index => $anchor) {
            $gap = abs($anchor - $x);

            if ($gap < $distance) {
                $distance = $gap;
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * Recovers a column structure from one page of extracted text.
     *
     * Only accepted when enough consecutive lines split into the SAME
     * number of columns -- a paragraph splits into one column, a heading
     * into one or two, and neither should be mistaken for a register.
     */
    private function recoverTable(string $text, int $pageNumber): ?ExtractedTable
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (trim($line) === '') {
                continue;
            }

            $cells = preg_split(self::COLUMN_GAP, trim($line)) ?: [];

            if (count($cells) >= 2) {
                $rows[] = array_map('trim', $cells);
            }
        }

        if (count($rows) < 3) {
            return null;
        }

        // The width most lines agree on is the table's width; lines of a
        // different width are prose that happened to contain a wide gap.
        $widths = array_count_values(array_map('count', $rows));
        arsort($widths);
        $dominantWidth = (int) array_key_first($widths);
        $agreeing = $widths[$dominantWidth];

        if ($dominantWidth < 2 || $agreeing < 3) {
            return null;
        }

        $grid = array_values(array_filter($rows, fn (array $r) => count($r) === $dominantWidth));

        $located = $this->locator->locate($grid);
        $split = $this->locator->split($grid, $located['header_row']);

        if ($split['headings'] === [] || $split['rows'] === []) {
            return null;
        }

        /*
         * Capped low on purpose. This is a reconstruction from whitespace,
         * and the platform's contract is that an uncertain extraction is
         * offered for confirmation rather than imported.
         */
        $confidence = min(0.55, 0.2 + ($agreeing / max(count($rows), 1)) * 0.35);

        return new ExtractedTable(
            headings: $split['headings'],
            rows: $split['rows'],
            source: 'Halaman '.$pageNumber,
            confidence: $confidence,
            headerRowNumber: $located['header_row'],
            notes: array_merge($located['notes'], $split['notes'], [
                'Tabel ini dipulihkan dari teks PDF berdasarkan jarak antar kolom. Periksa sebelum mengimpor.',
            ]),
        );
    }
}
