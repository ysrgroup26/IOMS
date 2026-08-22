<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.6 (Production Readiness pass, Part 19 -- SaaS Billing
     * Architecture). Two additive tables closing the gap the audit
     * found: Tenant -> Subscription -> Invoice already existed, but
     * nothing recorded an actual gateway transaction or an inbound
     * webhook event, so idempotency (a duplicate webhook must never
     * double-activate a subscription) had nothing to be enforced
     * against.
     *
     * `payment_transactions`: one row per attempt at a gateway
     * (checkout created, payment attempted), independent of whether it
     * ever succeeds -- `gateway_reference` is unique per gateway so a
     * retried createCheckout() call can be detected as the same attempt.
     *
     * `payment_webhook_events`: an idempotency ledger. `gateway` +
     * `event_id` is unique -- a webhook delivered twice (a real,
     * common occurrence with every payment provider) hits this unique
     * constraint on the second delivery and is treated as already
     * processed rather than reapplied. `payload` is stored verbatim
     * (json) for auditability, `verified` records whether signature
     * verification passed BEFORE any side effect ran.
     */
    public function up(): void
    {
        Schema::createIfMissing('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('gateway');
            $table->string('gateway_reference')->unique();
            $table->string('status')->default('pending'); // pending, paid, failed, expired, refunded
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('IDR');
            $table->string('redirect_url')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
        });

        Schema::createIfMissing('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway');
            $table->string('event_id');
            $table->string('event_type')->nullable();
            $table->json('payload');
            $table->boolean('verified')->default(false);
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_transactions');
    }
};
