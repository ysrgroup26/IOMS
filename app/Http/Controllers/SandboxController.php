<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.53.0 -- the IOMS Sandbox.
 *
 * "See how IOMS works before you buy." A prospect walks into a real,
 * seeded workspace and clicks around it.
 *
 * IT IS NOT A FREE TRIAL, and the distinction is the whole design:
 *
 *   A free trial gives someone their OWN empty tenant, which then has to
 *   be provisioned, entitled, billed and eventually expired — the entire
 *   subscription machinery, running for people who may never buy, and an
 *   empty workspace is a bad demonstration anyway.
 *
 *   The Sandbox is ONE shared, pre-populated tenant that everybody
 *   visits. Nobody is provisioned, nothing is billed, and the visitor
 *   sees an operation that already has employees, projects, permits and
 *   stock in it.
 *
 * The purchase path is untouched: Landing -> Pricing -> Get Started ->
 * payment -> verified webhook -> provisioning -> Login.
 *
 * SECURITY. The demo account is an ordinary tenant user, so it is bounded
 * by exactly the same machinery as a customer account — TenantScope keeps
 * it inside the demo tenant, `role:platform_admin` keeps it out of Master
 * Admin, and RestrictDemoTenant additionally makes it read-mostly. This
 * controller grants nothing: it signs a visitor in as an account that
 * already exists with the privileges it already has, and refuses if the
 * Sandbox has not been seeded.
 */
class SandboxController extends Controller
{
    /** The landing page for the Sandbox — what it is, and what is and is not live in it. */
    public function show(): Response
    {
        abort_unless(config('ioms.sandbox.enabled'), 404);

        return Inertia::render('Public/Sandbox', [
            'available' => $this->demoUser() !== null,
            'contactEmail' => config('ioms.emails.hello'),
        ]);
    }

    /**
     * Signs the visitor in as the shared demo account.
     *
     * Deliberately a POST: signing someone in is a state change, and a
     * GET would let any page on the internet log a reader into the
     * Sandbox by embedding an image.
     */
    public function enter(Request $request): RedirectResponse
    {
        abort_unless(config('ioms.sandbox.enabled'), 404);

        $user = $this->demoUser();

        // The Sandbox has not been seeded on this deployment. Say so
        // rather than inventing an account — a demo account created on
        // demand would be a login the operator never intended to exist.
        if (! $user) {
            return back()->withErrors([
                'sandbox' => 'The IOMS Sandbox is not available on this deployment yet.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /**
     * The demo account, resolved by configuration and verified to belong
     * to a tenant that is actually flagged as a demo.
     *
     * Both halves matter. Resolving by email alone would mean a
     * misconfigured `IOMS_SANDBOX_USER` could sign a visitor into a real
     * customer's workspace, so the tenant flag is checked as well: no
     * demo tenant, no sandbox login.
     */
    private function demoUser(): ?User
    {
        $tenant = Tenant::where('slug', config('ioms.sandbox.tenant_slug'))
            ->where('is_demo', true)
            ->first();

        if (! $tenant) {
            return null;
        }

        return User::where('tenant_id', $tenant->id)
            ->where('email', config('ioms.sandbox.user_email'))
            ->where('is_active', true)
            ->first();
    }
}
