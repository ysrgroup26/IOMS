<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.141.0 -- AN APPROVAL KEEPS THE ROUTE IT STARTED ON.
 *
 * The engine read a flow's steps LIVE, so an administrator editing or
 * removing a flow re-routed every request already in it: a removed step
 * finalised it early, a changed role handed it to somebody else, and a
 * deleted flow left it pending with nobody able to decide it.
 *
 * A published version is an immutable copy of the steps. An approval is
 * pinned to the version it started on and reads its steps from there.
 * Additive: one new table and three nullable columns. Rows created before
 * this keep a null version and the engine reads them as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_flow_versions', function (Blueprint $table) {
            $table->id();
            // Deliberately NOT a foreign key: removing a flow must not
            // remove the version an in-flight approval is pinned to.
            $table->unsignedBigInteger('approval_flow_id')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedInteger('version');
            $table->json('steps');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->useCurrent();

            $table->unique(['approval_flow_id', 'version']);
        });

        // A step may name a POSITION from the company's own structure
        // (positions), not only an IOMS role or a named person.
        Schema::table('approval_flow_steps', function (Blueprint $table) {
            $table->unsignedBigInteger('approver_position_id')->nullable()->after('approver_user_id')->index();
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('approval_flow_version_id')->nullable()->after('approval_flow_id')->index();
            // An escalation widens who may decide THIS request -- it used to
            // add a permanent step row to the flow, making the escalation
            // role an approver of every later request too.
            $table->string('escalated_to_role', 40)->nullable()->after('escalated_at');
        });
    }

    public function down(): void
    {
        // Each part guarded, so a rollback that stopped half-way can be re-run.
        if (Schema::hasColumn('approvals', 'approval_flow_version_id')) {
            Schema::table('approvals', function (Blueprint $table) {
                $table->dropIndex(['approval_flow_version_id']);
                $table->dropColumn('approval_flow_version_id');
            });
        }
        if (Schema::hasColumn('approvals', 'escalated_to_role')) {
            Schema::table('approvals', fn (Blueprint $table) => $table->dropColumn('escalated_to_role'));
        }
        if (Schema::hasColumn('approval_flow_steps', 'approver_position_id')) {
            Schema::table('approval_flow_steps', function (Blueprint $table) {
                $table->dropIndex(['approver_position_id']);
                $table->dropColumn('approver_position_id');
            });
        }
        Schema::dropIfExists('approval_flow_versions');
    }
};
