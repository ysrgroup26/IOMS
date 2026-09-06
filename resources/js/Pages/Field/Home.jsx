import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ModuleCard from '@/Components/shared/ModuleCard';
import {
    Flame, ClipboardList, ClipboardCheck, Eye, AlertTriangle, CheckSquare, ArrowRight, Lock,
} from 'lucide-react';
import { useClock, greetingFor } from '@/lib/useClock';

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
 * v2.7.0 (Field/Foreman Experience pass, Phase 3A). A task-first landing
 * page for Department Users (see DashboardController::fieldHome()'s own
 * doc comment for why `isDepartmentUser()` is the trigger, and its
 * documented, honest limitation as an MVP proxy for "field user" rather
 * than a dedicated Foreman role). Still the SAME IOMS application --
 * same AuthenticatedLayout shell (topbar, search, notifications,
 * sidebar, logout all still reachable), same auth/tenant/RBAC/backend,
 * per the explicit "no second app" instruction. Only the CONTENT of the
 * one shared `dashboard` route differs for this user, not the
 * navigation chrome around it.
 *
 * Deliberately larger touch targets and less information density than
 * the enterprise `Dashboard/Index.jsx` (which stays completely
 * untouched, unchanged data/layout, for every non-Department-User role)
 * -- a field user should see "what do I need to do" in one glance, not
 * KPI charts or leaderboards.
 */
export default function FieldHome({ tiles, pendingApprovalsCount, myTasksCount }) {
    const { auth } = usePage().props;
    const now = useClock();
    const attentionCount = pendingApprovalsCount + myTasksCount;

    return (
        <AuthenticatedLayout>
            <Head title="Home" />

            <div className="mb-5">
                <h1 className="text-xl font-semibold tracking-tight text-graphite-900 dark:text-slate-50">
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
                        {pendingApprovalsCount > 0 && ` -- ${pendingApprovalsCount} persetujuan`}
                        {pendingApprovalsCount > 0 && myTasksCount > 0 && ','}
                        {myTasksCount > 0 && ` ${myTasksCount} tugas`}
                    </span>
                    <ArrowRight className="h-4 w-4 shrink-0" />
                </Link>
            )}

            {/* v2.49.0: was a hand-rolled Card + inline `bg-brand-50` chip.
                That fork is exactly why this page never inherited the shared
                chip/tint work and still looked flat white with pale icons.
                Now the shared ModuleCard at its `lg` size -- same 48px touch
                target the fork existed to protect, same tiles, same order,
                same links, same gating. The accent is derived from the tile's
                own icon so the surface tint matches the chip: incident red,
                checklist green, observation purple, isolation amber, permits
                and tasks the IOMS blue. */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {tiles.map((tile) => (
                    <ModuleCard
                        key={tile.label}
                        icon={ICONS[tile.icon] || ClipboardList}
                        title={tile.label}
                        description={tile.description}
                        href={tile.href}
                        size="lg"
                        accent={TILE_ACCENTS[tile.icon] || 'brand'}
                    />
                ))}            </div>
        </AuthenticatedLayout>
    );
}
