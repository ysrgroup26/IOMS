<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream C3 (RFQ). Always raised from an approved
     * `purchase_requisitions` row (`purchase_requisition_id` REQUIRED,
     * not nullable -- an RFQ with no PR behind it has no authorized
     * purchasing need). Creating an RFQ transitions its parent PR to
     * `converted_to_rfq` (see `RfqController::store()`), matching the
     * spec's own suggested PR lifecycle.
     *
     * `selected_vendor_id`/`evaluation_notes`/`selected_by`/`selected_at`
     * record the OUTCOME of the Quotation Comparison directly on the RFQ
     * row, rather than a separate `quotation_comparisons` table -- the
     * comparison itself is a computed VIEW over this RFQ's own
     * `vendor_quotations` (built at request-time in
     * `RfqController::show()`), not a second stored dataset to keep in
     * sync. This deliberately keeps the transparent-evaluation
     * requirement real (recorded notes + an explicit selection decision)
     * without inventing an extra entity nothing else needs to query.
     */
    public function up(): void
    {
        Schema::createIfMissing('rfqs', function (Blueprint $table) {
            $table->id();
            $table->string('rfq_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_requisition_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->date('issue_date');
            $table->date('quotation_deadline');
            $table->string('currency')->default('IDR');
            $table->string('delivery_location')->nullable();
            $table->text('delivery_requirement')->nullable();
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('draft');

            $table->foreignId('selected_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->text('evaluation_notes')->nullable();
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('selected_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfqs');
    }
};
