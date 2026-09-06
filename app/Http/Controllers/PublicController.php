<?php

namespace App\Http\Controllers;

use App\Services\PricingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * v2.18.0 (Public Website / Landing Page Foundation). The anonymous,
 * public-facing surface -- reachable by anyone, no `auth`/`guest`
 * middleware (see `routes/web.php`'s own comment on why `/` moved out of
 * the `auth` group entirely). Exposes ONLY public product information;
 * never touches tenant/employee/user/subscription data (Part "Security"
 * of that phase's own directive) -- `home()`'s only data query is
 * `PricingService::publicPlans()`, the exact same read-only, already-
 * public-safe source the authenticated `subscription.plans` page already
 * uses (see `SettingsController::plans()`), not a new/parallel query.
 */
class PublicController extends Controller
{
    /**
     * `/` branches on auth state rather than being wrapped in `guest`
     * middleware -- an authenticated user (tenant OR Platform Admin)
     * must still be able to reach the app from the root URL (this
     * phase's own explicit "authenticated users should still be able to
     * access the application normally"), just redirected past the
     * marketing page rather than shown it.
     */
    public function home(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            return $user->isPlatformAdmin()
                ? redirect()->route('platform.dashboard')
                : redirect()->route('dashboard');
        }

        return Inertia::render('Public/Welcome', [
            // Same shape `SettingsController::plans()` already sends the
            // authenticated Plans page -- reused, not duplicated. If no
            // plan is public yet, the page shows an honest "coming soon"
            // state (see Public/Welcome.jsx) rather than inventing one.
            'plans' => app(PricingService::class)->publicPlans(),
        ]);
    }

    /**
     * v2.50.0 -- the SaaS front door.
     *
     * Pricing was previously only a section inside the landing page, so
     * "View Pricing" could only ever be an anchor scroll and there was no
     * URL to link a prospect to. It is a real page now, sharing the exact
     * same PricingService payload the landing section and the authenticated
     * Plans page already use -- one price source, three surfaces.
     */
    public function pricing(): Response
    {
        return Inertia::render('Public/Pricing', [
            'plans' => app(PricingService::class)->publicPlans(),
            'supportEmail' => config('ioms.support_email'),
        ]);
    }

    /**
     * The acquisition entry point.
     *
     * WHY THIS EXISTS: "Get Started" previously pointed at `route('login')`,
     * which is semantically wrong -- it sent a visitor who has no IOMS
     * account to a form that can only reject them. A prospect and a
     * returning customer need different doors.
     *
     * WHAT IT HONESTLY DOES: IOMS has no self-serve tenant provisioning yet
     * (no registration route exists anywhere in the app), and standing one
     * up means creating a tenant, company, admin user, subscription and
     * entitlement from an unauthenticated request -- a security surface that
     * should not be half-built. So this page presents the standardized plans,
     * lets a prospect carry a plan + billing cycle into an enquiry, and says
     * plainly what happens next. It does not pretend to provision an account
     * or take a payment. See the report/ROADMAP for the remaining steps and
     * the provider configuration they require.
     */
    public function getStarted(Request $request): Response
    {
        $plans = app(PricingService::class)->publicPlans();

        // A plan may be pre-selected from a pricing CTA. Validated against
        // the real catalog so the page can never echo an arbitrary slug.
        $requested = (string) $request->query('plan', '');
        $selected = $plans->firstWhere('slug', $requested)['slug'] ?? null;

        $cycle = in_array($request->query('cycle'), ['monthly', 'yearly'], true)
            ? $request->query('cycle')
            : 'yearly';

        return Inertia::render('Public/GetStarted', [
            'plans' => $plans,
            'selectedPlan' => $selected,
            'billingCycle' => $cycle,
            'supportEmail' => config('ioms.support_email'),
        ]);
    }

    /**
     * v2.18.0 (Part "Footer"): honest placeholders, not fabricated legal
     * text -- this codebase has no actual Privacy Policy/Terms of
     * Service document to render, and this pass was explicitly told not
     * to invent one. A real route (not a dead `#` link) so the footer
     * links are functional, clearly labeled as pending completion.
     */
    public function privacy(): Response
    {
        return Inertia::render('Public/Legal', ['title' => 'Privacy Policy']);
    }

    public function terms(): Response
    {
        return Inertia::render('Public/Legal', ['title' => 'Terms of Service']);
    }
}
