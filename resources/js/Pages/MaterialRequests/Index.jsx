import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import AgingIndicator from '@/Components/shared/AgingIndicator';
import { Plus, Search, FileText, ChevronLeft, ChevronRight, Clock } from 'lucide-react';

/**
 * Material Request MVP (v1.6.8). Deliberately simple -- no approval
 * workflow, no purchasing, no inventory. List, create, edit, view,
 * delete-draft, and generate a printable PDF. Department-agnostic: this
 * list shows requests visible to the user's company (or all companies
 * for a Super Admin), regardless of which department created them.
 */
export default function MaterialRequestsIndex({ requests, filters, can, outstandingSummary }) {
    const outstandingOnly = !!filters.outstanding;

    function applyFilters(overrides = {}) {
        router.get(route('material-requests.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Material Requests" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-navy-900 dark:text-slate-50">Material Requests</h1>
                    {/* v2.26.0 (Final Copy Consistency pass): naturalized to Indonesian. */}
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Ajukan kebutuhan material operasional -- barricade, first aid, signage, dan lainnya.</p>
                </div>
                {can.manage && (
                    <Button asChild>
                        <Link href={route('material-requests.create')}><Plus className="h-4 w-4" /> New Request</Link>
                    </Button>
                )}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input
                            className="pl-8"
                            placeholder="Search request number..."
                            defaultValue={filters.search || ''}
                            onChange={(e) => applyFilters({ search: e.target.value || null })}
                        />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="submitted">Submitted / Pending Approval</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="consolidating">Consolidating</SelectItem>
                            <SelectItem value="rejected">Rejected</SelectItem>
                            <SelectItem value="processing">Processing</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>

                    {/*
                        v2.69.0 -- "what is still owed", which is every
                        non-terminal state at once and therefore something
                        the status dropdown next to it structurally cannot
                        express. Sorts oldest first, because the reason to
                        ask this question is to find what has been waiting
                        too long.
                    */}
                    <Button
                        type="button"
                        variant={outstandingOnly ? 'default' : 'outline'}
                        onClick={() => applyFilters({ outstanding: outstandingOnly ? null : 1, status: null })}
                        aria-pressed={outstandingOnly}
                    >
                        <Clock className="h-4 w-4" /> Outstanding
                        {outstandingSummary?.total > 0 && (
                            <span className="ml-1.5 rounded-full bg-white/20 px-1.5 text-[11px] tabular-nums">{outstandingSummary.total}</span>
                        )}
                    </Button>
                </CardContent>
            </Card>

            {/*
                Only worth a line when something is actually ageing. A
                permanent "0 overdue" banner trains people to stop reading
                the row it lives in.
            */}
            {outstandingSummary?.overdue > 0 && (
                <div className="mb-4 flex items-start gap-2 rounded-lg border border-danger/20 bg-danger/[0.04] px-3 py-2 text-sm dark:border-red-900/40 dark:bg-red-950/20">
                    <Clock className="mt-0.5 h-4 w-4 shrink-0 text-danger" />
                    <p className="text-graphite-700 dark:text-slate-200">
                        <span className="font-semibold text-danger">{outstandingSummary.overdue}</span>
                        {' '}permintaan terbuka lebih dari {outstandingSummary.overdue_days} hari
                        {outstandingSummary.attention > 0 && `, ${outstandingSummary.attention} lainnya lebih dari ${outstandingSummary.attention_days} hari`}.
                        {' '}
                        <button
                            type="button"
                            className="font-medium text-brand-600 underline-offset-2 hover:underline"
                            onClick={() => applyFilters({ outstanding: 1, status: null })}
                        >
                            Lihat yang tertunda
                        </button>
                    </p>
                </div>
            )}

            <Card>
                <CardContent className="p-0">
                    {requests.data.length === 0 ? (
                        <EmptyState icon={FileText} title="No material requests yet" description="Create your first request to get started." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Request No.</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Department</TableHead>
                                    <TableHead>Project</TableHead>
                                    <TableHead>Requested By</TableHead>
                                    <TableHead>Items</TableHead>
                                    <TableHead>Waiting</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {requests.data.map((r) => (
                                    <TableRow key={r.id} className="cursor-pointer" onClick={() => router.visit(route('material-requests.show', r.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{r.request_number}</TableCell>
                                        <TableCell>{new Date(r.request_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell>{r.department?.name || '-'}</TableCell>
                                        <TableCell>{r.project?.name || '-'}</TableCell>
                                        <TableCell>{r.requester?.name}</TableCell>
                                        <TableCell>{r.items_count}</TableCell>
                                        <TableCell><AgingIndicator days={r.open_age_days} level={r.aging_level} /></TableCell>
                                        <TableCell><StatusBadge value={r.status} label={r.status === 'submitted' ? 'Waiting Approval' : undefined} /></TableCell>
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
                        <button disabled={!requests.prev_page_url} onClick={() => router.get(requests.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <button disabled={!requests.next_page_url} onClick={() => router.get(requests.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
