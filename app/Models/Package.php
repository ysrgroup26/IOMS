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
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_custom' => 'boolean',
        ];
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /*
     * v2.60.0 -- `hasFeature()` AND `packages.features` ARE GONE.
     *
     * The column held a third list of what a plan includes, alongside the
     * workspace grant and the module grant. `hasFeature()` was its only
     * reader and a repository-wide search found NOT ONE call site: no
     * controller, no middleware, no policy, no Blade view, no JSX, no
     * test. It gated nothing.
     *
     * It was removed rather than left dormant because a dead entitlement
     * source is not harmless -- it is a plausible-looking answer to
     * "what does this plan include" sitting next to the real one, and the
     * next person to need a feature flag would have reached for it. The
     * server-authoritative chain is, and stays, exactly one path:
     *
     *   config/plans.php -> Package::defaultWorkspaceKeys()/
     *   defaultModuleKeys() -> tenant_workspaces / tenant_modules ->
     *   EntitlementService::grantedWorkspaceKeys() -> route gate + nav
     *
     * The column itself is dropped by the same migration that adds the
     * Business tier.
     */

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
     * The DEPARTMENT workspaces this plan includes, from config/plans.php.
     *
     * Read by both grant paths -- `TenantProvisioningService::activate()`
     * for a self-service purchase and `PlatformController::storeTenant()`
     * for an operator-created tenant -- so the two cannot diverge. Keyed
     * by slug, so renaming a plan's display name never breaks the mapping.
     *
     * v2.58.0 fixed the half of this that hid the application's own chrome
     * from paying customers; v2.60.0 moved the list itself out of a
     * hardcoded `match` whose `default => []` arm gave an unrecognised
     * slug a price and no product.
     */
    public function defaultWorkspaceKeys(): array
    {
        $departments = $this->planScope('workspaces', fn () => Workspace::query()
            ->where('tier', Workspace::TIER_DEPARTMENT)
            ->pluck('key')->all());

        // Global-tier workspaces are the application's own chrome and are
        // never sold -- unioned in here so no plan can omit them and no
        // config entry has to remember to list them. See v2.58.0.
        return array_values(array_unique([...$departments, ...Workspace::globalKeys()]));
    }

    /**
     * v2.60.0 -- the DEPARTMENT workspaces this plan grants, i.e. the part
     * of the grant that actually differs between tiers.
     *
     * `defaultWorkspaceKeys()` is the provisioning answer and must include
     * the global chrome. A pricing card is a different question: listing
     * "Reports" and "Administration" as bullet points under every tier
     * presents the application's own furniture as a purchased feature,
     * which is exactly the confusion config/plans.php refuses to encode.
     * So the pricing surfaces read this instead.
     */
    public function departmentWorkspaceKeys(): array
    {
        return array_values(array_diff($this->defaultWorkspaceKeys(), Workspace::globalKeys()));
    }

    /**
     * The Module grants, from config/plans.php.
     *
     * Deliberately narrower than the workspace layer: most real HSE
     * functionality has no Module key at all and is gated by the workspace
     * grant plus a role check (see config/modules.php). Professional adds
     * nothing here over Starter because HR has no Module rows of its own;
     * Business adds the modules its new departments actually use.
     */
    public function defaultModuleKeys(): array
    {
        return $this->planScope('modules', fn () => Module::query()->pluck('key')->all());
    }

    /**
     * v2.60.0 -- resolves one scope list for THIS plan from
     * `config/plans.php`, replacing a hardcoded `match` whose
     * `default => []` arm meant a mistyped or admin-created slug got a
     * price and no product.
     *
     * `'*'` means "everything that exists", resolved through $all so a
     * department added later is included without editing config.
     *
     * An unknown slug falls back to the configured entry tier rather than
     * to an empty array -- a paid customer must never receive zero
     * departments. If even the fallback is missing (a broken config), the
     * everything-list is used: the operator sees an obviously over-granted
     * tenant, which is recoverable, rather than a customer locked out of a
     * product they paid for, which is not.
     */
    private function planScope(string $kind, callable $all): array
    {
        $map = config("plans.$kind", []);
        $fallbackSlug = config('plans.fallback', 'starter');

        $scope = $map[$this->slug] ?? $map[$fallbackSlug] ?? '*';

        return $scope === '*' ? $all() : $scope;
    }
}
