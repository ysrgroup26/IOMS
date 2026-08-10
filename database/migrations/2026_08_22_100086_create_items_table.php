<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 1A (Item Master). The centralized
     * item catalog for anything Warehouse tracks stock for -- consumable,
     * spare_part, tool, asset (PPE is included in `TYPES` per the spec's
     * own list, but `PpeType`/`EmployeePpe` and `HseMaterial` already
     * exist as HSE's own working catalogs from earlier Milestone 4
     * workstreams and are deliberately NOT migrated onto this table now
     * -- that would be a risky retrofit of already-shipped, in-use
     * modules for a same-turn feature. This is a documented, honest scope
     * boundary (see docs/MODULES.md), not a silent duplication: new
     * Warehouse/Maintenance/Project/Procurement consumers use `Item`
     * going forward, HSE's existing catalogs are unchanged.
     *
     * `company_id` REQUIRED, `restrictOnDelete()` -- standard convention.
     * `min_stock`/`max_stock` are reorder-planning thresholds read by
     * Warehouse's own Low Stock report -- not enforced as a hard limit
     * anywhere (a stock adjustment can still go above/below them, same as
     * `hse_materials.reorder_level` already does).
     */
    public function up(): void
    {
        Schema::createIfMissing('items', function (Blueprint $table) {
            $table->id();
            $table->string('item_code')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('type')->default('consumable');
            $table->text('specification')->nullable();
            $table->string('unit')->default('pcs');
            $table->string('brand')->nullable();
            $table->unsignedInteger('min_stock')->default(0);
            $table->unsignedInteger('max_stock')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('attachment_path')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
