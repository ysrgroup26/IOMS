<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.128.0 -- THE JOB TITLE ON A SEAL IS THE COMPANY'S, AND A ROLE IS NOT ONE.
 *
 * A VERIFIED seal printed `HSE` and an APPROVED seal printed `MANAGER`.
 * Those are IOMS SYSTEM ROLES -- the thing that decides who may press the
 * button -- and the owner is right that they have no business being
 * printed as a professional job title on a controlled document. A yard's
 * verifier is an "HSE Officer"; its approver is a "Supervisor HSE" or a
 * "Manager Shipyard"; no two customers word it the same way, and none of
 * them word it "hse".
 *
 * The title was already read from the company's own data where it exists:
 * `positions.name`, reached account -> employee -> position, snapshotted
 * into `activity_logs.actor_title` at the moment of signing (v2.125.0).
 * What was missing is the case that actually produced the complaint: an
 * account with no linked Employee, and therefore no position, which fell
 * through to `roleLabel()` and printed the role. That fallback is gone.
 *
 * So each stage now carries a configured title of its own, set per
 * operating unit beside the roles and people that stage is routed to. It
 * is the company's word for whoever signs that stage, used when the
 * signer holds no position of their own, and it is snapshotted into the
 * activity row at action time so that re-wording it later cannot rewrite
 * a permit already filed.
 *
 * AUTHORITY IS UNTOUCHED. These two columns are LABELS. Who may verify
 * and who may approve is still `verify_roles`/`verify_user_ids` and
 * `approve_roles`/`approve_user_ids`, asked through PtwWorkflowService by
 * both the gate and the button. A job title grants nothing; naming it
 * cannot widen or narrow a single permission. See ADR 068.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ptw_workflow_settings', function (Blueprint $table) {
            // Null means "no configured title" -- and the seal then prints
            // the signer's own position if they have one, or NO title line
            // at all. Never a role, and never an invented word: the rule
            // the whole stamp is built on (ADR 065) is that it states a
            // record or it states nothing.
            $table->string('verify_title')->nullable()->after('verify_user_ids');
            $table->string('approve_title')->nullable()->after('approve_user_ids');
        });
    }

    public function down(): void
    {
        Schema::table('ptw_workflow_settings', function (Blueprint $table) {
            $table->dropColumn(['verify_title', 'approve_title']);
        });
    }
};
