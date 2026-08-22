import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, Users, ChevronLeft, ChevronRight } from 'lucide-react';

export default function TbmMeetingsIndex({ meetings, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('tbm-meetings.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Toolbox Meeting (TBM)" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">Toolbox Meeting (TBM)</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Pre-work safety briefings and attendance.</p>
                </div>
                {can.manage && (<Button asChild><Link href={route('tbm-meetings.create')}><Plus className="h-4 w-4" /> Record TBM</Link></Button>)}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search TBM number or topic..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {meetings.data.length === 0 ? (
                        <EmptyState icon={Users} title="No TBMs recorded" description="Record a Toolbox Meeting to start tracking it." />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>TBM No.</TableHead><TableHead>Topic</TableHead><TableHead>Date</TableHead><TableHead>Project</TableHead><TableHead>Conducted By</TableHead><TableHead>Attendees</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {meetings.data.map((m) => (
                                    <TableRow key={m.id} className="cursor-pointer" onClick={() => router.visit(route('tbm-meetings.show', m.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{m.tbm_number}</TableCell>
                                        <TableCell className="max-w-xs truncate">{m.topic}</TableCell>
                                        <TableCell>{new Date(m.meeting_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell>{m.project?.name || '-'}</TableCell>
                                        <TableCell>{m.conductor?.name}</TableCell>
                                        <TableCell>{m.attendees_count}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {meetings.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {meetings.current_page} of {meetings.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!meetings.prev_page_url} onClick={() => router.get(meetings.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!meetings.next_page_url} onClick={() => router.get(meetings.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
