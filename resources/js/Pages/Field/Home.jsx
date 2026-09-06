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
 * v2.53.0 -- ACTIONS FIRST, INFORMATION SECOND.
 *
 * v2.52.0 gave this page real content but put the live permits above the
 * action tiles, which inverted what a field worker actually opens it for.
 * Someone standing on a dock with a phone wants the thing they came to do
 * within thumb's reach; the permits already running are context they
 * consult second, if at all. So the order is now:
 *
 *     greeting -> what needs attention -> ACTIONS -> permits in progress
 *
 * THE GREETING addresses the crew, not a job title. It previously read
 * "Good Evening, HSE" because it used the account name, which is both
 * impersonal and wrong -- an account called HSE is a mailbox, not a
 * person, and a foreman is not HSE.
 *
 * LANGUAGE. Product and feature names stay English (My Work, Permit To
 * Work, PTW Access); the sentences explaining them are Indonesian,
 * because the people reading them on site read Indonesian. That hierarchy
 * is deliberate and applies across IOMS.
 *
 * WHO LANDS HERE: an account flagged `is_field_user` is routed straight
 * here at login (see User::landingRouteName()). That flag is a WORKSPACE
 * preference. `canCreatePtw` is a separate PERMISSION and decides whether
 * the Create PTW action exists at all -- the page never fakes it, and
 * could not grant anything if it did, because PermitToWorkController
 * enforces the same gate server-side.
 */
export default function FieldHome({
    tiles,
    pendingApprovalsCount,
    myTasksCount,
    myTasks = [],
    myPermits = [],
    canCreatePtw = false,
}) {
    const now = useClock();
    const attentionCount = pendingApprovalsCount + myTasksCount;

    const fmtDate = (v) =>
        v ? new Date(v).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : null;
    const fmtDateTime = (v) =>
        v ? new Date(v).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : null;

    return (
        <AuthenticatedLayout>
            <Head title="My Work" />

            {/* Addressed to the crew. Never the account's role -- "Good
                Evening, HSE" is a mailbox greeting, and a foreman is not HSE. */}
            <div className="mb-4">
                <h1 className="text-xl font-semibold tracking-tight text-navy-900 dark:text-slate-50">
                    {greetingFor(now)}, Team.
                </h1>
                <p className="mt-0.5 text-sm text-graphite-500 dark:text-slate-400">
                    Pilih pekerjaan yang mau Anda kerjakan hari ini.
                </p>
            </div>

            {attentionCount > 0 && (
                <Link
                    href={route('work-center.index')}
                    className="mb-4 flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 transition-colors hover:bg-amber-100 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300"
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
            {/* ACTIONS -- first, because this is what the page is for.     */}
            {/* ---------------------------------------------------------- */}
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
                <p className="mt-3 flex items-start gap-2 text-xs leading-relaxed text-graphite-500">
                    <ShieldAlert className="mt-px h-3.5 w-3.5 shrink-0 text-graphite-400" />
                    <span>
                        Akun Anda belum memiliki <strong className="text-navy-800 dark:text-slate-200">PTW Access</strong>, sehingga
                        belum dapat membuat Permit To Work. Hubungi administrator IOMS perusahaan Anda bila akses ini diperlukan.
                    </span>
                </p>
            )}

            {/* ---------------------------------------------------------- */}
            {/* SUPPORTING INFORMATION -- consulted, not acted on first.    */}
            {/* Job and location are shown as TWO separate facts: "what     */}
            {/* job" and "where" are different questions on site.           */}
            {/* ---------------------------------------------------------- */}
            {myPermits.length > 0 && (
                <section className="mt-6">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-[13px] font-semibold uppercase tracking-wide text-graphite-500">
                            Permit To Work berjalan
                        </h2>
                        <Link href={route('permits-to-work.mine')} className="text-xs font-medium text-brand-700 hover:underline">
                            Lihat semua
                        </Link>
                    </div>
                    <div className="space-y-2">
                        {myPermits.map((permit) => (
                            <Link
                                key={permit.id}
                                href={permit.href}
                                className="block rounded-xl border border-steel-200/70 bg-gradient-to-b from-steel-100/60 via-white to-white p-3.5 shadow-card transition-all hover:-translate-y-0.5 hover:shadow-card-hover dark:border-slate-800 dark:from-slate-900 dark:to-slate-900"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="flex items-center gap-2">
                                        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-[8px] bg-gradient-to-br from-navy-800 to-brand-600 text-white shadow-[0_3px_8px_-3px_rgba(33,102,196,0.38)]">
                                            <Flame className="h-3.5 w-3.5" />
                                        </span>
                                        <span className="text-[13px] font-semibold text-navy-900 dark:text-slate-50">{permit.ptw_number}</span>
                                    </span>
                                    <StatusBadge value={permit.status} />
                                </div>

                                <dl className="mt-2.5 grid gap-x-4 gap-y-1.5 sm:grid-cols-3">
                                    <Fact icon={Briefcase} label="Pekerjaan" value={permit.work_identity} />
                                    <Fact icon={MapPin} label="Lokasi kerja" value={permit.location} />
                                    <Fact icon={CalendarClock} label="Mulai" value={fmtDateTime(permit.start_datetime)} />
                                </dl>
                            </Link>
                        ))}
                    </div>
                </section>
            )}

            {myTasks.length > 0 && (
                <section className="mt-6">
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-[13px] font-semibold uppercase tracking-wide text-graphite-500">Tugas Anda</h2>
                        <Link href={route('work-center.index')} className="text-xs font-medium text-brand-700 hover:underline">
                            Lihat semua
                        </Link>
                    </div>
                    <div className="divide-y divide-steel-100 overflow-hidden rounded-xl border border-steel-200/70 bg-white shadow-card dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
                        {myTasks.map((task) => (
                            <Link key={task.id} href={task.href} className="flex items-center gap-3 p-3 transition-colors hover:bg-steel-50/70 dark:hover:bg-slate-800/50">
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
