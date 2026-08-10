<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream C3 (Vendor Quotation). Multiple quotations
     * can exist per RFQ (one per responding vendor, `unique(rfq_id,
     * vendor_id)` so a vendor can only have one active quotation per RFQ
     * -- resubmission overwrites via update, not a second row). `items`
     * is JSON, same reasoning as the PR's own line items -- a quotation
     * is compared as a whole document (Comparison reads each quotation's
     * `total_amount` + `items` side-by-side), nothing queries an
     * individual quotation line elsewhere in this codebase.
     */
    public function up(): void
    {
        Schema::createIfMissing('vendor_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('vendor_reference_number')->nullable();
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->string('currency')->default('IDR');
            $table->json('items')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('delivery_terms')->nullable();
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('submitted');
            $table->timestamps();

            $table->unique(['rfq_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_quotations');
    }
};
