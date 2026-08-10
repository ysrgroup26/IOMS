<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B11 (HSE Materials & Consumables). Separate
     * from `safety_equipment` (fixed-location assets) and `employee_ppe`
     * (per-person issued PPE) -- this is the shared consumables/reusable
     * materials catalog (absorbent pads, barricade tape, signage, gas
     * detector calibration gas, etc.), each with a simple on-hand stock
     * count. Deliberately NOT a full warehouse/inventory-transaction
     * system (no goods-receipt/issue-ledger here) -- reordering/receiving
     * still goes through the EXISTING Material Request pipeline
     * (Workstream B13), this table is just the catalog + current level
     * that pipeline's own HSE-category requests would reference.
     */
    public function up(): void
    {
        Schema::createIfMissing('hse_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('category')->default('consumable');
            $table->string('unit')->default('pcs');
            $table->unsignedInteger('current_stock')->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hse_materials');
    }
};
