<?php

namespace App\Contracts;

/**
 * Report Export architecture (v1.6.8) -- prepares the plug-in point the
 * spec asks for, without building any actual company template yet ("the
 * actual company Excel templates will be provided later").
 *
 * A future company-specific export class (e.g. `AcmeCorpKpiExport`)
 * implements this same interface that `KpiReportExport` (the current
 * generic export) already satisfies -- `ReportTemplateResolver` is the
 * only place that decides which concrete class gets instantiated, so
 * plugging in a real company template later means adding one class and
 * one line in the resolver, not touching `ReportController` or rewriting
 * anything about how reports are generated.
 */
interface ReportExportInterface
{
    /**
     * Must return something Maatwebsite\Excel can hand to Excel::download
     * -- in practice, this interface is implemented alongside one or more
     * of Maatwebsite's own export concerns (FromArray, WithEvents, etc.),
     * this method exists only so the resolver can type against a single
     * contract rather than a specific Excel library concern.
     */
    public function build(): self;
}
