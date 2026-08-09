<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream A2. The actual "what job can this person
     * safely and legally perform" question is answered by comparing
     * this table (what a Position requires) against employee_competencies
     * (what an employee actually has, and whether it's still valid) --
     * e.g. Welder requires Welding Certificate + Safety Induction +
     * Working at Height. A plain many-to-many pivot: a Position can
     * require several competencies, and one competency (e.g. "Safety
     * Induction") is commonly required by many positions.
     */
    public function up(): void
    {
        Schema::createIfMissing('position_competency_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['position_id', 'competency_type_id'], 'position_competency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_competency_requirements');
    }
};
