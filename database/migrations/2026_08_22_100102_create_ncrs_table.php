<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 3 (NCR -- Non-Conformance Report).
     * `source_type`/`source_id` optionally point at whatever raised the
     * NCR (an InspectionRequest, a Project, etc.) -- both nullable, an
     * NCR can also stand alone. Corrective action tracking deliberately
     * does NOT get its own column/table here -- `Ncr::correctiveActions()`
     * (a `morphMany`) reuses the SAME polymorphic `CorrectiveAction`
     * entity Safety Observation/HSE Inspection/Incident already use
     * (Workstream B1/B15), per the spec's own explicit "reuse existing
     * CAPA, do not create duplicate corrective action systems"
     * instruction.
     */
    public function up(): void
    {
        Schema::createIfMissing('ncrs', function (Blueprint $table) {
            $table->id();
            $table->string('ncr_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('description');
            $table->string('severity')->default('minor');
            $table->string('responsible_party')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('raised_by')->constrained('users')->restrictOnDelete();
            $table->date('raised_date');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ncrs');
    }
};
