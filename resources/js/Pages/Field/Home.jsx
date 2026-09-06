import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleCard from '@/Components/shared/ModuleCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import {
    Flame, ClipboardList, ClipboardCheck, Eye, AlertTriangle, CheckSquare, ArrowRight, Lock,
    MapPin, Briefcase, CalendarClock, ShieldAlert,
} from 'lucide-react';
import { useClock, greetingFor } from '@/lib/useClock';
import { cn } from '@/lib/utils';

// v2.11.0 (Field/Foreman Experience pass, Phase 3H): added Lock for the new LOTO tile.
const ICONS = { Flame, ClipboardList, ClipboardCheck, Eye, AlertTriangle, CheckSquare, Lock };

// Surface tint per action, keyed by the icon the server already sends --
// presentational only, so no controller, route or permission changes.
// Semantics, not decoration: reporting an incident is red, a completed
// checklist green, an observation purple, an isolation amber, and the
// permit/task actions carry the IOMS blue.
const TILE_ACCENTS = {
    AlertTriangle: 'red',
    ClipboardCheck: 'green',
    Eye: 'purple',
    Lock: 'amber',
    Flame: 'brand',
    ClipboardList: 'brand',
    CheckSquare: 'brand',
};

/**
 * MY WORK -- the field execution workspace.
 *
 * v2.52.0 -- IT IS A WORKSPACE, NOT A MENU.
 *
 * Until now this page answered "what CAN I do", which a foreman already
 * knows, and it did so with a wall of shortcut tiles. What they open it
 * for is "what do I have to do today, and what is still open on me". So
 * the execution content -- assigned tasks, live permits with their job
 * name and work location -- now sits ABOVE the tiles, and the tiles
 * remain underneath as the things you go and start.
 *
 * WHO LANDS HERE: an account flagged `is_field_user` is routed straight
 * here at login instead of an office dashboard (see
 * User::landingRouteName()). That flag is a WORKSPACE preference.
 *
 * PTW ACCESS IS A DIFFERENT THING. `canCreatePtw` decides whether the
 * Create PTW action exists at all. A field worker normally has My Work
 * without PTW Access; a foreman may have both. The page never fakes the
 * action when the permission is absent -- and it could not grant anything
 * if it did, because PermitToWorkController enforces the same gate
 * server-side.
 *
 * Still the SAME IOMS application -- same AuthenticatedLayout shell, same
 * auth/tenant/RBAC/backend. Deliberately larger touch targets and lower
 * information density than the enterprise Dashboard, which stays
 * untouched.
 */
