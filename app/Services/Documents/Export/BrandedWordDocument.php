<?php

namespace App\Services\Documents\Export;

use App\Models\CompanySetting;
use App\Services\DocumentEngine;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * v2.110.0 -- DOCX EXPORT, WHICH IOMS HAD NONE OF.
 *
 * Every document IOMS produced was a PDF or a spreadsheet. A customer who
 * needs to edit a report before sending it -- add a paragraph, attach it
 * to a letter, put it on a tender -- had to retype it.
 *
 * WHY PHPWORD AND NOT CARBONE OR DOCXTEMPLATER. Both are excellent and
 * both are Node.js; Carbone additionally shells out to LibreOffice for
 * its PDF path. The production host is cPanel shared hosting with no
 * Node, no JVM and no LibreOffice (docs/ADR/028 records that `node` and
 * `npm` are not installed at all). PhpWord is pure PHP, LGPL-3.0, needs
 * only the dom/gd/zip/xml extensions, and is already a sibling of the
 * PhpSpreadsheet this project depends on.
 *
 * BUILT, NOT TEMPLATED, AND THAT IS A DELIBERATE TRADE. PhpWord's
 * `TemplateProcessor` can fill placeholders in a .docx a customer
 * designed, which is the better answer for a fixed corporate form. It is
 * also brittle for REPEATING data -- cloning table rows by placeholder
 * name -- and every document in this library is a register. So the
 * structure is composed here and the BRANDING is resolved per tenant,
 * which keeps one code path for documents whose length is unknown.
 * `TemplateProcessor` remains available for a future customer-supplied
 * letterhead without changing this class.
 */
class BrandedWordDocument
{
    private PhpWord $word;

    private Section $section;

    public function __construct(private readonly DocumentEngine $documents)
    {
        $this->word = new PhpWord;

        $this->word->setDefaultFontName('Arial');
        $this->word->setDefaultFontSize(10);

        $this->section = $this->word->addSection([
            'marginTop' => 900, 'marginBottom' => 900,
            'marginLeft' => 900, 'marginRight' => 900,
        ]);

        $this->registerStyles();
    }

    private function registerStyles(): void
    {
        $this->word->addTitleStyle(1, ['bold' => true, 'size' => 14], ['spaceAfter' => 120]);
        $this->word->addTitleStyle(2, ['bold' => true, 'size' => 11], ['spaceBefore' => 200, 'spaceAfter' => 80]);

        $this->word->addTableStyle('IomsTable', [
            'borderColor' => 'D1D5DB',
            'borderSize' => 6,
            'cellMargin' => 60,
            'width' => 100 * 50,
            'unit' => TblWidth::PERCENT,
        ], [
            'bgColor' => '1E3A8A',
        ]);
    }

    /**
     * The tenant's letterhead: logo when there is one, company name
     * always.
     *
     * MISSING LOGO IS A SUPPORTED STATE, not an error. A tenant who has
     * not uploaded one gets a clean typographic header, which is the
     * professional fallback the brief asks for.
     *
     * The logo is read from the local disk path rather than a URL: a
     * document generator must not make an HTTP request to render, and a
     * URL would also be reachable only if the asset were public.
     */
    public function letterhead(string $title, array $refs = []): self
    {
        $identity = $this->documents->identity();

        $header = $this->section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $header->addRow();

        $logoCell = $header->addCell(2200);
        $logoPath = $this->logoPath($identity);

        if ($logoPath !== null) {
            try {
                $logoCell->addImage($logoPath, ['height' => 48, 'alignment' => Jc::START]);
            } catch (\Throwable) {
                // A corrupt or unreadable logo must not cost the document.
                $logoPath = null;
            }
        }

        $textCell = $header->addCell(7800);
        $textCell->addText(
            htmlspecialchars((string) ($identity['name'] ?? config('ioms.name')), ENT_QUOTES),
            ['bold' => true, 'size' => 13]
        );

        foreach ([
            trim(implode(', ', array_filter([$identity['address'] ?? null, $identity['locality'] ?? null]))),
            implode(' | ', array_filter([
                ($identity['phone'] ?? null) ? 'T '.$identity['phone'] : null,
                $identity['email'] ?? null,
                $identity['website'] ?? null,
            ])),
        ] as $line) {
            if ($line !== '') {
                $textCell->addText(htmlspecialchars($line, ENT_QUOTES), ['size' => 8, 'color' => '666666']);
            }
        }

        $this->section->addTextBreak(1);
        $this->section->addTitle(htmlspecialchars(mb_strtoupper($title), ENT_QUOTES), 1);

        $visibleRefs = array_filter($refs, fn ($v) => $v !== null && $v !== '');

        if ($visibleRefs !== []) {
            foreach ($visibleRefs as $label => $value) {
                $this->section->addText(
                    htmlspecialchars($label.': '.$value, ENT_QUOTES),
                    ['size' => 9, 'color' => '444444']
                );
            }
        }

        $this->section->addTextBreak(1);

        // Page numbering lives in the footer, where Word expects it.
        $footer = $this->section->addFooter();
        $footerText = $footer->addTextRun(['alignment' => Jc::CENTER]);
        $footerText->addText('Halaman ', ['size' => 8, 'color' => '888888']);
        // `addField()`'s fourth argument is TEXT, not a font style --
        // passing a style array there throws "Invalid text". The run's own
        // style carries the formatting instead.
        $footerText->addField('PAGE');
        $footerText->addText(' dari ', ['size' => 8, 'color' => '888888']);
        $footerText->addField('NUMPAGES');

        return $this;
    }

