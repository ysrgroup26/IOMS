<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.52.0 -- BAST (Berita Acara Serah Terima) as its own record.
 *
 * WHY THIS IS NOT A GOODS RECEIPT. v2.51.0 titled the Goods Receipt PDF
 * "Goods Receipt / Berita Acara Serah Terima Barang". That was wrong, and
 * it is the kind of wrong that quietly corrupts a domain model:
 *
 *   Goods Receipt is a WAREHOUSE TRANSACTION. Stock arrived, in this
 *   quantity, in this condition, on this date, against this PO, and
 *   inventory moved as a result. It happens many times a week, it is
 *   posted by a receiving officer, and its consequence is a stock level.
 *
 *   BAST is a FORMAL HANDOVER INSTRUMENT. Two named parties record that
 *   defined work, goods or services were handed over and accepted, both
 *   sign it, and it is later produced as evidence against a contract or
 *   a payment. Its consequence is contractual, not inventory.
 *
 * A completed Work Order handed over to the asset owner needs a BAST and
 * moves no stock at all. A pallet of electrodes booked into the warehouse
 * needs a Goods Receipt and no BAST. Collapsing the two means a company
 * either cannot produce a real BAST when a client asks for one, or starts
 * treating routine receiving as a contractual acceptance.
 *
 * REUSE, NOT DUPLICATION. A BAST does not re-enter the underlying
 * business data. `source_type`/`source_id` point at whatever is being
 * handed over -- a WorkOrder, PurchaseOrder, GoodsReceipt or Project that
 * already exists -- and `items` holds only the handover LINES as agreed
 * between the parties, which are frequently a summary of the source
 * rather than a copy of it (one line reading "Overhaul Crane #4 sesuai
 * SPK-2026-00012" is a complete and correct BAST).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_records', function (Blueprint $table) {
            $table->id();

            // Numbered through NumberGeneratorService like every other
            // document, so it is tenant-safe by construction.
            $table->string('bast_number')->index();

            // Tenant isolation flows through company_id exactly as it does
            // for every other operational record (see TenantScope on
            // Company -- downstream tables inherit isolation transitively).
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('handover_type', 40)->default('work_completion')
                ->comment('work_completion, goods, service, project_phase, asset');

            $table->string('title');
            $table->date('handover_date');

            // The two parties. Names are recorded as text on purpose: the
            // second party is very often a vendor's site representative or
            // a client's engineer who is not, and should not be, a row in
            // this tenant's Employee directory. Where the party IS a known
            // employee, `first_party_employee_id` keeps the structured
            // link rather than duplicating a name.
            $table->string('first_party_name');
            $table->string('first_party_position')->nullable();
            $table->string('first_party_organization')->nullable();
            $table->foreignId('first_party_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('second_party_name');
            $table->string('second_party_position')->nullable();
            $table->string('second_party_organization')->nullable();

            // What is being handed over. Polymorphic so a BAST can sit on
            // top of the record that already holds the business data.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference_number')->nullable()
                ->comment('Contract/SPK/PO number when the source is outside IOMS');

            $table->text('scope')->nullable();

            // The acceptance statement is the operative sentence of the
            // document and is editable, because its wording is a
            // commercial matter between the parties.
            $table->text('acceptance_statement')->nullable();

            $table->json('items')->nullable();

            $table->string('status', 24)->default('draft')
                ->comment('draft, issued, accepted, rejected');

            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['source_type', 'source_id'], 'handover_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_records');
    }
};
