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
     * Whether a specific module key is both granted (Platform ceiling,
     * existing mechanism) AND the tenant's subscription is currently
     * usable. Does NOT check department/role -- see this class's own doc
     * comment for why that's deliberately left to the existing, separate
     * mechanisms.
     */
    public function tenantCanUseModule(?Tenant $tenant, string $moduleKey): bool
    {
        if (! $this->tenantIsUsable($tenant)) {
            return false;
        }

        return $tenant->modules()->where('key', $moduleKey)->exists();
    }

    /** Same as tenantCanUseModule() but for a workspace key -- see Tenant::workspaces(). */
    public function tenantCanUseWorkspace(?Tenant $tenant, string $workspaceKey): bool
    {
        if (! $this->tenantIsUsable($tenant)) {
            return false;
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
