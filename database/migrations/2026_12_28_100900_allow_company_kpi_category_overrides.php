<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.119.0 -- THE HOLE v2.106.0 CLOSED FOR PPE AND LEFT OPEN FOR KPI.
     *
     * `kpi_categories` uses the same shape as `ppe_types`: a null
     * `company_id` is the shared built-in catalogue every customer starts
     * from. v2.106.0 found that any tenant administrator could rewrite one
     * of those shared PPE rows, and ADR 050 answered it with a per-company
     * override that supersedes the template without touching it.
     *
     * The identical write path on KPI categories was never guarded. The
     * ownership check short-circuits on a null company
     * (`SettingsController::assertCompanyInTenant()` returns early for
     * null, which is correct for "this row has no owner to compare" and
     * wrong as a licence to write it), so one customer could rename,
     * re-weight, deactivate or DELETE a category every other customer is
     * scoring against -- and `kpi_records` points at it by id, so a delete
     * reaches historical scores in other tenants.
     *
     * Same column, same meaning, same uniqueness guarantee as
     * `ppe_types.source_default_id`, so there is one override pattern in
     * this codebase rather than two.
     */
    public function up(): void
    {
        if (! Schema::hasTable('kpi_categories') || Schema::hasColumn('kpi_categories', 'source_default_id')) {
            return;
        }

        Schema::table('kpi_categories', function (Blueprint $table) {
            $table->foreignId('source_default_id')->nullable()->after('company_id')
                ->constrained('kpi_categories')->nullOnDelete();

            /*
             * One override per company per template. Without this, a double
             * submit produces two rows superseding the same built-in and the
             * effective catalogue silently shows the category twice.
             *
             * `company_id` is never null on an override (an override belongs
             * to a company by definition), so MySQL's "NULL is never equal to
             * NULL" does not weaken this the way it would on (company_id, code).
             */
            $table->unique(['company_id', 'source_default_id'], 'kpi_categories_company_source_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kpi_categories') || ! Schema::hasColumn('kpi_categories', 'source_default_id')) {
            return;
        }

        Schema::table('kpi_categories', function (Blueprint $table) {
            $table->dropUnique('kpi_categories_company_source_unique');
            $table->dropForeign(['source_default_id']);
            $table->dropColumn('source_default_id');
        });
    }
};
