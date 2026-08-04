<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original kpi_categories.code column had a GLOBAL unique
     * constraint, which conflicts with per-company KPI categories
     * (2026_07_20_100017_add_company_id_to_kpi_categories_table): two
     * different companies should each be able to have their own
     * "Toolbox Meeting" category without colliding.
     *
     * MySQL's NULL-handling in composite unique indexes makes a
     * straightforward unique(company_id, code) constraint behave
     * incorrectly for global (company_id IS NULL) categories -- NULL is
     * never considered equal to NULL in a unique index, so it wouldn't
     * actually prevent two global categories from sharing a code. Rather
     * than fight that, uniqueness is enforced at the application
     * validation layer instead (see Store/UpdateKpiCategoryRequest,
     * which scope the check by company_id explicitly), and this
     * migration simply removes the now-too-strict DB-level constraint.
     */
    public function up(): void
    {
        Schema::table('kpi_categories', function (Blueprint $table) {
            $table->index('code'); // keep a plain index for lookup performance, added before dropping the old one
        });

        Schema::table('kpi_categories', function (Blueprint $table) {
            $table->dropUnique(['code']);
        });
    }

    public function down(): void
    {
        Schema::table('kpi_categories', function (Blueprint $table) {
            $table->unique('code');
        });

        Schema::table('kpi_categories', function (Blueprint $table) {
            $table->dropIndex(['code']);
        });
    }
};
