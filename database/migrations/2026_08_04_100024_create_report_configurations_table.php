<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Report Configuration foundation (v1.6.7 Tasks 3/4) -- schema and
     * model only, deliberately NOT the complete Report Builder. The
     * actual KPI *category* structure is already configurable per
     * company (see KpiCategory::visibleForCompany(), from v1.5.0) --
     * KpiReportService already reads whichever categories exist for a
     * company rather than assuming a fixed set. What's genuinely new
     * here is making the OTHER two axes from the stated future vision
     * (Settings > Report Configuration > choose KPI / Group By / Export
     * Type) storable at all: which categories a saved report includes,
     * how it groups rows, and which format it exports to. No
     * controller, routes, or Settings UI are built in this migration --
     * a future session adds those on top of this table without needing
     * a schema change first.
     */
    public function up(): void
    {
        Schema::create('report_configurations', function (Blueprint $table) {
            $table->id();
            // Nullable = a global/default configuration available to
            // every company, same convention already used by
            // kpi_categories.company_id.
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            // Which KPI categories this configuration includes, as an
            // ordered list of kpi_categories.id -- a JSON array rather
            // than a pivot table, since order matters (column order in
            // the generated report) and this is read as a whole, never
            // queried by individual category membership.
            $table->json('kpi_category_ids');
            $table->string('group_by')->default('department');
            $table->string('export_type')->default('excel');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_configurations');
    }
};
