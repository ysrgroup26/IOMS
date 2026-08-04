<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revision to the v1.3 Daily Report module: the report is now
     * attributed to a chosen HSE Officer (an Employee whose department is
     * "HSE"), not automatically derived from the logged-in user account.
     * `created_by` (users FK) is kept as an internal audit field only --
     * it is no longer shown or asked for in the Daily Report UI.
     *
     * Nullable rather than required at the DB level, since existing rows
     * (if any) have no natural HSE Officer to backfill to; the app layer
     * (StoreDailyReportRequest/UpdateDailyReportRequest) enforces it as
     * required for all new/updated reports going forward.
     */
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->foreignId('hse_officer_id')
                ->nullable()
                ->after('project_id')
                ->constrained('employees')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hse_officer_id');
        });
    }
};
