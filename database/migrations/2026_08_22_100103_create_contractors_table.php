<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 4 (Contractor Management). Deliberately
     * a SEPARATE entity from `vendors` (Procurement) -- a Contractor
     * supplies LABOR/services under HSE oversight (safety compliance,
     * worker access), a Vendor supplies GOODS/quotations under Procurement
     * (pricing, delivery). A company could plausibly be both, but nothing
     * forces that; no shared table, matching the spec's own explicit
     * positioning of Contractor as HSE's own domain.
     */
    public function up(): void
    {
        Schema::createIfMissing('contractors', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('company_name');
            $table->text('address')->nullable();
            $table->string('pic_name')->nullable();
            $table->string('pic_contact')->nullable();
            $table->string('approval_status')->default('pending');
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractors');
    }
};
