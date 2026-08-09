<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream A3 (Shift & Roster Management). Shift
     * Master -- Morning/Afternoon/Night, or whatever a tenant's own
     * industrial operation actually runs, entirely table-driven (no
     * hard-coded shift list). `company_id` is REQUIRED, not nullable --
     * same deliberate choice as `competency_types`
     * (2026_08_09_104450) and Department/Position, not KpiCategory's own
     * nullable-means-global pattern (a separately-flagged cross-tenant
     * leak) -- this table is automatically tenant-safe via Company's own
     * TenantScope with no separate global-row case to get wrong.
     *
     * `start_time`/`end_time` are plain TIME columns, deliberately NOT
     * cast to a Carbon datetime in the model -- a shift's start/end is a
     * time-of-day, not a specific calendar date, and forcing it through
     * a date-bearing cast would invite exactly the kind of "which day
     * did this actually mean" bug an overnight (night) shift is prone
     * to. `Shift::isNightShift()`/`workingHoursDecimal()` parse these
     * strings explicitly, handling the overnight-wraparound case on
     * purpose (see that model's own doc comment) -- this is the
     * Fatigue Management Foundation's "night shift indicator" and
     * "working hours tracking" requirements, computed, not stored.
     */
    public function up(): void
    {
        Schema::createIfMissing('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_duration_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'shifts_company_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
