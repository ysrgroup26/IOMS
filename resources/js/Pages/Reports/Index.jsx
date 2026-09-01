import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatCard from '@/Components/shared/StatCard';
import KpiSummaryCard from '@/Components/shared/KpiSummaryCard';
import EmptyState from '@/Components/shared/EmptyState';
import { Card } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import PeriodFilter from '@/Components/shared/PeriodFilter';
import { FileSpreadsheet, FileText, Users } from 'lucide-react';
import { cn } from '@/lib/utils';

export default function ReportsIndex({ report, filters, companies, departments, availableYears }) {
    // v2.24.0 (Complete Product UI/UX Transformation, cont'd --
    // Reports/Analytics). Pure client-side aggregation of the SAME
    // report.departments/categories data already being rendered below --
    // no new query, no fabricated number. Gives this page the "KEY
    // SUMMARY before detailed data" reading order a decision-support
    // report should have, instead of jumping straight into per-employee
    // matrices with nothing to orient around first.
    //
    // v2.30.0 (Interior UI Transformation, Phase 2, Part 14 -- Reports
    // was an explicitly named gap). `report.categories` is the exact
    // same `KpiCategory` collection Dashboard's own "KPI Summary" row
    // renders via `KpiSummaryCard` (same icon/color/short_label/
    // is_negative fields, same model) -- reused here instead of hand-
    // rolled flat chips, so "what can I learn from this data" reads
    // consistently with the rest of the product before the per-employee
    // matrices below it.
    const totalEmployees = report.departments.reduce((sum, g) => sum + g.rows.length, 0);
    const categoryTotals = report.categories.map((c) => ({
        ...c,
        total: report.departments.reduce((sum, g) => sum + g.rows.reduce((s, row) => s + (Number(row[c.code]) || 0), 0), 0),
    }));

    function updateFilters(overrides = {}) {
        router.get(route('reports.index'), {
            year: filters.year,
            month: filters.month,
            department_id: filters.department_id,
            company_id: filters.company_id,
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    }

    function handlePeriodChange({ year, month, departmentId }) {
        updateFilters({ year, month, department_id: departmentId });
    }

    function exportParams() {
        const p = new URLSearchParams({ year: filters.year });
        if (filters.month) p.set('month', filters.month);
        if (filters.department_id) p.set('department_id', filters.department_id);
        if (filters.company_id) p.set('company_id', filters.company_id);
        return p.toString();
    }

    return (
        <AuthenticatedLayout>
            <Head title="Reports" />

            <PageHeader title="Reports" subtitle="Laporan KPI per departemen, mengikuti format Excel yang sudah dipakai perusahaan.">
                <Button variant="outline" asChild>
                    <a href={route('reports.export.excel') + '?' + exportParams()}>
                        <FileSpreadsheet className="h-4 w-4" /> Excel
                    </a>
                </Button>
                <Button variant="outline" asChild>
                    <a href={route('reports.export.pdf') + '?' + exportParams()}>
                        <FileText className="h-4 w-4" /> PDF
                    </a>
                </Button>
            </PageHeader>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Select
                    value={filters.company_id ? String(filters.company_id) : 'all'}
                    onValueChange={(v) => updateFilters({ company_id: v === 'all' ? null : Number(v), department_id: null })}
                >
                    <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Companies</SelectItem>
                        {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                    </SelectContent>
                </Select>
                <PeriodFilter
                    year={filters.year}
                    month={filters.month}
                    departmentId={filters.department_id}
                    years={availableYears}
                    departments={departments}
                    companies={companies}
                    onChange={handlePeriodChange}
                />
            </div>

            {report.departments.length === 0 ? (
                <Card><EmptyState title="Belum ada data" description="Belum ada data KPI untuk periode ini." /></Card>
            ) : (
                <>
                    {/* KEY SUMMARY -- oriented before the detailed
                        per-department matrices below, matching this
                        pass's own "report title -> key summary -> detail"
                        reading order for a decision-support page.
                        v2.30.0: was a row of plain white chips with no
                        icon/color -- now reuses the same `KpiSummaryCard`
                        (data-driven icon/color, same component Dashboard's
                        own KPI Summary row uses) so this page reads as
                        "what can I learn from this data" at a glance,
                        matching this pass's own explicit target for
                        Reports. Employee count kept as a StatCard (the
                        one metric with no KpiCategory of its own) so both
                        card types share the same visual family. */}
                    <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500">Key Summary</p>
                    <div className="mb-6 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8">
                        <StatCard icon={Users} value={totalEmployees} label="Employees" size="sm" />
                        {categoryTotals.map((c) => (
                            <KpiSummaryCard
                                key={c.code}
                                label={c.short_label}
                                value={c.total}
                                isNegative={c.is_negative}
                                icon={c.icon}
                                color={c.color}
                                compact
                            />
                        ))}
                    </div>

                    <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-graphite-400 dark:text-slate-500">Detail by Department</p>
                    <div className="space-y-4">
                    {report.departments.map((group) => (
                        <Card key={group.department_name} className="overflow-hidden">
                            {/* v2.30.0: solid bg-brand-600 band replaced with
                                the same light-tinted section-header
                                treatment used elsewhere in this pass (e.g.
                                PTW's DocSection) -- reads as premium
                                structure, not a loud color block, and adds
                                a real row-count so the header itself
                                carries information. */}
                            <div className="flex items-center justify-between border-b border-graphite-200 bg-brand-50/70 px-4 py-2 dark:border-slate-700 dark:bg-brand-950/30">
                                <p className="text-[13px] font-semibold text-brand-800 dark:text-brand-300">{group.department_name}</p>
                                <span className="text-[11px] font-medium text-brand-600/80 dark:text-brand-400/80">{group.rows.length} employee{group.rows.length !== 1 ? 's' : ''}</span>
                            </div>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        {report.categories.map((c) => (
                                            <TableHead key={c.code} className="text-center">{c.short_label}</TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {group.rows.length === 0 ? (
                                        <TableRow><TableCell colSpan={report.categories.length + 1} className="py-6 text-center text-graphite-400">No employees.</TableCell></TableRow>
                                    ) : group.rows.map((row) => (
                                        <TableRow key={row.employee.id}>
                                            <TableCell className="font-medium">{row.employee.full_name}</TableCell>
                                            {report.categories.map((c) => (
                                                <TableCell
                                                    key={c.code}
                                                    className={cn('text-center', c.is_negative && row[c.code] > 0 && 'font-semibold text-red-600')}
                                                >
                                                    {row[c.code]}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    ))}
                    </div>
                </>
            )}
        </AuthenticatedLayout>
    );
}
