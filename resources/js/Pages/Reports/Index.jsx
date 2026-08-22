import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import PeriodFilter from '@/Components/shared/PeriodFilter';
import { FileSpreadsheet, FileText } from 'lucide-react';
import { cn } from '@/lib/utils';

export default function ReportsIndex({ report, filters, companies, departments, availableYears }) {
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

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">Reports</h1>
                    <p className="mt-1 text-sm text-graphite-500">KPI report grouped by department, mirroring the Excel format.</p>
                </div>
                <div className="flex gap-2">
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
                </div>
            </div>

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
                <Card><CardContent className="py-16 text-center text-graphite-400">No data available for this period.</CardContent></Card>
            ) : (
                <div className="space-y-6">
                    {report.departments.map((group) => (
                        <Card key={group.department_name}>
                            <div className="rounded-t-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white">
                                {group.department_name}
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
            )}
        </AuthenticatedLayout>
    );
}