export default function FieldHome({
    tiles,
    pendingApprovalsCount,
    myTasksCount,
    myTasks = [],
    myPermits = [],
    canCreatePtw = false,
}) {
    const { auth } = usePage().props;
    const now = useClock();
    const attentionCount = pendingApprovalsCount + myTasksCount;

    const fmtDate = (v) =>
        v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : null;
    const fmtDateTime = (v) =>
        v ? new Date(v).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : null;

    return (
        <AuthenticatedLayout>
            <Head title="My Work" />

            <div className="mb-5">
                <h1 className="text-xl font-semibold tracking-tight text-navy-900 dark:text-slate-50">
                    {greetingFor(now)}, {auth?.user?.name?.split(' ')[0]}
                </h1>
                <p className="mt-0.5 text-sm text-graphite-500 dark:text-slate-400">Apa yang mau dikerjakan hari ini?</p>
            </div>

            {attentionCount > 0 && (
                <Link
                    href={route('work-center.index')}
                    className="mb-5 flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 transition-colors hover:bg-amber-100 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300"
                >
                    <span>
                        <strong>{attentionCount}</strong> hal menunggu Anda
                        {pendingApprovalsCount > 0 && ` — ${pendingApprovalsCount} persetujuan`}
                        {pendingApprovalsCount > 0 && myTasksCount > 0 && ','}
                        {myTasksCount > 0 && ` ${myTasksCount} tugas`}
                    </span>
                    <ArrowRight className="h-4 w-4 shrink-0" />
                </Link>
            )}

            {/* ---------------------------------------------------------- */}
            {/* Live permits. Job name and work location are shown as TWO   */}
            {/* separate facts, because "what job" and "where" are          */}
            {/* different questions a field user needs answered.            */}
            {/* ---------------------------------------------------------- */}
            {myPermits.length > 0 && (
                <section className="mb-5">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-[13px] font-semibold uppercase tracking-wide text-graphite-500">Izin kerja berjalan</h2>
                        <Link href={route('permits-to-work.mine')} className="text-xs font-medium text-brand-700 hover:underline">
                            Lihat semua
                        </Link>
                    </div>
                    <div className="space-y-2">
                        {myPermits.map((permit) => (
                            <Link
                                key={permit.id}
                                href={permit.href}
                                className="block rounded-xl border border-steel-200/70 bg-gradient-to-b from-steel-100/60 via-white to-white p-4 shadow-card transition-all hover:-translate-y-0.5 hover:shadow-card-hover dark:border-slate-800 dark:from-slate-900 dark:to-slate-900"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="flex items-center gap-2">
                                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-[9px] bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)]">
                                            <Flame className="h-4 w-4" />
                                        </span>
                                        <span className="text-sm font-semibold text-navy-900 dark:text-slate-50">{permit.ptw_number}</span>
                                    </span>
                                    <StatusBadge value={permit.status} />
                                </div>

                                <dl className="mt-3 grid gap-x-4 gap-y-1.5 sm:grid-cols-3">
                                    <Fact icon={Briefcase} label="Pekerjaan" value={permit.work_identity} />
                                    <Fact icon={MapPin} label="Lokasi kerja" value={permit.location} />
                                    <Fact icon={CalendarClock} label="Mulai" value={fmtDateTime(permit.start_datetime)} />
                                </dl>
                            </Link>
                        ))}
                    </div>
                </section>
            )}

            {/* ---------------------------------------------------------- */}
            {/* Assigned tasks                                              */}
            {/* ---------------------------------------------------------- */}
            {myTasks.length > 0 && (
                <section className="mb-5">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-[13px] font-semibold uppercase tracking-wide text-graphite-500">Tugas Anda</h2>
                        <Link href={route('work-center.index')} className="text-xs font-medium text-brand-700 hover:underline">
                            Lihat semua
                        </Link>
                    </div>
                    <div className="divide-y divide-steel-100 overflow-hidden rounded-xl border border-steel-200/70 bg-white shadow-card dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
                        {myTasks.map((task) => (
                            <Link key={task.id} href={task.href} className="flex items-center gap-3 p-3.5 transition-colors hover:bg-steel-50/70 dark:hover:bg-slate-800/50">
                                <CheckSquare className="h-4 w-4 shrink-0 text-graphite-300" />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium text-navy-900 dark:text-slate-100">{task.title}</span>
                                    {task.due_date && (
                                        <span className={cn('block text-[11px]', task.is_overdue ? 'font-semibold text-danger' : 'text-graphite-500')}>
                                            {task.is_overdue ? 'Terlambat — ' : 'Jatuh tempo '}{fmtDate(task.due_date)}
                                        </span>
                                    )}
                                </span>
                                <StatusBadge value={task.priority} />
                            </Link>
                        ))}
                    </div>
                </section>
            )}

            {/* ---------------------------------------------------------- */}
            {/* Actions. v2.49.0: the shared ModuleCard at `lg`, so future   */}
            {/* global visual work reaches this page automatically.          */}
            {/* ---------------------------------------------------------- */}
            <h2 className="mb-2 text-[13px] font-semibold uppercase tracking-wide text-graphite-500">Aksi cepat</h2>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {tiles.map((tile) => (
                    <ModuleCard
                        key={tile.label}
                        size="lg"
                        accent={TILE_ACCENTS[tile.icon] || 'brand'}
                        icon={ICONS[tile.icon] || ClipboardList}
                        title={tile.label}
                        description={tile.description}
                        href={tile.href}
                    />
                ))}
            </div>

            {/* Honest about a missing capability rather than silently one
                button short. PTW Access is a permission an administrator
                grants; it is not something this page can hand out. */}
            {! canCreatePtw && (
                <p className="mt-4 flex items-start gap-2 text-xs leading-relaxed text-graphite-500">
                    <ShieldAlert className="mt-px h-3.5 w-3.5 shrink-0 text-graphite-400" />
                    <span>
                        Akun Anda belum memiliki <strong className="text-navy-800 dark:text-slate-200">PTW Access</strong>, sehingga
                        belum dapat membuat Permit To Work. Hubungi administrator IOMS perusahaan Anda bila akses ini diperlukan.
                    </span>
                </p>
            )}
        </AuthenticatedLayout>
    );
}

/** One labelled fact on a permit card. Renders an em dash rather than hiding a field that is genuinely empty. */
function Fact({ icon: Icon, label, value }) {
    return (
        <div className="min-w-0">
            <dt className="flex items-center gap-1 text-[10px] uppercase tracking-wide text-graphite-400">
                <Icon className="h-3 w-3" />
                {label}
            </dt>
            <dd className="truncate text-[13px] text-graphite-700 dark:text-slate-300">{value || '—'}</dd>
        </div>
    );
}
