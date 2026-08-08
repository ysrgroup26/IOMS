<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Milestone 3 (Report Center, Task #65). Generic Excel renderer for any
 * Analytics Framework dataset ({labels, values}) -- one class serves
 * every registered dataset (config/analytics.php) rather than one
 * per-module export class, matching the Analytics Framework's own
 * "config entry, not new code" philosophy.
 */
class AnalyticsDatasetExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
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
}
