<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Multi-Tenant Foundation (Epic 3). Resolves TenantContext once per
 * request and binds the resolved instance into the container as a
 * singleton for this request, so anything downstream (controllers,
 * policies, a future automatic query-scoping layer) resolves the SAME
 * already-computed context instead of re-deriving it independently.
 *
 * Deliberately does not reject/redirect requests that have no resolvable
 * tenant -- Super Admins operating across all companies is a legitimate,
 * existing, correct state in this app (see TenantContext's docblock),
 * not an error condition to block.
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(TenantContext::class);
        $context->resolve(
            $request->user(),
            $request->input('company_id') ? (int) $request->input('company_id') : null
        );

        return $next($request);
    }
}
