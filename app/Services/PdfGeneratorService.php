<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

/**
 * Reusable PDF generation service (v1.6.8). Every document (Material
 * Request, PPE Replacement Request, and future Daily Report / Incident
 * Report / PTW forms) goes through this same service rather than each
 * controller calling the PDF library directly -- the actual document
 * layout lives entirely in a Blade view per document type
 * (resources/views/pdf/*.blade.php), and this class is just the thin,
 * consistent wrapper around rendering + streaming/downloading it.
 *
 * Deliberately built around plain Blade + CSS (not a canvas-drawing
 * approach) specifically because the stated goal is "compatibility with
 * existing company paperwork" -- HTML/CSS is the fastest, most flexible
 * way to match a traditional form layout, and it's the same skill
 * (writing a Blade view) needed for every future document type this is
 * meant to support. Company document templates (letterhead-per-company)
 * are an explicitly deferred future feature -- this service takes a
 * `company` in its data so a future version can swap in a per-company
 * template without changing this class at all.
 */
class PdfGeneratorService
{
    /**
     * Render a Blade view to PDF and return it as a browser-viewable
     * (inline) response -- what "Print" and "view before downloading"
     * both want.
     */
    public function streamInline(string $view, array $data, string $filename): Response
    {
        return Pdf::loadView($view, $data)
            ->setPaper('a4', 'portrait')
            ->stream($filename);
    }

    /**
     * Render a Blade view to PDF and force a file download.
     */
    public function download(string $view, array $data, string $filename): Response
    {
        return Pdf::loadView($view, $data)
            ->setPaper('a4', 'portrait')
            ->download($filename);
    }
}
