<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Milestone 3 (Analytics Framework, Task #64). Reads `config/analytics.php`'s
 * dataset registry -- the ONLY place that should build ad-hoc aggregation
 * queries for dashboard/report charts. A dashboard, Report Center export,
 * or future widget calls `dataset($key)`/`trend($key)`, never queries a
 * model directly for chart data; adding a new chart to any module means
 * adding a config entry, not a new controller method.
 *
 * Tenant safety: every dataset is scoped to `company_id IN (<this
 * tenant's companies>)`, using `Company::pluck('id')` -- Company already
 * carries `TenantScope` (Milestone 2), so this list is automatically
 * just the current tenant's companies with zero extra tenant-matching
 * code here. Datasets whose model has no `company_id` column at all
 * would need a bespoke join to scope safely; none of the registered
 * datasets today are in that position (all ten have `company_id`).
 */
class AnalyticsService
{
    /**
     * All datasets visible to the current request: registered datasets
     * whose module_key is either null (always visible) or present in
     * $enabledModuleKeys (the same granted+enabled set every sidebar
     * item is already filtered by).
     */
    public function available(array $enabledModuleKeys = []): array
    {
        return collect(config('analytics'))
            ->filter(fn (array $def) => $def['module_key'] === null || in_array($def['module_key'], $enabledModuleKeys, true))
            ->map(fn (array $def, string $key) => [
                'key' => $key,
                'label' => $def['label'],
                'chart' => $def['chart'],
            ])
            ->values()
            ->all();
    }

    /**
     * A single dataset as chart-ready {labels, values}. For a
     * group_by-less dataset (e.g. a pure trend line), returns the
     * monthly trend instead -- there's nothing else to group by.
     */
    public function dataset(string $key): array
    {
        $def = $this->definition($key);

        if (! $def['group_by']) {
            return $this->trend($key);
        }

        $companyIds = Company::query()->pluck('id');

        /** @var \Illuminate\Database\Eloquent\Builder $query */
        $query = $this->scopeToTenant($def['model']::query(), $def, $companyIds);

        $rows = $query
            ->selectRaw("{$def['group_by']} as bucket, COUNT(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $names = ! empty($def['label_model'])
            ? $def['label_model']::query()->whereIn('id', $rows->keys()->filter())->pluck('name', 'id')
            : collect();

        return [
            'label' => $def['label'],
            'chart' => $def['chart'],
            'labels' => $rows->keys()->map(fn ($v) => $v === null ? 'Unassigned' : ($names[$v] ?? (string) $v))->all(),
            'values' => $rows->values()->all(),
        ];
    }

    /**
     * Last 6 months, by count, for datasets that declare a date_field.
     * Used directly for group_by-less datasets (goods receipts trend)
     * and available to any dataset for a "trend" view even if it also
     * supports a snapshot breakdown.
     */
    public function trend(string $key, int $months = 6): array
    {
        $def = $this->definition($key);

        if (! $def['date_field']) {
            return ['label' => $def['label'], 'chart' => 'line', 'labels' => [], 'values' => []];
        }

        $companyIds = Company::query()->pluck('id');
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $rows = $this->scopeToTenant($def['model']::query(), $def, $companyIds)
            ->where($def['date_field'], '>=', $start)
            ->selectRaw("DATE_FORMAT({$def['date_field']}, '%Y-%m') as bucket, COUNT(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $labels = [];
        $values = [];
        for ($i = 0; $i < $months; $i++) {
            $monthKey = $start->copy()->addMonths($i)->format('Y-m');
            $labels[] = $start->copy()->addMonths($i)->format('M Y');
            $values[] = (int) ($rows[$monthKey] ?? 0);
        }

        return ['label' => $def['label'].' -- Trend', 'chart' => 'line', 'labels' => $labels, 'values' => $values];
    }

    /**
     * See config/analytics.php's doc comment on `company_via` -- most
     * models scope directly via their own `company_id` column; a few
     * (Milestone, GoodsReceipt) only carry it transitively through a
     * parent relation and need whereHas() instead.
     */
    private function scopeToTenant(\Illuminate\Database\Eloquent\Builder $query, array $def, $companyIds): \Illuminate\Database\Eloquent\Builder
    {
        if (! empty($def['company_via'])) {
            return $query->whereHas($def['company_via'], fn ($q) => $q->whereIn('company_id', $companyIds));
        }

        return $query->whereIn('company_id', $companyIds);
    }

    private function definition(string $key): array
    {
        $def = config('analytics')[$key] ?? null;

        abort_if($def === null, 404, "Unknown analytics dataset [{$key}].");

        return $def;
    }
}
