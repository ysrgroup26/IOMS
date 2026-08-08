<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 3 (Dynamic Document Engine, Task #66). A template is
 * "chrome" around a module's own PDF content -- header/footer text,
 * which of logo/QR/signature/watermark to show -- not a drag-drop
 * canvas (explicitly out of scope per the brief: "form-based, not
 * drag-drop canvas"). tenant_id required + company_id nullable follows
 * the same pattern as numbering_formats/approval_flows (ADR-018):
 * tenant-wide default when company_id is null, one company's override
 * when set. One default template per (tenant, company, module_key) is
 * enforced in the application layer (SettingsController), not a DB
 * constraint -- mirrors how NumberingFormat/ApprovalFlow already do
 * this same "one default, app-enforced" pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module_key');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->text('header_text')->nullable();
            $table->text('footer_text')->nullable();
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_qr')->default(false);
            $table->boolean('show_signature')->default(true);
            $table->boolean('show_watermark')->default(false);
            $table->string('watermark_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
