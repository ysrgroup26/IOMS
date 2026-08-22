import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, FileQuestion, ChevronLeft, ChevronRight } from 'lucide-react';

export default function RfqsIndex({ rfqs, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('rfqs.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="RFQ (Request for Quotation)" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-graphite-900 dark:text-slate-50">RFQ (Request for Quotation)</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Invite vendors to quote against an approved Purchase Requisition.</p>
                </div>
                {can.manage && (<Button asChild><Link href={route('rfqs.create')}><Plus className="h-4 w-4" /> New RFQ</Link></Button>)}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search RFQ number..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent><SelectItem value="all">All</SelectItem><SelectItem value="draft">Draft</SelectItem><SelectItem value="issued">Issued</SelectItem><SelectItem value="closed">Closed</SelectItem><SelectItem value="cancelled">Cancelled</SelectItem></SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {rfqs.data.length === 0 ? (
                        <EmptyState icon={FileQuestion} title="No RFQs recorded" description="Create an RFQ from an approved Purchase Requisition." />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>RFQ No.</TableHead><TableHead>PR</TableHead><TableHead>Deadline</TableHead><TableHead>Buyer</TableHead><TableHead>Quotations</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {rfqs.data.map((r) => (
                                    <TableRow key={r.id} className={`cursor-pointer ${r.is_overdue ? 'bg-red-50/50' : ''}`} onClick={() => router.visit(route('rfqs.show', r.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{r.rfq_number}</TableCell>
                                        <TableCell>{r.purchase_requisition?.pr_number}</TableCell>
                                        <TableCell>{new Date(r.quotation_deadline).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}{r.is_overdue && <span className="ml-1 text-red-600">overdue</span>}</TableCell>
                                        <TableCell>{r.buyer?.name}</TableCell>
                                        <TableCell>{r.quotations_count}</TableCell>
                                        <TableCell><StatusBadge value={r.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {rfqs.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {rfqs.current_page} of {rfqs.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!rfqs.prev_page_url} onClick={() => router.get(rfqs.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!rfqs.next_page_url} onClick={() => router.get(rfqs.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
