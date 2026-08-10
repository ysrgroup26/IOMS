<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B10 (HSE Safety Equipment / Operational HSE
     * Assets / Emergency Facilities). Deliberately a SEPARATE table from
     * `employee_ppe` (per-person issued items) and from the future
     * `hse_materials` (consumables/reusable materials) -- per the spec's
     * own explicit taxonomy: "separate PPE / HSE Consumables / Reusable
     * Safety Materials / Safety Equipment/Operational HSE Assets /
     * Emergency Facilities. Do NOT treat all HSE items as the same
     * thing." This table is fixed-location operational assets (fire
     * extinguishers, safety showers, eyewash stations, emergency alarms,
     * spill kits) with a real, queryable inspection-due date -- the thing
     * a future notification/reminder actually keys off, unlike a JSON
     * document.
     */
    public function up(): void
    {
        Schema::createIfMissing('safety_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('location')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('last_inspection_date')->nullable();
            $table->date('next_inspection_due')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'next_inspection_due']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_equipment');
    }
};
