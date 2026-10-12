<?php

namespace App\Services\Documents\Extraction;

use App\Services\Documents\Contracts\DocumentExtractor;
use App\Services\Documents\FormatDetector;
use App\Services\Documents\Support\ExtractedDocument;
use RuntimeException;

/**
 * v2.110.0 -- OCR, AND WHY IT IS OFF.
 *
 * A scanned PDF has no text layer, so no pure-PHP library can read it:
 * OCR needs a trained engine, which means a binary. The realistic
 * open-source options are Tesseract (usually with OCRmyPDF and
 * Ghostscript) -- all system packages, installed with root.
 *
 * IOMS deploys to cPanel shared hosting (ADR 027/028), where there is no
 * root, no package manager and, as that ADR records, not even Node.js.
 * So OCR CANNOT be enabled on the current production host, and this class
 * says so rather than pretending.
 *
 * IT IS AN ADAPTER, NOT A STUB. If `tesseract` and `pdftoppm` are present
 * -- on a VPS, in a container, on a developer's machine -- it detects
 * them at runtime and works. Nothing else in the platform changes: the
 * pipeline asks `isAvailable()`, and a scanned PDF either gets OCR or
 * gets an honest "this needs OCR, which is not available here" in the
 * import report.
 *
 * WHAT IT WILL NOT DO: send a customer's document to a cloud OCR API.
 * That would put confidential industrial records (incident reports,
 * medical details, contractor identities) through a third party for a
 * convenience, which this platform's security rules forbid outright.
 *
 * ENABLING IT (VPS or container only):
 *   apt-get install tesseract-ocr tesseract-ocr-ind poppler-utils
 * then set IOMS_OCR_ENABLED=true. Nothing else is required.
 */
class OcrExtractor implements DocumentExtractor
{
    /** Pages beyond this are not OCR'd: it is ~2-5 seconds per page. */
    public const MAX_PAGES = 20;

    public function supports(): array
    {
        return [FormatDetector::PDF];
    }

    public function isAvailable(): bool
    {
        if (! config('documents.ocr.enabled', false)) {
            return false;
        }

        return $this->binary('tesseract') !== null && $this->binary('pdftoppm') !== null;
    }

    public function unavailableReason(): ?string
    {
        if ($this->isAvailable()) {
            return null;
        }

        /*
         * INDONESIAN, because this string is rendered as help text on the
         * Document Library and on the import dialog, and the house
         * language rule puts help text, hints and empty-state guidance in
         * Indonesian (CLAUDE.md, CONVENTIONS.md § the language hierarchy).
         * It also has to be read by the storeman who uploaded the scan,
         * not only by whoever deployed the server -- so it says what to do
         * instead, not just what is missing.
         */
        if (! config('documents.ocr.enabled', false)) {
            return 'OCR tidak diaktifkan pada instalasi ini. Dokumen hasil pindai tidak dapat dibaca '
                .'sebagai data; unggah berkas spreadsheet asalnya, atau masukkan datanya secara manual.';
        }

        return 'OCR sudah diaktifkan, tetapi program tesseract/pdftoppm belum terpasang di server ini. '
            .'Pemasangannya memerlukan akses root, yang tidak tersedia pada shared hosting.';
    }

    public function extract(string $path): ExtractedDocument
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException($this->unavailableReason());
        }

        $workspace = $this->makeWorkspace();

        try {
            // Render pages to greyscale images, then read each one. Both
            // commands take fixed arguments plus one escaped path: no part
            // of a user-supplied name reaches the shell unescaped.
            $this->run(sprintf(
                '%s -r %d -gray -png -f 1 -l %d %s %s',
                escapeshellarg($this->binary('pdftoppm')),
                (int) config('documents.ocr.dpi', 300),
                self::MAX_PAGES,
                escapeshellarg($path),
                escapeshellarg($workspace.'/page')
            ));

            $pages = glob($workspace.'/page*.png') ?: [];
            sort($pages);

            $text = [];

            foreach ($pages as $page) {
                $this->run(sprintf(
                    '%s %s %s -l %s --psm 6',
                    escapeshellarg($this->binary('tesseract')),
                    escapeshellarg($page),
                    escapeshellarg(substr($page, 0, -4)),
                    escapeshellarg((string) config('documents.ocr.language', 'ind+eng'))
                ));

                $out = substr($page, 0, -4).'.txt';

                if (is_file($out)) {
                    $text[] = trim((string) file_get_contents($out));
                }
            }

            return new ExtractedDocument(
                format: FormatDetector::PDF,
                tables: [],
                text: trim(implode("\n\n", $text)),
                warnings: [
                    'Teks ini dihasilkan oleh OCR dan dapat memuat kesalahan baca. Periksa sebelum digunakan.',
                ],
                meta: ['ocr' => true, 'pages_processed' => count($pages)],
            );
        } finally {
            $this->cleanUp($workspace);
        }
    }

    private function makeWorkspace(): string
    {
        $path = storage_path('app/ocr/'.bin2hex(random_bytes(8)));

        if (! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Could not create a temporary OCR workspace.');
        }

        return $path;
    }

    /** Temporary page images carry customer data and are never left behind. */
    private function cleanUp(string $workspace): void
    {
        foreach (glob($workspace.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($workspace);
    }

    private function run(string $command): void
    {
        exec($command.' 2>&1', $output, $exit);

        if ($exit !== 0) {
            throw new RuntimeException('OCR step failed: '.mb_substr(implode(' ', $output), 0, 200));
        }
    }

    private function binary(string $name): ?string
    {
        $found = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return $found !== '' ? $found : null;
    }
}
