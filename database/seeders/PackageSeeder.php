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
     * v2.60.0: `features` IS GONE. v1.11.15 had already established that
     * nothing read `hasFeature()`/`features` at runtime and that the real
     * enforcement is the Module/Workspace grant tables -- so it was a
     * third, non-authoritative list of what a plan includes, sitting
     * beside the real one and drifting from it. It has been dropped from
     * the schema (see the drop_dead_package_features_column migration)
     * rather than left dormant for somebody to reach for.
     *
     * v2.52.0 SUPERSEDES v2.51.0 on prices AND capacity. Launch pricing is
     * now Starter 299.000/2.990.000, Professional 999.000/9.990.000,
     * Enterprise 1.999.000/19.990.000. The capacity model is stated once,
     * here and in 2026_09_18_100230: `max_users` is how many LOGIN ACCOUNTS
     * the tenant may create, and `max_ptw_users` is how many OF THOSE
     * ACCOUNTS may be granted PTW Access. PTW Access is a permission on an
     * existing account, never an extra pool of accounts, so Starter's
     * "10 Users / 5 PTW Access" means ten people can sign in and five of
     * them may raise a permit -- not fifteen accounts.
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
        // v2.60.0 -- THE APPROVED FOUR-TIER CATALOGUE.
        //
        // This seeder is for a FRESH install. An existing deployment is
        // updated by 2026_09_26_100270_establish_four_tier_pricing, because
        // `db:seed` does not re-run on a live database -- the v2.51.0
        // lesson, recorded in CONVENTIONS. The two must state the same
        // figures, and a test asserts they do.
        //
        // Annual is monthly x 10 on every tier (~16.67%, shown as 17%).
        // PricingService derives the saving from the plan's own two prices,
        // so no percentage is written down anywhere.
        //
        // What each tier GRANTS lives in config/plans.php, not here.
        $packages = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Digitalize Health, Safety & Environment for one operating unit — incidents, observations, inspections, PPE, Permit To Work, CAPA and every other HSE module.',
                'price_monthly' => 299000,
                'price_yearly' => 2990000,
                'currency' => 'IDR',
                'trial_days' => null,
                'max_users' => 10,
                'max_companies' => 1,
                // v2.53.0: PTW Access is no longer a sold capacity. Null
                // means the entitlement layer applies no ceiling; the
                // PERMISSION (users.ptw_access) and its server-side gate
                // are untouched.
                'max_ptw_users' => null,
                'is_public' => true,
                'is_custom' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'Health, Safety & Environment plus People and Workforce — employees, competency and certificate expiry, shifts and rosters, leave — for an organization running up to two operating units.',
                // v2.60.0: 999.000 -> 799.000. Professional cost 3.3x
                // Starter and added ONE department while Enterprise added
                // eight for 2x -- the expensive step was the small one.
                'price_monthly' => 799000,
                'price_yearly' => 7990000,
                'currency' => 'IDR',
                'trial_days' => null,
                'max_users' => 50,
                'max_companies' => 2,
                'max_ptw_users' => null,
                'is_public' => true,
                'is_custom' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'Cross-functional operational visibility: Health, Safety & Environment and People, plus Project Management, Logistics / PPIC and Procurement — so work, materials and approvals stop living in separate systems.',
                // The bridge tier, and the largest scope jump in the ladder:
                // three departments at the middle price. That is what earns
                // it "Most Popular" rather than a badge chosen for effect.
                'price_monthly' => 1499000,
                'price_yearly' => 14990000,
                'currency' => 'IDR',
                'trial_days' => null,
                'max_users' => 150,
                'max_companies' => 4,
                'max_ptw_users' => null,
                'is_public' => true,
                'is_custom' => false,
                'sort_order' => 3,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'The complete IOMS platform — every operational department, unlimited operating units and unlimited user accounts, with per-unit authorization and legal entity structures where they apply.',
                // v2.60.0: 1.999.000 -> 2.499.000. Enterprise adds only a
                // handful of thin departments over Business; what it sells
                // is UNLIMITED operating units and users plus multi-entity
                // governance. At 1.999.000 it sat too close to Business and
                // would have cannibalised it.
                'price_monthly' => 2499000,
                'price_yearly' => 24990000,
                'currency' => 'IDR',
                'trial_days' => null,
                // Null is this schema's "unlimited" on both columns.
                'max_users' => null,
                'max_companies' => null,
                'max_ptw_users' => null,
                'is_public' => true,
                // v2.50.0: was `is_custom => true`, which rendered Enterprise
                // as "Hubungi Kami" with no price. Enterprise is the most
                // complete STANDARDIZED tier, not a negotiated custom build.
                // "Build once, improve for everyone": no per-customer
                // development is sold here.
                'is_custom' => false,
                'sort_order' => 4,
            ],
        ];
        foreach ($packages as $package) {
            Package::updateOrCreate(['slug' => $package['slug']], $package);
        }
    }
}
