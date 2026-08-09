<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream A2. One row per employee per competency
     * they've actually achieved -- a training completion record or a
     * certification record, per `competency_types.type`. `expiry_date`
     * is nullable and, when left blank, is auto-computed from
     * `achieved_date` + the CompetencyType's `validity_months` (mirrors
     * EmployeePpe's own auto-expiry-on-save pattern, see that model's
     * boot() for the precedent this follows) -- never both silently
     * disagreeing with each other.
     *
     * `competency_type_id` is `restrictOnDelete` (not cascade): a
     * CompetencyType with real employee records against it must not be
     * deletable out from under them, matching PpeTypeController's own
     * "cannot delete a PPE type that has been issued" guard for the same
     * reason. `employee_id` is `cascadeOnDelete` -- this record has no
     * independent meaning once its Employee is genuinely (hard-)deleted,
     * matching EmployeeInternship's own precedent.
     */
    public function up(): void
    {
        Schema::createIfMissing('employee_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_type_id')->constrained()->restrictOnDelete();
            $table->string('certificate_number')->nullable();
            $table->string('issuer')->nullable(); // may override/duplicate competency_types.issuing_body for this specific record
            $table->date('achieved_date'); // training completion date or certificate issue date
            $table->date('expiry_date')->nullable();
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'competency_type_id']);
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_competencies');
    }
};
