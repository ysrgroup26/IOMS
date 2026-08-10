<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 1B (Warehouse integration). Additive,
     * same pattern as the Procurement PO-link migration on this same
     * table: `warehouse_id` on the receipt (which warehouse the delivery
     * goes into) and `item_id` on each receipt line (which catalog Item
     * this line actually is) are BOTH nullable -- an existing receipt
     * with free-text-only lines (no Item Master reference) keeps working
     * exactly as before; only receipts that DO specify both get a real
     * StockMovement + Stock balance update (see
     * GoodsReceiptController::store()'s own comment).
     */
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('purchase_order_id')->constrained()->nullOnDelete();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->after('purchase_order_item_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
