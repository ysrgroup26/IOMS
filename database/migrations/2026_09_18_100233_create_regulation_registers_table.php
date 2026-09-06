<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.52.0 -- Regulations & Standards Register.
 *
 * The register of legal and normative requirements a Health, Safety &
 * Environment function is expected to identify, keep current, and be able
 * to evidence compliance against. Every HSE management system asks for
 * one; IOMS had nowhere to hold it, so it lived in someone's spreadsheet.
 *
 * DESIGNED TO BE MAINTAINED, NOT SHIPPED "COMPLETE". Regulations change,
 * get superseded, and differ by sector and by site. So:
 *
 *   - `category` and `document_type` are strings backed by suggestion
 *     lists, not enums. A pressure-vessel rule, a provincial regulation
 *     or an internal standard all have to fit, and a database enum would
 *     mean a code change every time a customer meets a requirement we did
 *     not anticipate.
 *   - `status` distinguishes active / superseded / revoked / draft, and
 *     `superseded_by` records what replaced an entry, because "which
 *     version applied on the date of the incident" is a real question.
 *   - `review_date` is what makes this a living register rather than a
 *     list typed once: it drives "due for review".
 *
 * Tenant-scoped through company_id like every other operational record.
 * The attached document goes to the private disk, never `public` -- a
 * customer's controlled-document copies are not world-readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_registers', function (Blueprint $table) {
            $table->id();

            $table->string('register_number')->nullable()->index();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('category', 120)
                ->comment('Occupational Safety, Environmental Protection, B3/Hazardous Materials, ...');
            $table->string('document_type', 80)
                ->comment('UU, PP, Permenaker, Kepmen, SNI, ISO, Internal Standard, ...');

            $table->string('regulation_number')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('title');
            $table->string('issuing_authority')->nullable();

            $table->string('status', 24)->default('active')
                ->comment('active, superseded, revoked, draft');
            $table->foreignId('superseded_by')->nullable()->constrained('regulation_registers')->nullOnDelete();

            $table->date('effective_date')->nullable();
            $table->date('review_date')->nullable();

            $table->text('applicability')->nullable();
            $table->text('scope')->nullable();

            // Owner/PIC is an Employee, not free text -- the person
            // accountable for a requirement is someone in the directory.
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('source_reference', 500)->nullable();

            // Private disk. See SecureDocumentAccess -- attachments are
            // served through an authorized controller, never a public URL.
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();

            $table->text('compliance_reference')->nullable()
                ->comment('How this tenant evidences compliance: procedure, permit, record');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'category']);
            $table->index('review_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_registers');
    }
};
