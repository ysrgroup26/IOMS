import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Flame, ClipboardList, ClipboardCheck, Eye, AlertTriangle, CheckSquare, ArrowRight,
} from 'lucide-react';
import { useClock, greetingFor } from '@/lib/useClock';

const ICONS = { Flame, ClipboardList, ClipboardCheck, Eye, AlertTriangle, CheckSquare };

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

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {tiles.map((tile) => {
                    const Icon = ICONS[tile.icon] || ClipboardList;
                    return (
                        <Link key={tile.label} href={tile.href}>
                            <Card className="h-full transition-all duration-200 hover:-translate-y-0.5 hover:shadow-card-hover">
                                {/* p-5 + 48x48 icon -- deliberately larger
                                    than the enterprise ModuleCard's dense
                                    32x32/12px-padding scale used
                                    elsewhere; a field user's primary
                                    action tile needs to be a genuinely
                                    easy touch target on a phone, not
                                    compact information density. */}
                                <CardContent className="flex items-center gap-3 p-5">
                                    <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950/40 dark:text-brand-400">
                                        <Icon className="h-6 w-6" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-base font-semibold text-graphite-900 dark:text-slate-50">{tile.label}</p>
                                        <p className="truncate text-xs text-graphite-500 dark:text-slate-400">{tile.description}</p>
                                    </div>
                                    <ArrowRight className="h-4 w-4 shrink-0 text-graphite-300 dark:text-slate-600" />
                                </CardContent>
                            </Card>
                        </Link>
                    );
                })}
            </div>
        </AuthenticatedLayout>
    );
}
