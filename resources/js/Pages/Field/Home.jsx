import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/shared/StatusBadge';
import {
    Flame, ClipboardList, ClipboardCheck, Eye, AlertTriangle, CheckSquare, Lock,
    CalendarClock, ShieldAlert, ShieldCheck, Stamp, Wrench, ChevronRight,
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
const TILE_CAPSULE = {
    AlertTriangle: 'icon-tile-rose',
    ClipboardCheck: 'icon-tile-green',
    Eye: 'icon-tile-violet',
    Lock: 'icon-tile-amber',
    Flame: 'icon-tile-blue',
    ClipboardList: 'icon-tile-blue',
    CheckSquare: 'icon-tile-blue',
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
    waitingOnMe = [],
    assignedToMe = [],
    myPermits = [],
    counts = {},
    links = {},
    canCreatePtw = false,
}) {
    const now = useClock();

    /*
     * v2.141.0 -- THREE QUESTIONS, IN THE ORDER A PERSON ASKS THEM.
     *
     *   Waiting on Me   a decision only I can take (blocks somebody else)
     *   Assigned to Me  work handed to me, overdue first
     *   My Permits      permits I requested or lead, still open
     *
     * Everything is computed and authorised by MyWorkService: an item comes
     * with an `href` only when the server would let this person open it, so
     * the page never offers a door that answers 403 (ADR 061). Quick
     * actions sit between the work and the permits -- within thumb's reach,
     * after what is already waiting.
     */
    const summary = [
        { key: 'waiting', label: 'Waiting on Me', value: counts.waitingOnMe ?? 0, tone: 'warning' },
        { key: 'assigned', label: 'Assigned to Me', value: counts.assignedToMe ?? 0, tone: counts.overdue ? 'critical' : 'info', note: counts.overdue ? `${counts.overdue} terlambat` : null },
        { key: 'permits', label: 'My Permits', value: counts.myPermits ?? 0, tone: 'info' },
    ];

    return (
        <AuthenticatedLayout>
            <Head title="My Work" />

            {/* Addressed to the crew. Never the account's role -- "Good
                Evening, HSE" is a mailbox greeting, and a foreman is not HSE. */}
            <div className="mb-4">
                <h1 className="text-xl font-bold tracking-tight text-navy-900">
                    {greetingFor(now)}, Team.
                </h1>
                <p className="mt-0.5 text-sm text-graphite-500">
                    Yang menunggu keputusan Anda, pekerjaan Anda, dan izin kerja Anda dalam satu tempat.
                </p>
            </div>

            <nav aria-label="My Work summary" className="mb-5 grid grid-cols-3 gap-2">
                {summary.map((card) => (
                    <a
                        key={card.key}
                        href={`#${card.key}`}
                        className="surface flex min-h-[64px] flex-col justify-center rounded-xl px-3 py-2 transition-colors hover:bg-steel-50/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                    >
                        <span className={cn('text-xl font-bold leading-none', card.value > 0 ? SUMMARY_TONE[card.tone] : 'text-graphite-400')}>
                            {card.value}
                        </span>
                        <span className="mt-1 text-[11px] font-semibold leading-tight text-graphite-600">{card.label}</span>
                        {card.note && <span className="text-[10px] font-semibold text-danger">{card.note}</span>}
                    </a>
                ))}
            </nav>

            <WorkSection
                id="waiting"
                title="Waiting on Me"
                items={waitingOnMe}
                total={counts.waitingOnMe ?? 0}
                seeAll={links.workCenter}
                empty="Tidak ada persetujuan atau verifikasi yang menunggu Anda. Permintaan baru akan muncul di sini dan di notifikasi."
            />

            <WorkSection
                id="assigned"
                title="Assigned to Me"
                items={assignedToMe}
                total={counts.assignedToMe ?? 0}
                seeAll={links.tasks ?? links.workCenter}
                empty="Belum ada tugas atau tindakan perbaikan yang diserahkan kepada Anda."
            />

            <section aria-labelledby="quick-actions" className="mt-6">
                <h2 id="quick-actions" className="mb-2 text-[13px] font-bold uppercase tracking-wide text-graphite-500">Quick Actions</h2>
                {/* Compact on a phone: two per row, the label and its icon
                    within thumb's reach; the explanation joins from `sm` up. */}
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {tiles.map((tile) => {
                        const Icon = ICONS[tile.icon] || ClipboardList;
                        return (
                            <Link
                                key={tile.label}
                                href={tile.href}
                                className="surface flex min-h-[56px] items-center gap-2.5 rounded-xl px-3 py-2.5 transition-colors hover:bg-steel-50/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            >
                                <span className={cn('icon-tile h-9 w-9 shrink-0 rounded-[10px]', TILE_CAPSULE[tile.icon] || 'icon-tile-blue')}>
                                    <Icon className="h-4 w-4" />
                                </span>
                                <span className="min-w-0">
                                    <span className="block text-[13px] font-bold leading-tight text-navy-900">{tile.label}</span>
                                    <span className="hidden text-[11px] leading-snug text-graphite-500 sm:block">{tile.description}</span>
                                </span>
                            </Link>
                        );
                    })}
                </div>

                {/* Honest about a missing capability rather than silently one
                    button short. PTW Access is a permission an administrator
                    grants; it is not something this page can hand out. */}
                {!canCreatePtw && (
                    <p className="mt-3 flex items-start gap-2 text-xs leading-relaxed text-graphite-500">
                        <ShieldAlert className="mt-px h-3.5 w-3.5 shrink-0 text-graphite-400" />
                        <span>
                            Akun Anda belum memiliki <strong className="text-navy-800">PTW Access</strong>, sehingga
                            belum dapat membuat Permit To Work. Hubungi administrator IOMS perusahaan Anda bila akses ini diperlukan.
                        </span>
                    </p>
                )}
            </section>

            <WorkSection
                id="permits"
                title="My Permits"
                items={myPermits}
                total={counts.myPermits ?? 0}
                seeAll={links.permits}
                empty="Tidak ada izin kerja terbuka yang Anda ajukan atau pimpin."
            />
        </AuthenticatedLayout>
    );
}

