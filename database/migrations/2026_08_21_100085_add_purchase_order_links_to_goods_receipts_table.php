<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream C4/C5 (Goods Receipt / PO integration). Adds
     * the PO link ADDITIVELY -- `goods_receipts.material_request_id`
     * stays exactly as it was (the pre-Procurement flow: Material Request
     * -> Goods Receipt directly, with no PO in between, still works
     * unmodified for any tenant not yet using the Procurement pipeline).
     * A receipt is now EITHER against a Material Request OR a Purchase
     * Order (both nullable, application-level XOR enforced in
     * `GoodsReceiptController`, not a DB constraint -- consistent with
     * how `material_request_id` was already nullable before this
     * migration).
     */
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('material_request_id')->constrained()->nullOnDelete();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->foreignId('purchase_order_item_id')->nullable()->after('goods_receipt_id')->constrained('purchase_order_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_item_id');
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
