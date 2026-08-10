<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Milestone 4, Acceleration Part 6. Revision history -- every new file upload keeps the previous version's row instead of overwriting it. */
    public function up(): void
    {
        Schema::createIfMissing('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('controlled_document_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
