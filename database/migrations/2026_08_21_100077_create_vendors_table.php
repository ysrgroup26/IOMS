<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream C1 (Vendor/Supplier Master). First real
     * Procurement entity -- no Vendor/Supplier/PurchaseOrder/RFQ table
     * existed anywhere in this codebase before this migration (confirmed
     * by this workstream's own opening audit: `MaterialRequest` ->
     * `GoodsReceipt` was previously a direct two-step flow with no
     * purchasing layer in between at all).
     *
     * `company_id` REQUIRED, `restrictOnDelete()` -- the now-established
     * convention since Workstream A3, not the older `incidents`-style
     * nullable pattern.
     *
     * Bank account fields are plain nullable strings, not encrypted --
     * this table stores OPERATIONAL vendor reference data (which bank a
     * vendor invoices against), not payment credentials being processed
     * or a payment-execution system; no accounting/banking integration
     * exists in this codebase to protect against. Genuinely sensitive
     * secrets (passwords, API tokens) are never stored here.
     *
     * `qualification_status` lives directly on this table rather than a
     * separate `vendor_qualifications` document/checklist table -- see
     * this migration's own scope note in docs/MODULES.md: a full
     * qualification-checklist system was deliberately deferred to avoid
     * a second, thinner, un-integrated module; the real states
     * (draft/under_review/qualified/conditionally_qualified/rejected/
     * suspended/expired) the spec asked for are all here and enforced,
     * just without a separate reviewable-checklist-item table.
     */
    public function up(): void
    {
        Schema::createIfMissing('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('vendor_code')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type')->default('goods'); // goods / services / both
            $table->string('legal_entity_name')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->default('Indonesia');

            $table->string('pic_name')->nullable();
            $table->string('pic_phone')->nullable();
            $table->string('pic_email')->nullable();
            $table->string('website')->nullable();

            $table->string('npwp')->nullable();
            $table->string('nib')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_holder')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('tax_info')->nullable();

            $table->string('category')->nullable();
            $table->text('capability')->nullable();
            $table->boolean('is_active')->default(true);

            $table->string('qualification_status')->default('draft');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('reviewed_at')->nullable();
            $table->date('qualified_until')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'qualification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
