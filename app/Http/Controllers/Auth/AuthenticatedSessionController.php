<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Your account has been deactivated. Contact the HSE Admin.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        // Milestone 2 (Task #44): a Platform Super Admin has no tenant --
        // route('dashboard') would only ever show them an empty,
        // correctly-scoped-to-nothing tenant dashboard (see
        // docs/ADR/008-tenancy-foundation.md). Send them to their own
        // surface instead.
        if ($user->isPlatformAdmin()) {
            return redirect()->intended(route('platform.dashboard'));
        }

        // v2.52.0: a field account lands in My Work, not an office
        // dashboard it has no use for. See User::landingRouteName() -- the
        // decision lives on the model so every caller agrees.
        return redirect()->intended(route($request->user()->landingRouteName()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        /*
         * v2.128.0 -- SIGNING OUT RETURNS YOU TO THE DOOR YOU CAME IN BY.
         *
         * There is one logout for both account classes, and it sent
         * everybody to `/login` -- the email-and-password form, which a My
         * Work account cannot use at all: it signs in with a badge number
         * and an access code at `/my-work/login` (ADR 062). A worker
         * signing out therefore landed on a form that had no field they
         * could fill in, which reads as having been locked out.
         *
         * Asked BEFORE `logout()`, because afterwards there is no user to
         * ask. Nothing else about the logout changes: the same guard, the
         * same session invalidation, and the same token regeneration --
         * which is also what cycles `remember_token` and so revokes every
         * remembered device.
         */
        $returnTo = $request->user()?->isMyWorkUser()
            ? route('my-work.login')
            : '/login';

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($returnTo);
    }
}
