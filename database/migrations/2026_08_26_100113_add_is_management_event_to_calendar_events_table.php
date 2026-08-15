<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.2 (Final Completion Pass, Part 2/3). Adds the Management
     * Calendar visibility flag to manual events -- the "Show on Management
     * Calendar" concept the spec asked for. Deliberately a single boolean
     * on the existing `calendar_events` table (additive column, default
     * false so every existing row stays off the Management Calendar until
     * an authorized user explicitly opts it in) rather than a second
     * calendar table -- there is still only ONE Calendar Engine.
     *
     * Only manual events carry this flag. Virtual/module-provided events
     * (Leave/PTW/TBM/Milestone/WorkOrder) are not rows in this table and
     * can't carry a per-row flag; CalendarService::managementEvents()
     * instead treats a small, fixed, already-cross-department-significant
     * subset of virtual sources (PTW, Milestone) as inherently
     * management-relevant -- see that class's own doc comment.
     *
     * Renamed 2026_08_15_100113 -> 2026_08_26_100113 (production incident,
     * same day this migration was first committed): it originally used
     * the real wall-clock date, which sorted BEFORE
     * 2026_08_24_100111_create_calendar_events_table -- the migration that
     * table's `Schema::table('calendar_events', ...)` above depends on --
     * because this project's migration filenames are a fictional
     * forward-dated sequence (already past 2026-08-25 at the time this was
     * written), not real dates. Confirmed safe to rename: production had
     * never successfully run this migration (it was the one that failed,
     * with `calendar_events` reported as not existing), so no batch/ran
     * history needed reconciling.
     */
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            if (! Schema::hasColumn('calendar_events', 'is_management_event')) {
                $table->boolean('is_management_event')->default(false)->after('department_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            if (Schema::hasColumn('calendar_events', 'is_management_event')) {
                $table->dropColumn('is_management_event');
            }
        });
    }
};
