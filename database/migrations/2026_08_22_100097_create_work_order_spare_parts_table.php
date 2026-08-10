<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 2 -- Warehouse integration. Each row
     * is one spare-part-Item used on a Work Order; recording usage posts
     * a real StockMovement (type=issue) via the SAME StockService the
     * Warehouse module itself uses, from whichever `warehouse_id` the
     * technician draws the part from -- not a separate, parallel
     * "maintenance stock" concept.
     */
    public function up(): void
    {
        Schema::createIfMissing('work_order_spare_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_used', 15, 2);
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_spare_parts');
    }
};
