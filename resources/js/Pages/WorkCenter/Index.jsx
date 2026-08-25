import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import EmptyState from '@/Components/shared/EmptyState';
import StatusBadge from '@/Components/shared/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import {
    CheckSquare, ClipboardCheck, HardHat, ArrowRight, Bell, History,
    PackagePlus, UserPlus, Plus,
    AlertTriangle, Eye, Flame, FileWarning, ShieldAlert, FlaskConical,
    Clock, CalendarDays, GraduationCap, FolderKanban, ClipboardList, Flag,
    FileStack, ShoppingCart, PackageCheck, ArrowRightLeft,
} from 'lucide-react';
import { cn } from '@/lib/utils';

// v2.2.0 (IOMS OS Ecosystem pass, Part 5): extended to match every icon
// key WorkCenterController::quickActionsFor() can now emit (HSE/HRD/
// Project/Logistics/Warehouse actions, not only the original 4).
const QUICK_ACTION_ICONS = {
    PackagePlus, UserPlus, HardHat, CheckSquare,
    AlertTriangle, Eye, ClipboardCheck, Flame, FileWarning, ShieldAlert, FlaskConical,
    Clock, CalendarDays, GraduationCap, FolderKanban, ClipboardList, Flag,
    FileStack, ShoppingCart, PackageCheck, ArrowRightLeft,
};

/**
 * Work Center / "My Workspace" (v1.8.0, extended Milestone 3 Task #63).
 * NOT a Department -- the global, cross-cutting personal dashboard for
 * work assigned to the current user regardless of which Department
 * actually owns the underlying record (see
 * docs/ADR/007-workspace-navigation.md). Every item here is a pointer
 * into the module that owns it (Material Requests stay Logistics',
 * Tasks stay wherever they were created); this page renders no
 * module-specific UI of its own, only the aggregation.
 *
 * Task #63 added the Notifications and Recent Activity widgets plus
 * Quick Actions, completing this as the "My Workspace" tier of the
 * Enterprise Dashboard epic -- all real data from the Notification
 * Center / Activity Center / module registry, not placeholders.
 */
