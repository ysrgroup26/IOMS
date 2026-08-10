<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 4. A contractor's own worker --
     * deliberately NOT a row in `employees` (that table is this tenant's
     * own workforce; a contractor worker belongs to and is paid by the
     * contractor company, not this tenant). `hse_status` is the one
     * operational field HSE actually needs day-to-day (can this person be
     * on site right now), matching the same "operational info only, not
     * detailed records" boundary already established for MCU/Fit-To-Work
     * in Workstream B's own ownership rules.
     */
    public function up(): void
    {
        Schema::createIfMissing('contractor_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('worker_id_number')->nullable();
            $table->string('position')->nullable();
            $table->string('competency')->nullable();
            $table->string('hse_status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_workers');
    }
};
