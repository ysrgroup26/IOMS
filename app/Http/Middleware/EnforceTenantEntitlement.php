<?php

namespace App\Http\Middleware;

use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1.11.0 (SaaS Finalization Pass, Part 12). The backend authority for
 * "does this tenant's commercial record allow using the product at all
 * right now" -- separate from, and runs alongside, `RestrictDepartmentAccess`
 * (which answers "is THIS user allowed to reach THIS department", not
 * "is the tenant even licensed at all"). Explicit product requirement:
 * "Direct URL access must also be denied if the tenant lacks the
 * entitlement" -- a frontend-only check (hiding a workspace from
 * navigation) is not sufficient, matching this codebase's own long-
 * standing "never rely on frontend filtering alone" rule.
 *
 * Registered globally (same as RestrictDepartmentAccess), but skips:
 * - Unauthenticated requests (nothing to check yet).
 * - Platform Admins (`tenant_id` null) -- they operate ACROSS tenants
 *   from `/platform/*`, which is a completely separate, already-gated
 *   surface (`role:platform_admin` middleware); this class has no
 *   opinion about it.
 * - A small allowlist so a user whose tenant has gone unusable can still
 *   log out, see the global Dashboard (which itself renders a clear
 *   "subscription inactive" state rather than crashing), reach Settings
 *   to see WHY (Tenant Admin's own Subscription tab), and receive
 *   notifications -- being locked out of literally everything, including
 *   the page that explains what's wrong, would be a worse product
 *   experience than the block itself.
 *
 * DEPLOY SAFETY (v1.11.1 update): gated behind
 * `config('saas.enforce_entitlement')`, now default TRUE -- safe as of
 * this pass because `abort_unless(...)` below only fires on
 * `Subscription::isBlocked()`, which is TRUE only for an explicit
 * `suspended`/`cancelled` status (always a deliberate Platform Admin
 * action). It is deliberately NOT true for an expired-by-date or
 * completely missing Subscription row -- exactly the two states a stale
 * `SubscriptionSeeder`-computed `ends_at` (seed time + 1 year, possibly
 * seeded long ago and never refreshed -- see the `public/build` staleness
 * incident in docs/CONVENTIONS.md for the same failure class) could
 * otherwise produce. Those two states instead surface as a "degraded"
 * warning (EntitlementService::tenantIsDegraded(), shown in Settings >
 * Subscription) without blocking anything. Still overridable per-install
 * via `SAAS_ENFORCE_ENTITLEMENT=false` in `.env`.
 */
class EnforceTenantEntitlement
{
    private const ALLOWLIST_PREFIXES = [
        'dashboard', 'home', 'settings', 'logout', 'login', 'password',
        'notifications', 'work-center', 'search',
    ];

    public function handle(Request $request, Closure $next, EntitlementService $entitlements): Response
    {
        if (! config('saas.enforce_entitlement', false)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || $user->tenant_id === null) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $prefix = $routeName ? explode('.', $routeName)[0] : null;

        if (! $prefix || in_array($prefix, self::ALLOWLIST_PREFIXES, true)) {
            return $next($request);
        }

        abort_unless(
            $entitlements->tenantIsUsable($user->tenant),
            403,
            'Your organization\'s subscription is not currently active. Contact your administrator or see Settings for details.'
        );

        return $next($request);
    }
}
