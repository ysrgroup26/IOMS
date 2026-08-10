<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream C2 (Purchase Requisition). Procurement's OWN
     * internal document -- distinct from `material_requests` (the
     * requesting department's own document, unchanged, not duplicated).
     * `source_material_request_id` is the real integration point: a PR is
     * typically raised by Procurement FROM an approved Material Request
     * once it's confirmed the item needs external purchasing (this
     * codebase has no warehouse/stock-ledger to automate that check
     * against -- see this workstream's own audit findings -- so it's a
     * human decision, not a fabricated automatic stock check), but a PR
     * can also stand alone (`source_material_request_id` nullable) for
     * Procurement-initiated purchasing that didn't originate from a
     * department request.
     *
     * `items` is JSON, same reasoning as HIRADC/JSA/RiskAssessment's own
     * line items -- a PR's estimate rows are edited/viewed as one
     * document; once converted into an RFQ/PO, the REAL line-item
     * tracking that other modules need to query (delivery reconciliation)
     * lives on `purchase_order_items`, a genuine child table -- see that
     * migration's own doc comment.
     *
     * `company_id` REQUIRED, `restrictOnDelete()` -- standard convention.
     * `cost_center` is a plain free-text field, not a master-data FK --
     * no cost-center master exists anywhere in this codebase and building
     * one now would be scope creep beyond what any other module needs.
     */
    public function up(): void
    {
        Schema::createIfMissing('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('pr_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_material_request_id')->nullable()->constrained('material_requests')->nullOnDelete();
            $table->string('cost_center')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->date('request_date');
            $table->string('priority')->default('medium');
            $table->date('required_date')->nullable();
            $table->text('justification')->nullable();
            $table->json('items')->nullable();
            $table->decimal('estimated_total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisitions');
    }
};
