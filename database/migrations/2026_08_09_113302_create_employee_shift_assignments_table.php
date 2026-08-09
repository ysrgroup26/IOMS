<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream A3. Which Shift an Employee is assigned to,
     * for a given effective period -- a detail/history record extending
     * Employee (Master Data Principle: no duplicate employee table),
     * same one-to-many-history shape as `Subscription` (one Tenant, many
     * dated subscription periods) rather than a single mutable
     * "current_shift_id" column on `employees` -- an employee's shift
     * history (day shift for 6 months, then rotated to night shift) is
     * a real, auditable fact worth keeping, not just today's snapshot.
     */
    public function up(): void
    {
        Schema::createIfMissing('employee_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->date('effective_date');
            $table->date('end_date')->nullable();
            $table->string('status')->default('active'); // active, ended, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shift_assignments');
    }
};