export default function WorkCenterIndex({ approvals, tasks, alerts, actionAlerts, notifications, recentActivity, quickActions }) {
    const alertCount = (alerts?.ppe?.count ?? 0) + (actionAlerts?.length ?? 0);
    const hasAnything = approvals.length > 0 || tasks.length > 0 || alertCount > 0;

    return (
        <AuthenticatedLayout>
            <Head title="My Workspace" />

            <PageHeader
                title="My Workspace"
                subtitle="Approvals, tasks, alerts, notifications, and recent activity assigned to you, across every department."
            />

            {quickActions?.length > 0 && (
                <div className="mb-5 flex flex-wrap gap-2">
                    {quickActions.map((action) => {
                        const Icon = QUICK_ACTION_ICONS[action.icon] || Plus;
                        return (
                            <Button key={action.url} asChild variant="outline" size="sm" className="gap-1.5">
                                <Link href={action.url}>
                                    <Icon className="h-3.5 w-3.5" />
                                    {action.label}
                                </Link>
                            </Button>
                        );
                    })}
                </div>
            )}

            {!hasAnything && (
                <Card className="mb-5">
                    <CardContent>
                        <EmptyState
                            icon={CheckSquare}
                            title="You're all caught up"
                            description="Nothing needs your attention right now."
                        />
                    </CardContent>
                </Card>
            )}

            {hasAnything && (
                <div className="mb-5 grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <Section
                        title="Approvals"
                        icon={ClipboardCheck}
                        count={approvals.length}
                        emptyLabel="No approvals waiting on you."
                    >
                        {approvals.map((approval) => (
                            <WorkItem
                                key={`approval-${approval.id}`}
                                href={approval.url}
                                title={approval.label}
                                meta={approval.requester ? `Requested by ${approval.requester} · ${approval.created_at}` : approval.created_at}
                                badge={<StatusBadge value="pending_approval" />}
                            />
                        ))}
                    </Section>

                    <Section
                        title="Tasks"
                        icon={CheckSquare}
                        count={tasks.length}
                        emptyLabel="No open tasks assigned to you."
                    >
                        {tasks.map((task) => (
                            <WorkItem
                                key={`task-${task.id}`}
                                href={task.url}
                                title={task.title}
                                meta={[task.task_number, task.company, task.due_date ? `Due ${task.due_date}` : null].filter(Boolean).join(' · ')}
                                badge={
                                    <div className="flex items-center gap-1.5">
                                        {task.is_overdue && <StatusBadge value="rejected" label="Overdue" />}
                                        <StatusBadge value={task.priority} />
                                    </div>
                                }
                            />
                        ))}
                    </Section>

                    <Section
                        title="Alerts"
                        icon={HardHat}
                        count={alertCount}
                        emptyLabel="Tidak ada peringatan saat ini."
                    >
                        {(alerts?.ppe?.count ?? 0) > 0 && (
                            <WorkItem
                                href={alerts.ppe.url}
                                title="PPE expiring soon or expired"
                                meta="Review in PPE Management"
                                badge={<StatusBadge value="expired" label={`${alerts.ppe.count} item(s)`} />}
                            />
                        )}
                        {/* v2.2.0 (IOMS OS Ecosystem pass, Part 6): real
                            cross-department alerts (CAPA due, PTW pending,
                            stock alert, maintenance due, PR/PO pending) --
                            see WorkCenterService::actionAlertsFor(). */}
                        {(actionAlerts ?? []).map((alert) => (
                            <WorkItem
                                key={`alert-${alert.key}`}
                                href={alert.url}
                                title={alert.label}
                                badge={<StatusBadge value="expiring_soon" label={`${alert.count}`} />}
                            />
                        ))}
                    </Section>
                </div>
            )}

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                        <CardTitle className="flex items-center gap-2 text-sm font-semibold text-graphite-700 dark:text-slate-200">
                            <Bell className="h-4 w-4 text-graphite-400" />
                            Notifications
                        </CardTitle>
                        <div className="flex items-center gap-2">
                            {notifications?.unread_count > 0 && (
                                <span className="rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-950/50 dark:text-brand-300">
                                    {notifications.unread_count} unread
                                </span>
                            )}
                            <Link href={route('notifications.read-all')} method="put" as="button" className="text-xs text-graphite-400 hover:text-graphite-600 dark:hover:text-slate-300">
                                Mark all read
                            </Link>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-1">
                        {(!notifications?.recent || notifications.recent.length === 0) ? (
                            <p className="py-4 text-center text-xs text-graphite-400 dark:text-slate-500">No notifications yet.</p>
                        ) : (
                            notifications.recent.map((n) => (
                                <WorkItem
                                    key={`notification-${n.id}`}
                                    href={n.url}
                                    title={n.title}
                                    meta={n.created_at}
                                    badge={!n.is_read ? <span className="h-2 w-2 rounded-full bg-brand-500" /> : null}
                                />
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-sm font-semibold text-graphite-700 dark:text-slate-200">
                            <History className="h-4 w-4 text-graphite-400" />
                            Recent Activity
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-1">
                        {(!recentActivity || recentActivity.length === 0) ? (
                            <p className="py-4 text-center text-xs text-graphite-400 dark:text-slate-500">No activity recorded yet.</p>
                        ) : (
                            recentActivity.map((log) => (
                                <div key={`activity-${log.id}`} className="rounded-lg border border-graphite-100 px-3 py-2.5 dark:border-slate-800">
                                    <p className="truncate text-sm font-medium text-graphite-800 dark:text-slate-100">{log.description}</p>
                                    <p className="mt-0.5 text-xs text-graphite-400 dark:text-slate-500">
                                        {[log.module, log.created_at].filter(Boolean).join(' · ')}
                                    </p>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function Section({ title, icon: Icon, count, emptyLabel, children }) {
    const isEmpty = count === 0;

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                <CardTitle className="flex items-center gap-2 text-sm font-semibold text-graphite-700 dark:text-slate-200">
                    <Icon className="h-4 w-4 text-graphite-400" />
                    {title}
                </CardTitle>
                {!isEmpty && (
                    <span className="rounded-full bg-graphite-100 px-2 py-0.5 text-xs font-medium text-graphite-500 dark:bg-slate-800 dark:text-slate-400">
                        {count}
                    </span>
                )}
            </CardHeader>
            <CardContent className="space-y-1">
                {isEmpty ? (
                    <p className="py-4 text-center text-xs text-graphite-400 dark:text-slate-500">{emptyLabel}</p>
                ) : (
                    children
                )}
            </CardContent>
        </Card>
    );
}

function WorkItem({ href, title, meta, badge }) {
    const content = (
        <div className={cn(
            'flex items-start justify-between gap-3 rounded-lg border border-graphite-100 px-3 py-2.5 transition-colors dark:border-slate-800',
            href && 'hover:border-brand-200 hover:bg-brand-50/40 dark:hover:bg-slate-900'
        )}>
            <div className="min-w-0">
                <p className="truncate text-sm font-medium text-graphite-800 dark:text-slate-100">{title}</p>
                {meta && <p className="mt-0.5 truncate text-xs text-graphite-400 dark:text-slate-500">{meta}</p>}
            </div>
            <div className="flex shrink-0 items-center gap-2">
                {badge}
                {href && <ArrowRight className="h-3.5 w-3.5 text-graphite-300" />}
            </div>
        </div>
    );

    return href ? <Link href={href}>{content}</Link> : content;
}
