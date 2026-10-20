<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * v2.128.0 -- A DEACTIVATED ACCOUNT STOPS WORKING ON THE NEXT REQUEST.
 *
 * `users.is_active` was checked in exactly two places, both of them sign-in
 * forms. So deactivating somebody took effect at their next SIGN-IN, and an
 * account already signed in carried on working until its session happened to
 * expire -- which is not what an administrator believes they have just done
 * when they switch an account off.
 *
 * Remember Me (ADR 068) is what made that gap worth closing rather than
 * noting: the remembered cookie Laravel issues is good for five years, so
 * "until the session expires" stopped meaning "until they close the app"
 * and started meaning "indefinitely". A revocation that does not revoke is
 * the kind of control that is worse than none, because it is believed.
 *
 * WHAT IT DOES, and nothing more: if there is an authenticated user and
 * their row says inactive, sign them out -- which also cycles
 * `remember_token` and so kills every remembered device -- and send them to
 * the form they sign in at, with a sentence saying why. Guests pass
 * through, active accounts pass through, and no authorization decision is
 * taken here: this middleware answers "is this account switched on", never
 * "may they see this page".
 *
 * Placed FIRST in the web group's appended list, before tenant resolution
 * and before the Inertia props are built: everything after it may assume
 * that an authenticated request belongs to an account that still exists and
 * is still switched on.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_active) {
            return $next($request);
        }

        // Asked before the logout, because afterwards there is nobody to
        // ask -- and a My Work account must go back to the badge-number
        // form, not to the email one it cannot use (ADR 062).
        $returnTo = $user->isMyWorkUser() ? route('my-work.login') : route('login');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($returnTo)->withErrors([
            'email' => 'Akun Anda sedang dinonaktifkan. Hubungi administrator IOMS perusahaan Anda.',
            'identifier' => 'Akses My Work Anda sedang dinonaktifkan. Hubungi administrator IOMS perusahaan Anda.',
        ]);
    }
}
