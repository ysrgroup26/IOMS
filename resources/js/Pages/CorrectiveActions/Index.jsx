import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Button } from '@/Components/ui/button';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import StatCard from '@/Components/shared/StatCard';
import { ClipboardList, ChevronLeft, ChevronRight, CircleDot, AlertTriangle, Clock, CheckCircle2 } from 'lucide-react';

/**
 * v2.5.0 (Field HSE Experience pass, Part 10 -- CAPA). Added the compact
 * Open/Overdue/In Progress/Closed summary row + an "Overdue" quick
 * filter chip, per the explicit "CAPA should be an ACTION MANAGEMENT
 * tool... make overdue actions obvious" requirement. Reuses the existing
 * StatCard component (same one every dashboard uses) rather than
 * inventing new summary-card markup -- the Overdue card is clickable
 * (toggles the `overdue=1` filter, same query-string pattern the rest of
 * this page already uses) so it doubles as both an indicator and a
 * shortcut, matching StatCard's existing `href`-as-filter-shortcut
 * pattern used elsewhere in this codebase.
 */
export default function CorrectiveActionsIndex({ actions, filters, summary, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('corrective-actions.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    function overdueHref() {
        // Build from only the truthy filter values -- `filters` (from
        // Request::only()) carries `null` for every absent query param,
        // and URLSearchParams would otherwise stringify that literal
        // null into the query string.
        const merged = { ...filters, overdue: filters.overdue ? '' : '1' };
        const params = new URLSearchParams(Object.fromEntries(Object.entries(merged).filter(([, v]) => !!v)));
        return route('corrective-actions.index') + '?' + params.toString();
    }

    function updateStatus(a, status) {
        // v2.5.0 (Field HSE Experience pass, Part 38): only the
        // destructive-feeling transition (Cancelled) asks for
        // confirmation -- In Progress/Completed/Verified stay one-click,
        // matching "do not over-confirm harmless actions."
        if (status === 'cancelled' && !confirm('Batalkan tindakan CAPA ini?')) return;
        router.post(route('corrective-actions.update-status', a.id), { status }, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Corrective Actions (CAPA)" />

            <div className="mb-4">
                <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">Corrective Actions (CAPA)</h1>
                <p className="text-xs text-graphite-500 dark:text-slate-400">Satu tampilan gabungan dari temuan Safety Observation, HSE Inspection, dan Incident.</p>
            </div>

            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatCard icon={CircleDot} value={summary.open} label="Open" />
                <StatCard icon={AlertTriangle} value={summary.overdue} label="Overdue" accent={summary.overdue > 0 ? 'red' : null} href={overdueHref()} />
                <StatCard icon={Clock} value={summary.in_progress} label="In Progress" accent="amber" />
                <StatCard icon={CheckCircle2} value={summary.closed} label="Closed" accent="green" />
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="open">Open</SelectItem>
                            <SelectItem value="in_progress">In Progress</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="verified">Verified</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters.priority || 'all'} onValueChange={(v) => applyFilters({ priority: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Priority" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Priorities</SelectItem>
                            <SelectItem value="low">Low</SelectItem>
                            <SelectItem value="medium">Medium</SelectItem>
                            <SelectItem value="high">High</SelectItem>
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {actions.data.length === 0 ? (
                        <EmptyState
                            icon={ClipboardList}
                            title="Belum ada CAPA."
                            description="Temuan dari Safety Observation, Inspection, atau Incident akan muncul di sini."
                        />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Action</TableHead><TableHead>Source</TableHead><TableHead>Assigned To</TableHead><TableHead>Due</TableHead><TableHead>Priority</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                            <TableBody>
                                {actions.data.map((a) => (
                                    <TableRow key={a.id} className={a.is_overdue ? 'bg-red-50/50' : ''}>
                                        <TableCell className="max-w-xs truncate">{a.action}</TableCell>
                                        <TableCell>{a.source_route ? <Link href={a.source_route} className="text-brand-700 hover:underline">{a.source_type}: {a.source_label}</Link> : `${a.source_type}: ${a.source_label ?? '-'}`}</TableCell>
                                        <TableCell>{a.assignee?.name || '-'}</TableCell>
                                        <TableCell>{a.due_date ? new Date(a.due_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'}{a.is_overdue && <span className="ml-1 text-red-600">overdue</span>}</TableCell>
                                        <TableCell className="capitalize">{a.priority}</TableCell>
                                        <TableCell><StatusBadge value={a.status} /></TableCell>
                                        {can.manage && (
                                            <TableCell>
                                                {!['verified', 'cancelled'].includes(a.status) && (
                                                    <Select value="" onValueChange={(v) => updateStatus(a, v)}>
                                                        <SelectTrigger className="h-8 w-32 text-xs"><SelectValue placeholder="Update..." /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="in_progress">In Progress</SelectItem>
                                                            <SelectItem value="completed">Completed</SelectItem>
                                                            <SelectItem value="verified">Verified</SelectItem>
                                                            <SelectItem value="cancelled">Cancelled</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                )}
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {actions.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {actions.current_page} of {actions.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!actions.prev_page_url} onClick={() => router.get(actions.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!actions.next_page_url} onClick={() => router.get(actions.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
