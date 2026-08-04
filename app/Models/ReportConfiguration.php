<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Report Configuration foundation (v1.6.7 Tasks 3/4). Represents a saved
 * "which KPIs, grouped how, exported as what" preference -- not yet
 * exposed through any UI (no controller/routes exist for this yet; see
 * ROADMAP.md for the planned Settings > Report Configuration page this
 * is built for). Deliberately additive: KpiReportService does not depend
 * on this table existing, so nothing breaks if no configurations are
 * ever created.
 */
class ReportConfiguration extends Model
{
    public const GROUP_BY_DEPARTMENT = 'department';

    public const GROUP_BY_MONTH = 'month';

    public const GROUP_BY_COMPANY = 'company';

    public const EXPORT_EXCEL = 'excel';

    public const EXPORT_PDF = 'pdf';

    public const EXPORT_BOTH = 'both';

    protected $fillable = [
        'company_id',
        'name',
        'kpi_category_ids',
        'group_by',
        'export_type',
        'is_default',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kpi_category_ids' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Resolves the actual KpiCategory records this configuration
     * references, in the saved order -- what a future report-generation
     * step would actually iterate over instead of
     * KpiCategory::visibleForCompany() unconditionally.
     */
    public function kpiCategories()
    {
        return KpiCategory::whereIn('id', $this->kpi_category_ids)
            ->orderByRaw('FIELD(id, '.implode(',', array_map('intval', $this->kpi_category_ids ?: [0])).')')
            ->get();
    }

    public function scopeVisibleForCompany($query, ?int $companyId)
    {
        if ($companyId) {
            return $query->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            });
        }

        return $query->whereNull('company_id');
    }
}
