<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.4 (HSE Waste Management, Part 13/14). One row per waste
     * generation event, numbered via the existing NumberGeneratorService
     * (no new numbering engine -- see its own DEFAULTS registry entry
     * added for 'waste_record'). Source reuses the EXISTING Project/
     * ProjectActivity tables (both nullable FKs, both optional -- a
     * project isn't always the source) rather than inventing a parallel
     * source concept, per explicit instruction. `status` walks a fixed
     * lifecycle (generated -> stored -> scheduled_pickup -> in_transit
     * -> disposed -> closed), enforced by WasteRecord's own
     * ALLOWED_TRANSITIONS map (mirrors WorkOrder/MaintenanceRequest's own
     * established transition-guard pattern) -- invalid transitions are
     * rejected at the model/controller layer, not just left to the UI.
     */
    public function up(): void
    {
        Schema::createIfMissing('waste_records', function (Blueprint $table) {
            $table->id();
            $table->string('record_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('waste_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_activity_id')->nullable()->constrained('project_activities')->nullOnDelete();
            $table->string('location')->nullable(); // free-text work-area/location, same convention as permits_to_work.location
            $table->foreignId('storage_location_id')->nullable()->constrained('waste_storage_locations')->nullOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->string('unit');
            $table->string('container')->nullable();
            $table->date('generated_date');
            $table->date('received_date')->nullable();
            $table->string('status')->default('generated');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'waste_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_records');
    }
};
