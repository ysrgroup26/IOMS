<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream A3. Roster Management -- the actual
     * per-employee schedule: which Shift applies, which rotation
     * pattern (if any) governs on/off days, which Project or site the
     * employee is deployed to for this period, and the date range.
     *
     * Deliberately does NOT generate one row per calendar day (a
     * write-heavy, fast-growing table for something fully derivable from
     * roster_pattern + cycle_start_date) -- `EmployeeRoster::dutyTypeOn(Carbon $date)`
     * computes on/off for any given date on demand, the same
     * computed-not-stored philosophy as `EmployeePpe`/`EmployeeCompetency`'s
     * own expiry-status accessors elsewhere in this codebase.
     *
     * `project_id` reuses the EXISTING `projects` table (Master Data
     * Principle -- no duplicate site/project concept); `site_name` is a
     * free-text fallback for a deployment that isn't tracked as a
     * Project at all (e.g. a client site with no IOMS project record).
     * `nullOnDelete` on `project_id`: a roster entry has independent
     * meaning even if the Project it referenced is later removed.
     */
    public function up(): void
    {
        Schema::createIfMissing('employee_rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('roster_pattern_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_name')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            // Anchor date for computing which day of the rotation cycle a
            // given calendar date falls on -- only meaningful when
            // roster_pattern_id is set; defaults to start_date when left
            // blank (see EmployeeRosterController::store()).
            $table->date('cycle_start_date')->nullable();
            $table->string('status')->default('active'); // active, completed, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_rosters');
    }
};
