<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B4 (HIRADC / Risk Assessment). Document
     * header + an `items` JSON column for the hazard/risk/control rows
     * (activity, hazard, existing_control, likelihood, severity,
     * risk_rating, additional_control, residual_likelihood,
     * residual_severity, residual_rating, pic, target_date) -- these rows
     * are always edited/viewed as one ordered document, never queried
     * individually by any other module today, so a JSON column is the
     * genuine, non-duplicating shape rather than a speculative child
     * table nothing else joins against. Same `company_id`
     * required/`restrictOnDelete()` convention as every table since
     * Workstream A3.
     */
    public function up(): void
    {
        Schema::createIfMissing('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->string('ra_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('location')->nullable();
            $table->date('assessment_date');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('items')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
