<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B2 (HSE Inspection). Same "logged after the
     * fact" shape as TBM -- an inspection record IS the record of an
     * inspection that already happened. `checklist_items` is JSON (item,
     * result [ok/not_ok/na], remarks) -- same "edited/viewed as one whole
     * document" reasoning as HIRADC/JSA. A `not_ok` item is turned into a
     * real `CorrectiveAction` row explicitly from the Show page (reusing
     * the existing polymorphic CAPA entity from Workstream B1/B15, not a
     * second findings-tracking system).
     */
    public function up(): void
    {
        Schema::createIfMissing('hse_inspections', function (Blueprint $table) {
            $table->id();
            $table->string('inspection_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('inspection_type');
            $table->string('location')->nullable();
            $table->date('inspection_date');
            $table->foreignId('inspector_id')->constrained('users')->restrictOnDelete();
            $table->json('checklist_items')->nullable();
            $table->string('overall_result')->default('pass');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'inspection_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hse_inspections');
    }
};
