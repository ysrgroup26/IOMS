<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v2.60.0 -- THE APPROVED FOUR-TIER IOMS PRICING.
 *
 * A migration rather than a seeder edit, for the reason recorded in
 * CONVENTIONS: the `packages` table is what renders, `db:seed` does not
 * re-run on an existing deployment, and v2.51.0 shipped a whole release
 * where production still showed the previous prices because only the
 * seeder had been changed.
 *
 *   Starter       Rp   299.000 / Rp  2.990.000    10 users   1 unit
 *   Professional  Rp   799.000 / Rp  7.990.000    50 users   2 units
 *   Business      Rp 1.499.000 / Rp 14.990.000   150 users   4 units
 *   Enterprise    Rp 2.499.000 / Rp 24.990.000   unlimited   unlimited
 *
 * WHY PROFESSIONAL GOES DOWN. Measured by implemented route prefixes,
 * Starter (HSE, 31) is already most of the product and Professional added
 * one department (HR, 11) for 3.3x the price, while Enterprise added eight
 * for 2x. The expensive step was the small one. Professional at 799.000
 * and Business at 1.499.000 restore a ladder whose multipliers DECREASE as
 * the absolute price rises: 2.67x, 1.88x, 1.67x.
 *
 * ANNUAL IS MONTHLY x 10 for every tier -- the existing IOMS rule, kept.
 * PricingService derives the saving from the plan's own two prices, so
 * nothing here asserts a percentage.
 *
 * ENTERPRISE'S CAPACITY IS ITS PRODUCT. It adds only about ten thin route
 * prefixes over Business; what it actually sells is unlimited Operating
 * Units and unlimited users, plus the legal-entity structures and
 * per-unit authorization from v2.54.0. Both limits are NULL, which is this
 * codebase's long-standing "unlimited" convention on these columns.
 *
 * NOTHING IS DELETED. Existing plans are updated in place, so every live
 * subscription keeps its `package_id`. Business is inserted only if it is
 * absent, so a re-run cannot create a duplicate.
 */
return new class extends Migration
{
    private const PLANS = [
        'starter' => [
            'name' => 'Starter',
            'price_monthly' => 299000,
            'price_yearly' => 2990000,
            'max_users' => 10,
            'max_companies' => 1,
            'sort_order' => 1,
            'description' => 'Digitalize Health, Safety & Environment for one operating unit — incidents, observations, inspections, PPE, Permit To Work, CAPA and every other HSE module.',
        ],
        'professional' => [
            'name' => 'Professional',
            'price_monthly' => 799000,
            'price_yearly' => 7990000,
            'max_users' => 50,
            'max_companies' => 2,
            'sort_order' => 2,
            'description' => 'Health, Safety & Environment plus People and Workforce — employees, competency and certificate expiry, shifts and rosters, leave — for an organization running up to two operating units.',
        ],
        'business' => [
            'name' => 'Business',
            'price_monthly' => 1499000,
            'price_yearly' => 14990000,
            'max_users' => 150,
            'max_companies' => 4,
            'sort_order' => 3,
            'description' => 'Cross-functional operational visibility: Health, Safety & Environment and People, plus Project Management, Logistics / PPIC and Procurement — so work, materials and approvals stop living in separate systems.',
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price_monthly' => 2499000,
            'price_yearly' => 24990000,
            // NULL is this schema's "unlimited" on both columns.
            'max_users' => null,
            'max_companies' => null,
            'sort_order' => 4,
            'description' => 'The complete IOMS platform — every operational department, unlimited operating units and unlimited user accounts, with per-unit authorization and legal entity structures where they apply.',
        ],
    ];

    public function up(): void
    {
        foreach (self::PLANS as $slug => $plan) {
            $exists = DB::table('packages')->where('slug', $slug)->exists();

            $attributes = [
                ...$plan,
                'currency' => 'IDR',
                'is_active' => true,
                'is_public' => true,
                // Enterprise is the fullest STANDARDIZED tier, never a
                // negotiated build -- `is_custom` would render it as
                // "Contact us" with no price, which contradicts that.
                'is_custom' => false,
                'max_ptw_users' => null,
                // IOMS does not sell a free trial. Professional was still
                // carrying trial_days = 14 from an earlier catalogue and the
                // Plans page was advertising it -- normalised here so the
                // update path clears it, not just the insert path.
                'trial_days' => null,
                'updated_at' => now(),
            ];

            if ($exists) {
                DB::table('packages')->where('slug', $slug)->update($attributes);

                continue;
            }

            DB::table('packages')->insert([
                ...$attributes,
                'slug' => $slug,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Restores the three-tier prices and removes Business ONLY if nothing
     * is subscribed to it -- a plan with live customers is not something a
     * rollback may delete out from under them.
     */
    public function down(): void
    {
        $previous = [
            'starter' => [299000, 2990000, 10, 1],
            'professional' => [999000, 9990000, 50, 2],
            'enterprise' => [1999000, 19990000, null, null],
        ];

        foreach ($previous as $slug => [$monthly, $yearly, $users, $units]) {
            DB::table('packages')->where('slug', $slug)->update([
                'price_monthly' => $monthly,
                'price_yearly' => $yearly,
                'max_users' => $users,
                'max_companies' => $units,
                'updated_at' => now(),
            ]);
        }

        $business = DB::table('packages')->where('slug', 'business')->first();

        if ($business && ! DB::table('subscriptions')->where('package_id', $business->id)->exists()) {
            DB::table('packages')->where('id', $business->id)->delete();
        }
    }
};
