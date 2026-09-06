<?php

namespace App\Exports\Concerns;

use App\Services\DocumentEngine;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * v2.51.0 -- the shared Excel formatting foundation.
 *
 * The counterpart to pdf/partials for spreadsheets. Exports had accreted
 * individually: some set document properties, some styled a header row,
 * none froze it, none set print options, and none carried the tenant's
 * identity in the sheet itself. A recipient opening two IOMS exports got
 * two different-looking files.
 *
 * This is a trait rather than a base class on purpose: every export in
 * this codebase already implements a different mix of Maatwebsite
 * concerns (FromArray vs FromCollection vs WithMapping), and forcing them
 * under one parent would mean rewriting working export logic to gain
 * formatting. A trait adds the shared behaviour without touching how any
 * export produces its data -- which matters because these files carry real
 * operational data and a rewrite risks changing it.
 *
 * What it deliberately does NOT do: decorate. A spreadsheet is a working
 * document. Frozen headers, autofilter and sensible print setup make it
 * genuinely easier to use; merged title art three rows deep makes it
 * harder to sort, filter and pivot.
 */
trait IomsSheetFormatting
{
    /** Document properties every IOMS export should carry, using the tenant's own identity. */
    protected function iomsProperties(string $title, ?string $description = null): array
    {
        $identity = app(DocumentEngine::class)->identity();
        $company = $identity['name'] ?? config('ioms.name');

        return [
            'creator' => $company,
            'lastModifiedBy' => $company,
            'title' => $title,
            'description' => $description ?? "{$title} exported from {$company} via IOMS",
            'company' => $company,
        ];
    }

    /**
     * Applies the shared look to a sheet whose header sits on $headerRow.
     *
     * @param  int  $headerRow  1-indexed row holding the column headings
     * @param  bool  $filter     add an autofilter (skip for grouped/sectioned sheets where it would be meaningless)
     */
    protected function applyIomsSheetFormatting(Worksheet $sheet, int $headerRow = 1, bool $filter = true): void
    {
        $lastColumn = $sheet->getHighestDataColumn();
        $lastRow = $sheet->getHighestDataRow();

        if ($lastRow < $headerRow) {
            return;
        }

        $headerRange = "A{$headerRow}:{$lastColumn}{$headerRow}";

        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F2747']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '2166C4']]],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        // Freeze below the header so the columns stay identifiable when a
        // recipient scrolls a few thousand rows -- the single most useful
        // thing an export can do for whoever has to read it.
        $sheet->freezePane('A'.($headerRow + 1));

        if ($filter && $lastRow > $headerRow) {
            $sheet->setAutoFilter($headerRange);
        }

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")
            ->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        // Print setup: A4 landscape, scaled to one page wide (not one page
        // tall -- squashing 900 rows onto one sheet makes it unreadable),
        // with the header repeated on every printed page.
        $setup = $sheet->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);
        $setup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
    }
}
