<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B1 (Safety Observation). Deliberately
     * follows the NEWER tenant-safety convention (`company_id` REQUIRED,
     * `restrictOnDelete()`) established by Shift/RosterPattern/
     * CompetencyType/HazardCategory -- NOT `incidents`' own older,
     * looser `company_id` nullable + `nullOnDelete()` pattern
     * (2026_08_12_100038), which predates that convention being settled.
     *
     * `reported_by`/`assigned_to`/`closed_by` all point at `users`, not
     * `employees` -- consistent with Incident's own `reported_by`
     * (nobody who isn't a logged-in system User can be notified of a
     * status change or "assigned" a follow-up in this codebase today;
     * Employee has no login concept). The column is literally
     * `reported_by` (not `observer_id`) on purpose, matching
     * `HasWorkflow::notificationRecipient()`'s own column-name convention
     * (`requested_by`/`reported_by`/`created_by`) so status-change
     * notifications work for free without overriding that method -- the
     * user-facing label is "Observer", the column name is a plumbing
     * detail.
     *
     * `location` is a plain free-text column, same precedent as
     * `incidents.location` -- no Area/Location master exists anywhere in
     * this codebase (confirmed by audit) and one isn't warranted for a
     * single free-text field.
     *
     * `type` (unsafe_act / unsafe_condition / positive) and `severity`
     * (minor/moderate/major/critical, reusing Incident::SEVERITIES'
     * exact scale for a consistent HSE severity vocabulary) are BOTH
     * fixed, small, structurally-universal sets -- kept as validated
     * string columns backed by model constants, not separate master
     * tables, matching Incident's own STATUS/SEVERITIES/CATEGORIES
     * precedent. `hazard_category_id` is the one field that genuinely
     * needs to be tenant-configurable, hence the separate
     * `hazard_categories` master.
     */
    public function up(): void
    {
        Schema::createIfMissing('safety_observations', function (Blueprint $table) {
            $table->id();
            $table->string('observation_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hazard_category_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('observed_at');
            $table->string('location')->nullable();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->text('description');
            $table->text('immediate_action')->nullable();
            $table->string('severity')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->text('closure_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_observations');
    }
};
