<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.141.0 -- A8b (decided by the owner) and the Visual Approval Flow
 * Builder.
 *
 *   approval_flow_steps.kind
 *       'approval' (every existing row) or 'final_verification' -- the
 *       mandatory last stage of a PTW flow, held to separation of duties:
 *       never the requester, never somebody who approved an earlier stage
 *       of the same permit.
 *
 *   ptw_workflow_settings.approval_mode
 *       'legacy' (every existing row: the verify-then-approve gates of ADR
 *       067, unchanged) or 'flow' (the permit runs on the company's own
 *       approval flow). Chosen per operating unit, off by default, so no
 *       running permit changes route because this release was deployed.
 *
 * Additive: two columns with defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->string('kind', 30)->default('approval')->after('step_number');
        });

        Schema::table('ptw_workflow_settings', function (Blueprint $table) {
            $table->string('approval_mode', 20)->default('legacy')->after('company_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('approval_flow_steps', 'kind')) {
            Schema::table('approval_flow_steps', fn (Blueprint $table) => $table->dropColumn('kind'));
        }
        if (Schema::hasColumn('ptw_workflow_settings', 'approval_mode')) {
            Schema::table('ptw_workflow_settings', fn (Blueprint $table) => $table->dropColumn('approval_mode'));
        }
    }
};
