<?php

namespace App\Exports;

use App\Exports\Concerns\IomsSheetFormatting;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Milestone 3 (Report Center, Task #65). Generic Excel renderer for any
 * Analytics Framework dataset ({labels, values}) -- one class serves
 * every registered dataset (config/analytics.php) rather than one
 * per-module export class, matching the Analytics Framework's own
 * "config entry, not new code" philosophy.
 *
 * v2.51.0: adopts the shared export foundation, so the file carries the
 * tenant's own identity in its document properties and opens with a
 * frozen, filterable header and print-ready page setup. A total row is
 * added because a count breakdown almost always gets summed by hand
 * otherwise -- and it is a real SUM of the rows above it, never a
 * separately-computed figure that could disagree with them.
 */
class AnalyticsDatasetExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithProperties, WithTitle
{
    use IomsSheetFormatting;

    public function __construct(private readonly array $dataset) {}

    public function array(): array
    {
        return array_map(
            fn ($label, $value) => [$label, $value],
            $this->dataset['labels'] ?? [],
            $this->dataset['values'] ?? []
        );
    }

    public function headings(): array
    {
        return ['Category', 'Count'];
    }

    public function title(): string
    {
        return substr($this->dataset['label'] ?? 'Report', 0, 31);
    }

    public function properties(): array
    {
        return $this->iomsProperties($this->dataset['label'] ?? 'Report');
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $this->applyIomsSheetFormatting($sheet);

                $lastRow = $sheet->getHighestDataRow();

                // Only when there is real data below the header.
                if ($lastRow > 1) {
                    $totalRow = $lastRow + 1;
                    $sheet->setCellValue("A{$totalRow}", 'Total');
                    $sheet->setCellValue("B{$totalRow}", "=SUM(B2:B{$lastRow})");
                    $sheet->getStyle("A{$totalRow}:B{$totalRow}")->getFont()->setBold(true);
                }
            },
        ];
    }
}
