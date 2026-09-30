<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v2.86.0 -- THE MY WORK USER RESTRICTION, ENFORCED ON THE SERVER.
 *
 * A My Work User is sold at a fifth of a Full User's price. That difference
 * only exists if the restriction is real, so it is enforced here, on every
 * request, by route name -- not by hiding menu items, which hides nothing
 * from anyone who types a URL.
 *
 * AN ALLOW-LIST, NOT A DENY-LIST, AND THAT IS THE WHOLE DESIGN.
 *
 * A deny-list of the workspaces this class may not reach would have to be
 * updated every time a route is added, and the failure mode of forgetting is
 * that a cheap account silently gains an expensive capability. An allow-list
 * fails the other way: a new route is unreachable for this class until
 * somebody decides otherwise, which is a visible, fixable problem rather
 * than an invisible, billable one. `config/departments.php` uses the same
 * reasoning for the same reason (see its "EXHAUSTIVE" note).
 *
 * WHAT IS ALLOWED, and why each one:
 *
 *   my-work        the workspace this class is named after
 *   permits-to-work    the field work it exists to do. AUTHORIZATION is
 *                      unchanged: creating a permit still requires
 *                      User::canCreatePtw(), which a My Work User does not
 *                      receive automatically. Reaching the route is not the
 *                      same as being allowed to use it, and both checks stay.
 *   tasks              assigned work
 *   account            their own profile and password
 *   notifications      their own notifications
 *   login/logout/password  authentication itself
 *   home/dashboard     the landing redirect, which sends them to My Work
 *
 * WHAT IS NOT, explicitly, because the approved model names them: the full
 * operational workspaces, People / HRD, Warehouse Logistics, Management,
 * Admin Space, user management, subscription and billing, tenant
 * administration, and master data.
 *
 * Note that `settings` and `subscription` are absent. They are on
 * EnforceTenantEntitlement's allow-list, because a Full User must always be
 * able to reach the page explaining why their tenant is blocked. A My Work
 * User must not: subscription and billing are named in the approved
 * restriction, and this class never administers the tenant.
 */
class RestrictMyWorkUser
{
    /**
     * Route-name prefixes a My Work User may reach.
     *
     * Matched on the first dot-segment, the same convention
     * EnforceTenantEntitlement already uses, so the two read alike.
     */
    private const ALLOWED_PREFIXES = [
        'my-work',
        'permits-to-work',
        'tasks',
        'account',
        'notifications',
        'logout',
        'login',
        'password',
        'home',
        'dashboard',
        // The field user's own landing redirect resolves through this.
        'verification',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isMyWorkUser()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        // An unnamed route cannot be matched against an allow-list, and the
        // safe answer for this class is no.
        if (! $routeName) {
            abort(403, 'Akun My Work tidak memiliki akses ke halaman ini.');
        }

        $prefix = explode('.', $routeName)[0];

        abort_unless(
            in_array($prefix, self::ALLOWED_PREFIXES, true),
            403,
            'Akun My Work hanya dapat mengakses My Work dan pekerjaan lapangan yang ditugaskan kepada Anda.'
        );

        return $next($request);
    }
}
