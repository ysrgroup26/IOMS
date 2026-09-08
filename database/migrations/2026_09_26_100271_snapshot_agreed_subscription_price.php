<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.60.0 -- WHAT A CUSTOMER AGREED TO PAY, RECORDED ON THEIR SUBSCRIPTION.
 *
 * THE PROBLEM THIS EXISTS FOR. `subscriptions` stored `package_id` and a
 * billing cycle, and every amount was recomputed from the package's
 * CURRENT price at the moment it was needed --
 * `PricingService::amountFor($package, $cycle)` in the checkout invoice,
 * in the registration record, and on the Billing page. Nothing anywhere
 * held the figure the customer actually agreed to.
 *
 * That was survivable only while prices never moved. This release moves
 * Professional from Rp999.000 to Rp799.000 and Enterprise from
 * Rp1.999.000 to Rp2.499.000. Without a snapshot, every existing
 * Enterprise customer's next renewal would silently become 25% more
 * expensive than the price they signed up at -- not a decision anyone
 * took, just an emergent consequence of editing a catalogue row.
 *
 * THE DESIGN, deliberately the smallest thing that fixes it: two nullable
 * columns holding the agreed amounts, plus the currency. No new table, no
 * price-history log, no proration engine, no change to how payments are
 * verified or subscriptions activated.
 *
 *   agreed_price_monthly   what this customer pays per month
 *   agreed_price_yearly    what this customer pays per year
 *   agreed_currency        the currency both were agreed in
 *
 * BOTH cycles are stored, not just the active one, so a customer switching
 * monthly<->yearly keeps their agreed pricing on both sides rather than
 * being silently repriced by the switch.
 *
 * NULL MEANS "follow the catalogue". A subscription with no snapshot reads
 * the package price exactly as before, so this migration cannot change
 * behaviour on its own and any row it fails to backfill degrades to the
 * old path rather than to zero.
 *
 * BACKFILL. Every existing subscription is stamped with its package's
 * price AS IT IS RIGHT NOW -- which is why this migration must run BEFORE
 * the repricing one. It does not: migrations run in filename order and
 * 100270 (repricing) precedes 100271 (this). So the backfill deliberately
 * reads the ORIGINAL three-tier prices from a literal table below rather
 * than from `packages`, because by the time this runs the catalogue has
 * already been updated. Reading the live table here would stamp every
 * existing customer with the NEW price and defeat the entire purpose.
 */
return new class extends Migration
{
    /**
     * The prices in force immediately before v2.60.0, by slug and cycle.
     * A literal table on purpose -- see the backfill note above.
     */
    private const PRICES_BEFORE_V2_60 = [
        'starter' => [299000.00, 2990000.00],
        'professional' => [999000.00, 9990000.00],
        'enterprise' => [1999000.00, 19990000.00],
    ];

    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->decimal('agreed_price_monthly', 12, 2)->nullable()->after('billing_cycle');
            $table->decimal('agreed_price_yearly', 12, 2)->nullable()->after('agreed_price_monthly');
            $table->string('agreed_currency', 3)->nullable()->after('agreed_price_yearly');
        });

        foreach (self::PRICES_BEFORE_V2_60 as $slug => [$monthly, $yearly]) {
            $packageId = DB::table('packages')->where('slug', $slug)->value('id');

            if (! $packageId) {
                continue;
            }

            DB::table('subscriptions')
                ->where('package_id', $packageId)
                // Only rows that have no snapshot yet: re-running must not
                // overwrite a price somebody has since agreed deliberately.
                ->whereNull('agreed_price_monthly')
                ->whereNull('agreed_price_yearly')
                ->update([
                    'agreed_price_monthly' => $monthly,
                    'agreed_price_yearly' => $yearly,
                    'agreed_currency' => 'IDR',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['agreed_price_monthly', 'agreed_price_yearly', 'agreed_currency']);
        });
    }
};
