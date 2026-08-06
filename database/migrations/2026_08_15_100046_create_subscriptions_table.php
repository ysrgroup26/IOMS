<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 2 (Package + Subscription). One row per Tenant subscription
     * period -- kept as a history table (a new row per renewal/plan change)
     * rather than a single mutable row per Tenant, so `Tenant::subscription()`
     * (hasOne ... latestOfMany()) always resolves the current one while past
     * periods stay auditable. Structure only: no payment gateway fields
     * (card/invoice) at this stage, matching this milestone's scope.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('trial'); // trial, active, cancelled, expired
            $table->string('billing_cycle')->default('monthly'); // monthly, yearly
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
