<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 6 (Document Control Foundation).
     * Deliberately a DIFFERENT entity from the existing `document_templates`
     * table (Milestone 3's Dynamic Document Engine -- reusable PDF
     * generation templates for Material Request/etc.) -- this is a
     * CONTROLLED DOCUMENT REGISTER (SOPs, policies, drawings, with a real
     * version/approval lifecycle), not a template for generating one.
     * Reuses the existing file-upload architecture (`Storage::disk('public')`,
     * same as every other module this milestone) -- no second storage
     * system.
     */
    public function up(): void
    {
        Schema::createIfMissing('controlled_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('category')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('version')->default('1.0');
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('file_path')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_documents');
    }
};
