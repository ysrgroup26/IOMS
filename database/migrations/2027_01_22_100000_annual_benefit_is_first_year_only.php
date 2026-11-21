<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * v2.144.0 -- THE ANNUAL BENEFIT IS A FIRST-YEAR OFFER (Founder, 21 Nov 2026).
 *
 *   Starter       pay 12, get 13 -- was 12/12
 *   Professional  pay 12, get 13 -- was pay 11, get 12 (yearly 6.660.000)
 *   Business      pay 12, get 14
 *   (one rule for every plan: pay twelve months; marketing, 21 Nov 2026)
 *   both          FIRST yearly payment only; every later year is a normal
 *                 year: 12 x the agreed monthly price for 12 months (unless
 *                 an operator grants a promotion deliberately).
 *
 * `subscriptions.annual_promo_used_at` records the moment a subscription
 * received its first-year terms. Null = still available. A subscription that
 * is ALREADY yearly has had its first year, so it is marked used from its
 * start -- no customer receives the benefit twice because the rule arrived
 * after they bought.
 *
 * Enterprise has been off the public catalogue since v2.85.0 and has no
 * subscribers; it is now also inactive so no operator path can sell it.
 * The row stays (history, foreign keys). Retired means not for sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('subscriptions', 'annual_promo_used_at')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->timestamp('annual_promo_used_at')->nullable()->after('billing_cycle');
            });
        }

        DB::table('subscriptions')
            ->where('billing_cycle', 'yearly')
            ->whereNull('annual_promo_used_at')
            ->update(['annual_promo_used_at' => DB::raw('COALESCE(starts_at, created_at)')]);

        DB::table('packages')->where('slug', 'starter')->update(['annual_months' => 13]);
        DB::table('packages')->where('slug', 'professional')->update(['annual_months' => 13, 'price_yearly' => 6660000]);
        DB::table('packages')->where('slug', 'business')->update(['annual_months' => 14]);

        $enterprise = DB::table('packages')->where('slug', 'enterprise')->value('id');
        if ($enterprise !== null && ! DB::table('subscriptions')->where('package_id', $enterprise)->exists()) {
            DB::table('packages')->where('id', $enterprise)->update(['is_active' => false, 'is_public' => false]);
        }
    }

    /** One-way, like 2027_01_21: recovery is from backup, not by re-granting a benefit. */
    public function down(): void {}
};
