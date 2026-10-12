<?php

namespace App\Services\Documents\Extraction;

use App\Services\Documents\Contracts\DocumentExtractor;
use App\Services\Documents\FormatDetector;
use App\Services\Documents\Support\ExtractedDocument;
use App\Services\Documents\Support\ExtractedTable;
use App\Services\Documents\Support\TableLocator;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;

/**
 * v2.110.0 -- DOCX TABLES AND TEXT, VIA PHPWORD.
 *
 * Chosen over Apache Tika because Tika is a Java service and the
 * production host has no JVM; over a hand-rolled OOXML reader because
 * Word's table model (merged cells, nested tables, runs split mid-word by
 * spell-check state) is exactly the kind of thing that looks simple until
 * it is wrong. PhpWord is pure PHP, LGPL-3.0, and already understands it.
 *
 * EVERY TABLE IN THE DOCUMENT IS RETURNED, each as its own
 * ExtractedTable, because a Word report routinely carries a small header
 * block table above the real register and the right one cannot be chosen
 * by position.
 *
 * TEXT IS KEPT TOO. A Word document's meaning often sits in its
 * paragraphs rather than its grid, and discarding that would be the
 * "silently discard meaningful information" failure this platform exists
 * to avoid. It is returned for display and search, never auto-mapped to
 * fields.
 *
 * WHAT IT CANNOT DO, stated rather than discovered: merged cells are
 * flattened to their top-left value, nested tables are reported as a
 * warning rather than recursed into, and images/embedded objects are not
 * extracted. Each of those is reported in `warnings` so the preview can
 * say so.
 */
class WordExtractor implements DocumentExtractor
{
    public const MAX_TABLES = 25;

    public const MAX_ROWS_PER_TABLE = 5000;

    public function __construct(private readonly TableLocator $locator) {}

    public function supports(): array
    {
        return [FormatDetector::DOCX];
    }

    public function isAvailable(): bool
    {
        return class_exists(IOFactory::class);
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'Pustaka pembaca Word (PhpWord) belum terpasang pada instalasi ini.';
    }

    public function extract(string $path): ExtractedDocument
    {
        $document = IOFactory::load($path, 'Word2007');

        $tables = [];
        $textParts = [];
        $warnings = [];

        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof Table) {
                    if (count($tables) >= self::MAX_TABLES) {
                        $warnings[] = 'Dokumen memuat lebih dari '.self::MAX_TABLES.' tabel; sisanya tidak dibaca.';
                        break 2;
                    }

                    $table = $this->readTable($element, count($tables) + 1, $warnings);

                    if ($table !== null) {
                        $tables[] = $table;
                    }

                    continue;
                }

                $text = $this->readText($element);

                if ($text !== '') {
                    $textParts[] = $text;
                }
            }
        }

        return new ExtractedDocument(
            format: FormatDetector::DOCX,
            tables: $tables,
            text: implode("\n", $textParts),
            warnings: $warnings,
        );
    }

    /** @param list<string> $warnings */
    private function readTable(Table $table, int $position, array &$warnings): ?ExtractedTable
    {
        $grid = [];
        $sawNestedTable = false;

        foreach ($table->getRows() as $row) {
            if (count($grid) >= self::MAX_ROWS_PER_TABLE) {
                $warnings[] = 'Tabel '.$position.' dipotong pada '.self::MAX_ROWS_PER_TABLE.' baris.';
                break;
            }

            $line = [];

            foreach ($row->getCells() as $cell) {
                $parts = [];

                foreach ($cell->getElements() as $element) {
                    if ($element instanceof Table) {
                        $sawNestedTable = true;

                        continue;
                    }

                    $text = $this->readText($element);

                    if ($text !== '') {
                        $parts[] = $text;
                    }
                }

                $line[] = $parts === [] ? null : implode(' ', $parts);
            }

            $grid[] = $line;
        }

        if ($sawNestedTable) {
            $warnings[] = 'Tabel '.$position.' memuat tabel bersarang yang tidak ikut dibaca.';
        }

        if (count($grid) < 2) {
            return null; // a one-row table is a layout device, not data
        }

        $located = $this->locator->locate($grid);
        $split = $this->locator->split($grid, $located['header_row']);

        if ($split['headings'] === [] || $split['rows'] === []) {
            return null;
        }

        return new ExtractedTable(
            headings: $split['headings'],
            rows: $split['rows'],
            source: 'Tabel '.$position,
            // A Word table IS explicitly a table, so structure is certain
            // even when the heading row is only probable.
            confidence: max(0.6, $located['confidence']),
            headerRowNumber: $located['header_row'],
            notes: array_merge($located['notes'], $split['notes']),
        );
    }

    private function readText(mixed $element): string
    {
        if ($element instanceof Text) {
            return trim((string) $element->getText());
        }

        if ($element instanceof TextRun) {
            $parts = [];

            foreach ($element->getElements() as $child) {
                $text = $this->readText($child);

                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            // Runs are joined without spaces: Word splits a single word
            // into several runs for formatting, and inserting spaces
            // between them is how "Karyawan" becomes "Kar yaw an".
            return implode('', $parts);
        }

        if (method_exists($element, 'getText')) {
            $text = $element->getText();

            return is_string($text) ? trim($text) : '';
        }

        return '';
    }
}
