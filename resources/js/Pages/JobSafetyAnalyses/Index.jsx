import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, ListChecks, ChevronLeft, ChevronRight } from 'lucide-react';

export default function JobSafetyAnalysesIndex({ jsas, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('job-safety-analyses.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Job Safety Analysis" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">Job Safety Analysis</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Step-by-step task hazard breakdown.</p>
                </div>
                {can.manage && (<Button asChild><Link href={route('job-safety-analyses.create')}><Plus className="h-4 w-4" /> New JSA</Link></Button>)}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search JSA number or job title..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="submitted">Submitted</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="archived">Archived</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {jsas.data.length === 0 ? (
                        <EmptyState icon={ListChecks} title="No JSAs recorded" description="Create a JSA to start tracking it." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow><TableHead>JSA No.</TableHead><TableHead>Job Title</TableHead><TableHead>Date</TableHead><TableHead>Project</TableHead><TableHead>Prepared By</TableHead><TableHead>Status</TableHead></TableRow>
                            </TableHeader>
                            <TableBody>
                                {jsas.data.map((j) => (
                                    <TableRow key={j.id} className="cursor-pointer" onClick={() => router.visit(route('job-safety-analyses.show', j.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{j.jsa_number}</TableCell>
                                        <TableCell className="max-w-xs truncate">{j.job_title}</TableCell>
                                        <TableCell>{new Date(j.jsa_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell>{j.project?.name || '-'}</TableCell>
                                        <TableCell>{j.preparer?.name}</TableCell>
                                        <TableCell><StatusBadge value={j.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {jsas.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {jsas.current_page} of {jsas.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!jsas.prev_page_url} onClick={() => router.get(jsas.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!jsas.next_page_url} onClick={() => router.get(jsas.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
