import { Head, router, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import EmptyState from '@/Components/shared/EmptyState';
import StatCard from '@/Components/shared/StatCard';
import { ArrowLeft, ChevronLeft, ChevronRight, BarChart3, TrendingDown, TrendingUp } from 'lucide-react';

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

            <div className="mb-4">
                <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">
                    KPI Records {activeCategory && <span className="text-graphite-400">&middot; {activeCategory.name}</span>}
                </h1>
                <p className="mt-1 text-sm text-graphite-500">Setiap kejadian KPI yang tercatat, bisa difilter per kategori dan periode.</p>
            </div>

            {/* v2.31.0 (Interior UI Transformation Phase 3, Part 7/8):
                this page is a real event log -- a table is genuinely the
                right structure for it, per this pass's own "don't turn
                tabular data into decorative cards" instruction -- but it
                previously had NO summary at all before the raw table,
                just a bare "N events matched" sentence. One real,
                data-driven primary KPI card now leads instead: the exact
                same `records.total` the old sentence stated, presented
                as the same informational-card family as the rest of the
                product, with a semantic accent (red/green) once a
                specific category is filtered -- not a fabricated
                breakdown, since a category's own cross-page total isn't
                data this page has (`records.data` is only the current
                page). */}
            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatCard
                    icon={activeCategory ? (activeCategory.is_negative ? TrendingDown : TrendingUp) : BarChart3}
                    value={records.total}
                    label={activeCategory ? activeCategory.name : 'Total Records'}
                    accent={activeCategory ? (activeCategory.is_negative ? 'red' : 'green') : undefined}
                    hint={activeCategory ? 'kategori terpilih' : 'seluruh kategori'}
                />
            </div>

            {/* v2.24.0 (Complete Product UI/UX Transformation, cont'd --
                Management/KPI). Filter bar unboxed, same treatment as the
                rest of this transformation pass. */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Select value={filters.company_id ? String(filters.company_id) : 'all'} onValueChange={(v) => applyFilters({ company_id: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-40 bg-white"><SelectValue placeholder="Company" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Companies</SelectItem>
                        {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                    </SelectContent>
                </Select>
                <Select value={filters.category_id ? String(filters.category_id) : 'all'} onValueChange={(v) => applyFilters({ category_id: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-48 bg-white"><SelectValue placeholder="Category" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Categories</SelectItem>
                        {categories.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                    </SelectContent>
                </Select>
                <Select value={String(filters.year)} onValueChange={(v) => applyFilters({ year: Number(v) })}>
                    <SelectTrigger className="w-28 bg-white"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        {availableYears.map((y) => <SelectItem key={y} value={String(y)}>{y}</SelectItem>)}
                    </SelectContent>
                </Select>
                <Select value={filters.month ? String(filters.month) : 'all'} onValueChange={(v) => applyFilters({ month: v === 'all' ? null : Number(v) })}>
                    <SelectTrigger className="w-36 bg-white"><SelectValue placeholder="Month" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Months</SelectItem>
                        {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                            <SelectItem key={m} value={String(m)}>{new Date(2000, m - 1).toLocaleString('en-US', { month: 'long' })}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <Card>
                <CardContent className="p-0">
                    {records.data.length === 0 ? (
                        <EmptyState icon={BarChart3} title="Belum ada data KPI." description="Belum ada kejadian yang cocok dengan filter ini." />
                    ) : (
                        <>
                            {/* v2.24.0: mobile card list -- this page had
                                none before. */}
                            <div className="divide-y divide-graphite-100 md:hidden">
                                {records.data.map((r) => (
                                    <div key={r.id} className="px-4 py-3">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 flex-1 truncate font-medium text-graphite-900">{r.employee.full_name}</span>
                                            <Badge variant={r.kpi_category.is_negative ? 'destructive' : 'secondary'}>{r.kpi_category.short_label}</Badge>
                                        </div>
                                        <p className="mt-1 truncate text-sm text-graphite-600">{r.department?.name ?? r.employee.company?.name ?? '—'}</p>
                                        <div className="mt-1.5 flex items-center justify-between gap-2 text-xs text-graphite-400">
                                            <span className="min-w-0 flex-1 truncate">{r.remarks || '—'}</span>
                                            <span className="shrink-0">{new Date(r.record_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            {/* v2.24.0: consolidated from 6 equal-weight
                                columns to 4 grouped cells -- Employee +
                                Company/Department as one identity unit. */}
                            <Table className="hidden md:table">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Remarks</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {records.data.map((r) => (
                                        <TableRow key={r.id}>
                                            <TableCell className="font-medium">{new Date(r.record_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                            <TableCell>
                                                <p className="text-graphite-900">{r.employee.full_name}</p>
                                                <p className="text-xs text-graphite-500">{r.employee.company?.name ?? '—'} &middot; {r.department?.name ?? '—'}</p>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={r.kpi_category.is_negative ? 'destructive' : 'secondary'}>{r.kpi_category.short_label}</Badge>
                                            </TableCell>
                                            <TableCell className="max-w-xs truncate text-graphite-500">{r.remarks || '—'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
                    )}
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
