import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, FileStack, ChevronLeft, ChevronRight } from 'lucide-react';

export default function PurchaseRequisitionsIndex({ requisitions, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('purchase-requisitions.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Purchase Requisition" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">Purchase Requisition</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Procurement's internal purchasing request, optionally raised from a Material Request.</p>
                </div>
                {can.manage && (<Button asChild><Link href={route('purchase-requisitions.create')}><Plus className="h-4 w-4" /> New PR</Link></Button>)}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search PR number..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {['draft', 'submitted', 'under_review', 'approved', 'rejected', 'converted_to_rfq', 'converted_to_po', 'completed', 'cancelled'].map((s) => (
                                <SelectItem key={s} value={s} className="capitalize">{s.replace(/_/g, ' ')}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {requisitions.data.length === 0 ? (
                        <EmptyState icon={FileStack} title="No purchase requisitions recorded" description="Create a PR to start tracking it." />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>PR No.</TableHead><TableHead>Date</TableHead><TableHead>Department</TableHead><TableHead>Priority</TableHead><TableHead>Est. Total</TableHead><TableHead>Requested By</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {requisitions.data.map((pr) => (
                                    <TableRow key={pr.id} className="cursor-pointer" onClick={() => router.visit(route('purchase-requisitions.show', pr.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{pr.pr_number}</TableCell>
                                        <TableCell>{new Date(pr.request_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell>{pr.department?.name || '-'}</TableCell>
                                        <TableCell><StatusBadge value={pr.priority} /></TableCell>
                                        <TableCell>{Number(pr.estimated_total).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })}</TableCell>
                                        <TableCell>{pr.requester?.name}</TableCell>
                                        <TableCell><StatusBadge value={pr.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {requisitions.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {requisitions.current_page} of {requisitions.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!requisitions.prev_page_url} onClick={() => router.get(requisitions.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!requisitions.next_page_url} onClick={() => router.get(requisitions.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
