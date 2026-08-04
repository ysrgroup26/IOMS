<?php

namespace Database\Seeders;

use App\Models\KpiCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class KpiCategorySeeder extends Seeder
{
    /**
     * The 8 KPI categories mandated by the company's HSE standard.
     * Every occurrence simply increments the count by 1 -- no scoring logic.
     */
    public function run(): void
    {
        // Defensive guard: company_id only exists after
        // 2026_07_20_100017_add_company_id_to_kpi_categories_table has
        // run. If a deploy script seeds before migrating (wrong order),
        // this degrades gracefully to matching by code alone instead of
        // throwing "Unknown column company_id" -- the correct order is
        // always `php artisan migrate` THEN `php artisan db:seed`, but
        // this seeder no longer hard-fails if that order is ever missed.
        $hasCompanyColumn = Schema::hasColumn('kpi_categories', 'company_id');

        $categories = [
            ['code' => KpiCategory::FATALITY, 'name' => 'Fatality', 'short_label' => 'Fatality', 'is_negative' => true, 'icon' => 'skull', 'supports_quick_attendance' => false, 'sort_order' => 1],
            ['code' => KpiCategory::LTI, 'name' => 'Lost Time Injury', 'short_label' => 'LTI', 'is_negative' => true, 'icon' => 'heart-pulse', 'supports_quick_attendance' => false, 'sort_order' => 2],
            ['code' => KpiCategory::FAC, 'name' => 'Medical / First Aid Case', 'short_label' => 'FAC', 'is_negative' => true, 'icon' => 'stethoscope', 'supports_quick_attendance' => false, 'sort_order' => 3],
            ['code' => KpiCategory::PPE_VIOLATION, 'name' => 'PPE Compliance Violation', 'short_label' => 'PPE Viol.', 'is_negative' => true, 'icon' => 'shield-alert', 'supports_quick_attendance' => false, 'sort_order' => 4],
            ['code' => KpiCategory::BBS_NEARMISS, 'name' => 'BBS / Nearmiss Report', 'short_label' => 'BBS', 'is_negative' => false, 'icon' => 'clipboard-list', 'supports_quick_attendance' => false, 'sort_order' => 5],
            ['code' => KpiCategory::DRILL, 'name' => 'Drill', 'short_label' => 'Drill', 'is_negative' => false, 'icon' => 'siren', 'supports_quick_attendance' => true, 'sort_order' => 6],
            ['code' => KpiCategory::CAMPAIGN, 'name' => 'HSE Campaign', 'short_label' => 'Campaign', 'is_negative' => false, 'icon' => 'megaphone', 'supports_quick_attendance' => true, 'sort_order' => 7],
            ['code' => KpiCategory::TBM, 'name' => 'Safety Meeting / TBM', 'short_label' => 'TBM', 'is_negative' => false, 'icon' => 'users-2', 'supports_quick_attendance' => true, 'sort_order' => 8],
        ];

        foreach ($categories as $cat) {
            // Explicit company_id => null in the match criteria: `code`
            // is no longer globally unique (v1.5.0 per-company KPI
            // categories), so matching by code alone could ambiguously
            // hit a company-specific category sharing the same slug.
            $match = $hasCompanyColumn
                ? ['code' => $cat['code'], 'company_id' => null]
                : ['code' => $cat['code']];

            KpiCategory::updateOrCreate($match, $cat + ['is_active' => true]);
        }
    }
}
