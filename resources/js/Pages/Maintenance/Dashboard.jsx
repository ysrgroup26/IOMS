import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import StatusBadge from '@/Components/shared/StatusBadge';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Wrench, AlertTriangle, CheckCircle2, ClipboardList, AlertCircle } from 'lucide-react';

const MAINTENANCE_MODULES = [
    { icon: ClipboardList, title: 'Maintenance Requests', description: 'Reported issues awaiting review.', href: 'maintenance-requests.index' },
    { icon: Wrench, title: 'Work Orders', description: 'Scheduled & in-progress repairs.', href: 'work-orders.index' },
];

/**
 * Maintenance Overview (v1.11.3 -- Global Dashboard/Overview UX Rework,
 * Part 4). New page -- this department had no Overview before this pass.
 * Its Department Calendar is genuine reuse: CalendarService already
 * stamps WorkOrder virtual events with department_key='maintenance', so
 * this widget shows real planned-maintenance dates with zero new
 * Calendar Engine code.
 */
export default function MaintenanceDashboard({
    openWorkOrdersCount, overdueWorkOrdersCount, completedThisMonthCount,
    pendingRequestsCount, urgentRequestsCount, recentWorkOrders, departmentCalendar,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Maintenance Overview" />
            <DashboardShell title="Maintenance" subtitle="Work orders and maintenance requests overview.">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard icon={Wrench} value={openWorkOrdersCount} label="Open Work Orders" href={route('work-orders.index')} />
                    <StatCard icon={AlertTriangle} value={overdueWorkOrdersCount} label="Overdue" accent={overdueWorkOrdersCount > 0 ? 'red' : null} href={route('work-orders.index')} />
                    <StatCard icon={CheckCircle2} value={completedThisMonthCount} label="Completed This Month" />
                    <StatCard icon={ClipboardList} value={pendingRequestsCount} label="Pending Requests" accent={pendingRequestsCount > 0 ? 'amber' : null} href={route('maintenance-requests.index')} />
                    <StatCard icon={AlertCircle} value={urgentRequestsCount} label="Urgent Requests" accent={urgentRequestsCount > 0 ? 'red' : null} href={route('maintenance-requests.index')} />
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {MAINTENANCE_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader><CardTitle>Recent Work Orders</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentWorkOrders}
                                getHref={(w) => route('work-orders.show', w.id)}
                                emptyIcon={Wrench}
                                emptyTitle="No work orders yet"
                                renderItem={(w) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{w.wo_number} -- {w.asset?.name}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{w.planned_date ? new Date(w.planned_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) : '-'}</span>
                                        <StatusBadge value={w.status} />
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <DepartmentCalendarWidget events={departmentCalendar} title="Maintenance Calendar" description="Next 3 weeks" />
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
