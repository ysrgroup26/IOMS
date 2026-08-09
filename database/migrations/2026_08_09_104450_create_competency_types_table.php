<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream A2 (Training & Competency Management). The
     * catalog of things an employee can be trained on or certified in --
     * "Safety Induction", "Working at Height", "SIO Crane", "Welder
     * Qualification", etc. One table for both "Training" and
     * "Certification" (distinguished by `type`), not two near-identical
     * parallel tables -- both are the same shape (a named competency,
     * optionally with an expiry period), just different label/grouping
     * for the UI. `validity_months` null means "does not expire" (e.g. a
     * one-time Safety Induction); set means the employee's record for it
     * auto-expires that many months after being achieved (see
     * EmployeeCompetency's own doc comment).
     *
     * `company_id` is REQUIRED (not nullable), deliberately unlike
     * KpiCategory's own company_id-nullable-means-global pattern
     * (2026_07_20_100017) -- that pattern predates Tenant existing at
     * all (Milestone 1) and a null company_id there is actually visible
     * to every tenant on the platform, not just the current one, which
     * is the same bug class the Dashboard/KPI cross-tenant leak was
     * (flagged separately for its own fix, not touched here). Following
     * Department/Position's own safer, already-established convention
     * instead (`company_id` required, `restrictOnDelete`) means this
     * table is automatically tenant-safe through Company's own
     * TenantScope with no separate global-row case to get wrong.
     */
    public function up(): void
    {
        Schema::createIfMissing('competency_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type')->default('training'); // training, certification
            $table->string('issuing_body')->nullable(); // e.g. "Kemnaker", "BNSP" -- reference only, not validated against a closed list
            $table->unsignedSmallInteger('validity_months')->nullable(); // null = does not expire
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_types');
    }
};
