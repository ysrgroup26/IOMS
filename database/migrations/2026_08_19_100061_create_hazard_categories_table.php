<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B (HSE Industrial Management), B0 Foundation.
     * Hazard Category Master -- the one B0 master genuinely needed by B1
     * (Safety Observation) right now. Deliberately NOT a hard-coded list:
     * real HSE hazard taxonomies vary by industry (mechanical, electrical,
     * chemical, ergonomic, working-at-height, etc.), so this is a small
     * table-driven catalog per company, same shape/convention as
     * `competency_types` (2026_08_09_104450) and `shifts`
     * (2026_08_09_113301) -- `company_id` REQUIRED, not nullable,
     * `restrictOnDelete()`, wrapped in `Schema::createIfMissing()`.
     * Reusable later by HIRADC (B4) and JSA (B5), which also reference
     * "hazard category" -- one catalog, not a duplicate per module.
     */
    public function up(): void
    {
        Schema::createIfMissing('hazard_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'hazard_categories_company_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hazard_categories');
    }
};
