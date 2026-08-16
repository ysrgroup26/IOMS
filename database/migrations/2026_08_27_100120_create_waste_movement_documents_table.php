<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.4 (HSE Waste Management, Part 17). Reuses the EXISTING
     * document-attachment shape (VendorDocument's own fillable:
     * file_path/original_name/document_type/uploaded_by, same Laravel
     * `public` disk storage convention used throughout this codebase) --
     * no new file-storage mechanism, per explicit instruction. Attached
     * to the WasteMovement (manifest/disposal certificate/transporter
     * document/photos are movement-level evidence, not record-level),
     * visible from the WasteRecord via its movements' own documents.
     */
    public function up(): void
    {
        Schema::createIfMissing('waste_movement_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waste_movement_id')->constrained()->cascadeOnDelete();
            $table->string('document_type'); // manifest, disposal_certificate, transporter_document, photo, other
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_movement_documents');
    }
};
