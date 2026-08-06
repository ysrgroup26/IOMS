<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 3 (Activity Center, Task #50). `activity_logs` has
     * existed since v1 (32+ call sites, per docs/ARCHITECTURE.md) but has
     * no `company_id`/`department_id`/`module` columns -- every existing
     * viewer scopes to one record (`where('subject_type', X)->where('subject_id', $id)`),
     * there's no cross-record filterable feed. These three nullable
     * columns are populated going forward by `ActivityLog::record()`
     * (best-effort: read directly off the `$subject` model's own
     * `company_id`/`department_id` attributes when present, derive
     * `module` from the subject's class name) -- NOT backfilled for
     * historical rows, since the original $subject state at the time of
     * each past action isn't reliably reconstructable. Historical rows
     * simply show as "no company/department/module" in filters, which is
     * honest (that data was genuinely never captured) rather than a
     * guessed backfill.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('subject_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('department_id')->nullable()->after('company_id');
            $table->string('module')->nullable()->after('department_id');

            $table->index('company_id');
            $table->index('department_id');
            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['department_id', 'module']);
        });
    }
};
