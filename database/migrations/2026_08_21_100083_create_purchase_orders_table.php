<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream C4 (Purchase Order). `vendor_id` REQUIRED
     * (a PO always has a vendor); `purchase_requisition_id`/`rfq_id`/
     * `vendor_quotation_id` all nullable -- the normal path is PR -> RFQ
     * -> Quotation -> PO (all three set, price/terms pre-filled from the
     * selected quotation), but Procurement can still raise a direct PO
     * without going through the full RFQ cycle for a low-value/emergency
     * purchase, matching how real procurement operations actually work.
     *
     * Deliberately NO separate "Pending Approval" status distinct from
     * "submitted" -- same established convention as MaterialRequest (see
     * `docs/ADR/006-material-request-workflow.md`): "submitted" IS what a
     * pending-approval PO looks like from a data-model perspective; the
     * UI is what labels it "Pending Approval" while an Approval-Engine-
     * style decision is outstanding. Kept consistent rather than inventing
     * a fifth PO status that means the same thing.
     *
     * `delivered_quantity`/`remaining_quantity` are NOT columns here --
     * they're computed on demand per line by summing
     * `goods_receipt_items.quantity_received` against
     * `purchase_order_items.id` (see that migration's own doc comment),
     * never a stored, driftable running total.
     */
    public function up(): void
    {
        Schema::createIfMissing('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rfq_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_quotation_id')->nullable()->constrained('vendor_quotations')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cost_center')->nullable();

            $table->date('po_date');
            $table->date('delivery_date')->nullable();
            $table->string('delivery_location')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('currency')->default('IDR');

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('shipping_amount', 15, 2)->default(0);
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms_conditions')->nullable();
            $table->string('attachment_path')->nullable();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('closed_at')->nullable();

            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
