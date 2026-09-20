<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * v2.74.0 -- confirming the email address on an IOMS account.
 *
 * Deliberately thin. `EmailVerificationRequest` is Laravel's own form
 * request: it validates the signed URL, checks the `id` matches the
 * signed-in user and the `hash` matches a SHA-1 of their current email,
 * and aborts before this method body runs if any of that fails. Every
 * security property of the link -- the signature, the expiry, the binding
 * to one user and one address -- comes from the framework, and
 * re-implementing any of it here would only be a way to get it wrong.
 *
 * WHAT VERIFICATION GATES, and what it does not:
 *
 *   - It GATES the subscribe flow. Buying a subscription with an address
 *     nobody has confirmed means invoices and activation notices going
 *     into the void, so `SubscribeController` requires it.
 *
 *   - It does NOT gate signing in, or the Account area. Locking an
 *     unverified account out of the one page that explains how to verify
 *     would be circular.
 *
 *   - It does NOT gate existing operational tenant users. Every user that
 *     predates v2.74.0 is unverified (they were created by
 *     TenantProvisioningService, which never set the column), so gating
 *     the product on it would lock out every existing customer overnight
 *     to solve a problem none of them have -- their address was verified
 *     on the registration record before they were ever created.
 */
class EmailVerificationController extends Controller
{
    /**
     * The signed link's destination.
     *
     * Idempotent: following the same link twice, or after verifying from
     * another device, lands on the account page rather than erroring.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.overview')->with('success', 'Your email address is already confirmed.');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
            ActivityLog::record('updated', "Email address confirmed for {$request->user()->email}.", $request->user());
        }

        return redirect()->route('account.overview')->with('success', 'Your email address has been confirmed.');
    }

    /**
     * Re-send the confirmation email.
     *
     * Throttled at the route. The response is the same whether or not
     * anything was actually sent, so this cannot be used to probe an
     * account's verification state -- though it is already behind auth,
     * so that matters less here than on a public endpoint.
     */
    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return back()->with('success', 'Your email address is already confirmed.');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('success', 'Confirmation email sent. Please check your inbox.');
    }
}
