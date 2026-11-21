<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.146.0 -- FIELD ATTENDANCE (owner-approved design, 21 Nov 2026).
     *
     * Purely additive: two new tables, nothing existing is altered.
     *
     * attendance_sites -- the work locations a check-in is measured
     * against (a yard, a dock, a project site): a point and a radius. A
     * company with no site, or with the location requirement switched
     * off, simply records attendance without a location check.
     *
     * attendance_records -- one row per employee per work date (unique),
     * whichever way it was taken: the worker's own check-in on My Work,
     * a foreman's crew roll call, or an HRD correction. Worked minutes and
     * overtime are derived and stored on the row because they are what
     * feeds Man-Hour; overtime only reaches Man-Hour once a supervisor
     * approves it. `man_hour_log_id` links the Man-Hour row this record
     * produced, so a manually entered Man-Hour row (e.g. a contractor
     * without an account) is never overwritten by attendance.
     *
     * Location is read only at the moment of checking in or out, never
     * tracked in between.
     */
    public function up(): void
    {
        Schema::createIfMissing('attendance_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->default(300);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::createIfMissing('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16);
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->foreignId('site_id')->nullable()->constrained('attendance_sites')->nullOnDelete();
            $table->unsignedInteger('distance_m')->nullable();
            $table->string('source', 8);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('overtime_status', 16)->default('none');
            $table->foreignId('overtime_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overtime_decided_at')->nullable();
            $table->foreignId('man_hour_log_id')->nullable()->constrained('man_hour_logs')->nullOnDelete();
            $table->text('correction_reason')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['company_id', 'work_date']);
            $table->index(['company_id', 'overtime_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sites');
    }
};
