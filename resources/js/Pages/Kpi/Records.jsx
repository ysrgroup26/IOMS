import { Head, router, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react';

/**
 * The destination for Dashboard's clickable KPI cards (v1.5.2) -- one row
 * per occurrence, filterable by category/company/period. Distinct from
 * Reports (an aggregated department x category matrix): this answers
 * "show me every FAC this month," which an aggregate table can't.
 */
export default function KpiRecords({ records, categories, companies, filters, availableYears }) {
    function applyFilters(overrides = {}) {
        router.get(route('kpi-records.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    const activeCategory = categories.find((c) => c.id === filters.category_id);

    return (
        <AuthenticatedLayout>
            <Head title="KPI Records" />

            <Link href={route('dashboard')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Dashboard
            </Link>

            <div className="mb-6">
                <h1 className="text-lg font-bold tracking-tight text-graphite-900">
                    KPI Records {activeCategory && <span className="text-graphite-400">&middot; {activeCategory.name}</span>}
                </h1>
                <p className="mt-1 text-sm text-graphite-500">{records.total} occurrence(s) matching the current filters</p>
            </div>

            <Card>
                <CardContent className="flex flex-wrap gap-2 p-4">
                    <Select value={filters.company_id ? String(filters.company_id) : 'all'} onValueChange={(v) => applyFilters({ company_id: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.category_id ? String(filters.category_id) : 'all'} onValueChange={(v) => applyFilters({ category_id: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="Category" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Categories</SelectItem>
                            {categories.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={String(filters.year)} onValueChange={(v) => applyFilters({ year: Number(v) })}>
                        <SelectTrigger className="w-28"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            {availableYears.map((y) => <SelectItem key={y} value={String(y)}>{y}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.month ? String(filters.month) : 'all'} onValueChange={(v) => applyFilters({ month: v === 'all' ? null : Number(v) })}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="Month" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Months</SelectItem>
                            {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                                <SelectItem key={m} value={String(m)}>{new Date(2000, m - 1).toLocaleString('en-US', { month: 'long' })}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card className="mt-4">
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Employee</TableHead>
                                <TableHead>Company</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Remarks</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {records.data.length === 0 ? (
                                <TableRow><TableCell colSpan={6} className="py-10 text-center text-graphite-400">No KPI records found.</TableCell></TableRow>
                            ) : records.data.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="font-medium">{new Date(r.record_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                    <TableCell>{r.employee.full_name}</TableCell>
                                    <TableCell>{r.employee.company?.name ?? '—'}</TableCell>
                                    <TableCell>{r.department?.name ?? '—'}</TableCell>
                                    <TableCell>
                                        <Badge variant={r.kpi_category.is_negative ? 'destructive' : 'secondary'}>{r.kpi_category.short_label}</Badge>
                                    </TableCell>
                                    <TableCell className="max-w-xs truncate text-graphite-500">{r.remarks || '—'}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {records.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-graphite-500">
                    <span>Page {records.current_page} of {records.last_page}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!records.prev_page_url} onClick={() => router.get(records.prev_page_url, {}, { preserveState: true })}>
                            <ChevronLeft className="h-4 w-4" /> Prev
                        </Button>
                        <Button variant="outline" size="sm" disabled={!records.next_page_url} onClick={() => router.get(records.next_page_url, {}, { preserveState: true })}>
                            Next <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
