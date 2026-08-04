import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import EmptyState from '@/Components/shared/EmptyState';
import StatusBadge from '@/Components/shared/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { CheckSquare, ClipboardCheck, HardHat, ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Work Center (v1.8.0). NOT a Department -- the global, cross-cutting
 * surface for work assigned to the current user regardless of which
 * Department actually owns the underlying record (see
 * docs/ADR/007-workspace-navigation.md). Every item here is a pointer
 * into the module that owns it (Material Requests stay Logistics',
 * Tasks stay wherever they were created); this page renders no
 * module-specific UI of its own, only the aggregation.
 */
export default function WorkCenterIndex({ approvals, tasks, alerts }) {
    const hasAnything = approvals.length > 0 || tasks.length > 0 || (alerts?.ppe?.count ?? 0) > 0;

    return (
        <AuthenticatedLayout>
            <Head title="Work Center" />

            <PageHeader
                title="Work Center"
                subtitle="Approvals, tasks, and alerts assigned to you, across every department."
            />

            {!hasAnything && (
                <Card>
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
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
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
                        count={alerts?.ppe?.count ?? 0}
                        emptyLabel="No PPE alerts right now."
                    >
                        {(alerts?.ppe?.count ?? 0) > 0 && (
                            <WorkItem
                                href={alerts.ppe.url}
                                title="PPE expiring soon or expired"
                                meta="Review in PPE Management"
                                badge={<StatusBadge value="expired" label={`${alerts.ppe.count} item(s)`} />}
                            />
                        )}
                    </Section>
                </div>
            )}
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
