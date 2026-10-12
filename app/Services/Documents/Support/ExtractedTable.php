<?php

namespace App\Services\Documents\Support;

/**
 * v2.110.0 -- ONE SHAPE EVERY EXTRACTOR PRODUCES.
 *
 * A spreadsheet sheet, a Word table and a PDF page all arrive here as the
 * same thing: a heading row and the rows under it, plus where it came
 * from. That is the whole point of the extraction layer -- once a file is
 * an ExtractedTable, nothing downstream (mapping, validation,
 * persistence) needs to know or care which format produced it.
 *
 * `confidence` is the extractor's own honesty about the table: a
 * spreadsheet with a clean header row is 1.0; a table recovered from PDF
 * text by column alignment is much lower and the UI is expected to say
 * so rather than import it silently.
 */
final class ExtractedTable
{
    /**
     * @param  list<string>  $headings  the heading row, verbatim
     * @param  list<array<int, scalar|null>>  $rows  data rows, positionally aligned to $headings
     * @param  string  $source  human label: a sheet name, "Table 2", "Page 3"
     * @param  float  $confidence  0..1, how sure the extractor is this IS a table
     * @param  int  $headerRowNumber  1-based row in the source where the heading row sat
     * @param  list<string>  $notes  anything a human should know about this extraction
     */
    public function __construct(
        public readonly array $headings,
        public readonly array $rows,
        public readonly string $source,
        public readonly float $confidence = 1.0,
        public readonly int $headerRowNumber = 1,
        public readonly array $notes = [],
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function columnCount(): int
    {
        return count($this->headings);
    }

    /**
     * Rows keyed by heading, which is what a mapper wants. Duplicate
     * headings keep their position in `$this->rows`; here the LAST wins,
     * matching how every spreadsheet reader in this stack behaves, and
     * the duplication is reported in `notes` by the extractor so it is
     * never silent.
     *
     * @return list<array<string, scalar|null>>
     */
    public function toAssociativeRows(): array
    {
        return array_map(function (array $row): array {
            $assoc = [];

            foreach ($this->headings as $index => $heading) {
                $assoc[$heading] = $row[$index] ?? null;
            }

            return $assoc;
        }, $this->rows);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'headings' => $this->headings,
            'row_count' => $this->rowCount(),
            'column_count' => $this->columnCount(),
            'confidence' => round($this->confidence, 2),
            'header_row_number' => $this->headerRowNumber,
            'notes' => $this->notes,
        ];
    }
}