const SUMMARY_TONE = {
    warning: 'text-amber-700',
    critical: 'text-danger',
    info: 'text-brand-700',
};

const KIND_ICON = {
    approval: Stamp,
    ptw_verify: ShieldCheck,
    final_verification: ShieldCheck,
    ptw_approve: Stamp,
    task: CheckSquare,
    capa: Wrench,
    permit: Flame,
};

const KIND_LABEL = {
    approval: 'Approval',
    ptw_verify: 'PTW Verification',
    final_verification: 'Final Verification',
    ptw_approve: 'PTW Approval',
    task: 'Task',
    capa: 'CAPA',
    permit: 'PTW',
};

const fmtDate = (value) =>
    value ? new Date(value).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : null;
const fmtDateTime = (value) =>
    value ? new Date(value).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : null;

function WorkSection({ id, title, items, total, seeAll, empty }) {
    return (
        <section id={id} aria-labelledby={`${id}-title`} className="mt-6 scroll-mt-20">
            <div className="mb-2 flex items-center justify-between gap-3">
                <h2 id={`${id}-title`} className="text-[13px] font-bold uppercase tracking-wide text-graphite-500">
                    {title}
                    {total > 0 && <span className="ml-1.5 text-graphite-400">({total})</span>}
                </h2>
                {seeAll && total > 0 && (
                    <Link href={seeAll} className="inline-flex min-h-[44px] items-center text-xs font-semibold text-brand-700 hover:underline">
                        View all
                    </Link>
                )}
            </div>

            {items.length === 0 ? (
                <p className="surface rounded-xl px-4 py-3 text-[13px] leading-relaxed text-graphite-500">{empty}</p>
            ) : (
                <ul className="surface divide-y divide-steel-100 overflow-hidden rounded-xl">
                    {items.map((item, index) => (
                        <li key={`${item.kind}-${item.number ?? index}-${index}`}>
                            <WorkItem item={item} />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function WorkItem({ item }) {
    const Icon = KIND_ICON[item.kind] || ClipboardList;
    const when = item.kind === 'task' || item.kind === 'capa' ? fmtDate(item.due) : fmtDateTime(item.due);

    const body = (
        <>
            <span className={cn('icon-tile h-9 w-9 shrink-0 rounded-[10px]', item.overdue ? 'icon-tile-rose' : 'icon-tile-blue')}>
                <Icon className="h-4 w-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-x-2 text-[11px] font-semibold uppercase tracking-wide text-graphite-400">
                    {KIND_LABEL[item.kind] ?? item.kind}
                    {item.number && <span className="type-code normal-case tracking-normal text-graphite-600">{item.number}</span>}
                </span>
                <span className="block truncate text-sm font-semibold text-navy-900">{item.title}</span>
                {item.subtitle && <span className="block truncate text-xs text-graphite-500">{item.subtitle}</span>}
                {when && (
                    <span className={cn('mt-0.5 flex items-center gap-1 text-[11px]', item.overdue ? 'font-semibold text-danger' : 'text-graphite-500')}>
                        <CalendarClock className="h-3 w-3" />
                        {item.overdue ? 'Terlambat, ' : item.kind === 'task' || item.kind === 'capa' ? 'Jatuh tempo ' : 'Mulai '}
                        {when}
                    </span>
                )}
                {!item.href && (
                    <span className="mt-0.5 block text-[11px] text-graphite-400">Tidak dapat dibuka dari akun Anda.</span>
                )}
            </span>
            <span className="flex shrink-0 flex-col items-end gap-1">
                <StatusBadge value={item.status} />
                {item.priority && item.priority !== 'medium' && item.priority !== 'normal' && <StatusBadge value={item.priority} />}
            </span>
            {item.href && <ChevronRight className="h-4 w-4 shrink-0 text-graphite-300" aria-hidden="true" />}
        </>
    );

    const className = 'flex min-h-[60px] items-center gap-3 px-3 py-2.5';

    return item.href ? (
        <Link href={item.href} className={cn(className, 'transition-colors hover:bg-steel-50/70 focus-visible:bg-steel-50 focus-visible:outline-none')}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}
