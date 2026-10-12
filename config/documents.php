<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document Processing Platform (v2.110.0)
    |--------------------------------------------------------------------------
    |
    | Limits and engine switches for document import and export. Every
    | engine behind these settings is pure PHP and composer-installed:
    | nothing here requires Node.js, Java, LibreOffice or Docker, because
    | IOMS deploys to cPanel shared hosting where none of those exist
    | (docs/ADR/027, docs/ADR/028).
    |
    */

    /*
    | THE LIMIT IS DELIBERATELY LOWER THAN PHP'S OWN.
    |
    | A spreadsheet is expanded in memory many times its file size, and a
    | shared host gives PHP a fixed memory_limit it cannot raise. 15 MB of
    | XLSX is comfortably inside what 256 MB of PHP can open; 50 MB is not.
    | Raise this only after testing against the real host's memory_limit.
    */
    'max_upload_mb' => env('IOMS_DOC_MAX_UPLOAD_MB', 15),

    /* Rows accepted from one import, across all sheets. */
    'max_rows' => env('IOMS_DOC_MAX_ROWS', 20000),

    /* How long an uploaded file may sit in temporary storage. */
    'temp_retention_hours' => env('IOMS_DOC_TEMP_RETENTION_HOURS', 6),

    'ocr' => [
        /*
        | OFF BY DEFAULT, AND IT CANNOT BE TURNED ON ON SHARED HOSTING.
        |
        | OCR needs tesseract + poppler binaries, which need root to
        | install. Turning this on without them changes nothing: the
        | extractor checks for the binaries too, and reports honestly
        | either way. See App\Services\Documents\Extraction\OcrExtractor.
        */
        'enabled' => env('IOMS_OCR_ENABLED', false),
        'language' => env('IOMS_OCR_LANGUAGE', 'ind+eng'),
        'dpi' => env('IOMS_OCR_DPI', 300),
    ],

    /*
    | Confidence below which a mapping is never applied without a human
    | confirming it. The matcher reports how it reached each decision;
    | anything reached by sampling values rather than by reading a heading
    | lands under this line.
    */
    'auto_map_threshold' => 0.75,

];
