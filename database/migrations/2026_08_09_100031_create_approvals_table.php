<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Universal Approval Engine (v1.6.9). Deliberately a single,
     * polymorphic table rather than a Material-Request-specific one --
     * `approvable_type`/`approvable_id` let any future model (PPE
     * Replacement Request, Permit To Work, Purchase Request, Asset
     * Request, Inspection) opt into the exact same approve/reject flow
     * by adding the `HasApprovals` trait, with zero new tables or
     * controller logic needed per module.
     *
     * Deliberately NOT the full configurable multi-step Workflow Engine
     * discussed in an earlier session (Manager -> Logistics -> Purchasing
     * -> Warehouse, editable per company) -- that's a distinctly larger,
     * separate feature. This is the simpler, fixed-vocabulary version
     * this session actually asked for (Draft -> Submitted -> Approved ->
     * Rejected -> Completed), built so a future multi-step engine could
     * still be layered on top of this table later (e.g. an optional
     * `step` column) without this version's data needing to change
     * shape.
     */
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
