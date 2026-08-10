<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 3 (Quality Control Foundation). One
     * request typically has ONE result, so the spec's "Inspection
     * Result" fields (passed/failed, notes, evidence) live directly on
     * this same row rather than a separate 1:1 child table nothing else
     * needs to query independently. Evidence photos reuse the existing
     * dedicated-child-photo pattern (`InspectionEvidence`) since an
     * inspection can have MULTIPLE evidence photos, unlike the single
     * pass/fail result.
     */
    public function up(): void
    {
        Schema::createIfMissing('inspection_requests', function (Blueprint $table) {
            $table->id();
            $table->string('inspection_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inspector_id')->constrained('users')->restrictOnDelete();
            $table->date('inspection_date');
            $table->string('status')->default('requested');
            $table->string('result')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_requests');
    }
};
