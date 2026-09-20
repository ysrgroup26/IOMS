<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.74.0 -- CREATING AN IOMS ACCOUNT, AND NOTHING ELSE.
 *
 * Before this release there was no such thing. `/get-started` asked for
 * identity, company address, plan and password in one form, stored it as
 * a `TenantRegistration`, and created the `users` row only after a
 * verified payment. You could not sign in before you had paid.
 *
 * This endpoint creates ONLY the person:
 *
 *   - no Tenant
 *   - no Company
 *   - no Operating Unit
 *   - no Subscription
 *   - no payment
 *
 * The account then lands on a FORK -- set a subscription up now, or not
 * yet -- and neither branch is taken for them. That is a deliberate
 * product decision, not an oversight: dropping a brand-new account
 * straight into a pricing table is the software equivalent of asking for
 * a credit card at the door, and dropping it into an empty account area
 * with no stated next step is the opposite mistake. Offering the choice
 * costs one screen and answers the only question they have.
 *
 * "Continue setup" opens the subscription setup form -- the SAME page
 * /get-started renders, opened by an account rather than by a stranger.
 * "Maybe later" goes to the Account area and creates nothing.
 *
 * See `docs/ADR/038-account-organization-subscription.md`.
 *
 * THE ROLE MATTERS. A new account gets `User::ROLE_ACCOUNT`, which grants
 * nothing -- no `isX()` predicate returns true for it. Combined with a
 * null `tenant_id`, that makes the account inert: `RequireOrganization`
 * keeps it out of every operational route, and even if a route were left
 * unguarded there is no tenant for its data to be scoped to.
 */
class RegisteredUserController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        // Somebody already signed in has no business creating a second
        // account by accident. Send them where they can actually act.
        if ($request->user()) {
            return redirect()->route($request->user()->landingRouteName());
        }

        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            // Laravel's own rule set rather than a bare `min:8`: it adds
            // the compromised-password check against Have I Been Pwned's
            // k-anonymity API, which is the single highest-value password
            // rule available and costs the user nothing.
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()->uncompromised()],
            'terms' => ['accepted'],
        ]);

        $email = mb_strtolower(trim($validated['email']));

        /*
         * EXISTING-ADDRESS HANDLING, WITHOUT ENUMERATION.
         *
         * A registration form that says "this email is already taken"
         * tells an unauthenticated stranger which addresses hold IOMS
         * accounts. The legacy `/get-started` flow already made that
         * decision and used one shared message pointing at sign-in; this
         * keeps the same position for the same reason.
         *
         * The message is identical whether the address belongs to a full
         * tenant user, an unsubscribed account, or a Google-only account,
         * so no case is distinguishable from the outside.
         */
        if (User::where('email', $email)->exists()) {
            return back()->withErrors([
                'email' => 'This email address is already registered with IOMS. Please sign in, or use a different address.',
            ])->onlyInput('name', 'email');
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $email,
            'password' => Hash::make($validated['password']),
            // Grants nothing. See User::ROLE_ACCOUNT.
            'role' => User::ROLE_ACCOUNT,
            // The three things this endpoint deliberately does not create.
            'tenant_id' => null,
            'company_id' => null,
            'is_active' => true,
        ]);

        /*
         * Laravel's own Registered event fires the verification
         * notification through the framework's signed-URL machinery
         * (`verification.verify`, signed + expiring), which is what makes
         * the link tamper-proof. The notification itself is overridden on
         * the User model so the email is an IOMS one rather than the
         * framework default -- see User::sendEmailVerificationNotification().
         */
        event(new Registered($user));

        ActivityLog::record('created', "IOMS account created for {$user->email}.", $user);

        // Signed in immediately, deliberately. The account is real and
        // theirs; making them sign in again to reach a page that says
        // "check your email" is friction with no security value -- the
        // unverified state is enforced by middleware, not by withholding
        // the session.
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('register.welcome');
    }

    /**
     * The fork: "Continue setup" or "Maybe later".
     *
     * Its own URL rather than only a redirect target, so a refresh does not
     * lose it. An account that already has an organization has nothing to
     * choose here and is sent to its product.
     */
    public function welcome(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasNoOrganization()) {
            return redirect()->route($user->landingRouteName());
        }

        return Inertia::render('Auth/AccountCreated', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
            ],
        ]);
    }
}
