<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 1B (Inventory). `quantity` is a REAL
     * stored running balance -- unlike `PurchaseOrderItem::delivered_
     * quantity` (summed live from a small, bounded set of Goods Receipt
     * rows per PO line), a warehouse's stock balance is updated by
     * potentially thousands of movements over its lifetime, so this
     * follows the conventional warehouse-system pattern instead: one
     * balance row per item+warehouse, updated ATOMICALLY (DB transaction
     * + `lockForUpdate()`, same concurrency-safety pattern already
     * established in `NumberGeneratorService::nextSequence()`) every time
     * a `StockMovement` is recorded -- never a plain unlocked
     * read-then-write. `StockMovement` remains the full, permanent audit
     * log; `Stock.quantity` is the fast-read cache of "where does that
     * log currently net out to," always derivable by replaying it if it
     * were ever to drift.
     */
    public function up(): void
    {
        Schema::createIfMissing('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('storage_location_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('reserved_quantity', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
