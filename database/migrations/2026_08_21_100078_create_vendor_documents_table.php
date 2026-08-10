<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream C1. Vendor legal/qualification documents
     * (company profile, legal docs, certificates, contracts) -- mirrors
     * DailyReportPhoto/SafetyObservationPhoto's dedicated-child-row
     * pattern, generalized to arbitrary document types with an optional
     * expiry date (certificates/contracts genuinely expire; a plain
     * photo doesn't need one).
     */
    public function up(): void
    {
        Schema::createIfMissing('vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_documents');
    }
};
