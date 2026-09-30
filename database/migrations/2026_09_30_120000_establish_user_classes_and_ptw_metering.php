<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.86.0 -- TWO USER CLASSES AND A METERED PTW DOCUMENT.
 *
 * The schema half of the approved monetization model. Every column and
 * table added here is ADDITIVE, and every existing row keeps the meaning it
 * had before this ran. See `down()`: it is a complete reversal.
 *
 * WHY `users.user_type` RATHER THAN REUSING `is_field_user`.
 *
 * `is_field_user` is a LANDING PREFERENCE. Its own controller says so:
 * "which WORKSPACE you land in (My Work vs Dashboard) ... it therefore
 * consumes NO quota and grants NO capability". Every account carrying it
 * today is a FULL account that happens to start in My Work, and several of
 * them are foremen and HSE staff who legitimately reach other workspaces.
 *
 * Reusing the flag as the billable class would therefore have silently
 * DEMOTED every existing field account the moment this deployed, removing
 * access those customers are already paying for. So the two stay separate:
 *
 *   user_type      WHAT YOU MAY REACH, and what you are billed as
 *   is_field_user  WHERE YOU LAND after signing in
 *
 * Every existing user becomes `full`, which is exactly what they are today.
 * A My Work User is a class an administrator creates deliberately from now
 * on; nobody is converted into one by a migration. Documented in
 * docs/IOMS Website Redesign/04 - Implementation Log.md under D-7.
 *
 * WHY TWO QUOTA TABLES RATHER THAN A COUNTER.
 *
 * `ptw_quota_grants` holds every grant a tenant has received, each with its
 * own kind, quantity, consumed count and expiry. `ptw_quota_consumptions`
 * records which permit consumed which grant. That is more than a remaining
 * figure needs, and it is what makes the approved rules expressible:
 *
 *   - included and purchased quota stay genuinely separate pools, rather
 *     than one ambiguous remaining counter the spec explicitly rejects;
 *   - included quota expires at a period boundary and purchased quota does
 *     not, which a single counter cannot represent;
 *   - a permit consumes exactly once, enforced by a unique index rather
 *     than by remembering to check;
 *   - deleting a permit does not restore quota, because consumption is its
 *     own record and is never deleted with the permit. That closes the
 *     create-delete-repeat loophole by construction rather than by policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * THE BILLABLE CLASS. Kept as a string rather than a native enum:
         * this codebase's own convention for status-like columns, and adding
         * a third class later is then a code change rather than a migration
         * against a locked type.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_type', 20)->default('full')->after('is_field_user')->index();
        });

        // Explicit rather than relying on the column default, so the intent
        // is visible in the migration and an existing row is never left to
        // a default that might change later.
        DB::table('users')->update(['user_type' => 'full']);

        /*
         * PLAN CAPACITY. `max_users` keeps its exact meaning -- FULL users --
         * and is not renamed, for the reason v2.82.0 already gave when it
         * reused the column: renaming touches the plan editor, its
         * validator, the seeder, the operator UI and several test files to
         * express a fact that has not changed.
         *
         * `ptw_included_monthly` is deliberately named MONTHLY. The approved
         * model keeps PTW quota monthly on every billing cycle, so an annual
         * plan receives twelve monthly allocations rather than one lump sum.
         */
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('max_my_work_users')->nullable()->after('max_users');
            $table->unsignedInteger('ptw_included_monthly')->nullable()->after('max_my_work_users');
        });

        /*
         * PURCHASED MY WORK CAPACITY, in packs of ten, on the SUBSCRIPTION
         * rather than the package -- the same placement and the same reason
         * as `additional_users`: purchased capacity belongs to the customer
         * who bought it, so it survives a plan change instead of being
         * silently discarded by one.
         */
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('additional_my_work_packs')->default(0)->after('additional_users');
        });

        /*
         * THE GRANT LEDGER.
         *
         * `kind` is 'included' or 'purchased'.
         *
         * An INCLUDED grant carries the monthly window it belongs to and
         * expires at the end of it. `term_sequence` is which allocation of
         * the current subscription term it is, which is what caps an annual
         * plan at twelve: Business annual receives fourteen months of
         * platform ACCESS and twelve months of PTW entitlement, and without
         * this column months thirteen and fourteen would quietly mint two
         * more allocations.
         *
         * A PURCHASED grant has no window and no expiry, carries the invoice
         * that paid for it, and is never reset by a renewal.
         */
        Schema::create('ptw_quota_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->index();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('consumed')->default(0);
            $table->timestamp('effective_from');
            // Null means it never expires, which is what purchased quota is.
            $table->timestamp('expires_at')->nullable()->index();
            // Which subscription term and which allocation within it. Null
            // for purchased quota, which belongs to no term.
            $table->timestamp('term_started_at')->nullable();
            $table->unsignedInteger('term_sequence')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'kind', 'expires_at']);
            // One included allocation per tenant per term position. This is
            // what makes granting idempotent: a lifecycle run that fires
            // twice, or a lazy top-of-window grant racing the nightly job,
            // cannot mint the same allocation twice.
            $table->unique(['tenant_id', 'term_started_at', 'term_sequence'], 'ptw_grants_term_slot_unique');
        });

        /*
         * WHICH PERMIT SPENT WHICH GRANT.
         *
         * The unique index on `permit_to_work_id` is the idempotency
         * guarantee: a permit consumes exactly one document, once, whatever
         * a retry or a double-submitted form does.
         *
         * `permit_to_work_id` is deliberately NOT a cascading foreign key.
         * Deleting a permit must NOT delete its consumption record, because
         * the approved model says consumed quota is not refunded when a
         * permit is deleted or cancelled. A cascade here would be the
         * create-delete-repeat loophole, written into the schema.
         */
        Schema::create('ptw_quota_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ptw_quota_grant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('permit_to_work_id');
            // Kept even though the grant knows it, so a consumption report
            // does not have to join to answer which pool was spent.
            $table->string('kind', 20);
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('permit_to_work_id');
            $table->index(['tenant_id', 'created_at']);
        });

        /*
         * INVOICE LINE ITEMS.
         *
         * `invoices.amount` stays the authoritative total and is untouched,
         * so every existing payment, webhook and lifecycle path keeps
         * working exactly as before. Items describe WHAT the total is made
         * of, which the single-amount model could not.
         *
         * Every existing invoice is backfilled with one line carrying its
         * own amount, so no invoice in the system is left without an
         * itemisation and a reader never has to special-case the ones
         * raised before this release.
         */
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_amount', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['invoice_id', 'sort_order']);
        });

        $now = now();

        DB::table('invoices')->orderBy('id')->chunkById(200, function ($invoices) use ($now) {
            $rows = [];

            foreach ($invoices as $invoice) {
                $rows[] = [
                    'invoice_id' => $invoice->id,
                    'kind' => 'subscription',
                    'description' => 'Langganan IOMS',
                    'quantity' => 1,
                    'unit_amount' => $invoice->amount,
                    'amount' => $invoice->amount,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('invoice_items')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('ptw_quota_consumptions');
        Schema::dropIfExists('ptw_quota_grants');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('additional_my_work_packs');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['max_my_work_users', 'ptw_included_monthly']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['user_type']);
            $table->dropColumn('user_type');
        });
    }
};
