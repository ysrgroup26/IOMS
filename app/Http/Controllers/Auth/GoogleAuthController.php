<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * v2.74.0 -- CONTINUE WITH GOOGLE.
 *
 * ---------------------------------------------------------------------
 * WHY SOCIALITE RATHER THAN A HAND-ROLLED FLOW
 * ---------------------------------------------------------------------
 *
 * The identity has to be established by Google, server-side. That means:
 * a real authorization-code redirect, a `state` parameter generated and
 * checked against the session to stop CSRF, and a back-channel token
 * exchange using the client secret before any claim about who the user is
 * is believed. Socialite is a first-party Laravel package that does
 * exactly that and is widely audited; hand-rolling OAuth is a well-known
 * way to get a subtle detail wrong in a way nobody notices until it
 * matters.
 *
 * WHAT IS NEVER TRUSTED: anything the browser sends. The email and the
 * Google subject id below come from Socialite's `user()` call, which is a
 * server-to-server exchange with Google using the authorization code and
 * the client secret. No request field influences which account is signed
 * in.
 *
 * ---------------------------------------------------------------------
 * ACCOUNT LINKING
 * ---------------------------------------------------------------------
 *
 * Three cases, resolved in this order:
 *
 *  1. `google_id` already known  -> that account. Authoritative, because
 *     Google's `sub` is stable for the life of the identity even if the
 *     address is renamed.
 *
 *  2. Email matches an existing IOMS account -> LINK to it, and mark the
 *     email verified. This is the case that could create a duplicate
 *     account if handled carelessly, and the case where a careless
 *     implementation hands over somebody else's account.
 *
 *     It is safe here for one specific reason: Google only returns an
 *     email on a successful authentication, and this code additionally
 *     requires `verified_email` to be true before matching on it. An
 *     unverified Google address must never take over an IOMS account
 *     whose password somebody else knows -- that would be a full account
 *     takeover through a self-asserted address.
 *
 *  3. Neither -> create a new account, exactly as the email/password
 *     registration does: identity only. No tenant, no company, no
 *     subscription, and `ROLE_ACCOUNT` which grants nothing.
 *
 * A Google-authenticated account needs no IOMS verification email --
 * Google has already proved control of the address -- and its `password`
 * stays null rather than a placeholder hash. See the owning migration.
 */
class GoogleAuthController extends Controller
{
    /** Is Google sign-in configured on this deployment at all? */
    public static function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public function redirect(): RedirectResponse
    {
        // A deployment without credentials must not present a button that
        // 500s. The login page already hides it (see `configured()` shared
        // to Inertia), and this is the matching server-side guard for
        // anyone who reaches the URL directly.
        abort_unless(self::configured(), 404);

        return Socialite::driver('google')
            // Only what is needed to identify the person. Asking for more
            // scope than the product uses is how a sign-in button starts
            // looking like a data grab on Google's consent screen.
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::configured(), 404);

        try {
            // Socialite verifies `state` against the session here and
            // performs the code-for-token exchange server-to-server. A
            // tampered or replayed callback throws rather than returning a
            // user.
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Deliberately vague to the visitor, specific in the log. A
            // failed OAuth callback is indistinguishable from the outside
            // whether it was a cancelled consent screen, an expired state
            // or a forged request, and saying which would be unhelpful at
            // best.
            Log::warning('Google OAuth callback failed.', ['error' => $e->getMessage()]);

            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in could not be completed. Please try again, or sign in with your email and password.',
            ]);
        }

        $googleId = (string) $googleUser->getId();
        $email = mb_strtolower(trim((string) $googleUser->getEmail()));

        /*
         * Google's own verification flag, read from the token response.
         * Socialite exposes the raw claims via `user`; the key differs
         * between Google's userinfo shapes, so both are checked and the
         * default is FALSE -- an absent flag must never be read as
         * verified.
         */
        $raw = (array) $googleUser->user;
        $emailVerified = (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false);

        if ($email === '') {
            return redirect()->route('login')->withErrors([
                'email' => 'Google did not return an email address for that account. Please sign in with your email and password.',
            ]);
        }

        // 1. Known Google identity.
        $user = User::where('google_id', $googleId)->first();

        // 2. Existing IOMS account with the same, GOOGLE-VERIFIED address.
        if (! $user && $emailVerified) {
            $user = User::where('email', $email)->first();

            if ($user) {
                $user->linkGoogleIdentity($googleId);
                ActivityLog::record('updated', "Google sign-in linked to {$user->email}.", $user);
            }
        }

        // An unverified Google address that matches an existing account is
        // refused outright rather than linked or turned into a duplicate.
        // Creating a second account on the same address would break the
        // unique index anyway; refusing explicitly says why.
        if (! $user && ! $emailVerified && User::where('email', $email)->exists()) {
            return redirect()->route('login')->withErrors([
                'email' => 'That Google account\'s email address is not verified with Google, so it cannot be linked to an existing IOMS account. Please sign in with your email and password.',
            ]);
        }

        // 3. Nobody yet -- create the identity, and nothing else.
        if (! $user) {
            $user = User::create([
                'name' => $googleUser->getName() ?: $email,
                'email' => $email,
                // Null, not a placeholder hash. This account has no IOMS
                // password until its owner sets one.
                'password' => null,
                'role' => User::ROLE_ACCOUNT,
                'tenant_id' => null,
                'company_id' => null,
                'is_active' => true,
            ]);

            /*
             * The Google identity is written SEPARATELY and explicitly,
             * because `google_id` is not mass-assignable -- see the note
             * on User::$fillable for why linking must never be reachable
             * through mass assignment.
             *
             * Caught by a test: passing these to create() silently dropped
             * them, producing an account that was neither verified nor
             * linked, which would then have failed to match on the next
             * sign-in and created a duplicate.
             */
            if ($emailVerified) {
                $user->linkGoogleIdentity($googleId);
            } else {
                $user->forceFill(['google_id' => $googleId, 'google_linked_at' => now()])->save();
            }

            ActivityLog::record('created', "IOMS account created for {$user->email} via Google.", $user);
        }

        if (! $user->is_active) {
            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been deactivated. Contact the HSE Admin.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route($user->landingRouteName()));
    }
}
