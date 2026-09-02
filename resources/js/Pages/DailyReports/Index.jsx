import { Head, router, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, ChevronLeft, ChevronRight, Camera, ListChecks, ClipboardList } from 'lucide-react';

/**
 * v2.32.0 (Interior UI Completion Phase 3B, Part 5): was 7 equal-weight
 * columns (Date/Project/Department/Company/Type/Activities/Photos) with
 * no mobile fallback and a boxed filter card. Reworked around the same
 * identity-first pattern this session already proved elsewhere: Report
 * (date + type, this record's own identity) leads, Project/Department/
 * Company grouped as one location fact, Activities+Photos grouped as one
 * compact "content" metric -- no data dropped, no report field invented.
 */
export default function DailyReportsIndex({ reports, projects, companies, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('daily-reports.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Daily Reports" />

            <PageHeader title="Daily Reports" subtitle={`${reports.total} laporan harian tercatat.`}>
                {can.manage && (
                    <Button asChild>
                        <Link href={route('daily-reports.create')}><Plus className="h-4 w-4" /> New Report</Link>
                    </Button>
                )}
            </PageHeader>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Select value={filters.company_id ? String(filters.company_id) : 'all'} onValueChange={(v) => applyFilters({ company_id: v === 'all' ? null : v, project_id: null })}>
                    <SelectTrigger className="w-40 bg-white"><SelectValue placeholder="Company" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Companies</SelectItem>
                        {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                    </SelectContent>
                </Select>
                <Select value={filters.project_id ? String(filters.project_id) : 'all'} onValueChange={(v) => applyFilters({ project_id: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-52 bg-white"><SelectValue placeholder="Project" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Projects</SelectItem>
                        {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                    </SelectContent>
                </Select>
                <Select value={filters.report_type || 'all'} onValueChange={(v) => applyFilters({ report_type: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-36 bg-white"><SelectValue placeholder="Type" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Types</SelectItem>
                        <SelectItem value="normal">Normal</SelectItem>
                        <SelectItem value="overtime">Overtime</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <Card>
                <CardContent className="p-0">
                    {reports.data.length === 0 ? (
                        <EmptyState icon={ClipboardList} title="Belum ada laporan harian" description="Belum ada laporan yang cocok dengan filter ini." />
                    ) : (
                        <>
                            <div className="divide-y divide-graphite-100 md:hidden">
                                {reports.data.map((r) => (
                                    <Link
                                        key={r.id}
                                        href={route('daily-reports.show', r.id)}
                                        className="block px-4 py-3 transition-colors active:bg-graphite-50"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="font-medium text-graphite-900">{new Date(r.report_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                                                <p className="truncate text-xs text-graphite-500">{r.project.name} · {r.department_name}</p>
                                            </div>
                                            <Badge variant={r.report_type === 'overtime' ? 'secondary' : 'outline'} className="shrink-0 capitalize">{r.report_type}</Badge>
                                        </div>
                                        <div className="mt-1.5 flex items-center gap-3 text-xs text-graphite-400">
                                            <span className="flex items-center gap-1"><ListChecks className="h-3.5 w-3.5" /> {r.activities_count}</span>
                                            <span className="flex items-center gap-1"><Camera className="h-3.5 w-3.5" /> {r.photos_count}</span>
                                        </div>
                                    </Link>
                                ))}
                            </div>

                            <Table className="hidden md:table">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Report</TableHead>
                                        <TableHead>Project / Department</TableHead>
                                        <TableHead>Content</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {reports.data.map((r) => (
                                        <TableRow key={r.id} className="cursor-pointer" onClick={() => router.visit(route('daily-reports.show', r.id))}>
                                            <TableCell>
                                                <p className="font-medium text-graphite-900">{new Date(r.report_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                                                <Badge variant={r.report_type === 'overtime' ? 'secondary' : 'outline'} className="mt-1 capitalize">{r.report_type}</Badge>
                                            </TableCell>
                                            <TableCell>
                                                <p className="text-graphite-800">{r.project.name}</p>
                                                <p className="text-xs text-graphite-500">{r.department_name} · {r.project.company?.name}</p>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-3 text-graphite-500">
                                                    <span className="flex items-center gap-1"><ListChecks className="h-3.5 w-3.5" /> {r.activities_count} kegiatan</span>
                                                    <span className="flex items-center gap-1"><Camera className="h-3.5 w-3.5" /> {r.photos_count} foto</span>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
                    )}
                </CardContent>
            </Card>

            {reports.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-graphite-500">
                    <span>Page {reports.current_page} of {reports.last_page}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!reports.prev_page_url} onClick={() => router.get(reports.prev_page_url, {}, { preserveState: true })}>
                            <ChevronLeft className="h-4 w-4" /> Prev
                        </Button>
                        <Button variant="outline" size="sm" disabled={!reports.next_page_url} onClick={() => router.get(reports.next_page_url, {}, { preserveState: true })}>
                            Next <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
