<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 1C. Asset lifecycle event log --
     * assignment/transfer/inspection/status_change all write one row here
     * (same "one append-only log table for several related event types"
     * shape as StockMovement, generalized with nullable
     * type-specific columns rather than a JSON blob, since each event
     * type's fields are few and worth being real, queryable columns).
     */
    public function up(): void
    {
        Schema::createIfMissing('asset_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->foreignId('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('inspection_result')->nullable();
            $table->string('previous_status')->nullable();
            $table->string('new_status')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->date('transaction_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_transactions');
    }
};
