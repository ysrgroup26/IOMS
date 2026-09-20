<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v2.74.0 -- AN ACCOUNT WITHOUT AN ORGANIZATION CANNOT REACH THE
 * OPERATIONAL PRODUCT.
 *
 * Before this release the question could not arise: there was no way to
 * be signed in without a tenant unless you were the platform operator.
 * Now that an account can exist on its own, something has to say where it
 * may go -- and that something must fail CLOSED, because the alternative
 * is that any route added in future is reachable by an unsubscribed
 * account until somebody notices.
 *
 * ---------------------------------------------------------------------
 * WHY AN ALLOW-LIST, AND WHY IN THE GLOBAL STACK
 * ---------------------------------------------------------------------
 *
 * Two options were available and only one of them is safe by default.
 *
 * A route-group guard (`Route::middleware(['auth', 'require.organization'])`)
 * protects exactly the routes somebody remembered to put inside it. This
 * codebase has a documented production outage from getting a route group
 * membership wrong in the other direction (v1.9.x, Material Request
 * nested inside `role:super_admin`), and three separate incidents of a
 * new route prefix being missed in `config/departments.php`. Membership
 * lists are not reliably maintained here, and that is an observation
 * about this repository rather than a guess.
 *
 * So this runs in the global `web` stack and blocks EVERYTHING by
 * default, with a short, reviewable allow-list of what an organization-less
 * account may reach. A new operational route is protected the moment it
 * is written, without anybody remembering anything.
 *
 * ---------------------------------------------------------------------
 * WHAT IT DOES NOT DO
 * ---------------------------------------------------------------------
 *
 * It does not enforce tenant isolation -- TenantScope, BelongsToCompany
 * and the per-controller checks already do that, and they would return
 * empty results for a tenant-less user anyway. This is about giving that
 * user a coherent destination instead of a correctly-scoped-to-nothing
 * page that looks broken.
 *
 * It also deliberately does not touch platform admins. They have no
 * tenant either, but `User::isPlatformAdmin()` is now a role check and
 * `hasNoOrganization()` excludes them, so they keep reaching
 * `/platform/*` exactly as before.
 */
class RequireOrganization
{
    /**
     * Route-name prefixes an account with no organization may reach.
     *
     * Everything here is one of four things: the account's own area, the
     * flow by which it acquires an organization, the session itself, or
     * public marketing/legal pages that were never tenant-scoped.
     *
     * Deliberately matched on the ROUTE NAME rather than the URL path.
     * Path prefixes are easy to alias accidentally (`/account` vs
     * `/accounts`), and every route in this application is named.
     */
    private const ALLOWED_PREFIXES = [
        // The account's own area: overview, profile, security, sign-out.
        'account',

        // Acquiring an organization: plan -> organization -> summary ->
        // payment. This IS the intended destination for this user, so it
        // has to be reachable; every step re-checks authorization itself.
        'subscribe',

        // Session and credential management. `verification` matters most:
        // an unverified account must be able to reach the page telling it
        // to verify, or it is locked out of the only action that helps.
        'login', 'logout', 'password', 'verification', 'auth',

        // The public site. Never tenant-scoped, and pricing in particular
        // is where an undecided account is most likely to go next.
        'home', 'pricing', 'platform-overview', 'solutions', 'how-it-works',
        'faq', 'contact', 'legal', 'sandbox', 'get-started', 'register',
        'robots', 'sitemap', 'manifest',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not signed in, or signed in with an organization: nothing to do.
        // `hasNoOrganization()` is false for platform admins, so they pass
        // straight through.
        if (! $user || ! $user->hasNoOrganization()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        // An unnamed route cannot be matched against the allow-list, so it
        // is refused. Fail-closed is the whole point: every route in this
        // application is named, and an unnamed one is more likely to be a
        // mistake than a page this account should see.
        if ($routeName && $this->isAllowed($routeName)) {
            return $next($request);
        }

        // Inertia needs a real redirect (409 + X-Inertia-Location) rather
        // than a 302 it would follow with XHR and then try to render as a
        // page component. `redirect()` from middleware handles the plain
        // case; Inertia's own middleware converts it correctly for an
        // Inertia request because this returns a RedirectResponse.
        return redirect()->route('account.overview');
    }

    private function isAllowed(string $routeName): bool
    {
        $prefix = explode('.', $routeName)[0];

        return in_array($prefix, self::ALLOWED_PREFIXES, true);
    }
}
