<?php

use App\Models\Package;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.82.0 -- THE APPROVED PRICING MODEL, AND THE TWO THINGS THE OLD
     * SCHEMA COULD NOT EXPRESS.
     *
     * The catalogue becomes three tiers with a small included-user
     * allowance and paid extras, replacing four tiers with large hard
     * caps:
     *
     *   Starter       Rp189.000/mo    3 included users   HSE
     *   Professional  Rp555.000/mo   10 included users   HSE + People
     *   Business    Rp1.249.000/mo   25 included users   HSE + People + Logistics/Warehouse
     *
     * TWO NEW COLUMNS, because two rules genuinely had nowhere to live.
     *
     * `packages.annual_months` -- HOW MUCH SERVICE A YEARLY PAYMENT BUYS.
     * The annual benefit is not one rule across the ladder:
     *
     *   Starter       pay 12 months, get 12   (no annual benefit)
     *   Professional  pay 11 months, get 12   (a price discount)
     *   Business      pay 12 months, get 14   (extra SERVICE, not a discount)
     *
     * Business is the reason this is a column. Its annual price is exactly
     * twelve monthly payments -- there is no discount to derive from the
     * two prices, and the benefit is invisible unless the PERIOD knows
     * about it. Presenting it as "2 months free" would be a different
     * offer from the one that was approved.
     *
     * `subscriptions.additional_users` -- PAID CAPACITY BEYOND THE PLAN.
     * The included allowance belongs to the package; what a tenant has
     * bought on top belongs to the subscription, because it is that
     * customer's commercial arrangement and it survives a plan change.
     * Defaults to 0, so every existing subscription behaves exactly as it
     * does today.
     *
     * `max_users` IS NOW THE INCLUDED ALLOWANCE, not a hard ceiling. The
     * column is reused rather than replaced: it has always meant "how many
     * login accounts this plan carries", and the effective limit is now
     * that number plus whatever the tenant has purchased. Renaming it
     * would have touched the plan editor, the validator, the seeder, the
     * platform UI and four tests to express the same fact.
     *
     * ENTERPRISE IS RETIRED, NOT DELETED. It leaves the public catalogue
     * (`is_public = false`) and keeps its row, its price and its grants,
     * because tenants are subscribed to it. Deleting it would orphan live
     * subscriptions and rewrite the history of paid invoices; hiding it
     * stops it being sold while every existing customer continues exactly
     * as before. It stays `is_active` for the same reason.
     *
     * EXISTING SUBSCRIPTIONS ARE NOT REPRICED. `agreed_price_monthly` /
     * `agreed_price_yearly` were added in v2.60.0 precisely so a catalogue
     * change cannot reprice a live customer, and this migration does not
     * touch a single subscription row's price. A customer on the old
     * Starter keeps paying the old Starter price until they change plan.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('packages', 'annual_months')) {
            Schema::table('packages', function (Blueprint $table) {
                // Twelve is the neutral default, so any plan created
                // without an opinion behaves as a plain year.
                $table->unsignedTinyInteger('annual_months')->default(12)->after('price_yearly');
            });
        }

        if (! Schema::hasColumn('subscriptions', 'additional_users')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->unsignedInteger('additional_users')->default(0)->after('seat_limit');
            });
        }

        /*
         * The approved catalogue, applied to an EXISTING database.
         * `db:seed` does not re-run on a live deployment -- the v2.51.0
         * lesson recorded in docs/CONVENTIONS.md -- so the figures live
         * here as well as in PackageSeeder, and a test asserts the two
         * agree.
         *
         * Written literally rather than read from config, for the same
         * reason the v2.60.0 repricing was: a migration that reads the
         * catalogue it is migrating is a migration that can only ever
         * agree with itself.
         */
        $catalogue = [
            'starter' => [
                'price_monthly' => 189000,
                'price_yearly' => 2268000,   // 12 x 189.000, 12 months access
                'annual_months' => 12,
                'max_users' => 3,
                'sort_order' => 1,
                'is_public' => true,
            ],
            'professional' => [
                'price_monthly' => 555000,
                'price_yearly' => 6105000,   // 11 x 555.000, 12 months access
                'annual_months' => 12,
                'max_users' => 10,
                'sort_order' => 2,
                'is_public' => true,
            ],
            'business' => [
                'price_monthly' => 1249000,
                'price_yearly' => 14988000,  // 12 x 1.249.000, 14 months access
                'annual_months' => 14,
                'max_users' => 25,
                'sort_order' => 3,
                'is_public' => true,
                // The copy has to move with the scope. It still named
                // Project Management and Procurement, which this release
                // removes from the tier -- a card advertising departments
                // the plan no longer grants is worse than one saying less.
                'description' => 'Health, Safety & Environment and People, plus Logistics / PPIC and Warehouse — so work, materials and stock stop living in separate systems.',
            ],
        ];

        foreach ($catalogue as $slug => $values) {
            DB::table('packages')->where('slug', $slug)->update($values + ['updated_at' => now()]);
        }

        // Retired from sale, kept for the tenants on it.
        DB::table('packages')->where('slug', 'enterprise')->update([
            'is_public' => false,
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverses the schema and restores the v2.60.0 four-tier catalogue.
     *
     * Deliberately does NOT try to reconstruct additional-user purchases:
     * dropping the column discards them, which is what a rollback of this
     * feature means. Nothing else can be inferred back.
     */
    public function down(): void
    {
        $previous = [
            'starter' => ['price_monthly' => 299000, 'price_yearly' => 2990000, 'max_users' => 10, 'is_public' => true],
            'professional' => ['price_monthly' => 799000, 'price_yearly' => 7990000, 'max_users' => 50, 'is_public' => true],
            'business' => ['price_monthly' => 1499000, 'price_yearly' => 14990000, 'max_users' => 150, 'is_public' => true],
            'enterprise' => ['is_public' => true],
        ];

        foreach ($previous as $slug => $values) {
            DB::table('packages')->where('slug', $slug)->update($values + ['updated_at' => now()]);
        }

        if (Schema::hasColumn('subscriptions', 'additional_users')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('additional_users');
            });
        }

        if (Schema::hasColumn('packages', 'annual_months')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropColumn('annual_months');
            });
        }
    }
};
