import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Button } from '@/Components/ui/button';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { ClipboardList, ChevronLeft, ChevronRight } from 'lucide-react';

export default function CorrectiveActionsIndex({ actions, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('corrective-actions.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    function updateStatus(a, status) {
        router.post(route('corrective-actions.update-status', a.id), { status }, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Corrective Actions (CAPA)" />

            <div className="mb-4">
                <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">Corrective Actions (CAPA)</h1>
                <p className="text-xs text-graphite-500 dark:text-slate-400">One consolidated view across Safety Observation, HSE Inspection, and Incident findings.</p>
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
                        <EmptyState icon={ClipboardList} title="No corrective actions" description="Findings raised from Safety Observation, Inspection, or Incident will appear here." />
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
