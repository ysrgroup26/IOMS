<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 3 (Universal Approval Engine v2). Extends the existing
     * single-step Approval Engine (`Approval`, `HasApprovals`, ADR-001)
     * with configurable multi-level, parallel, and conditional chains,
     * plus escalation -- WITHOUT changing behavior for any existing
     * consumer (MaterialRequest, LeaveRequest). See
     * docs/ADR/010-approval-engine-v2.md for the full reasoning; the
     * short version: when no `ApprovalFlow` row exists for a module (the
     * default, unconfigured state), `App\Services\ApprovalEngine` falls
     * back to the exact legacy single-step behavior -- one Approval row,
     * `config('workflow.approvers')` authorizes, one decision finalizes
     * the approvable. Nothing about today's Material Request/Leave
     * Request approval flow changes unless a flow is explicitly
     * configured for that module later (Task #57's Settings UI).
     *
     * `approval_flows`: one row per configured chain. `company_id` null =
     * tenant-wide default; `conditions` (json, nullable) lets several
     * flows exist for the same module -- the first whose conditions match
     * the approvable record wins (evaluated in `priority` order), and a
     * flow with null conditions is a catch-all/default.
     *
     * `approval_flow_steps`: one row PER APPROVER at a given step_number
     * -- a parallel step is expressed as multiple rows sharing the same
     * step_number, not a separate table. `mode` ('single', 'parallel_any',
     * 'parallel_all') describes how many of that step's approvers must
     * decide before the chain advances; stored on every row of the step
     * for simplicity (all rows at one step_number are expected to agree,
     * enforced by convention/the resolving service, not a DB constraint).
     */
    public function up(): void
    {
        Schema::createIfMissing('approval_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->json('conditions')->nullable();
            $table->timestamps();

            $table->index(['module_key', 'is_active']);
        });

        Schema::createIfMissing('approval_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('step_number');
            $table->string('mode')->default('single'); // single, parallel_any, parallel_all
            $table->string('approver_role')->nullable();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('escalate_after_hours')->nullable();
            $table->string('escalate_to_role')->nullable();
            $table->timestamps();

            $table->index(['approval_flow_id', 'step_number']);
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->foreignId('approval_flow_id')->nullable()->after('approvable_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('step_number')->default(1)->after('approval_flow_id');
            $table->boolean('is_escalated')->default(false)->after('comments');
            $table->timestamp('escalated_at')->nullable()->after('is_escalated');

            $table->index(['approval_flow_id', 'step_number']);
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_flow_id');
            $table->dropColumn(['step_number', 'is_escalated', 'escalated_at']);
        });

        Schema::dropIfExists('approval_flow_steps');
        Schema::dropIfExists('approval_flows');
    }
};
