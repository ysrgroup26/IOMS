import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, Wrench, ChevronLeft, ChevronRight } from 'lucide-react';

export default function MaintenanceRequestsIndex({ requests, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('maintenance-requests.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Maintenance Request" />
            <PageHeader title="Maintenance Request" subtitle="Report an asset problem for maintenance.">
                <Button asChild><Link href={route('maintenance-requests.create')}><Plus className="h-4 w-4" /> Report Problem</Link></Button>
            </PageHeader>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px]">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search request number..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {['reported', 'approved', 'rejected', 'converted_to_wo', 'cancelled'].map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace(/_/g, ' ')}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {requests.data.length === 0 ? (
                        <EmptyState icon={Wrench} title="No maintenance requests" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Request No.</TableHead><TableHead>Asset</TableHead><TableHead>Problem</TableHead><TableHead>Priority</TableHead><TableHead>Reported By</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {requests.data.map((r) => (
                                    <TableRow key={r.id} className="cursor-pointer" onClick={() => router.visit(route('maintenance-requests.show', r.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{r.request_number}</TableCell>
                                        <TableCell>{r.asset?.name}</TableCell>
                                        <TableCell className="max-w-xs truncate">{r.problem}</TableCell>
                                        <TableCell><StatusBadge value={r.priority} /></TableCell>
                                        <TableCell>{r.reporter?.name}</TableCell>
                                        <TableCell><StatusBadge value={r.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {requests.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {requests.current_page} of {requests.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!requests.prev_page_url} onClick={() => router.get(requests.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!requests.next_page_url} onClick={() => router.get(requests.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
