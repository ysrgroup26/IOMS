<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\TenantRegistration;
use App\Services\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.74.0 -- THE ACCOUNT AREA.
 *
 * Where a signed-in person lands when they have an identity but not (yet)
 * an organization. It is a deliberate product state, and the single most
 * important thing about this page is that it must not look like an error
 * or a paywall.
 *
 * An unsubscribed account is not broken and has done nothing wrong: they
 * created an account, which is exactly what they were invited to do. The
 * page tells them what they have, what they do not have yet, and how to
 * get it -- and then leaves them alone. There is no forced redirect to
 * plan selection, by design; see
 * `docs/ADR/038-account-organization-subscription.md`.
 *
 * IT EXPOSES NO TENANT DATA. Structurally, not by filtering: a user with
 * `tenant_id = null` has no tenant for TenantScope to resolve, so there
 * is nothing to leak. Everything rendered here belongs to the `users` row
 * itself or to the public plan catalogue.
 *
 * The area is also reachable by users who DO have an organization -- it is
 * their profile and security page too -- which is why the controller
 * branches on `hasNoOrganization()` for the subscription panel rather
 * than assuming.
 */
class AccountController extends Controller
{
    public function __construct(private readonly PricingService $pricing) {}

    public function overview(Request $request): Response
    {
        $user = $request->user();

        /*
         * An order this account already started, if any.
         *
         * Shown so somebody who abandoned checkout can pick it up rather
         * than starting again and creating a second pending order. Scoped
         * to `user_id` -- this is the account's own order, and there is no
         * tenant involved yet.
         */
        $pendingOrder = TenantRegistration::where('user_id', $user->id)
            ->whereIn('status', [
                TenantRegistration::STATUS_VERIFIED,
                TenantRegistration::STATUS_AWAITING_PAYMENT,
                TenantRegistration::STATUS_PAID,
            ])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();

        return Inertia::render('Account/Overview', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'has_password' => $user->hasPassword(),
                'google_linked' => filled($user->google_id),
                'google_linked_at' => $user->google_linked_at,
                'created_at' => $user->created_at,
            ],
            // The whole point of the page: what commercial state is this
            // account in. `false` here is a normal state, not a failure.
            'hasOrganization' => ! $user->hasNoOrganization(),
            'organization' => $user->hasNoOrganization() ? null : [
                'name' => $user->tenant?->name,
                'company' => $user->company?->name,
            ],
            'pendingOrder' => $pendingOrder ? [
                'reference' => $pendingOrder->reference,
                'status' => $pendingOrder->status,
                'plan' => $pendingOrder->package?->name,
                'organization' => $pendingOrder->displayName(),
                // The existing order page -- the same one the public flow ends on.
                'resume_url' => route('register.status', $pendingOrder->token),
            ] : null,
            // A teaser of the catalogue, so "choose a plan" is a decision
            // they can start making here rather than a leap into a pricing
            // page they have not seen.
            'plans' => $this->pricing->publicPlans(),
            'googleEnabled' => \App\Http\Controllers\Auth\GoogleAuthController::configured(),
        ]);
    }

    /** Update the person's own identity. Never their role, tenant or company. */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user->update(['name' => $validated['name']]);

        return back()->with('success', 'Your name has been updated.');
    }

    /**
     * Set or change the account password.
     *
     * TWO DIFFERENT OPERATIONS BEHIND ONE ENDPOINT, and the difference is
     * a security decision rather than a convenience:
     *
     *   - An account that HAS a password must prove it (`current_password`)
     *     before changing it. Otherwise a borrowed, already-signed-in
     *     session could lock the real owner out.
     *
     *   - A Google-only account has no password to prove. Requiring one
     *     would make it impossible to ever add password sign-in, and
     *     inventing a placeholder to check against would be worse. The
     *     session itself is the proof, and that session was established by
     *     Google authenticating the address.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $rules = [
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()->uncompromised()],
        ];

        if ($user->hasPassword()) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        $request->validate($rules);

        $wasPasswordless = ! $user->hasPassword();

        $user->forceFill(['password' => Hash::make($request->input('password'))])->save();

        ActivityLog::record(
            'updated',
            $wasPasswordless
                ? "Password sign-in enabled for {$user->email}."
                : "Password changed for {$user->email}.",
            $user
        );

        return back()->with('success', $wasPasswordless
            ? 'Password sign-in is now enabled for your account.'
            : 'Your password has been updated.');
    }

    /**
     * Unlink Google sign-in.
     *
     * REFUSED IF IT WOULD LOCK THE ACCOUNT OUT. An account with no
     * password and no Google link has no way back in, and "are you sure"
     * is not a substitute for not offering the action. The remedy is
     * stated rather than implied: set a password first.
     */
    public function unlinkGoogle(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasPassword()) {
            return back()->withErrors([
                'google' => 'Set a password first. Removing Google sign-in now would leave no way to access this account.',
            ]);
        }

        $user->forceFill(['google_id' => null, 'google_linked_at' => null])->save();

        ActivityLog::record('updated', "Google sign-in unlinked from {$user->email}.", $user);

        return back()->with('success', 'Google sign-in has been removed from your account.');
    }
}
