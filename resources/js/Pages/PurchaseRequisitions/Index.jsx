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
import { Plus, Search, FileStack, ChevronLeft, ChevronRight, ArrowRight } from 'lucide-react';

function formatIDR(value) {
    return Number(value).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
}

export default function PurchaseRequisitionsIndex({ requisitions, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('purchase-requisitions.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Purchase Requisition" />

            {/* v2.24.0 (Complete Product UI/UX Transformation, cont'd --
                Purchasing/Procurement). Same PageHeader + unboxed-filter
                treatment already established across PTW/Incidents/Safety
                Observation/Employees/Goods Receipt. */}
            <PageHeader title="Purchase Requisition" subtitle="Permintaan pembelian internal dari tiap departemen, bisa berasal dari Material Request.">
                {can.manage && (<Button asChild><Link href={route('purchase-requisitions.create')}><Plus className="h-4 w-4" /> New PR</Link></Button>)}
            </PageHeader>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="relative min-w-[220px] flex-1">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                    <Input className="border-graphite-200 bg-white pl-8 shadow-none" placeholder="Search PR number..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                </div>
                <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-44 bg-white"><SelectValue placeholder="Status" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Statuses</SelectItem>
                        {['draft', 'submitted', 'under_review', 'approved', 'rejected', 'converted_to_rfq', 'converted_to_po', 'completed', 'cancelled'].map((s) => (
                            <SelectItem key={s} value={s} className="capitalize">{s.replace(/_/g, ' ')}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <Card>
                <CardContent className="p-0">
                    {requisitions.data.length === 0 ? (
                        <EmptyState icon={FileStack} title="Belum ada Purchase Requisition." description="Buat PR untuk mulai melacak kebutuhan pembelian." action={can.manage && (<Button asChild size="sm"><Link href={route('purchase-requisitions.create')}><Plus className="h-4 w-4" /> New PR</Link></Button>)} />
                    ) : (
                        <>
                            {/* v2.24.0: mobile card list -- this page had
                                none before, same pattern proven across this
                                transformation pass. */}
                            <div className="divide-y divide-graphite-100 md:hidden dark:divide-slate-800">
                                {requisitions.data.map((pr) => (
                                    <Link key={pr.id} href={route('purchase-requisitions.show', pr.id)} className="block px-4 py-3 active:bg-graphite-50 dark:active:bg-slate-800/50">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 flex-1 truncate font-medium text-graphite-900 dark:text-slate-100">{pr.pr_number}</span>
                                            <StatusBadge value={pr.status} />
                                        </div>
                                        <p className="mt-1 text-sm text-graphite-700 dark:text-slate-300">{pr.department?.name || '-'} &middot; {formatIDR(pr.estimated_total)}</p>
                                        <div className="mt-2 flex items-center justify-between gap-2 text-xs text-graphite-400">
                                            <span className="min-w-0 flex-1 truncate">{pr.requester?.name} &middot; {new Date(pr.request_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                            <StatusBadge value={pr.priority} />
                                        </div>
                                    </Link>
                                ))}
                            </div>

                            {/* v2.24.0: consolidated from 7 equal-weight
                                columns to 5 grouped cells -- PR number+date
                                as one identity unit, no data dropped. */}
                            <Table className="hidden md:table">
                                <TableHeader><TableRow><TableHead>Requisition</TableHead><TableHead>Department</TableHead><TableHead>Est. Total</TableHead><TableHead>Requested By</TableHead><TableHead>Priority</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                                <TableBody>
                                    {requisitions.data.map((pr) => (
                                        <TableRow key={pr.id} className="cursor-pointer" onClick={() => router.visit(route('purchase-requisitions.show', pr.id))}>
                                            <TableCell>
                                                <p className="font-semibold text-graphite-900 dark:text-slate-100">{pr.pr_number}</p>
                                                <p className="text-xs text-graphite-500 dark:text-slate-400">{new Date(pr.request_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                                            </TableCell>
                                            <TableCell className="text-graphite-700 dark:text-slate-300">{pr.department?.name || '-'}</TableCell>
                                            <TableCell className="font-medium text-graphite-800 dark:text-slate-200">{formatIDR(pr.estimated_total)}</TableCell>
                                            <TableCell className="text-graphite-500 dark:text-slate-400">{pr.requester?.name}</TableCell>
                                            <TableCell><StatusBadge value={pr.priority} /></TableCell>
                                            <TableCell><StatusBadge value={pr.status} /></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
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
