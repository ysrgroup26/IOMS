<?php

namespace App\Services\Documents;

use App\Services\Documents\Contracts\DocumentExtractor;
use App\Services\Documents\Extraction\OcrExtractor;
use App\Services\Documents\Extraction\PdfExtractor;
use App\Services\Documents\Extraction\SpreadsheetExtractor;
use App\Services\Documents\Extraction\WordExtractor;
use App\Services\Documents\Support\ExtractedDocument;
use App\Services\ImportHeaderMatcher;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * v2.110.0 -- THE FRONT DOOR OF THE DOCUMENT PLATFORM.
 *
 * Stages 1-3 of the documented pipeline, and ONLY those:
 *
 *   1. ingestion and format detection   (FormatDetector, by content)
 *   2. extraction                       (the format's own extractor)
 *   3. source-to-IOMS field mapping     (ImportHeaderMatcher, reused)
 *
 * Stages 4-6 -- business validation, preview confirmation and persistence
 * -- stay in the module that owns the records, deliberately. This class
 * knows nothing about employees, permits or tenants, and must not: the
 * moment a document engine starts deciding what a valid employee is, the
 * tenant rules live in two places and one of them is a parser.
 *
 * REPLACEABLE BY CONSTRUCTION. Every extractor is behind one interface
 * and resolved from a registry. Swapping PhpWord for something else, or
 * adding a format, touches one class and no business logic anywhere.
 */
class DocumentIngestionService
{
    /** @var list<DocumentExtractor> */
    private array $extractors;

    public function __construct(
        private readonly FormatDetector $detector,
        private readonly ImportHeaderMatcher $matcher,
        SpreadsheetExtractor $spreadsheet,
        WordExtractor $word,
        PdfExtractor $pdf,
        private readonly OcrExtractor $ocr,
    ) {
        $this->extractors = [$spreadsheet, $word, $pdf];
    }

    /**
     * Reads an uploaded file into tables, with a suggested mapping for
     * the module it is destined for.
     *
     * @param  string  $moduleKey  a key in config/mapping_fields.php
     * @return array<string, mixed>
     */
    public function inspect(UploadedFile $file, string $moduleKey): array
    {
        $format = $this->detector->detectUploaded($file);

        if ($format === null) {
            throw new RuntimeException(
                'Berkas ini bukan format yang didukung. Format yang diterima: Excel (.xlsx/.xls), '
                .'CSV, Word (.docx), dan PDF.'
            );
        }

        $document = $this->extract($file->getRealPath(), $format);

        $tables = [];

        foreach ($document->tables as $index => $table) {
            $sampleRows = array_slice($table->toAssociativeRows(), 0, 5);

            $tables[] = array_merge($table->toArray(), [
                'index' => $index,
                'mapping' => $this->matcher->suggest($table->headings, $sampleRows, $moduleKey),
                'preview_rows' => array_slice($table->rows, 0, 10),
            ]);
        }

        return [
            'format' => $format,
            'format_label' => $this->detector->label($format),
            'tables' => $tables,
            'text_excerpt' => mb_substr($document->text, 0, 2000),
            'has_text' => $document->text !== '',
            'warnings' => $document->warnings,
            'meta' => $document->meta,
            // Stated so the UI never has to guess why a scan produced
            // nothing, and never implies OCR silently failed.
            'ocr' => [
                'available' => $this->ocr->isAvailable(),
                'reason' => $this->ocr->unavailableReason(),
            ],
        ];
    }

    /**
     * Runs the right extractor for a detected format.
     *
     * A PDF with no text layer is handed to OCR when OCR is genuinely
     * available, and otherwise returns the text extractor's own honest
     * report -- never an empty success.
     */
    public function extract(string $path, string $format): ExtractedDocument
    {
        $extractor = $this->extractorFor($format);

        $document = $extractor->extract($path);

        $needsOcr = ($document->meta['likely_scanned'] ?? false) === true;

        if ($needsOcr && $this->ocr->isAvailable()) {
            return $this->ocr->extract($path);
        }

        return $document;
    }

    private function extractorFor(string $format): DocumentExtractor
    {
        foreach ($this->extractors as $extractor) {
            if (! in_array($format, $extractor->supports(), true)) {
                continue;
            }

            if (! $extractor->isAvailable()) {
                throw new RuntimeException((string) $extractor->unavailableReason());
            }

            return $extractor;
        }

        throw new RuntimeException('No extractor is registered for ['.$format.'].');
    }

    /**
     * What this deployment can actually read, for display on the import
     * screen and in the deployment documentation.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        $formats = [];

        foreach (FormatDetector::SUPPORTED as $format) {
            $available = false;
            $reason = null;

            foreach ($this->extractors as $extractor) {
                if (in_array($format, $extractor->supports(), true)) {
                    $available = $extractor->isAvailable();
                    $reason = $extractor->unavailableReason();
                    break;
                }
            }

            $formats[$format] = [
                'label' => $this->detector->label($format),
                'available' => $available,
                'reason' => $reason,
            ];
        }

        return [
            'formats' => $formats,
            'ocr' => [
                'available' => $this->ocr->isAvailable(),
                'reason' => $this->ocr->unavailableReason(),
            ],
            'max_upload_mb' => (int) config('documents.max_upload_mb'),
            'max_rows' => (int) config('documents.max_rows'),
        ];
    }
}
