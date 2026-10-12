<?php

namespace App\Services\Documents\Support;

/**
 * v2.110.0 -- FINDING THE TABLE INSIDE A SHEET THAT IS NOT ONLY A TABLE.
 *
 * Real customer spreadsheets open with a merged title, a logo row, a
 * "Periode: Januari 2026" line and two blank rows before the headings
 * start. `WithHeadingRow` assumes row 1, so every one of those files
 * imports as a single column of nonsense or as nothing at all.
 *
 * This scores each candidate row and picks the one that behaves like a
 * heading row: mostly non-empty, mostly text, mostly distinct, and
 * followed by rows of the same width. It is a measurement, not a guess,
 * and it reports the row it chose so the preview can show the user where
 * it thinks the table starts.
 *
 * IT CAN BE WRONG, AND THE DESIGN ASSUMES SO. The chosen row number and
 * a confidence travel with the result; the import dialog shows both, and
 * the user can override. What it must never do is silently decide that a
 * title row is a header row and import every column under the name of a
 * company.
 */
class TableLocator
{
    /** Rows to examine before giving up on finding a heading row. */
    private const MAX_SCAN_ROWS = 25;

    /**
     * @param  list<array<int, scalar|null>>  $grid  raw rows, positionally indexed
     * @return array{header_row: int, confidence: float, notes: list<string>}
     */
    public function locate(array $grid): array
    {
        $notes = [];
        $best = ['header_row' => 1, 'score' => -1.0];
        $limit = min(count($grid), self::MAX_SCAN_ROWS);

        for ($i = 0; $i < $limit; $i++) {
            $score = $this->scoreAsHeader($grid, $i);

            if ($score > $best['score']) {
                $best = ['header_row' => $i + 1, 'score' => $score];
            }
        }

        if ($best['header_row'] > 1) {
            $notes[] = sprintf(
                '%d baris di atas tabel dilewati; baris %d dibaca sebagai judul kolom.',
                $best['header_row'] - 1,
                $best['header_row']
            );
        }

        return [
            'header_row' => $best['header_row'],
            // A negative or zero score means nothing in the scanned range
            // behaved like a heading row at all.
            'confidence' => max(0.0, min(1.0, $best['score'])),
            'notes' => $notes,
        ];
    }

    /**
     * How much row $index behaves like a heading row, 0..1.
     *
     * Four signals, each of which a title row or a data row fails:
     *  - fill: a heading row has few empty cells
     *  - text: headings are words, not numbers or dates
     *  - distinct: headings do not repeat
     *  - support: the rows beneath it are as wide as it is
     */
    private function scoreAsHeader(array $grid, int $index): float
    {
        $row = $grid[$index] ?? [];
        $cells = array_map(fn ($c) => trim((string) ($c ?? '')), $row);
        $filled = array_values(array_filter($cells, fn (string $c) => $c !== ''));

        // One filled cell is a title, not a header row.
        if (count($filled) < 2) {
            return 0.0;
        }

        $width = count($cells);
        $fill = count($filled) / max($width, 1);

        $textual = count(array_filter(
            $filled,
            fn (string $c) => ! is_numeric($c) && ! $this->looksLikeDate($c)
        )) / count($filled);

        $distinct = count(array_unique(array_map('mb_strtolower', $filled))) / count($filled);

        $support = $this->supportBelow($grid, $index, $cells);

        /*
         * Weighted so `support` cannot be outvoted: a decorative row can
         * easily look textual and distinct, but only a real heading row
         * has a block of similarly-wide rows under it.
         */
        return (0.25 * $fill) + (0.2 * $textual) + (0.15 * $distinct) + (0.4 * $support);
    }

