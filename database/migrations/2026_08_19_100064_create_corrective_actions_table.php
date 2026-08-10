<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B1/B15. CorrectiveAction -- a genuinely
     * reusable CAPA (Corrective And Preventive Action) building block, not
     * a Safety-Observation-only table. Polymorphic `source` (source_type +
     * source_id) so Incident (B14), HSE Inspection (B2), HIRADC (B4) etc.
     * can attach their own corrective actions to this SAME table later
     * instead of each module growing its own closure/follow-up columns --
     * directly answers B15's explicit "CAPA should be reusable across HSE
     * sources" requirement, and Safety Observation's own spec (Responsible
     * Person / Due Date / Status / Closure Evidence / Closed By / Closed
     * Date) needs exactly this shape today.
     *
     * `company_id` is stored directly on this table (copied from the
     * source record at creation time) rather than derived by joining
     * through the polymorphic `source` on every query -- every future
     * source type has its own company_id in a different place/shape, so
     * duplicating it here once is the simplest way to keep this table
     * uniformly tenant-safe (`restrictOnDelete()`, required, same
     * convention as every other table in this migration set) regardless
     * of which module created the row.
     */
    public function up(): void
    {
        Schema::createIfMissing('corrective_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->text('action');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority')->default('medium');
            $table->date('due_date')->nullable();
            $table->string('status')->default('open');
            $table->string('evidence_path')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corrective_actions');
    }
};
