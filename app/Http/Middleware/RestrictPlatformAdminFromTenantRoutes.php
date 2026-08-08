<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Milestone 3 (M3: FINAL verification pass, Task #70). Caught live
 * during this pass, not assumed: logging in as Master (platform_admin,
 * no tenant) and navigating directly to `/dashboard` rendered the
 * Company Admin's OPERATIONAL dashboard ("Good Morning, Master",
 * Employees/Active Projects/Incidents/PPE cards) -- the entire tenant
 * `auth` route group (`/dashboard`, `/employees`, `/settings`, etc.) had
 * no technical barrier keeping a Platform Super Admin out of it, only
 * the UI never linking there. This directly contradicts UAT
 * requirement #3 ("Platform Super Admin must NOT manage company
 * operations") and `routes/web.php`'s own existing comment on the
 * Platform group being "a SEPARATE surface... not nested inside the
 * tenant-side `auth` group" -- that separation was previously
 * aspirational (a comment), not enforced.
 *
 * Applied only to the big tenant `auth` group in routes/web.php --
 * `/platform/*`, `/login`, `/logout` are untouched. Redirects (not
 * aborts) a platform_admin back to their own dashboard, matching "no
 * confusion between Master and Administrator" rather than a bare 403.
 */
class RestrictPlatformAdminFromTenantRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isPlatformAdmin()) {
            return redirect()->route('platform.dashboard');
        }

        return $next($request);
    }
}
