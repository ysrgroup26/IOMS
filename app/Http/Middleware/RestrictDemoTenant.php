<?php

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v2.53.0 -- keeps the IOMS Sandbox a DEMONSTRATION, not a free tenant.
 *
 * The Sandbox is a real tenant, so tenant isolation, RBAC, PTW
 * authorization and every other boundary already apply to it unchanged.
 * This middleware adds the one property a shared demo needs on top:
 * a visitor must not be able to break the demonstration for the next
 * visitor, or to reach anything that is not part of it.
 *
 * WHAT IT REFUSES, and why each one:
 *
 *  - Every write outside a short ALLOWED_WRITES list. A demo that cannot
 *    be clicked is a screenshot, so a couple of genuinely useful actions
 *    stay live; everything else is read-only. The list is an allow-list
 *    rather than a deny-list on purpose — a new destructive route added
 *    later is refused by default instead of silently becoming reachable.
 *
 *  - Settings, billing and user administration entirely. These are the
 *    surfaces where a visitor could change the demo's identity, invite
 *    themselves, or read commercial data. They are not part of what the
 *    Sandbox is demonstrating.
 *
 * WHAT IT DOES NOT DO: it grants nothing, and it is not the thing keeping
 * the Sandbox out of other tenants or out of Master Admin. TenantScope
 * and `role:platform_admin` already do that, for the demo account exactly
 * as for any customer account. This is defence in depth, not the defence.
 */
class RestrictDemoTenant
{
    /**
     * Route names a Sandbox visitor may still POST/PUT/DELETE.
     *
     * Deliberately small. These are the interactions that make the
     * demonstration worth walking through — raising a permit and logging
     * a safety observation are the two things a prospect most wants to
     * feel — plus logout, so a visitor can always leave.
     */
    private const ALLOWED_WRITES = [
        'logout',
        'permits-to-work.store',
        'safety-observations.store',
        'tasks.store',
        'sandbox.reset',
    ];

    /** Route-name prefixes a Sandbox visitor may not reach at all, read or write. */
    private const BLOCKED_PREFIXES = [
        'settings.',
        'subscription.',
        'platform.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(CurrentTenant::class)->get();

        if (! $tenant?->isDemo()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName() ?? '';

        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                abort(403, 'This area is not available in the IOMS Sandbox. Start a subscription to use it with your own company data.');
            }
        }

        if (! $request->isMethodSafe() && ! in_array($routeName, self::ALLOWED_WRITES, true)) {
            abort(403, 'The IOMS Sandbox is a demonstration environment, so this action is read-only here. Start a subscription to use it with your own company data.');
        }

        return $next($request);
    }
}
