<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expands KPI Category configuration (v1.5.2) so the Dashboard can be
     * generated ENTIRELY from database configuration -- no KPI name,
     * icon, color, or dashboard-inclusion logic is hardcoded anywhere.
     * Adding a new category (e.g. "LSA") and enabling `show_on_dashboard`
     * makes its card appear immediately; no code change required.
     *
     *   show_on_dashboard          - whether this category gets a
     *                                Dashboard card (distinct from
     *                                is_active: a category can be active
     *                                for data entry but hidden from the
     *                                Dashboard, e.g. a rarely-used one)
     *   count_in_dashboard_stats   - whether this category's totals feed
     *                                into aggregate Dashboard statistics
     *                                even when its own card is hidden
     *   requires_approval          - reserved for a future approval
     *                                workflow; stored now, not yet
     *                                enforced anywhere (no approval
     *                                workflow exists yet in this release)
     *   icon                       - optional lucide-react icon name;
     *                                falls back to a sensible default
     *                                (based on is_negative) when null
     *   color                      - optional hex color for the
     *                                dashboard card accent; falls back to
     *                                the existing red/blue is_negative
     *                                convention when null
     */
    public function up(): void
    {
        Schema::table('kpi_categories', function (Blueprint $table) {
            $table->boolean('show_on_dashboard')->default(true)->after('is_negative');
            $table->boolean('count_in_dashboard_stats')->default(true)->after('show_on_dashboard');
            $table->boolean('requires_approval')->default(false)->after('count_in_dashboard_stats');
            $table->string('icon')->nullable()->after('requires_approval');
            $table->string('color', 20)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_categories', function (Blueprint $table) {
            $table->dropColumn(['show_on_dashboard', 'count_in_dashboard_stats', 'requires_approval', 'icon', 'color']);
        });
    }
};
