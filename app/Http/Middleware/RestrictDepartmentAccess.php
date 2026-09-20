<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Department User enforcement (v1.10.3, hardened v1.10.5). AuthenticatedLayout.jsx
 * already hides the Department Selector and every other department's
 * sidebar for a Department User -- that's UX only. This is the real
 * boundary: a Department User who navigates directly to another
 * department's URL gets denied here, not just kept from seeing a link to
 * it.
 *
 * Runs on every web request (registered globally in bootstrap/app.php,
 * same as HandleInertiaRequests/IdentifyTenant) rather than per-route --
 * a per-route `->middleware()` list would need updating every time a new
 * module is added to a department, exactly the kind of drift
 * `config/departments.php` exists to avoid. Administrators
 * (`department_key` null) are untouched -- this middleware does nothing
 * at all for them, matching every existing account's current behavior.
 *
 * v1.10.5 SECURITY FIX: this used to fail OPEN for any route-name prefix
 * not listed in `config/departments.php` ("the map is a curated allow-list,
 * not an exhaustive registry, so an unmapped route is more likely an
 * oversight than something that should be locked down"). In practice this
 * meant every HSE route added during Workstream B (Safety Observation, HSE
 * Inspection, HIRADC, JSA, PTW, LOTO, TBM, CAPA, Contractor, Visitor,
 * Document Control) was reachable by direct URL from ANY department user,
 * not just an HSE one -- the map had simply gone stale, and "unmapped"
 * silently meant "unrestricted" rather than "not yet audited". `
 * config/departments.php` is now treated as exhaustive (cross-checked
 * against every route-name prefix in `routes/web.php`), and the default
 * for an unmapped prefix has flipped to DENY. A prefix that's genuinely
 * needed by every department regardless of assignment belongs in
 * `UNIVERSAL_PREFIXES` below, not left out of the map by omission.
 */
class RestrictDepartmentAccess
{
    /**
     * Routes every authenticated user needs regardless of department --
     * either truly cross-department (dashboard, work center, notifications,
     * search, approvals) or routes this middleware would never actually see
     * in practice (login/logout/password live in the `guest` group or
     * redirect before reaching here) but are listed anyway as a defensive
     * safety net against a future routing change silently locking everyone
     * out of authentication itself.
     */
    private const UNIVERSAL_PREFIXES = [
        'dashboard', 'home', 'work-center', 'approvals', 'notifications',
        // v2.42.0: Field Home, now its own route rather than a hijack of
        // `dashboard`. Cross-department by definition -- it is a personal
        // task list, owned by no department.
        'my-work',
        'search', 'logout', 'login', 'password',
        /*
         * v2.74.0 -- ACCOUNT, AUTHENTICATION AND SUBSCRIPTION ACQUISITION.
         *
         * None of these belong to a department, and two of them are the
         * ONLY places an account without an organization is allowed to be
         * (see RequireOrganization). Found the way this class of mistake
         * is always found here: signing up returned "This page belongs to
         * a different department" -- a 403 from the routing layer, on the
         * sign-up page, for a prefix that simply was not in the map.
         *
         *   account       the person's own area: identity, security
         *   subscribe     Plan -> Organization -> Order -> Payment
         *   register      creating an IOMS account
         *   verification  confirming the email address on one
         *   auth          the Google OAuth redirect and callback
         *   get-started   the legacy public onboarding, same reasoning
         */
        'account', 'subscribe', 'register', 'verification', 'auth', 'get-started',
        // v2.42.0: PTW is a genuinely cross-department CAPABILITY, not an
        // HSE-department-owned page -- User::canCreatePtw() is deliberately
        // a UNION of canManageHse() and an individually-granted `ptw_access`
        // flag (Settings > Users > PTW Access), precisely so a Field/
        // Operations user in a NON-hse department can be granted permit
        // authoring rights without joining HSE. Leaving 'permits-to-work'
        // inside the 'hse' entry below meant this middleware 403'd exactly
        // that user before the request ever reached the controller's own
        // canCreatePtw() check -- department ownership and capability
        // ownership had silently diverged. The controller's per-action gates
        // (canCreatePtw() for create/store, canManageHse() for approval
        // actions) remain the real, unweakened authorization boundary; this
        // only stops the ROUTING layer from second-guessing them. Same
        // reasoning as 'man-hour' below (shared HR+HSE data, owned by
        // neither department exclusively).
        'permits-to-work',
        // v1.11.0: the Global Calendar aggregates events FROM several
        // departments (Leave/HR, PTW+TBM/HSE, Milestone/Project,
        // Work Order/Maintenance) into one cross-department view by
        // design (see CalendarController's own doc comment) -- it isn't
        // owned by any single department, the same reasoning as
        // 'dashboard' itself.
        'calendar',
        // v1.11.15 (SaaS Package + Ecosystem pass, Part 27 -- Entitlement
        // Dependency Rule): Man-Hour is genuinely shared HR+HSE
        // operational data (User::canManageManHour() now grants both),
        // the exact same "aggregates/serves several departments, owned by
        // none of them" shape as 'calendar' above -- moved here from the
        // `hr`-only entry in config/departments.php, which only supports
        // one owning department per prefix.
        'man-hour',
        /*
         * v2.71.0 -- MATERIAL REQUEST IS RAISED BY EVERY DEPARTMENT AND
         * OWNED BY NONE OF THEM.
         *
         * Exactly the divergence 'permits-to-work' above was moved here
         * to fix, found again in a different module.
         * `User::canManageMaterialRequests()` is `isSuperAdmin() ||
         * isHse()` -- the capability has belonged to HSE since the module
         * shipped -- while `config/departments.php` filed the route
         * prefix under `logistics`. Two consequences, both confirmed
         * against the running application rather than reasoned about:
         *
         *  1. A Department User in HSE was 403'd here before the request
         *     ever reached the controller's own permission check.
         *  2. Worse, `EnforceTenantEntitlement` resolves a route's owning
         *     WORKSPACE through this same map. Starter sells `['hse']` and
         *     Professional `['hse','hr']` (config/plans.php) -- neither
         *     grants `logistics` -- so on the two plans that sell HSE, the
         *     HSE team could not open the module their own permission says
         *     they own. A capability the product sells was unreachable for
         *     the customers it was sold to.
         *
         * Requesting materials is a need any department has; fulfilling
         * the request is the specialised part, and Purchase Requisitions,
         * RFQs, Purchase Orders and Goods Receipts all stay owned by
         * procurement/logistics exactly as before.
         *
         * NOTHING IS WEAKENED. MaterialRequestController still gates every
         * write on `canManageMaterialRequests()` / `canConsolidateDemand()`,
         * and `MaterialRequest::scopeVisibleTo()` still confines every read
         * to the viewer's own tenant and company. This only stops the
         * ROUTING layer from second-guessing a capability check that is
         * already stricter than it is.
         */
        'material-requests',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->department_key) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $prefix = $routeName ? explode('.', $routeName)[0] : null;

        if (! $prefix || in_array($prefix, self::UNIVERSAL_PREFIXES, true)) {
            return $next($request);
        }

        $map = config('departments', []);

        // v2.46.0: a prefix may now be listed under MORE THAN ONE department.
        // This used to take the FIRST match only, which silently made the map
        // single-owner and produced the reported PTW Access 403: Settings >
        // Users is where PTW Access is granted, its route is explicitly gated
        // `role:super_admin,hse`, yet the `settings` prefix was owned by
        // `administration` alone -- so a department-scoped HSE user was denied
        // here, BEFORE the route's own role gate could allow them. The routing
        // layer was contradicting the authorization layer.
        //
        // Still fail-closed, and this widens nothing on its own: a prefix is
        // only reachable by a department that is explicitly listed for it, and
        // every route keeps whatever `role:` gate it already had. Settings'
        // mutating sub-routes remain `role:super_admin`.
        $owningDepartments = collect($map)
            ->filter(fn (array $prefixes) => in_array($prefix, $prefixes, true))
            ->keys();

        // v1.10.5: fail CLOSED. A prefix that isn't in the map at all --
        // whether because it genuinely belongs to no department (in which
        // case it should be added to UNIVERSAL_PREFIXES above) or because
        // the map simply hasn't been updated yet for a newly added route --
        // is denied rather than silently allowed. Getting this wrong now
        // shows up immediately as a legitimate user being blocked (loud,
        // reported, fixed by adding one line to config/departments.php),
        // instead of the previous failure mode (a route silently reachable
        // by every department, discovered only by audit).
        abort_unless($owningDepartments->contains($user->department_key), 403, 'This page belongs to a different department.');

        return $next($request);
    }
}
