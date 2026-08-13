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
        'search', 'logout', 'login', 'password',
        // v1.11.0: the Global Calendar aggregates events FROM several
        // departments (Leave/HR, PTW+TBM/HSE, Milestone/Project,
        // Work Order/Maintenance) into one cross-department view by
        // design (see CalendarController's own doc comment) -- it isn't
        // owned by any single department, the same reasoning as
        // 'dashboard' itself.
        'calendar',
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
        $owningDepartment = collect($map)->search(fn (array $prefixes) => in_array($prefix, $prefixes, true));

        // v1.10.5: fail CLOSED. A prefix that isn't in the map at all --
        // whether because it genuinely belongs to no department (in which
        // case it should be added to UNIVERSAL_PREFIXES above) or because
        // the map simply hasn't been updated yet for a newly added route --
        // is denied rather than silently allowed. Getting this wrong now
        // shows up immediately as a legitimate user being blocked (loud,
        // reported, fixed by adding one line to config/departments.php),
        // instead of the previous failure mode (a route silently reachable
        // by every department, discovered only by audit).
        abort_unless($owningDepartment !== false && $owningDepartment === $user->department_key, 403, 'This page belongs to a different department.');

        return $next($request);
    }
}
