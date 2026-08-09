<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream A3. Rotation Pattern master -- a
     * configurable "N days on, M days off" cycle (e.g. site/marine
     * rotation: 6-on/1-off, 14-on/14-off), never hard-coded. An
     * `employee_rosters` row referencing a pattern computes which
     * calendar days are on/off duty from `days_on`/`days_off` relative
     * to that roster entry's own `cycle_start_date` -- see
     * `RosterPattern::dutyTypeOn()`. A roster with no pattern is simply
     * on duty every day in its date range (the common office/fixed-shift
     * case) -- the pattern is only needed for genuine rotation.
     */
    public function up(): void
    {
        Schema::createIfMissing('roster_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->unsignedSmallInteger('days_on');
            $table->unsignedSmallInteger('days_off');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'roster_patterns_company_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_patterns');
    }
};
