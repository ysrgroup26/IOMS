<?php

namespace App\Services\Documents;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * v2.110.0 -- WHAT THIS FILE ACTUALLY IS, NOT WHAT IT IS CALLED.
 *
 * An extension is a claim the uploader makes; the first bytes are
 * evidence. A `.xlsx` that is really a ZIP of something else, or a `.pdf`
 * that is an executable, must be refused before any parser is pointed at
 * it -- a parser handed the wrong format is the most reliable way to turn
 * a malformed upload into an unhandled exception, and sometimes worse.
 *
 * OOXML (xlsx/docx) IS A ZIP, so the signature alone cannot tell them
 * apart. The archive's own content types are read to decide, which is
 * also how a ZIP that is neither gets rejected rather than guessed at.
 *
 * MACROS ARE NEVER RUN, and macro-enabled formats are not accepted at
 * all: `.xlsm`/`.docm` are refused here rather than parsed with macros
 * ignored, because "we did not execute it" is a weaker promise than "we
 * did not accept it". Neither PhpSpreadsheet nor PhpWord executes VBA in
 * any case; this is the belt to that braces.
 */
class FormatDetector
{
    public const XLSX = 'xlsx';

    public const XLS = 'xls';

    public const CSV = 'csv';

    public const DOCX = 'docx';

    public const PDF = 'pdf';

    /** Every format the platform will open. Anything else is refused. */
    public const SUPPORTED = [self::XLSX, self::XLS, self::CSV, self::DOCX, self::PDF];

    /**
     * @return string|null one of SUPPORTED, or null when the file is not
     *                     a format this platform accepts
     */
    public function detect(string $path, ?string $originalName = null): ?string
    {
        if (! is_file($path) || filesize($path) === 0) {
            return null;
        }

        $head = (string) file_get_contents($path, false, null, 0, 8);

        // --- PDF -------------------------------------------------------
        if (str_starts_with($head, '%PDF-')) {
            return self::PDF;
        }

        // --- legacy Excel (OLE2 compound file) -------------------------
        if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return self::XLS;
        }

        // --- OOXML: a ZIP that has to be opened to be identified -------
        if (str_starts_with($head, "PK\x03\x04")) {
            return $this->detectOoxml($path);
        }

        /*
         * --- CSV, which has no signature at all --------------------------
         *
         * So it is accepted only when the NAME claims it and the content
         * is plausibly delimited text. "Plausibly" is deliberately weak:
         * a one-column CSV is legal and has no delimiter, so the test is
         * that the first kilobyte is text, not that it contains a comma.
         */
        $extension = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt'], true) && $this->looksLikeText($path)) {
            return self::CSV;
        }

        return null;
    }

    public function detectUploaded(UploadedFile $file): ?string
    {
        return $this->detect($file->getRealPath(), $file->getClientOriginalName());
    }

    /**
     * Distinguishes xlsx from docx from "a ZIP we will not open", using
     * the package's own manifest rather than the file name.
     */
    private function detectOoxml(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $contentTypes = $zip->getFromName('[Content_Types].xml');

            if ($contentTypes === false) {
                return null; // a ZIP, but not an Office package
            }

            /*
             * Macro-enabled packages are refused outright. Their content
             * type is the ONLY reliable marker -- the extension can be
             * renamed and the ZIP structure is otherwise identical.
             */
            if (str_contains($contentTypes, 'ms-excel.sheet.macroEnabled')
                || str_contains($contentTypes, 'ms-word.document.macroEnabled')) {
                return null;
            }

            if (str_contains($contentTypes, 'spreadsheetml.sheet.main')) {
                return self::XLSX;
            }

            if (str_contains($contentTypes, 'wordprocessingml.document.main')) {
                return self::DOCX;
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    /** First kilobyte is readable text rather than binary. */
    private function looksLikeText(string $path): bool
    {
        $sample = (string) file_get_contents($path, false, null, 0, 1024);

        if ($sample === '') {
            return false;
        }

        // A NUL byte in the first kilobyte means binary in every text
        // encoding this platform accepts.
        if (str_contains($sample, "\0")) {
            return false;
        }

        return mb_check_encoding($sample, 'UTF-8') || mb_check_encoding($sample, 'ISO-8859-1');
    }

    /** Human label for a detected format, for messages and reports. */
    public function label(?string $format): string
    {
        return match ($format) {
            self::XLSX => 'Excel Workbook (.xlsx)',
            self::XLS => 'Excel 97-2003 Workbook (.xls)',
            self::CSV => 'CSV / delimited text',
            self::DOCX => 'Word Document (.docx)',
            self::PDF => 'PDF Document',
            default => 'Unsupported file type',
        };
    }
}
