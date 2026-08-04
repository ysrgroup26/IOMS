<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KPI categories are stored as data, not hardcoded enums, so future
     * categories (e.g. Incident Investigation, Gas Test) can be added via
     * Settings without a migration. Each category simply "counts" (+1 per
     * occurrence) per the company's KPI standard -- no weighted scoring.
     */
    public function up(): void
    {
        Schema::create('kpi_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // fatality, lti, fac, ppe_violation, bbs_nearmiss, drill, campaign, tbm
            $table->string('name'); // display name
            $table->string('short_label', 20); // used in report table headers, e.g. "LTI"
            $table->text('description')->nullable();
            $table->boolean('is_negative')->default(false); // true = incident/violation (red styling), false = positive engagement (green)
            $table->boolean('supports_quick_attendance')->default(false); // TBM, Drill, Campaign, Safety Meeting
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_categories');
    }
};
