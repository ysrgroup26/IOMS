<?php

namespace App\Services;

use App\Exports\KpiReportExport;

/**
 * Report Export architecture (v1.6.8). Currently always resolves to the
 * generic `KpiReportExport` -- there are no company-specific templates
 * to plug in yet ("the actual company Excel templates will be provided
 * later"). What this class actually provides is the single seam a real
 * template gets registered at later: adding a `match` arm here (keyed on
 * company ID, or a future `report_configurations.template` column) is
 * the entire integration point, with zero changes needed to
 * `ReportController` or how the report data itself is assembled.
 */
class ReportTemplateResolver
{
    public function resolve(?int $companyId, KpiReportService $reportService, int $year, ?int $month, ?int $departmentId): KpiReportExport
    {
        // Future: match($companyId) { 1 => new AcmeCorpKpiExport(...), default => new KpiReportExport(...) }
        return new KpiReportExport($reportService, $year, $month, $departmentId, $companyId);
    }
}
