<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.125.0 -- A JOB TITLE ON A STAMP IS A FACT ABOUT THE PAST.
 *
 * `activity_logs` already captures `actor_name` for exactly this reason
 * (v2.100.0): the approval stamp on a permit used to resolve the
 * approver's CURRENT name, so renaming one person retroactively rewrote
 * every permit they had ever signed, and removing them blanked the stamp.
 *
 * The stamp now also carries the approver's JOB TITLE, because the title
 * is what makes the authority legible -- "SPV HSE" says something
 * "Super Admin" does not, and which title a company uses is the
 * company's own configuration (`positions`), not ours. That makes the
 * title subject to the identical defect: a company renaming a position,
 * or moving somebody into a different one, must not alter what a permit
 * signed last year says about who signed it.
 *
 * So it is captured at action time, beside the name it belongs with, from
 * the same row and the same evidence. Nullable and NOT backfilled: an
 * action recorded before this column existed genuinely has no stored
 * title, and the read path falls back to the live relation exactly as it
 * did before -- which omits nothing that used to be shown and invents
 * nothing that was never recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('actor_title')->nullable()->after('actor_name');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn('actor_title');
        });
    }
};
