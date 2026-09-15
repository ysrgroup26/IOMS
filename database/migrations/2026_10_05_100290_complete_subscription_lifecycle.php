<?php

use App\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.70.0 -- THE SUBSCRIPTION LIFECYCLE, COMPLETED.
 *
 * Before this release IOMS could sell a subscription and never do
 * anything with it again. `ends_at` was written at provisioning and no
 * code path ever read it to act: no scheduled job, no renewal invoice, no
 * access change, no state transition. `STATUS_EXPIRED` and
 * `STATUS_GRACE_PERIOD` existed as constants that NOTHING ever wrote.
 *
 * Three schema changes make the real lifecycle expressible.
 *
 * 1. THE STORED STATUS NARROWS TO THE DELIBERATE AXIS.
 *
 *    `status` now answers only "what did a human or a verified payment
 *    decide about this subscription": active, suspended, cancelled (plus
 *    trial). Where it is in TIME -- active / grace / lapsed -- is derived
 *    from `ends_at` on every read, exactly as Employee disciplinary
 *    standing and PurchaseOrderItem delivery are derived.
 *
 *    That is not a stylistic choice. A stored `expired` is correct on the
 *    day a job writes it and wrong the moment a customer pays, and it
 *    needs a scheduled job to stay true. A derived one cannot drift and
 *    needs nothing running. `expired`/`grace_period` are removed from the
 *    vocabulary; any row carrying one is normalised to `active`, whose
 *    time state then derives correctly from the dates already on the row.
 *
 * 2. A SUBSCRIPTION CAN CARRY A PENDING CHANGE.
 *
 *    A downgrade or a monthly<->yearly switch must NOT take effect the
 *    moment it is asked for -- the customer paid for the period they are
 *    in, and a downgrade applied immediately could drop a tenant below
 *    the seats or operating units it is actively using. The request is
 *    recorded and applied at the period boundary.
 *
 * 3. AN INVOICE SAYS WHAT IT BUYS.
 *
 *    The webhook must decide, from a verified payment alone, whether it is
 *    provisioning a new tenant, extending a period, or applying a plan
 *    change. Inferring that from which foreign keys happen to be null is
 *    exactly the kind of implicit contract that breaks quietly. `purpose`
 *    states it, and `target_package_id`/`target_billing_cycle` carry what
 *    an upgrade is for. The invoice becomes the contract the payment
 *    settles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // A change the customer asked for that takes effect at the end
            // of the period they already paid for.
            $table->foreignId('pending_package_id')->nullable()->after('package_id')
                ->constrained('packages')->nullOnDelete();
            $table->string('pending_billing_cycle')->nullable()->after('pending_package_id');
            $table->timestamp('pending_requested_at')->nullable()->after('pending_billing_cycle');

            // When the customer was last told their period is ending.
            // Stops a daily job emailing the same reminder every day.
            $table->timestamp('renewal_reminded_at')->nullable()->after('ends_at');
        });

        // The two statuses nothing ever wrote. Normalising to `active`
        // loses nothing: the row keeps its `ends_at`, and the derived time
        // state reads the same answer the stored one was trying to express.
        DB::table('subscriptions')
            ->whereIn('status', ['expired', 'grace_period'])
            ->update(['status' => Subscription::STATUS_ACTIVE]);

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('purpose')->default('renewal')->after('subscription_id')->index();
            $table->foreignId('target_package_id')->nullable()->after('purpose')
                ->constrained('packages')->nullOnDelete();
            $table->string('target_billing_cycle')->nullable()->after('target_package_id');
        });

        // Existing invoices predate `purpose`. One raised against a
        // registration was an onboarding invoice; anything else was a
        // manually-issued billing document, which is what `renewal` means
        // here -- money for a period of service.
        DB::table('invoices')->whereNotNull('registration_id')->update(['purpose' => 'onboarding']);

        // `tenants.status = 'expired'` is retired for the same reason.
        // Expiry is where a SUBSCRIPTION sits in time, derived from its
        // own dates; an account status is whether the platform operator
        // has the account open. Normalising to `active` opens nothing that
        // was closed -- `tenants.status` was read by NOTHING before this
        // release, so every tenant was effectively active regardless of
        // what this column said. From here it is enforced, and leaving a
        // stale `expired` behind would lock out a paying customer on the
        // day enforcement arrives.
        DB::table('tenants')->where('status', 'expired')->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_package_id');
            $table->dropColumn(['purpose', 'target_billing_cycle']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_package_id');
            $table->dropColumn(['pending_billing_cycle', 'pending_requested_at', 'renewal_reminded_at']);
        });

        // `expired`/`grace_period` are not restored: nothing wrote them
        // before this migration, so there is nothing to restore TO.
    }
};
