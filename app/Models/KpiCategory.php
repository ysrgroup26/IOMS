<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class KpiCategory extends Model
{
    use BelongsToCompany;

    /** A null company_id here is the shared built-in default, not an unowned row. */
    public function companyScopeAllowsGlobalRows(): bool
    {
        return true;
    }

    /**
     * True for a shared catalogue template -- a row the whole installation
     * reads and no single customer owns.
     *
     * v2.119.0. The template itself is never rewritten; an administrator
     * changes its effective value for their own Operating Unit through an
     * override, exactly as `PpeType` does (ADR 050).
     */
    public function isSharedDefault(): bool
    {
        return $this->company_id === null;
    }

    /** The built-in this row replaces for its company, if it is an override. */
    public function sourceDefault()
    {
        return $this->belongsTo(self::class, 'source_default_id');
    }

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
        'source_default_id',
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
    /**
     * v2.119.0 -- AND A BUILT-IN THIS COMPANY HAS OVERRIDDEN IS REPLACED,
     * NOT LISTED BESIDE ITS REPLACEMENT.
     *
     * The override lives in the same table with `source_default_id` set, so
     * without this clause a company that re-weighted "Safety Awareness"
     * would see it twice -- the built-in and their own -- with the wrong one
     * still selectable forever. That is the defect ADR 050 was written to
     * avoid on PPE, and the reason the hiding belongs HERE: this scope is
     * the one place the Dashboard, Reports, KPI input and report
     * configurations all read the catalogue through, so they cannot
     * disagree about which row is in force.
     */
    public function scopeVisibleForCompany($query, ?int $companyId)
    {
        if ($companyId) {
            return $query
                ->where(function ($q) use ($companyId) {
                    $q->whereNull('company_id')->orWhere('company_id', $companyId);
                })
                ->whereNotIn('id', static::overriddenTemplateIds($companyId));
        }

        /*
         * "All companies": only the shared catalogue, since mixing several
         * companies' distinct KPI sets into one view is not meaningful. No
         * override is in force here either -- an override belongs to one
         * company, and this view belongs to none.
         */
        return $query->whereNull('company_id');
    }

    /**
     * The built-in ids this company has replaced.
     *
     * Returned as a subquery rather than a collection of ids so the filter
     * stays one statement, and read WITHOUT the global scopes because the
     * question is about rows this company owns, asked while the caller may
     * be scoped to something else entirely.
     */
    public static function overriddenTemplateIds(int $companyId)
    {
        return static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('source_default_id')
            ->select('source_default_id');
    }

    /**
     * The same replacement rule for the surfaces that are deliberately
     * CROSS-COMPANY, and therefore have no single company to ask about.
     *
     * Quick Attendance (KPI input) spans departments and companies by
     * design, Settings lists the whole catalogue, and the Management
     * workspace reads across the tenant. On all three, a built-in that this
     * tenant has overridden must still be replaced rather than shown beside
     * its replacement -- otherwise the one screen a customer configures
     * their KPIs on is the one screen that shows each overridden category
     * twice.
     *
     * "Overridden" is judged against the companies the CURRENT QUERY can
     * already see, so another tenant's override never hides a built-in from
     * anybody: the subquery runs through the ordinary scopes, which are
     * tenant-bounded, rather than `withoutGlobalScopes()`.
     */
    public function scopeWithoutSupersededTemplates($query)
    {
        $table = $query->getModel()->getTable();

        return $query->whereNotExists(function ($exists) use ($table) {
            $exists->selectRaw('1')
                ->from($table.' as override')
                ->whereColumn('override.source_default_id', $table.'.id')
                ->whereIn('override.company_id', Company::query()->select('id'));
        });
    }

    /**
     * The catalogue in force for one company: its own rows and additions,
     * plus every built-in it has not overridden.
     *
     * Named to match `PpeType::scopeEffectiveForCompany()` so the two shared
     * catalogues answer the same question with the same word.
     */
    public function scopeEffectiveForCompany($query, int $companyId)
    {
        return $query->visibleForCompany($companyId);
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
