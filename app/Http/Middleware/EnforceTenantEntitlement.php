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
 * DEPLOY SAFETY: gated behind `config('saas.enforce_entitlement')`,
 * default `false`. Reason: `SubscriptionSeeder` gives an existing
 * installation's tenant an active Subscription with `ends_at =
 * (seed time) + 1 year` -- if that seeder was run once, long ago, in a
 * production environment that (per this project's own confirmed history
 * of seeders/migrations not always being re-run -- see the `public/build`
 * staleness incident in docs/CONVENTIONS.md) may never have been
 * refreshed since, `ends_at` could already be in the past RIGHT NOW.
 * Enabling this middleware without first confirming the real tenant's
 * Subscription dates via the new Platform Admin Subscriptions view would
 * risk an immediate, total production lockout on deploy -- the opposite
 * of "production readiness". Flip `SAAS_ENFORCE_ENTITLEMENT=true` in
 * `.env` only after verifying (or correcting) that record.
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
