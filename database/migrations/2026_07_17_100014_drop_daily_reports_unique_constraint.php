<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revision to the v1.3 Daily Report module: a project may now have
     * multiple Daily Reports on the same date, since different HSE
     * Officers may supervise different activities, normal shifts, or
     * overtime shifts on the same day.
     *
     * MySQL/InnoDB requires the `project_id` foreign key to always be
     * backed by an index. The old unique index
     * (daily_reports_project_id_report_date_unique) was serving that role,
     * so it cannot simply be dropped first -- MySQL rejects dropping an
     * index that's currently the only one supporting a FK constraint
     * (error 1553). The fix is ordering: create the replacement plain
     * index FIRST (so the FK always has *some* covering index), and only
     * then drop the unique index, each as its own ALTER TABLE statement.
     */
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->index(['project_id', 'report_date'], 'daily_reports_project_id_report_date_index');
        });

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropUnique('daily_reports_project_id_report_date_unique');
        });
    }

    public function down(): void
    {
        // Mirror the same safe ordering in reverse: add the unique index
        // back first (so the FK is never left without a covering index),
        // then drop the plain index.
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->unique(['project_id', 'report_date'], 'daily_reports_project_id_report_date_unique');
        });

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropIndex('daily_reports_project_id_report_date_index');
        });
    }
};
