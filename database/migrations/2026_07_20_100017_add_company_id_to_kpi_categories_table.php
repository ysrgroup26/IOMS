<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enables per-company KPI configuration (v1.5.0): a KPI category with
     * company_id = null applies to every company (the existing 8 seeded
     * categories all remain global -- fully backward compatible, nothing
     * breaks for existing installs). A category with company_id set only
     * appears for that company, so Company A can run TRIR/LTIFR/Near Miss
     * while Company B runs Safety Patrol/Training/Toolbox Meeting, with
     * zero code changes -- purely data, managed via Settings > KPI
     * Categories (Super Admin + HSE).
     */
    public function up(): void
    {
        Schema::table('kpi_categories', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kpi_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
