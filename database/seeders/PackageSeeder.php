<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    /**
     * Milestone 2 (Package + Subscription). Default catalog so the
     * Subscription structure has real rows to point at immediately --
     * not tenant-scoped, no CurrentTenant dependency (see Package's own
     * doc comment).
     *
     * v1.11.15 (SaaS Package + Ecosystem pass, Part 1/26): `features`
     * corrected to the actual product requirement -- Starter's old list
     * (`employees, ppe, reports`) was barely a fraction of real HSE
     * functionality (no incidents/observations/inspections/CAPA/PTW/
     * LOTO/gas-test/JSA/HIRADC/waste/master-data/man-hour at all), and
     * Professional/Enterprise previously had IDENTICAL lists -- Enterprise
     * unlocked nothing Professional didn't already have. Package.features
     * itself is a display/reference field only (confirmed via a
     * whole-codebase search that nothing reads `hasFeature()`/`features`
     * at runtime -- the actual enforcement mechanism is the Module/
     * Workspace grant tables, see `Package::defaultWorkspaceKeys()`/
     * `defaultModuleKeys()` and `PlatformController::storeTenant()`), but
     * corrected here too so the stored catalog description matches what
     * the tenant actually gets, not a stale, narrower list.
     *
     * v2.51.0 SUPERSEDES v2.50.0 prices with the approved LAUNCH figures
     * (499.000 / 999.000 / 1.999.000 monthly, 4.990.000 / 9.990.000 /
     * 19.990.000 annual). Note that a seeder alone could not fix the live
     * deployment -- the runtime source of truth is the `packages` table and
     * db:seed had never re-run, which is why v2.50.0 prices never appeared.
     * See 2026_09_17_100220_standardize_launch_plan_pricing, which applies
     * the same catalog on deploy; this seeder stays in step with it so a
     * FRESH install and an UPGRADED install end up identical.
     *
     * v2.50.0 SUPERSEDES the v2.14.0 note below on two points: the
     * standardized IOMS commercial model is now a recorded business
     * decision, so the placeholder 0/49/149 figures became real IDR prices
     * (1.499.000 / 3.499.000 / 7.499.000 monthly), and Enterprise is no
     * longer `is_custom` -- it is the most complete STANDARDIZED tier with a
     * published price, not a negotiated custom build.
     *
     * v2.14.0 (SaaS Productization / Pricing Foundation): added
     * `currency`/`trial_days`/`is_public`/`is_custom`. Deliberately did
     * NOT touch any `price_monthly`/`price_yearly` value already seeded
     * here -- those are an existing, prior business decision recorded in
     * this repository, and this phase's own directive explicitly forbids
     * inventing/changing final prices without a documented decision.
     * Enterprise becomes `is_custom = true` (sold by negotiation, matching
     * its "Hubungi Kami" presentation on the Plans page) -- its existing
     * `price_monthly`/`price_yearly` values are left in place rather than
     * nulled out, since `PricingService` already ignores the numeric
     * price entirely once `is_custom` is true, and leaving them intact
     * avoids a destructive-looking change to existing seed data for a
     * purely cosmetic/presentation flag.
     *
     * v2.17.0 (PTW Field Workflow Foundation + Controlled PTW Access,
     * Part 5): added `max_ptw_users`. Starter = 15, the explicit baseline
     * this phase's own directive states. Professional = 50 -- a
     * proportionate step up (same ~5x ratio `max_users`/`max_companies`
     * already use between these two tiers), NOT a final pricing/limit
     * decision -- the directive is explicit that Professional's real
     * number is still to-be-finalized; this is a working default a
     * Platform Admin can change from the Plans admin UI at any time, in
     * data, with no code change required. Enterprise = null
     * (unlimited/custom), matching its existing `max_users`/
     * `max_companies` null convention on this same row.
     *
     * v2.17.1 (PTW Field Workflow Verification & Correction pass): fixed
     * the resulting contradiction this same v2.17.0 pass had honestly
     * flagged rather than silently ignored -- Starter's seeded
     * `max_ptw_users` (15) exceeded its own `max_users` (10), meaning a
     * real Starter tenant could never actually reach the PTW quota it was
     * told it had. `max_ptw_users` represents "of this package's User
     * Account allowance, how many may ALSO be PTW-enabled" -- a subset,
     * never a separate/larger pool -- so `max_ptw_users <= max_users`
     * must always hold. Fixed per the correction directive's own stated
     * preference ("If Starter remains 15 PTW users, max_users must
     * support at least 15 User Accounts") by raising Starter's
     * `max_users` 10 -> 15, rather than lowering `max_ptw_users` below
     * the phase's own explicit stated baseline. Professional (50/50) and
     * Enterprise (null/null) were already internally consistent and are
     * unchanged. `PlatformController::validatePlan()` now also enforces
     * this relationship server-side for any future Plan edit -- see that
     * method's own doc comment.
     */
    public function run(): void
    {
        $packages = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'A fully operational HSE product for a single company -- incidents, observations, inspections, PPE, PTW, CAPA, and every other HSE module, without requiring HRD.',
                // Starter -- HSE-focused access.
                'price_monthly' => 499000,
                'price_yearly' => 4990000,
                'currency' => 'IDR',
                'trial_days' => null,
                // v2.17.1 fix: max_users raised 10 -> 15 so max_ptw_users
                // (this phase's own explicit "Starter = 15" baseline) is
                // never larger than the pool of User Accounts it's a
                // subset of. See this seeder's own class-level doc
                // comment for the full correction reasoning.
                'max_users' => 15,
                'max_companies' => 1,
                'max_ptw_users' => 15,
                'features' => ['employees', 'ppe', 'kpi_input', 'reports'],
                'is_public' => true,
                'is_custom' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'HSE plus HRD/workforce management and cross-department management visibility, for growing operations across multiple companies.',
                // Professional -- HSE + Management + People/HR.
                'price_monthly' => 999000,
                'price_yearly' => 9990000,
                'currency' => 'IDR',
                'trial_days' => 14,
                'max_users' => 50,
                'max_companies' => 5,
                'max_ptw_users' => 50,
                'features' => ['employees', 'ppe', 'kpi_input', 'reports', 'projects', 'daily_reports', 'material_requests'],
                'is_public' => true,
                'is_custom' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Full IOMS -- every department (HSE, HRD, Project Management, Logistics/PPIC, Warehouse, Procurement, Asset Management, Maintenance, Quality Control) and unlimited users/companies.',
                // Enterprise -- the full standardized platform.
                'price_monthly' => 1999000,
                'price_yearly' => 19990000,
                'currency' => 'IDR',
                'trial_days' => null,
                'max_users' => null,
                'max_companies' => null,
                'max_ptw_users' => null,
                'features' => ['employees', 'ppe', 'kpi_input', 'reports', 'projects', 'daily_reports', 'material_requests'],
                'is_public' => true,
                // v2.50.0: was `is_custom => true`, which rendered Enterprise as
                // "Hubungi Kami" with no price. Enterprise is the most complete
                // STANDARDIZED tier, not a negotiated custom build -- it has a
                // published price like every other plan. "Build once, improve for
                // everyone": no per-customer development is being sold here.
                'is_custom' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($packages as $package) {
            Package::updateOrCreate(['slug' => $package['slug']], $package);
        }
    }
}
