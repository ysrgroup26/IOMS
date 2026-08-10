<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 1B. The permanent, append-only
     * warehouse transaction log -- receipt/issue/transfer/adjustment/
     * opname all write exactly one row here (transfer writes two: one
     * `transfer_out` on the source warehouse, one `transfer_in` on the
     * destination, linked via `related_movement_id`). `quantity` is
     * always POSITIVE; direction is entirely determined by `type` (see
     * `StockMovement::isInbound()`), avoiding the classic
     * sign-convention bug where a movement type and a signed quantity
     * silently disagree. `reference_type`/`reference_id` is a generic
     * polymorphic pointer back to whatever caused the movement
     * (GoodsReceipt, MaterialRequest, ...) without this table needing a
     * dedicated nullable FK per possible source.
     */
    public function up(): void
    {
        Schema::createIfMissing('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('storage_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->decimal('quantity', 15, 2);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('related_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->date('movement_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'item_id', 'warehouse_id']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
