import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { cn } from '@/lib/utils';
import { Plus, ArrowRight, RotateCcw, FileWarning, ChevronLeft, ChevronRight } from 'lucide-react';

const FILTERS = [
    { key: 'all', label: 'All' },
    { key: 'submitted', label: 'Pending' },
    { key: 'approved', label: 'Approved' },
    { key: 'active', label: 'Active' },
    { key: 'rejected', label: 'Rejected' },
    { key: 'closed', label: 'Closed' },
];

// Draft/cancelled aren't in the filter tabs (directive: "only show
// filters that map to real existing states" the field user actually
// needs to act on) but a draft or cancelled permit can still appear
// under "All" -- both get a sensible action label here rather than
// falling through to nothing.
const ACTION_LABEL = {
    draft: 'Continue',
    submitted: 'View',
    approved: 'View PTW',
    active: 'View PTW',
    rejected: 'View',
    closed: 'View',
    cancelled: 'View',
};

/**
 * v2.9.0 (Field/Foreman Experience pass, Phase 3C -- My PTW). A
 * SEPARATE, field-oriented view over the same `permits_to_work` data
 * the enterprise `PermitsToWork/Index.jsx` already lists -- not a
 * redesign of it (that page, its table, its Type/Status filters for
 * HSE/Admin all stay exactly as they are). This page is reached from
 * Field Home ("My PTW" tile) and scoped server-side to the current
 * user's own requested permits (see
 * `PermitToWorkController::myIndex()`'s own doc comment for the
 * ownership/security reasoning) -- always a compact card list,
 * deliberately never the dense enterprise table, on any viewport.
 *
 * Reuses everything else: Create PTW still goes to the exact same
 * `permits-to-work.create` form from Phase 1, every card's "View"
 * action goes to the exact same `Show.jsx` (which already has View PTW
 * Document / Download PDF / Print / Resubmit from Phases 2/3B) -- no
 * duplicated detail logic, no second PTW form.
 */
export default function MyPermitsToWork({ permits, filters, counts }) {
    function applyFilter(status) {
        router.get(route('permits-to-work.mine'), status === 'all' ? {} : { status }, { preserveState: true, replace: true });
    }

    function resubmit(id) {
        if (!confirm('Kembalikan PTW ini ke Draft untuk diajukan ulang?')) return;
        router.post(route('permits-to-work.transition', id), { status: 'draft' }, { preserveScroll: true });
    }

    const activeFilter = filters.status || 'all';

    return (
        <AuthenticatedLayout>
            <Head title="My PTW" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight text-graphite-900 dark:text-slate-50">My PTW</h1>
                    <p className="mt-0.5 text-sm text-graphite-500 dark:text-slate-400">Izin kerja yang Anda ajukan.</p>
                </div>
                <Button asChild><Link href={route('permits-to-work.create')}><Plus className="h-4 w-4" /> New PTW</Link></Button>
            </div>

            {/* Filter tabs -- horizontally scrollable on a narrow phone
                rather than wrapping into a cramped grid, so they stay on
                one row and remain easy to tap. */}
            <div className="mb-4 flex gap-2 overflow-x-auto pb-1">
                {FILTERS.map((f) => (
                    <button
                        key={f.key}
                        onClick={() => applyFilter(f.key)}
                        className={cn(
                            'shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors',
                            activeFilter === f.key
                                ? 'bg-brand-600 text-white'
                                : 'bg-graphite-100 text-graphite-600 hover:bg-graphite-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'
                        )}
                    >
                        {f.label}{counts[f.key] > 0 && ` (${counts[f.key]})`}
                    </button>
                ))}
            </div>

            {permits.data.length === 0 ? (
                <EmptyState
                    icon={FileWarning}
                    title="Belum ada PTW."
                    description="Buat izin kerja untuk aktivitas berisiko tinggi berikutnya."
                    action={<Button asChild size="sm"><Link href={route('permits-to-work.create')}><Plus className="h-4 w-4" /> New PTW</Link></Button>}
                />
            ) : (
                <div className="space-y-3">
                    {permits.data.map((p) => (
                        <div key={p.id} className="rounded-lg border border-graphite-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
                            <Link href={route('permits-to-work.show', p.id)} className="block">
                                <div className="flex items-start justify-between gap-2">
                                    <span className="font-medium text-graphite-900 dark:text-slate-100">{p.ptw_number}</span>
                                    <StatusBadge value={p.status} />
                                </div>
                                <p className="mt-1 text-sm capitalize text-graphite-700 dark:text-slate-300">{p.permit_type.replace('_', ' ')}</p>
                                {p.work_description && <p className="mt-0.5 line-clamp-2 text-xs text-graphite-500 dark:text-slate-400">{p.work_description}</p>}
                                <p className="mt-2 text-xs text-graphite-400">
                                    {[p.project?.name, p.location].filter(Boolean).join(' · ')}
                                </p>
                                <p className="mt-0.5 text-xs text-graphite-400">
                                    {new Date(p.start_datetime).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}
                                    {' – '}
                                    {new Date(p.end_datetime).toLocaleString('en-US', { hour: 'numeric', minute: '2-digit' })}
                                </p>
                            </Link>

                            {p.status === 'rejected' && (
                                <div className="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-950/30 dark:text-red-400">
                                    <span className="font-medium">Alasan:</span> {p.rejection_reason || 'Tidak ada alasan tercatat.'}
                                </div>
                            )}

                            <div className="mt-3 flex items-center justify-between gap-2">
                                <Link href={route('permits-to-work.show', p.id)} className="flex items-center gap-1 text-sm font-medium text-brand-700 dark:text-brand-400">
                                    {ACTION_LABEL[p.status] || 'View'} <ArrowRight className="h-3.5 w-3.5" />
                                </Link>
                                {p.status === 'rejected' && (
                                    <Button variant="outline" size="sm" onClick={() => resubmit(p.id)}><RotateCcw className="h-3.5 w-3.5" /> Resubmit</Button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}

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
