<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 2 (Package + Subscription). A `Package` is a pricing/
     * feature tier a Tenant can subscribe to (Starter, Professional,
     * Enterprise, ...) -- structure only at this stage, no billing/payment
     * gateway integration. `max_users`/`max_companies` are nullable =
     * unlimited; `features` is a flat JSON list of feature keys (mirrors
     * how `config/modules.php` already represents a flat set of togglable
     * keys, so a future "does this tenant's plan include X" check has an
     * obvious shape to follow instead of inventing a new one).
     */
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 12, 2)->default(0);
            $table->decimal('price_yearly', 12, 2)->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_companies')->nullable();
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
