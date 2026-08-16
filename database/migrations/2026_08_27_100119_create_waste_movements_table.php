<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.4 (HSE Waste Management, Part 16). Movement/Disposal --
     * mirrors GasTestRecord/SafetyEquipmentInspection's own "child
     * record, never overwritten" pattern: a WasteRecord's full pickup ->
     * disposal history is a real, queryable log, and each new movement
     * row also updates its parent WasteRecord.status to keep the
     * denormalized lifecycle field in sync (same convention
     * SafetyEquipmentController::recordInspection() already established).
     * `vendor_id` reuses the EXISTING Vendor table (see
     * 2026_08_27_100115's is_waste_vendor flag) -- no separate
     * WasteVendor table.
     */
    public function up(): void
    {
        Schema::createIfMissing('waste_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waste_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('manifest_number')->nullable();
            $table->date('pickup_date')->nullable();
            $table->string('destination')->nullable();
            $table->date('disposal_date')->nullable();
            $table->string('status')->default('scheduled'); // scheduled, picked_up, disposed
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['waste_record_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_movements');
    }
};
