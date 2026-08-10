<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B6 (Permit To Work). `required_qualification`
     * is a plain nullable free-text label ("Confined Space Entry
     * Certificate", "Rigger Level 2", etc.) -- deliberately NOT a foreign
     * key into `competency_types` and NOT auto-checked against every
     * certificate an employee holds. Per the spec's own explicit
     * instruction: "PTW MUST NOT automatically inspect every certificate
     * belonging to an employee. Instead support optional 'Required
     * Qualification' configuration per permit/work type." This column IS
     * that optional configuration -- HSE decides per-permit whether one
     * applies and what it says, the system never blocks issuance on it.
     * `risk_assessment_id`/`jsa_id` are optional links to an existing
     * HIRADC/JSA (Workstream B4/B5) -- reused, not duplicated.
     */
    public function up(): void
    {
        Schema::createIfMissing('permits_to_work', function (Blueprint $table) {
            $table->id();
            $table->string('ptw_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('risk_assessment_id')->nullable()->constrained('risk_assessments')->nullOnDelete();
            $table->foreignId('jsa_id')->nullable()->constrained('job_safety_analyses')->nullOnDelete();
            $table->string('permit_type');
            $table->text('work_description');
            $table->string('location')->nullable();
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->string('required_qualification')->nullable();
            $table->text('precautions')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('area_authority_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hse_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permits_to_work');
    }
};
