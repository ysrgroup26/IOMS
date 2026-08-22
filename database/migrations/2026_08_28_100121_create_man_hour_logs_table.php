<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.6 (Production Readiness pass, Part 4 -- Man-Hour). Audited
     * first: `EmployeeShiftAssignment` only records that an employee is
     * SCHEDULED to a shift (effective_date/end_date, no daily record),
     * and `Shift` only has planned start/end times -- neither captures
     * actual worked hours on a given day. No Attendance/Timesheet model
     * exists anywhere in this codebase. Per the explicit instruction not
     * to fabricate Man-Hours from active-employee-count or assume
     * "8 hours = a work day," this is a genuinely new, minimal
     * operational record: one row per employee per work date, with
     * regular/overtime hours entered explicitly (never assumed).
     *
     * Deliberately NOT a duplicate employee/roster system -- employee_id
     * is a plain FK into the existing `employees` table, department/
     * company are read from that relationship (denormalized nowhere),
     * and project_id is an optional FK into the existing `projects`
     * table for departments that want work-area breakdown. No workflow/
     * approval state: this is a simple operational log, matching the
     * "proper operational input source" the spec asked for rather than
     * a new approval-gated module -- HasWorkflow can be added later if a
     * real approval requirement emerges.
     */
    public function up(): void
    {
        Schema::createIfMissing('man_hour_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->decimal('regular_hours', 5, 2)->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['company_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('man_hour_logs');
    }
};
