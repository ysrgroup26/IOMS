import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, FileWarning, ChevronLeft, ChevronRight, ArrowRight } from 'lucide-react';

/**
 * v2.8.0 (PTW Mobile / Task-First pass, Phase 3B, Part 3). PREVIOUSLY:
 * one enterprise `<Table>` for every viewport -- 6 columns (PTW No./
 * Type/Start/Location/Requested By/Status), Status rightmost with no
 * reorder/priority handling, so on a phone the table's own
 * `overflow-auto` (from the shared Table component) meant a field user
 * had to scroll horizontally just to see the one thing that matters
 * most: the status. Confirmed via this pass's own fresh audit before
 * touching anything.
 *
 * Fixed by adding a genuine mobile card list (`md:hidden`) alongside the
 * existing desktop table (`hidden md:block`, completely unchanged) --
 * not a redesign of the enterprise view, an additional presentation of
 * the exact same `permits` data for narrow viewports. Card order follows
 * the product's own field-priority list: PTW number + status (most
 * important, together, no scrolling), permit type, work description,
 * location, start date, one obvious "View PTW" action.
 */
export default function PermitsToWorkIndex({ permits, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('permits-to-work.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Permit To Work" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">Permit To Work</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Hot work, confined space, working at height and other high-risk permits.</p>
                </div>
                {can.manage && (<Button asChild><Link href={route('permits-to-work.create')}><Plus className="h-4 w-4" /> New Permit</Link></Button>)}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search PTW number or description..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.type || 'all'} onValueChange={(v) => applyFilters({ type: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Type" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Types</SelectItem>
                            {['hot_work', 'cold_work', 'confined_space', 'working_at_height', 'excavation', 'electrical', 'general'].map((t) => (
                                <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {['draft', 'submitted', 'rejected', 'approved', 'active', 'closed', 'cancelled'].map((s) => (
                                <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {permits.data.length === 0 ? (
                        <EmptyState
                            icon={FileWarning}
                            title="Belum ada PTW."
                            description="Buat izin kerja pertama untuk memulai."
                            action={can.manage && (
                                <Button asChild size="sm"><Link href={route('permits-to-work.create')}><Plus className="h-4 w-4" /> New Permit</Link></Button>
                            )}
                        />
                    ) : (
                        <>
                            {/* Mobile card list -- see this file's own
                                doc comment above for why this exists
                                alongside (not instead of) the desktop
                                table below. */}
                            <div className="divide-y divide-graphite-100 md:hidden dark:divide-slate-800">
                                {permits.data.map((p) => (
                                    <Link
                                        key={p.id}
                                        href={route('permits-to-work.show', p.id)}
                                        className="block px-4 py-3 active:bg-graphite-50 dark:active:bg-slate-800/50"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="font-medium text-graphite-900 dark:text-slate-100">{p.ptw_number}</span>
                                            <StatusBadge value={p.status} />
                                        </div>
                                        <p className="mt-1 text-sm capitalize text-graphite-700 dark:text-slate-300">{p.permit_type.replace('_', ' ')}</p>
                                        {p.work_description && <p className="mt-0.5 line-clamp-1 text-xs text-graphite-500 dark:text-slate-400">{p.work_description}</p>}
                                        <div className="mt-2 flex items-center justify-between gap-2 text-xs text-graphite-400">
                                            <span className="truncate">
                                                {p.location || '-'} &middot; {new Date(p.start_datetime).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}
                                            </span>
                                            <span className="flex shrink-0 items-center gap-0.5 font-medium text-brand-700 dark:text-brand-400">
                                                View PTW <ArrowRight className="h-3 w-3" />
                                            </span>
                                        </div>
                                    </Link>
                                ))}
                            </div>

                            <Table className="hidden md:table">
                                <TableHeader>
                                    <TableRow><TableHead>PTW No.</TableHead><TableHead>Type</TableHead><TableHead>Start</TableHead><TableHead>Location</TableHead><TableHead>Requested By</TableHead><TableHead>Status</TableHead></TableRow>
                                </TableHeader>
                                <TableBody>
                                    {permits.data.map((p) => (
                                        <TableRow key={p.id} className="cursor-pointer" onClick={() => router.visit(route('permits-to-work.show', p.id))}>
                                            <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{p.ptw_number}</TableCell>
                                            <TableCell className="capitalize">{p.permit_type.replace('_', ' ')}</TableCell>
                                            <TableCell>{new Date(p.start_datetime).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</TableCell>
                                            <TableCell className="max-w-[160px] truncate">{p.location || '-'}</TableCell>
                                            <TableCell>{p.requester?.name}</TableCell>
                                            <TableCell><StatusBadge value={p.status} /></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
                    )}
                </CardContent>
            </Card>

            {permits.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {permits.current_page} of {permits.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!permits.prev_page_url} onClick={() => router.get(permits.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!permits.next_page_url} onClick={() => router.get(permits.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