    public function heading(string $text): self
    {
        $this->section->addTitle(htmlspecialchars($text, ENT_QUOTES), 2);

        return $this;
    }

    public function paragraph(string $text): self
    {
        $this->section->addText(htmlspecialchars($text, ENT_QUOTES), ['size' => 10], ['spaceAfter' => 80]);

        return $this;
    }

    /**
     * A label/value block -- the shape most of these documents' header
     * sections actually are.
     *
     * @param  array<string, string|null>  $fields
     */
    public function fields(array $fields): self
    {
        $table = $this->section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT]);

        foreach ($fields as $label => $value) {
            $table->addRow();
            $table->addCell(3000)->addText(htmlspecialchars($label, ENT_QUOTES), ['bold' => true, 'size' => 9]);
            $table->addCell(7000)->addText(
                htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES),
                ['size' => 9]
            );
        }

        $this->section->addTextBreak(1);

        return $this;
    }

    /**
     * @param  list<string>  $headings
     * @param  iterable<array<int, string|null>>  $rows
     */
    public function table(array $headings, iterable $rows): self
    {
        $table = $this->section->addTable('IomsTable');

        $table->addRow(null, ['tblHeader' => true]);

        foreach ($headings as $heading) {
            $table->addCell(null, ['bgColor' => '1E3A8A'])->addText(
                htmlspecialchars((string) $heading, ENT_QUOTES),
                ['bold' => true, 'color' => 'FFFFFF', 'size' => 9]
            );
        }

        $empty = true;

        foreach ($rows as $row) {
            $empty = false;
            $table->addRow();

            foreach ($headings as $index => $ignored) {
                $value = $row[$index] ?? null;
                $table->addCell()->addText(
                    htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES),
                    ['size' => 9]
                );
            }
        }

        if ($empty) {
            $table->addRow();
            $table->addCell(null, ['gridSpan' => count($headings)])
                ->addText('Tidak ada data untuk ditampilkan.', ['size' => 9, 'italic' => true, 'color' => '888888']);
        }

        $this->section->addTextBreak(1);

        return $this;
    }

    /**
     * Signature blocks, rendered only for the parties actually supplied.
     *
     * @param  list<array{role: string, name: string|null}>  $signatories
     */
    public function signatures(array $signatories): self
    {
        if ($signatories === []) {
            return $this;
        }

        $this->section->addTextBreak(1);

        $table = $this->section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT]);
        $table->addRow();

        foreach ($signatories as $signatory) {
            $cell = $table->addCell(null, ['valign' => 'top']);
            $cell->addText(htmlspecialchars($signatory['role'], ENT_QUOTES), ['size' => 9, 'bold' => true]);
            $cell->addTextBreak(3);
            $cell->addText(
                htmlspecialchars($signatory['name'] ?? '(..................)', ENT_QUOTES),
                ['size' => 9, 'underline' => 'single']
            );
        }

        return $this;
    }

    private function logoPath(array $identity): ?string
    {
        $stored = CompanySetting::get('company_logo_path');

        if (! $stored) {
            return null;
        }

        $path = storage_path('app/public/'.$stored);

        return is_file($path) && is_readable($path) ? $path : null;
    }

    public function save(string $path): string
    {
        IOFactory::createWriter($this->word, 'Word2007')->save($path);

        return $path;
    }

    public function download(string $filename): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'ioms-docx-').'.docx';
        $this->save($path);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }
}
