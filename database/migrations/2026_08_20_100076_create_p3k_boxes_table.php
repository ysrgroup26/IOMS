<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B12 (P3K / First Aid). Deliberately just
     * the OPERATIONAL first-aid-station record (location, inspection/
     * restock due date, completeness status) HSE actually owns -- NOT a
     * medical-records or treatment-log system. Per the spec's own
     * explicit instruction: "Do NOT build a full medical records system,
     * HR-owned." Detailed medical/MCU records stay entirely out of scope
     * here, same reasoning already applied to Fit-To-Work being the only
     * MCU-adjacent field HSE would ever consume (not built this turn --
     * no MCU/Fit-To-Work source exists yet on the Employee side to
     * consume from).
     */
    public function up(): void
    {
        Schema::createIfMissing('p3k_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('location');
            $table->date('last_inspection_date')->nullable();
            $table->date('next_inspection_due')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('complete');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'next_inspection_due']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p3k_boxes');
    }
};