    /**
     * Fraction of the next rows that sit UNDER this candidate's columns.
     *
     * v2.111.0 -- this used to compare WIDTHS: a row supported the
     * candidate only if it filled at least half as many cells. That is
     * wrong for the commonest template of all, where a sheet declares
     * twelve columns and the customer fills five. The real heading row
     * scored zero support, and a DATA row -- whose neighbours happen to
     * be exactly as sparse as it is -- scored full support and won.
     *
     * The relationship that actually defines a heading row is
     * CONTAINMENT, not width: data sits in the columns the headings
     * named. A row supports the candidate when the columns it fills are
     * a subset of the candidate's, and it fills at least two of them.
     *
     * @param  list<string>  $headerCells  the candidate row, trimmed, positionally indexed
     */
    private function supportBelow(array $grid, int $index, array $headerCells): float
    {
        $headerColumns = [];

        foreach ($headerCells as $column => $value) {
            if ($value !== '') {
                $headerColumns[$column] = true;
            }
        }

        $checked = 0;
        $supporting = 0;

        for ($i = $index + 1; $i < count($grid) && $checked < 5; $i++) {
            $filledColumns = [];

            foreach ($grid[$i] as $column => $value) {
                if (trim((string) ($value ?? '')) !== '') {
                    $filledColumns[] = $column;
                }
            }

            // Blank rows are skipped rather than counted against: a gap
            // between the headings and the first record is common.
            if ($filledColumns === []) {
                continue;
            }

            $checked++;

            $inside = count(array_filter($filledColumns, fn ($c) => isset($headerColumns[$c])));

            // Every filled cell under a heading, and at least two of
            // them. A row that spills into columns the candidate never
            // named is not sitting under it.
            if ($inside >= 2 && $inside === count($filledColumns)) {
                $supporting++;
            }
        }

        return $checked === 0 ? 0.0 : $supporting / $checked;
    }

    private function looksLikeDate(string $value): bool
    {
        return (bool) preg_match('#^\d{1,4}[-/.]\d{1,2}[-/.]\d{1,4}$#', $value);
    }

    /**
     * Splits a located grid into headings and data rows, dropping rows
     * that are entirely empty.
     *
     * TRAILING EMPTY COLUMNS ARE DROPPED, leading ones are not: a
     * spreadsheet routinely reports a used range wider than its data, but
     * a genuinely empty first column usually means the table is indented
     * and its position still matters.
     *
     * @param  list<array<int, scalar|null>>  $grid
     * @return array{headings: list<string>, rows: list<array<int, scalar|null>>, notes: list<string>}
     */
    public function split(array $grid, int $headerRow): array
    {
        $notes = [];
        $headings = array_map(fn ($c) => trim((string) ($c ?? '')), $grid[$headerRow - 1] ?? []);

        while ($headings !== [] && end($headings) === '') {
            array_pop($headings);
        }

        $width = count($headings);

        // An unnamed column still holds data, so it is kept and named by
        // position rather than dropped -- dropping it would silently
        // discard a column the customer can see in their own file.
        $blanks = 0;
        foreach ($headings as $i => $heading) {
            if ($heading === '') {
                $blanks++;
                $headings[$i] = 'Kolom '.($i + 1);
            }
        }

        if ($blanks > 0) {
            $notes[] = $blanks.' kolom tanpa judul diberi nama sementara (Kolom N).';
        }

        $seen = [];
        foreach ($headings as $heading) {
            $key = mb_strtolower($heading);
            if (isset($seen[$key])) {
                $notes[] = 'Judul kolom "'.$heading.'" muncul lebih dari sekali.';
                break;
            }
            $seen[$key] = true;
        }

        $rows = [];
        for ($i = $headerRow; $i < count($grid); $i++) {
            $row = array_slice(array_pad($grid[$i], $width, null), 0, $width);

            $hasValue = false;
            foreach ($row as $cell) {
                if (trim((string) ($cell ?? '')) !== '') {
                    $hasValue = true;
                    break;
                }
            }

            if ($hasValue) {
                $rows[] = array_values($row);
            }
        }

        return ['headings' => array_values($headings), 'rows' => $rows, 'notes' => $notes];
    }
}
