<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v2.51.0 -- the pricing defect, fixed at its actual source.
 *
 * v2.50.0 changed PackageSeeder and every pricing surface, and the public
 * pages still rendered "Gratis / Rp490 / Rp1.490". The reason is that a
 * seeder is not the runtime source of truth -- the `packages` TABLE is.
 * `db:seed` had never been re-run against this deployment, so the rows
 * still held the original 0 / 49 / 149 monthly and 0 / 490 / 1490 yearly
 * placeholders: USD-scale numbers wearing an IDR label. Editing the
 * seeder again would have changed nothing for a second release running.
 *
 * So the correction belongs in a migration, which every environment runs
 * exactly once on deploy, whether or not anyone remembers to seed.
 *
 * IT IS NOT ONLY THE PRICES. Verifying the fix against the live catalog
 * showed the same drift in the plan DEFINITION, and one part of it was a
 * real entitlement defect rather than a cosmetic one:
 *
 *   - `max_ptw_users` was NULL on every plan. Null means "no limit" to
 *     the entitlement layer, so every tier was silently granting
 *     unlimited PTW Access seats regardless of what it sold.
 *   - Starter's `max_users` was 10 while its PTW allowance is 15, which
 *     is impossible: PTW seats are a SUBSET of user accounts, so a
 *     Starter tenant could never reach the quota it was told it had.
 *     (v2.17.1 recorded the fix as raising max_users to 15; the seeder
 *     carried it, the database never did.)
 *   - Descriptions still described Starter as "core HR and HSE tracking"
 *     when Starter is the HSE tier and HR belongs to Professional.
 *
 * These are the approved launch figures. The migration is idempotent and
 * touches ONLY the three standardized plan slugs by name -- a custom plan
 * a Platform Admin created by hand is left alone, and re-running it is a
 * no-op because it writes absolute values rather than deltas.
 */
return new class extends Migration
{
    /**
     * The approved IOMS launch catalog, kept in step with PackageSeeder so
     * a FRESH install and an UPGRADED install end up identical.
     */
    private const LAUNCH_CATALOG = [
        'starter' => [
            'price_monthly' => 499000,
            'price_yearly' => 4990000,
            // 15, not 10: max_ptw_users below is a subset of this, and a
            // plan cannot allow more PTW seats than user accounts.
            'max_users' => 15,
            'max_companies' => 1,
            'max_ptw_users' => 15,
            'trial_days' => null,
            'description' => 'A fully operational HSE product for a single company -- incidents, observations, inspections, PPE, PTW, CAPA, and every other HSE module, without requiring HRD.',
        ],
        'professional' => [
            'price_monthly' => 999000,
            'price_yearly' => 9990000,
            'max_users' => 50,
            'max_companies' => 5,
            'max_ptw_users' => 50,
            'trial_days' => 14,
            'description' => 'HSE plus HRD/workforce management and cross-department management visibility, for growing operations across multiple companies.',
        ],
        'enterprise' => [
            'price_monthly' => 1999000,
            'price_yearly' => 19990000,
            // Null across the board = the highest standardized tier, with
            // no capacity ceiling. Still one standard product.
            'max_users' => null,
            'max_companies' => null,
            'max_ptw_users' => null,
            'trial_days' => null,
            'description' => 'Full IOMS -- every department (HSE, HRD, Project Management, Logistics/PPIC, Warehouse, Procurement, Asset Management, Maintenance, Quality Control) and unlimited users/companies.',
        ],
    ];

    public function up(): void
    {
        foreach (self::LAUNCH_CATALOG as $slug => $plan) {
            DB::table('packages')->where('slug', $slug)->update([
                ...$plan,
                'currency' => 'IDR',
                // Every tier is a standardized plan with a published
                // price. Enterprise is the highest-capacity standardized
                // tier, not a negotiated custom build -- `is_custom` made
                // PricingService render it as "Hubungi Kami" with no price.
                'is_custom' => false,
                'is_public' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Deliberately not reversible. The previous values were placeholder
     * figures that rendered as "Gratis"/"Rp490", alongside a NULL PTW seat
     * limit that granted unlimited PTW access on every tier. Restoring
     * them would reintroduce both defects, and a rollback that puts wrong
     * prices and a broken entitlement back in front of customers is worse
     * than one that leaves correct values in place. Plans are business
     * data -- change them from Platform Admin > Plans, not by rolling back
     * a migration.
     */
    public function down(): void {}
};
