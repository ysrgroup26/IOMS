<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Department User enforcement (v1.10.3). AuthenticatedLayout.jsx already
 * hides the Department Selector and every other department's sidebar for
 * a Department User -- that's UX only. This is the real boundary: a
 * Department User who navigates directly to another department's URL
 * gets denied here, not just kept from seeing a link to it.
 *
 * Runs on every web request (registered globally in bootstrap/app.php,
 * same as HandleInertiaRequests/IdentifyTenant) rather than per-route --
 * a per-route `->middleware()` list would need updating every time a new
 * module is added to a department, exactly the kind of drift
 * `config/departments.php` exists to avoid. Administrators
 * (`department_key` null) are untouched -- this middleware does nothing
 * at all for them, matching every existing account's current behavior.
 */
class RestrictDepartmentAccess
{
    /** Routes every authenticated user needs regardless of department. */
    private const UNIVERSAL_PREFIXES = ['dashboard', 'work-center', 'approvals', 'search', 'logout', 'login'];

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

        // A route not listed in the map at all is treated the same as
        // "universal" -- deliberately fail open here, not closed: the map
        // is a curated allow-list of what's been explicitly assigned to a
        // department, not an exhaustive registry of every route in the
        // app, so an unmapped route is far more likely to be an oversight
        // than something that should be locked down.
        if ($owningDepartment === false) {
            return $next($request);
        }

        abort_unless($owningDepartment === $user->department_key, 403, 'This page belongs to a different department.');

        return $next($request);
    }
}
