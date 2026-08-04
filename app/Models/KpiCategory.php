<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiCategory extends Model
{
    // Fixed category codes per the company's KPI standard (see KpiCategorySeeder).
    public const FATALITY = 'fatality';

    public const LTI = 'lti';

    public const FAC = 'fac';

    public const PPE_VIOLATION = 'ppe_violation';

    public const BBS_NEARMISS = 'bbs_nearmiss';

    public const DRILL = 'drill';

    public const CAMPAIGN = 'campaign';

    public const TBM = 'tbm';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'short_label',
        'description',
        'is_negative',
        'show_on_dashboard',
        'count_in_dashboard_stats',
        'supports_quick_attendance',
        'requires_approval',
        'icon',
        'color',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_negative' => 'boolean',
            'show_on_dashboard' => 'boolean',
            'count_in_dashboard_stats' => 'boolean',
            'supports_quick_attendance' => 'boolean',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected $appends = ['effective_icon', 'effective_color'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function kpiRecords()
    {
        return $this->hasMany(KpiRecord::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeQuickAttendanceEnabled($query)
    {
        return $query->where('supports_quick_attendance', true)->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * The set of categories that should render as Dashboard cards --
     * this, not any hardcoded list, is what the Dashboard iterates over
     * (v1.5.2). Adding a new active category with show_on_dashboard=true
     * makes it appear immediately; disabling either flag removes it,
     * with no code change in either direction.
     */
    public function scopeDashboardVisible($query)
    {
        return $query->where('is_active', true)->where('show_on_dashboard', true)->orderBy('sort_order');
    }

    /**
     * Company-aware KPI configuration (v1.5.0): a category with
     * company_id = null is global (visible to every company); one with
     * company_id set is only visible to that specific company. When
     * $companyId is null (e.g. "All Companies" filter), only global
     * categories are shown -- mixing multiple companies' distinct KPI
     * sets into one combined view wouldn't be meaningful.
     */
    public function scopeVisibleForCompany($query, ?int $companyId)
    {
        if ($companyId) {
            return $query->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            });
        }

        return $query->whereNull('company_id');
    }

    /**
     * Icon/color are optional per spec -- these accessors provide a
     * sensible default (based on is_negative) whenever an admin hasn't
     * set one explicitly, so the Dashboard never has to guess or
     * hardcode a fallback itself.
     */
    public function getEffectiveIconAttribute(): string
    {
        return $this->icon ?: ($this->is_negative ? 'alert-triangle' : 'clipboard-list');
    }

    public function getEffectiveColorAttribute(): string
    {
        return $this->color ?: ($this->is_negative ? '#dc2626' : '#2563eb');
    }
}
