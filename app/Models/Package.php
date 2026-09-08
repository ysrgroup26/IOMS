<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 2 (Package + Subscription). A pricing/feature tier a Tenant
 * can subscribe to. Deliberately NOT tenant-scoped -- packages are the
 * platform operator's own catalog, visible/manageable only from the
 * future Platform Super Admin surface (Task #44), not per-tenant data.
 */
class Package extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_monthly',
        'price_yearly',
        'currency',
        'trial_days',
        'max_users',
        'max_companies',
        'max_ptw_users',
        'features',
        'is_active',
        'is_public',
        'is_custom',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'trial_days' => 'integer',
            'max_ptw_users' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_custom' => 'boolean',
        ];
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->features ?? [], true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** v2.14.0 (SaaS Productization). Plans a tenant should actually be offered on a Plans/pricing comparison page -- excludes both inactive AND internal-only (`is_public=false`) plans. */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * v1.11.15 (SaaS Package + Ecosystem pass, Part 1/26/27). The
     * canonical Package -> Workspace(department)-key mapping the product
     * requirement describes:
     *   Starter      = HSE only, but fully operational.
     *   Professional = HSE + Management + HRD.
     *   Enterprise   = full IOMS (every existing workspace).
     * "Management" isn't a gated workspace of its own (the Main
     * Dashboard is universal, not department-scoped), so Professional's
     * real workspace grant is HSE + HR. Used by
     * `PlatformController::storeTenant()` to actually grant a new
     * tenant's Module/Workspace rows at creation time -- previously
     * `storeTenant()` created the Subscription but never granted
     * anything, so the chosen Package had ZERO effect on what the tenant
     * could actually reach (confirmed by auditing this pass, not
     * assumed -- see `EnforceTenantEntitlement`'s own doc comment).
     * Keyed by slug rather than name so a future rename of the display
     * name doesn't silently break this mapping.
     */
    /**
     * The DEPARTMENT workspaces a plan includes.
     *
     * v2.58.0: global-tier workspaces (Reports, Administration) are unioned
     * in for every plan, and `default` no longer means "nothing". Both were
     * live defects. Reports and Administration are the application's own
     * chrome — Settings, Users, Audit Logs, Report Center — not sold
     * capacity, and listing only departments here is what left every
     * self-service Starter and Professional tenant with an empty sidebar
     * and a 403 on Reports.
     *
     * An unrecognised slug now grants the global keys rather than an empty
     * array. It still records no departments, and `grantedWorkspaceKeys()`
     * reads "no department grants" through the same fail-open rule this
     * codebase applies to every other unconfigured tenant, rather than
     * inventing a second, contradictory policy.
     */
    public function defaultWorkspaceKeys(): array
    {
        $global = Workspace::globalKeys();

        $departments = match ($this->slug) {
            'starter' => ['hse'],
            'professional' => ['hse', 'hr'],
            'enterprise' => Workspace::where('tier', Workspace::TIER_DEPARTMENT)->pluck('key')->all(),
            default => [],
        };

        return array_values(array_unique([...$departments, ...$global]));
    }

    /**
     * Module-level grant is intentionally narrower than workspace-level
     * (see `Module`'s own small, mostly-cross-cutting key set --
     * `config/modules.php`'s own doc comment explains most real HSE
     * functionality has no separate Module key at all, only the
     * workspace/department grant + role capability gate it). Starter
     * gets every Module that's actually HSE-relevant or cross-cutting;
     * Professional adds nothing new at the Module layer (HRD has no
     * Module-table entries of its own yet); Enterprise gets everything.
     */
    public function defaultModuleKeys(): array
    {
        return match ($this->slug) {
            'starter' => ['employees', 'ppe', 'kpi_input', 'reports'],
            'professional' => ['employees', 'ppe', 'kpi_input', 'reports'],
            'enterprise' => Module::pluck('key')->all(),
            default => [],
        };
    }
}
