<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level guard for the four-role system (super_admin, hse, hrd, manager).
 * Usage: ->middleware('role:super_admin,hse') accepts a comma-separated
 * list and passes if the user's role matches ANY of them.
 *
 * This is the real authorization boundary; the frontend additionally
 * hides buttons/menu items the user can't use, but that's UX only --
 * this middleware is what actually blocks the request server-side.
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            abort(403, 'You do not have permission to access this page.');
        }

        return $next($request);
    }
}
