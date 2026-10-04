<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.94.0 -- WHAT A PAYMENT ROW HAS TO BE ABLE TO ANSWER LATER.
 *
 * `payment_transactions` recorded that an attempt existed and what became
 * of it: invoice, gateway, reference, status, amount. That is enough to
 * settle an invoice, and it was never enough to RECONCILE one.
 *
 * The questions an operator actually gets asked, and could not answer from
 * this table:
 *
 *   "The customer says they paid on Tuesday."    -> no paid_at. The row's
 *                                                   updated_at was the
 *                                                   closest thing, and it
 *                                                   moves for any write.
 *   "Which bank / VA / wallet did they use?"     -> no payment_method.
 *   "Duitku's dashboard shows reference X."      -> only OUR reference was
 *                                                   stored. Their id, the
 *                                                   one the customer and
 *                                                   the provider's support
 *                                                   both quote, was not.
 *   "Why did it fail?"                           -> no result code, no
 *                                                   reason.
 *   "Did the callback ever arrive?"              -> unanswerable. A
 *                                                   payment that failed
 *                                                   and a callback that
 *                                                   never came looked
 *                                                   identical.
 *
 * The raw payload was already kept on `payment_webhook_events`, so none of
 * this is newly captured information -- it is the same facts promoted onto
 * the row they describe, so answering a support question is a lookup rather
 * than a hunt through JSON keyed by an event id nobody has.
 *
 * EVERY COLUMN IS NULLABLE AND NOTHING IS BACKFILLED. An existing row keeps
 * its exact meaning: null means "we did not record this", which is the
 * truth for every attempt made before this migration. Inventing a paid_at
 * from updated_at would manufacture a payment time that was never observed.
 *
 * INTERNAL IDS STAY AUTHORITATIVE. `gateway_reference` is ours and remains
 * the key the callback is matched on; `provider_reference` is the
 * provider's own and is stored for humans and reconciliation, never used to
 * decide anything.
 *
 * Portable by design: nullable adds and a plain JSON column, no
 * MODIFY/ALTER and nothing read out of information_schema, so this applies
 * identically on MySQL and on the SQLite the suite runs against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            // The provider's own identifier for the transaction -- Duitku's
            // `reference`, Midtrans's `transaction_id`. An EXTERNAL
            // reference: recorded, quoted to support, never trusted to
            // decide anything.
            $table->string('provider_reference')->nullable()->after('gateway_reference');

            // Duitku's publisherOrderId, where the provider sends one.
            $table->string('publisher_order_id')->nullable()->after('provider_reference');

            // The channel the customer actually used (VA bank, wallet,
            // card). Provider-specific string, stored as given.
            $table->string('payment_method')->nullable()->after('publisher_order_id');

            // The provider's verbatim result code, kept alongside the status
            // IOMS mapped it to. When a mapping is wrong, this is the only
            // record of what the provider really said.
            $table->string('result_code')->nullable()->after('status');

            // Why a failed attempt failed, in the provider's words.
            $table->string('failure_reason', 500)->nullable()->after('result_code');

            // When money was confirmed, when the session lapsed, and when a
            // callback last arrived. Three different events that
            // `updated_at` was being asked to stand in for.
            $table->timestamp('paid_at')->nullable()->after('failure_reason');
            $table->timestamp('expired_at')->nullable()->after('paid_at');
            $table->timestamp('callback_received_at')->nullable()->after('expired_at');

            // Whatever else the provider returned that is safe to keep.
            // NEVER the API key or the signature: a signature is a secret
            // derived from the key, and storing it would put a verifier's
            // input in the database beside the data it verifies.
            $table->json('provider_metadata')->nullable()->after('callback_received_at');
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            // Reconciliation reads: "which payments settled in this window"
            // and "find the row for the reference the provider quoted".
            $table->index('paid_at');
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
            $table->dropIndex(['provider_reference']);
        });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'provider_reference', 'publisher_order_id', 'payment_method',
                'result_code', 'failure_reason',
                'paid_at', 'expired_at', 'callback_received_at', 'provider_metadata',
            ]);
        });
    }
};
