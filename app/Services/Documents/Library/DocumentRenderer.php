<?php

namespace App\Services\Documents\Library;

use App\Services\DocumentEngine;
use App\Services\Documents\Export\BrandedWordDocument;
use App\Services\Documents\Export\BrandedWorkbook;
use App\Services\PdfGeneratorService;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * v2.110.0 -- THE ONE PLACE A DEFINITION BECOMES A FILE.
 *
 * Takes a `DocumentDefinition` and produces XLSX, DOCX or PDF from the
 * SAME rows. The three outputs are different presentations of one query,
 * not three implementations that happen to agree today.
 *
 * THE RENDERER NEVER FILTERS. It asks the definition for rows and prints
 * what it gets. Authorization and tenant scoping belong to the definition
 * and to the controller that resolves it; a renderer that also decided
 * who may see what would be a second, quieter copy of the tenant rules.
 *
 * WHY THESE THREE FORMATS. XLSX because an operations team works in a
 * spreadsheet; PDF because a filed, signed document is what an auditor or
 * a client asks for; DOCX because, before this, a customer who needed to
 * EDIT a report before sending it had to retype it. CSV is deliberately
 * absent: it carries no letterhead, no number formats and no identity,
 * and the modules that genuinely need a raw feed already have one.
 */
class DocumentRenderer
{
    public const XLSX = 'xlsx';

    public const DOCX = 'docx';

    public const PDF = 'pdf';

    public const FORMATS = [self::XLSX, self::DOCX, self::PDF];

    public function __construct(
        private readonly DocumentEngine $documents,
        private readonly PdfGeneratorService $pdf,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function render(DocumentDefinition $definition, string $format, array $filters = []): Response
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new RuntimeException('Unsupported document format ['.$format.'].');
        }

        if (! in_array($format, $definition->formats(), true)) {
            throw new RuntimeException(
                'Document ['.$definition->key().'] is not published in the ['.$format.'] format.'
            );
        }

        /*
         * Materialised ONCE. A definition may return a generator, and a
         * generator cannot be walked twice -- the PDF path iterates the
         * rows for the table and again for the count, and would print an
         * empty table the second time.
         */
        $rows = $this->collect($definition->rows($filters));
        $refs = array_filter($definition->refs($filters), fn ($v) => $v !== null && $v !== '');

        return match ($format) {
            self::XLSX => $this->xlsx($definition, $rows, $refs),
            self::DOCX => $this->docx($definition, $rows, $refs),
            self::PDF => $this->pdfDocument($definition, $rows, $refs),
        };
    }

    /** @return list<array<string, mixed>> */
    private function collect(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = $row;
        }

        return $out;
    }

    private function filename(DocumentDefinition $definition, string $extension): string
    {
        return Str::slug($definition->key()).'-'.now()->format('Ymd').'.'.$extension;
    }

    private function xlsx(DocumentDefinition $definition, array $rows, array $refs): Response
    {
        return (new BrandedWorkbook($this->documents))
            ->addSheet($definition->title(), $definition->title(), $definition->columns(), $rows, $refs)
            ->download($this->filename($definition, 'xlsx'));
    }

    private function docx(DocumentDefinition $definition, array $rows, array $refs): Response
    {
        $columns = $definition->columns();

        $document = (new BrandedWordDocument($this->documents))
            ->letterhead($definition->title(), $refs);

        $document->table(
            array_column($columns, 'label'),
            array_map(
                fn (array $row) => array_map(
                    fn (array $column) => $this->display($row[$column['key']] ?? null, $column['type'] ?? 'text'),
                    $columns
                ),
                $rows
            )
        );

        return $document->download($this->filename($definition, 'docx'));
    }

    private function pdfDocument(DocumentDefinition $definition, array $rows, array $refs): Response
    {
        $columns = $definition->columns();

        // Formatted HERE, not in the view: the Blade template then holds
        // layout only, and the DOCX and PDF of one register cannot format
        // the same column two different ways.
        $printable = array_map(
            fn (array $row) => array_map(
                fn (array $column) => $this->display($row[$column['key']] ?? null, $column['type'] ?? 'text'),
                $columns
            ),
            $rows
        );

        return $this->pdf->streamInline('pdf.document-register', [
            'identity' => $this->documents->identity(),
            'title' => $definition->title(),
            'description' => $definition->description(),
            'columns' => $columns,
            'rows' => $printable,
            'refs' => $refs,
            'generatedAt' => now(),
        ], $this->filename($definition, 'pdf'),
            // The same threshold BrandedWorkbook uses, for the same
            // reason: past six columns a portrait A4 wraps every cell
            // onto two lines and the widest ones leave the page.
            count($columns) > 6 ? 'landscape' : 'portrait'
        );
    }

    /**
     * A value as a reader should see it, for the formats that carry text
     * rather than typed cells.
     *
     * An empty value stays EMPTY. A dash or a zero would be a statement
     * the record does not make: "no recorded join date" and "joined on
     * day zero" are different facts, and a register that printed them the
     * same way would be lying about one of them.
     */
    public function display(mixed $value, string $type = 'text'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d M Y');
        }

        return match ($type) {
            'date' => ($timestamp = strtotime((string) $value)) ? date('d M Y', $timestamp) : (string) $value,
            'number' => number_format((float) $value, 0, ',', '.'),
            'decimal' => number_format((float) $value, 2, ',', '.'),
            'currency' => 'Rp '.number_format((float) $value, 0, ',', '.'),
            'percent' => rtrim(rtrim(number_format((float) $value * 100, 2, ',', '.'), '0'), ',').'%',
            default => (string) $value,
        };
    }
}
