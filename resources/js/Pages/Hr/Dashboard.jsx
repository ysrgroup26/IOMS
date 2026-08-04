import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Users, UserCheck, CalendarDays, ClipboardEdit, UserCog, ClipboardList } from 'lucide-react';

/**
 * HR Dashboard (v1.10.0). Operational HR information only -- see
 * HrDashboardController's own doc comment for exactly which widgets from
 * the spec were intentionally left out (no backing data model yet).
 */
export default function HrDashboard({
    totalEmployees, activeEmployees, employeesOnLeaveToday, pendingLeaveRequests,
    employeesNeedCompletionCount, kpiThisMonth, recentLeaveRequests,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="HR Dashboard" />
            <PageHeader title="HR Dashboard" subtitle="Operational HR overview." />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={Users} value={totalEmployees} label="Total Employees" href={route('employees.index')} />
                <StatCard icon={UserCheck} value={activeEmployees} label="Active Employees" href={route('employees.index', { status: 'active' })} />
                <StatCard icon={CalendarDays} value={employeesOnLeaveToday} label="On Leave Today" href={route('leave-requests.index')} />
                <StatCard icon={ClipboardEdit} value={pendingLeaveRequests} label="Pending Leave Approvals" accent={pendingLeaveRequests > 0 ? 'amber' : null} href={route('leave-requests.index', { status: 'submitted' })} />
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                <StatCard icon={UserCog} value={employeesNeedCompletionCount} label="Profiles Need Completion" accent={employeesNeedCompletionCount > 0 ? 'amber' : null} href={route('employees.index', { profile_status: 'needs_completion' })} />
                <StatCard icon={ClipboardList} value={kpiThisMonth} label="KPI Records This Month" href={route('kpi-records.index')} />
            </div>

            <Card className="mt-4">
                <CardHeader><CardTitle>Recent Leave Requests</CardTitle></CardHeader>
                <CardContent>
                    {recentLeaveRequests.length === 0 ? (
                        <EmptyState icon={CalendarDays} title="No leave requests yet" />
                    ) : (
                        <ul className="divide-y divide-graphite-100">
                            {recentLeaveRequests.map((lr) => (
                                <li key={lr.id}>
                                    <Link href={route('leave-requests.show', lr.id)} className="flex items-center justify-between gap-2 py-2.5 text-sm hover:text-brand-700">
                                        <span className="font-medium text-graphite-700">{lr.employee?.full_name}</span>
                                        <span className="capitalize text-graphite-400">{lr.leave_type}</span>
                                        <span className="text-xs text-graphite-400">
                                            {new Date(lr.start_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}
                                            {' - '}
                                            {new Date(lr.end_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}
                                        </span>
                                        <StatusBadge value={lr.status} label={lr.status === 'submitted' ? 'Waiting Approval' : undefined} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
