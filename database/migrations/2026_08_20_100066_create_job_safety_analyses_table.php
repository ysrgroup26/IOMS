<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B5 (JSA). Same shape/reasoning as
     * `risk_assessments` (2026_08_20_100065) -- document header + a
     * `steps` JSON column (step_number, task_step, potential_hazard,
     * control_measure).
     */
    public function up(): void
    {
        Schema::createIfMissing('job_safety_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('jsa_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_title');
            $table->string('location')->nullable();
            $table->date('jsa_date');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('required_ppe')->nullable();
            $table->json('steps')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_safety_analyses');
    }
};
