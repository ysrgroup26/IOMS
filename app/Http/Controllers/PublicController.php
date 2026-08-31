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
