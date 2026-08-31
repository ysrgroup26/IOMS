<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.17.0 (PTW Field Workflow Foundation, Part 9/10). PTW's overall
 * planned WORKFORCE for the permit -- explicitly not the same concept as
 * JSA's own (currently nonexistent, confirmed by audit) task-level
 * manpower; this pass does not touch `job_safety_analyses` at all, per
 * "do NOT force manpower into JSA simply because PTW now has it."
 *
 * A genuine many-to-many pivot IS required here (unlike PIC, which is a
 * single optional FK) -- an arbitrary-length list of Employees per
 * permit, so `permit_to_work_personnel` follows this codebase's existing
 * pivot conventions exactly (e.g. `tenant_modules`/`tenant_workspaces`):
 * plain id, both FKs `cascade` on delete (a deleted permit's personnel
 * rows are meaningless without it; an Employee record being removed
 * should remove them from past permits' rosters, not block the delete),
 * unique pair so the same person can't be added twice to one permit.
 * References `employees.id`, same reasoning as `pic_employee_id` above
 * -- workforce is drawn from Employee data, never free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_to_work_personnel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permit_to_work_id')->constrained('permits_to_work')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['permit_to_work_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permit_to_work_personnel');
    }
};
