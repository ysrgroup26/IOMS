<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream C4. A REAL child table -- unlike PR/HIRADC/
     * JSA/Quotation's JSON line items, PO items ARE independently queried
     * elsewhere: Goods Receipt needs to attach a delivery against a
     * SPECIFIC PO line (`goods_receipt_items.purchase_order_item_id`,
     * added in the next migration) so `ordered_quantity` vs `SUM(
     * goods_receipt_items.quantity_received WHERE purchase_order_item_id
     * = this row)` gives a genuine, always-correct delivered/remaining
     * calculation -- exactly the "PO Qty = 100, GRN #1 = 40, GRN #2 = 60"
     * reconciliation example from the spec.
     */
    public function up(): void
    {
        Schema::createIfMissing('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('specification')->nullable();
            $table->decimal('quantity', 15, 2);
            $table->string('unit');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
