import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, FileWarning, ChevronLeft, ChevronRight } from 'lucide-react';

export default function NcrsIndex({ ncrs, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('ncrs.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="NCR (Non-Conformance Report)" />
            <PageHeader title="NCR (Non-Conformance Report)" subtitle="Quality nonconformances, with corrective action tracking.">
                {can.manage && (<Button asChild><Link href={route('ncrs.create')}><Plus className="h-4 w-4" /> Raise NCR</Link></Button>)}
            </PageHeader>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <Select value={filters.severity || 'all'} onValueChange={(v) => applyFilters({ severity: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Severity" /></SelectTrigger>
                        <SelectContent><SelectItem value="all">All</SelectItem><SelectItem value="minor">Minor</SelectItem><SelectItem value="major">Major</SelectItem><SelectItem value="critical">Critical</SelectItem></SelectContent>
                    </Select>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent><SelectItem value="all">All</SelectItem><SelectItem value="open">Open</SelectItem><SelectItem value="in_progress">In Progress</SelectItem><SelectItem value="closed">Closed</SelectItem></SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {ncrs.data.length === 0 ? (
                        <EmptyState icon={FileWarning} title="No NCRs recorded" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>NCR No.</TableHead><TableHead>Description</TableHead><TableHead>Severity</TableHead><TableHead>Responsible Party</TableHead><TableHead>Raised By</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {ncrs.data.map((n) => (
                                    <TableRow key={n.id} className="cursor-pointer" onClick={() => router.visit(route('ncrs.show', n.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{n.ncr_number}</TableCell>
                                        <TableCell className="max-w-xs truncate">{n.description}</TableCell>
                                        <TableCell><StatusBadge value={n.severity === 'critical' ? 'critical' : n.severity} /></TableCell>
                                        <TableCell>{n.responsible_party || '-'}</TableCell>
                                        <TableCell>{n.raiser?.name}</TableCell>
                                        <TableCell><StatusBadge value={n.status === 'closed' ? 'approved' : n.status} label={n.status.replace('_', ' ')} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {ncrs.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {ncrs.current_page} of {ncrs.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!ncrs.prev_page_url} onClick={() => router.get(ncrs.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!ncrs.next_page_url} onClick={() => router.get(ncrs.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
