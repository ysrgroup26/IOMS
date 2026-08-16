/**
 * v1.11.3.2 (production UX fix, Part 3). Frontend counterpart to
 * `App\Http\Middleware\RestrictDepartmentAccess`. That middleware is and
 * remains the real enforcement boundary -- this file changes nothing
 * about what's allowed, it only lets a page avoid RENDERING a link a
 * department-restricted viewer can't actually follow (chiefly the Main
 * Dashboard, reachable by every user regardless of department, whose
 * stat cards otherwise link into other departments' routes and 403 on
 * click for a restricted user -- the exact bug this fixes).
 *
 * Mirrors `RestrictDepartmentAccess::UNIVERSAL_PREFIXES` -- keep both
 * lists in sync if that one changes; there are only two consumers, this
 * one and the middleware itself.
 */
const UNIVERSAL_PREFIXES = ['dashboard', 'home', 'work-center', 'approvals', 'notifications', 'search', 'logout', 'login', 'password', 'calendar'];

/**
 * True if `routeName` is reachable by the given auth user, per
 * `auth.department_prefixes` (null = unrestricted Administrator; an
 * array = the exact allowlist the backend computed from
 * config('departments') for this user's department_key).
 */
export function canReachRoute(routeName, departmentPrefixes) {
    if (!departmentPrefixes) return true; // unrestricted (Administrator)

    const prefix = routeName?.split('.')[0];
    if (!prefix) return true;

    return UNIVERSAL_PREFIXES.includes(prefix) || departmentPrefixes.includes(prefix);
}

/**
 * Returns `route(routeName, params)` when the viewer can reach it,
 * otherwise `undefined` -- pass straight into a component's `href` prop
 * so an unreachable card/link renders as plain (non-clickable) content
 * instead of a route that would 403 on click.
 */
export function deptSafeHref(routeName, departmentPrefixes, params) {
    return canReachRoute(routeName, departmentPrefixes) ? route(routeName, params) : undefined;
}
