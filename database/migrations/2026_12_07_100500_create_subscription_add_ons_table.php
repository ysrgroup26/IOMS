<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.104.0 -- AN ADD-ON IS A PURCHASE WITH ITS OWN CLOCK.
 *
 * Extra capacity used to be a number on the subscription
 * (`subscriptions.additional_users`, `additional_my_work_packs`): the
 * quantity went live immediately and the charge appeared on the NEXT
 * renewal invoice. That was a deliberate v2.82.0 decision and it has two
 * consequences the approved model now rejects:
 *
 *   - capacity is used before it is paid for, so a customer who never
 *     renews has had it for free;
 *   - everything lands on the subscription's renewal date, so a customer
 *     who buys capacity the day after renewing waits a year to be charged.
 *
 * An add-on is now a purchase, settled the same way a PTW top-up already
 * is: an invoice is raised immediately, nothing is granted until a VERIFIED
 * payment settles it, and the add-on then runs on ITS OWN period --
 * `starts_at` and `ends_at` that belong to the add-on, not to the
 * subscription. Renewal is per add-on, on its own date.
 *
 * NO PRORATION, IN EITHER DIRECTION. A period is bought whole. Buying
 * mid-month does not discount the first period, and cancelling does not
 * refund the rest of one -- which is why `ends_at` SURVIVES cancellation
 * (see `cancelled_at` below).
 *
 * EARLY PAYMENT MUST NOT MOVE THE SCHEDULE. A renewal invoice paid before
 * the add-on lapses extends from the existing `ends_at`, never from "now",
 * so paying early buys the same period it always did and the anniversary
 * never drifts. That is arithmetic in the service; the column exists so
 * the arithmetic has something stable to add to.
 *
 * WHAT THIS MIGRATION DELIBERATELY DOES NOT TOUCH. The two columns on
 * `subscriptions` stay, keep their values, and keep being counted. They now
 * mean "capacity granted under the earlier arrangement, or granted by an
 * operator" -- already in use, already billed that way, and re-charging it
 * through a new purchase would bill a customer twice for capacity they
 * already have. No new capacity is created there; new purchases are rows in
 * this table. `Subscription::additionalUsers()` is the one place the two are
 * added together.
 *
 * Portable by design: one new table, nullable columns and plain indexes, so
 * it applies identically on MySQL and on the SQLite the suite runs against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_add_ons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            // Which capacity this buys. PTW documents are deliberately absent:
            // a top-up is a one-off credit with no period at all, and it keeps
            // the model it already has.
            $table->string('type', 40);

            $table->unsignedInteger('quantity');

            /*
             * The price AGREED AT PURCHASE, per unit per month, snapshotted
             * for the same reason `subscriptions.agreed_price_monthly` is:
             * editing the catalogue must never reprice a customer who
             * already bought. Renewal reads this, not configuration.
             */
            $table->decimal('unit_price', 12, 2);
            $table->string('currency', 3)->default('IDR');

            $table->string('billing_cycle', 16);

            // pending_payment -> active -> cancelled. Nothing is granted
            // while pending: the quantity is not counted until money is
            // confirmed.
            $table->string('status', 24)->default('pending_payment');

            // The add-on's OWN period. Null until a payment settles it,
            // which is what "activation only after payment" means here.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            /*
             * Cancellation stops the NEXT renewal and nothing else. The paid
             * period is preserved: `ends_at` is not moved, the capacity
             * stays counted until that date, and no refund is computed,
             * because the period was bought whole.
             */
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // "What does this tenant currently hold" and "which add-ons fall
            // due" are the two questions asked of this table.
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'ends_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            /*
             * Which add-on this invoice is for, so settlement knows what to
             * activate or extend without re-deriving it from the line items.
             * Nullable because every existing invoice has none, and
             * `nullOnDelete` because an invoice is a financial record that
             * must outlive the thing it was raised for.
             */
            $table->foreignId('subscription_add_on_id')
                ->nullable()
                ->after('subscription_id')
                ->constrained('subscription_add_ons')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['subscription_add_on_id']);
            $table->dropColumn('subscription_add_on_id');
        });

        Schema::dropIfExists('subscription_add_ons');
    }
};
