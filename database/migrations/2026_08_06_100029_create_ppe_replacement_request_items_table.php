<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately links to a specific `employee_ppe` row rather than
     * duplicating employee/department/PPE-type data onto this table --
     * everything the spec wants "automatically retrieved" (Employee,
     * Employee ID/NIK, Department, PPE Item) is already reachable through
     * that relationship, so it's read live at display/PDF time instead of
     * being copied and risking drifting out of sync.
     *
     * `project_id` IS captured here explicitly, not derived through the
     * relationship -- an employee can have several project assignments
     * over time, so "which project was this replacement actually for"
     * needs to be a point-in-time fact on the request itself, not
     * something recomputed later from an employee's current assignments
     * (which could have changed by the time someone views this record).
     * Nullable and editable before submit, matching "edit where
     * necessary."
     *
     * `quantity` defaults to 1 -- each `employee_ppe` row already
     * represents exactly one issued unit; this column exists for the rare
     * case someone needs to request more than one of the same item at
     * once, not because the source data has any other quantity to pull
     * from.
     *
     * PPE Size is deliberately NOT a column here: sizes don't exist
     * anywhere in the current schema (PPE Master doesn't track them yet
     * -- an explicitly deferred future feature). Auto-populating a size
     * that doesn't exist would mean fabricating data, so this is honestly
     * left out rather than faked.
     */
    public function up(): void
    {
        Schema::create('ppe_replacement_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppe_replacement_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_ppe_id')->constrained('employee_ppe')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('documentation_photo_path')->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppe_replacement_request_items');
    }
};
