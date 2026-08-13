<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.0 (SaaS Finalization Pass, Part 4/5). MANUAL calendar events
     * only -- audited every existing due-date-bearing module first (Leave,
     * PTW, TBM, Milestone, Work Order, etc. all already have real
     * start/end/date columns of their own); those are surfaced on the
     * Calendar as read-only "virtual" events computed live by
     * CalendarController's own provider methods, NEVER duplicated into
     * this table. This table exists only for events that have no other
     * natural home -- a manually created reminder/meeting/deadline.
     *
     * Tenant-owned via `company_id` (same convention as every other new
     * table this project has added since Workstream A3), not `tenant_id`
     * directly -- matches how every other operational table in this
     * codebase is scoped (see docs/ADR/008-tenancy-foundation.md).
     */
    public function up(): void
    {
        Schema::createIfMissing('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('event_type')->default('general'); // general, meeting, deadline, reminder
            // Nullable -- an event visible tenant-wide (no department
            // owner) is legitimate (e.g. a company holiday); set when the
            // event is specifically that department's own business, so
            // department-scoped visibility can apply the same way
            // RestrictDepartmentAccess already scopes routes.
            $table->string('department_key')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
