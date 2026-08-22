import { Head, router, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Plus, ChevronLeft, ChevronRight, Camera, ListChecks } from 'lucide-react';

export default function DailyReportsIndex({ reports, projects, companies, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('daily-reports.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Daily Reports" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-graphite-900">Daily Reports</h1>
                    <p className="mt-1 text-sm text-graphite-500">{reports.total} reports total</p>
                </div>
                {can.manage && (
                    <Button asChild>
                        <Link href={route('daily-reports.create')}><Plus className="h-4 w-4" /> New Report</Link>
                    </Button>
                )}
            </div>

            <Card>
                <CardContent className="flex flex-wrap gap-2 p-4">
                    <Select value={filters.company_id ? String(filters.company_id) : 'all'} onValueChange={(v) => applyFilters({ company_id: v === 'all' ? null : v, project_id: null })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.project_id ? String(filters.project_id) : 'all'} onValueChange={(v) => applyFilters({ project_id: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-52"><SelectValue placeholder="Project" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Projects</SelectItem>
                            {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.report_type || 'all'} onValueChange={(v) => applyFilters({ report_type: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="Type" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Types</SelectItem>
                            <SelectItem value="normal">Normal</SelectItem>
                            <SelectItem value="overtime">Overtime</SelectItem>
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
                                <TableHead>Project</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Company</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Activities</TableHead>
                                <TableHead>Photos</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {reports.data.length === 0 ? (
                                <TableRow><TableCell colSpan={7} className="py-10 text-center text-graphite-400">No reports found.</TableCell></TableRow>
                            ) : reports.data.map((r) => (
                                <TableRow key={r.id} className="cursor-pointer" onClick={() => router.visit(route('daily-reports.show', r.id))}>
                                    <TableCell className="font-medium">{new Date(r.report_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                    <TableCell>{r.project.name}</TableCell>
                                    <TableCell>{r.department_name}</TableCell>
                                    <TableCell>{r.project.company?.name}</TableCell>
                                    <TableCell><Badge variant={r.report_type === 'overtime' ? 'secondary' : 'outline'} className="capitalize">{r.report_type}</Badge></TableCell>
                                    <TableCell className="flex items-center gap-1 text-graphite-500"><ListChecks className="h-3.5 w-3.5" /> {r.activities_count}</TableCell>
                                    <TableCell className="flex items-center gap-1 text-graphite-500"><Camera className="h-3.5 w-3.5" /> {r.photos_count}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
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
