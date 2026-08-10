<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B3. Attendee pivot -- kept as a real table
     * (not JSON) because "who attended which TBM" is genuinely useful to
     * query per-employee later (e.g. a future attendance/compliance
     * report), unlike HIRADC/JSA's line items.
     */
    public function up(): void
    {
        Schema::createIfMissing('tbm_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tbm_meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tbm_meeting_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbm_attendees');
    }
};
