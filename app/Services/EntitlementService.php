<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * v1.11.0 (SaaS Finalization Pass). The single, central authority for
 * "is this tenant actually allowed to use the product/a given module
 * right now" -- deliberately does NOT duplicate `Tenant::modules()`/
 * `workspaces()` (the existing Platform-grant mechanism from Milestone 3,
 * UAT #4/#5) or `RestrictDepartmentAccess`/`config('departments')` (the
 * existing department-scope mechanism). It composes them:
 *
 *   Tenant entitlement (this service, NEW: subscription usability)
 *     AND
 *   Module/workspace grant (Tenant::modules()/workspaces(), EXISTING)
 *     AND
 *   Department scope (RestrictDepartmentAccess, EXISTING)
 *     AND
 *   Role capability (User::canManageX(), EXISTING)
 *   = access granted
 *
 * This class only ever answers the FIRST question. It never reimplements
 * the other three -- callers (HandleInertiaRequests, middleware) apply
 * all four independently, exactly as they already did before this class
 * existed, with this one added as an additional AND condition.
 */
class EntitlementService
{
    /**
     * v1.11.1 (Final Production Readiness Pass, Part 15): whether the
     * tenant's commercial record BLOCKS access right now -- only TRUE for
     * an explicitly `suspended`/`cancelled` Subscription (see
     * Subscription::isBlocked()'s own doc comment for why this changed
     * from the previous, stricter version). A tenant with NO Subscription
     * row at all (UNCONFIGURED -- shouldn't happen post-
     * TenantGrantSeeder/PlatformController::storeTenant(), but is a real
     * possibility for data that predates this feature) is deliberately
     * NOT blocked -- "missing commercial record" is far more likely to be
     * a data gap than an actual delinquent tenant, and blocking on it by
     * default is exactly the kind of stale-data-triggered lockout this
     * service exists to avoid. Same reasoning for an expired-but-not-
     * suspended record.
     */
    public function tenantIsUsable(?Tenant $tenant): bool
    {
        if (! $tenant) {
            return false;
        }

        $subscription = $tenant->subscription;

        return $subscription === null || $subscription->isUsable();
    }

    /** Whether the tenant's Subscription is expired/unconfigured -- true even when NOT blocked, so the frontend can show a warning without hard-blocking anything. */
    public function tenantIsDegraded(?Tenant $tenant): bool
    {
        if (! $tenant) {
            return false;
        }

        $subscription = $tenant->subscription;

        return $subscription === null || $subscription->isDegraded();
    }

    /**
     * v2.13.0 (SaaS Phase 1 -- Subscription Architecture & Entitlement
     * Enforcement). "Ungranted" safety net, the missing piece that makes
     * `config('saas.enforce_workspace_entitlement')` finally safe to turn
     * on. Before this pass, a tenant with ZERO rows in `tenant_modules`/
     * `tenant_workspaces` (never explicitly provisioned -- the exact
     * "legacy tenant predates this feature" case Part 25 of this phase's
     * own directive asks to handle safely) would fail EVERY
     * `tenantCanUseModule()`/`tenantCanUseWorkspace()` check once
     * enforcement is enabled, i.e. total lockout -- the opposite of
     * today's actual behavior (unenforced, so an ungranted tenant
     * currently reaches everything its role/department allows). That is
     * exactly the "Do NOT simply deny everyone" failure this phase's
     * directive explicitly forbids.
     *
     * Fixed by treating "this tenant has no grant rows for this kind of
     * entitlement at all" as fully granted (skip the allow-list check
     * entirely) -- a genuinely PROVISIONED tenant (has at least one row,
     * whether from `PlatformController::storeTenant()`'s package-derived
     * sync or `TenantGrantSeeder`'s "grant everything" seed) is
     * unaffected and still uses the real allow-list. This is not a
     * bypass for a chosen tenant -- it's a uniform, auditable default
     * ("no explicit grants recorded yet" = "not yet restricted") applied
     * identically to every tenant, and it disappears the moment any
     * grant row exists for that tenant, at which point the real
     * allow-list takes over completely (including correctly DENYING
     * anything not in that tenant's own granted set).
     */
    public function tenantCanUseModule(?Tenant $tenant, string $moduleKey): bool
    {
        if (! $this->tenantIsUsable($tenant)) {
            return false;
        }

        if (! $tenant->modules()->exists()) {
            return true;
        }

        return $tenant->modules()->where('key', $moduleKey)->exists();
    }

    /** Same as tenantCanUseModule() but for a workspace key -- see Tenant::workspaces() and this method's own doc comment above for the "ungranted tenant" safety net. */
    public function tenantCanUseWorkspace(?Tenant $tenant, string $workspaceKey): bool
    {
        if (! $this->tenantIsUsable($tenant)) {
            return false;
        }

        if (! $tenant->workspaces()->exists()) {
            return true;
        }

        return $tenant->workspaces()->where('key', $workspaceKey)->exists();
    }

    /**
     * A short, user-facing reason string for why access is currently
     * blocked -- used by the frontend to distinguish "not built yet"
     * from "not included in your plan" from "subscription issue", per
     * the explicit product requirement that those three must never look
     * identical to a user.
     */
    public function blockedReason(?Tenant $tenant): ?string
    {
        if (! $tenant) {
            return 'no_tenant';
        }

        $subscription = $tenant->subscription;

        if (! $subscription) {
            return 'no_subscription';
        }

        if (in_array($subscription->status, ['suspended', 'cancelled'], true)) {
            return $subscription->status;
        }

        if ($subscription->isExpired()) {
            return $subscription->status === 'trial' ? 'trial_expired' : 'expired';
        }

        return null;
    }
}
