<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v2.52.0 -- current approved launch pricing and the CAPACITY MODEL.
 *
 * Supersedes 2026_09_17_100220. Same reasoning as that migration: the
 * runtime source of truth is the `packages` table, not the seeder, so a
 * commercial decision has to arrive as a migration or it never reaches a
 * deployment that has already been seeded.
 *
 * THE CAPACITY MODEL, stated once so it stops being re-derived:
 *
 *   max_users     = how many IOMS LOGIN ACCOUNTS the tenant may create.
 *   max_ptw_users = how many OF THOSE ACCOUNTS may be granted PTW Access.
 *
 * PTW Access is a permission on an existing account, never an extra pool
 * of accounts. "10 Users, 5 PTW Access" means ten people can sign in and
 * five of those ten may raise a Permit To Work -- it does NOT mean
 * fifteen accounts. `max_ptw_users <= max_users` therefore always holds,
 * and this migration asserts it rather than assuming it.
 */
return new class extends Migration
{
    /** The approved launch catalog. Kept in step with PackageSeeder. */
    private const CATALOG = [
        'starter' => [
            'price_monthly' => 299000,
            'price_yearly' => 2990000,
            'max_users' => 10,
            'max_ptw_users' => 5,
            'max_companies' => 1,
            'trial_days' => null,
            'description' => 'Health, Safety & Environment lengkap untuk satu perusahaan -- insiden, observasi, inspeksi, APD, PTW, CAPA, dan seluruh modul HSE lainnya.',
        ],
        'professional' => [
            'price_monthly' => 999000,
            'price_yearly' => 9990000,
            'max_users' => 50,
            'max_ptw_users' => 20,
            'max_companies' => 5,
            'trial_days' => 14,
            'description' => 'Health, Safety & Environment ditambah Human Resources dan visibilitas Management lintas departemen, untuk operasi yang berkembang di beberapa perusahaan.',
        ],
        'enterprise' => [
            'price_monthly' => 1999000,
            'price_yearly' => 19990000,
            // Null is how this entitlement architecture expresses "no
            // ceiling" -- the highest standardized capacity it supports.
            // Enterprise is the fullest STANDARDIZED tier, not a
            // custom-development tier.
            'max_users' => null,
            'max_ptw_users' => null,
            'max_companies' => null,
            'trial_days' => null,
            'description' => 'Seluruh platform IOMS -- setiap departemen (Health, Safety & Environment, Human Resources, Project Management, Logistics / PPIC, Warehouse, Procurement, Assets, Maintenance, Quality Control) dengan kapasitas pengguna dan perusahaan tertinggi.',
        ],
    ];

    public function up(): void
    {
        foreach (self::CATALOG as $slug => $plan) {
            // The invariant, enforced rather than assumed. A plan that
            // allowed more PTW seats than login accounts would be selling
            // a quota the tenant could never physically reach.
            if ($plan['max_users'] !== null && $plan['max_ptw_users'] !== null
                && $plan['max_ptw_users'] > $plan['max_users']) {
                throw new RuntimeException("Plan {$slug}: max_ptw_users cannot exceed max_users.");
            }

            DB::table('packages')->where('slug', $slug)->update([
                ...$plan,
                'currency' => 'IDR',
                'is_custom' => false,
                'is_public' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);
        }

        // Any OTHER plan a Platform Admin created by hand is left alone on
        // price -- but a null PTW allowance means "unlimited" to the
        // entitlement layer, which is almost never what a capped plan
        // intends. Where such a plan caps users but not PTW seats, the PTW
        // allowance is brought down to the user cap: the largest value
        // that is both safe and cannot reduce a quota anyone could
        // actually have been using.
        DB::table('packages')
            ->whereNotIn('slug', array_keys(self::CATALOG))
            ->whereNotNull('max_users')
            ->whereNull('max_ptw_users')
            ->update(['max_ptw_users' => DB::raw('max_users'), 'updated_at' => now()]);

        // And clamp any custom plan that already violates the invariant.
        DB::table('packages')
            ->whereNotNull('max_users')
            ->whereNotNull('max_ptw_users')
            ->whereColumn('max_ptw_users', '>', 'max_users')
            ->update(['max_ptw_users' => DB::raw('max_users'), 'updated_at' => now()]);
    }

    /**
     * Not reversible. Rolling back would restore superseded prices and, in
     * the custom-plan case, re-open an unlimited PTW allowance on a capped
     * plan. Prices and capacity are business data -- change them from
     * Platform Admin > Plans.
     */
    public function down(): void {}
};
