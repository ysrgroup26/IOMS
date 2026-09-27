<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.80.0 -- HOW THIS SUBSCRIPTION IS PAID FOR. Not what it may use.
     *
     * IOMS could not say whether an organization pays. A complimentary
     * pilot, a customer settling by bank transfer, and a customer paying
     * through a gateway were all one indistinguishable `active` row, so
     * the operations console counted a free pilot as revenue-bearing work
     * and the nightly job raised real invoices for accounts nobody intends
     * to bill.
     *
     * Three modes, and the boundary between them is who takes the money:
     *
     *  - `paid`          settled through a payment gateway.
     *  - `manual`        real money, settled out of band (bank transfer)
     *                    and recorded by an operator against the invoice.
     *  - `complimentary` free by decision -- a pilot, an internal account.
     *
     * DEFAULT `paid`, so every existing row keeps behaving exactly as it
     * does today: this column adds a distinction, it does not change one.
     *
     * It deliberately does NOT affect entitlement or lifecycle. Where a
     * subscription sits in time stays derived from its dates alone
     * (ADR 033 §1, ADR 041), because a billing mode that could also grant
     * access would be a second source of truth for the thing the whole
     * lifecycle design exists to keep single.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('subscriptions', 'billing_mode')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->string('billing_mode', 20)->default('paid')->after('billing_cycle');
                $table->index('billing_mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'billing_mode')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropIndex(['billing_mode']);
                $table->dropColumn('billing_mode');
            });
        }
    }
};
