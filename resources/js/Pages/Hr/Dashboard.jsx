import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import StatusBadge from '@/Components/shared/StatusBadge';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Users, UserCheck, CalendarDays, ClipboardEdit, UserCog, ClipboardList, GraduationCap, CalendarClock, ClipboardSignature, AlertTriangle } from 'lucide-react';

const HR_MODULES = [
    { icon: Users, title: 'Employees', description: 'Employee master records.', href: 'employees.index' },
    { icon: CalendarDays, title: 'Leave', description: 'Leave requests & approvals.', href: 'leave-requests.index' },
    { icon: ClipboardList, title: 'KPI Records', description: 'Performance indicator tracking.', href: 'kpi-records.index' },
    { icon: CalendarClock, title: 'Shift Master', description: 'Shift patterns & assignment.', href: 'shifts.master' },
    { icon: ClipboardSignature, title: 'Roster', description: 'Shift roster overview.', href: 'rosters.overview' },
    { icon: GraduationCap, title: 'Competency', description: 'Certifications & expiry tracking.', href: 'competency.master' },
];

/**
 * HR Dashboard (v1.10.0, redesigned v1.11.5 -- Dashboard UX Completion,
 * Phase 3). Restructured into: compact KPI strip -> Workforce Status ->
 * Attention Required (real contract/certification expiry rows, not just
 * counts) -> Recent Leave Activity -> Calendar. See
 * HrDashboardController's own doc comment for the two real data sources
 * added this pass (contract_end_date, EmployeeCompetency.expiry_date) --
 * both already existed in the schema, this only queries them from here.
 */
export default function HrDashboard({
    totalEmployees, activeEmployees, onShiftToday, employeesOnLeaveToday, pendingLeaveRequests,
    employeesNeedCompletionCount, kpiThisMonth, contractExpiringCount, certificationExpiringCount,
    attentionRequired, recentLeaveRequests,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="HR Dashboard" />
            <DashboardShell title="HR Overview" subtitle="Operational HR overview.">
                {/* LEVEL 1 -- compact KPI strip */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
                    <StatCard icon={Users} value={activeEmployees} label="Active Employees" href={route('employees.index', { status: 'active' })} />
                    <StatCard icon={UserCheck} value={onShiftToday} label="On Shift Today" href={route('rosters.overview')} />
                    <StatCard icon={CalendarDays} value={employeesOnLeaveToday} label="On Leave Today" href={route('leave-requests.index')} />
                    <StatCard icon={ClipboardEdit} value={pendingLeaveRequests} label="Pending Leave Approvals" accent={pendingLeaveRequests > 0 ? 'amber' : null} href={route('leave-requests.index', { status: 'submitted' })} />
                    <StatCard icon={AlertTriangle} value={contractExpiringCount} label="Contracts Expiring (30d)" accent={contractExpiringCount > 0 ? 'amber' : null} />
                    <StatCard icon={GraduationCap} value={certificationExpiringCount} label="Certifications Expiring" accent={certificationExpiringCount > 0 ? 'amber' : null} href={route('competency.expiring-soon')} />
                </div>

                {/* LEVEL 2/3 -- workforce status + attention required */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Workforce Status</CardTitle>
                            <CardDescription>Company-wide, all companies</CardDescription>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">Total</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{totalEmployees}</p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">Active</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{activeEmployees}</p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">On Shift Today</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{onShiftToday}</p>
                            </div>
                            <div className="rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <p className="text-xs text-graphite-400">Profiles Incomplete</p>
                                <p className="text-base font-bold text-graphite-900 dark:text-slate-50">{employeesNeedCompletionCount}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Attention Required</CardTitle>
                            <CardDescription>Contract & certification expiry, soonest first</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ActivityList
                                items={attentionRequired}
                                getKey={(a, i) => i}
                                getHref={(a) => a.href}
                                emptyIcon={GraduationCap}
                                emptyTitle="Nothing expiring in the next 30 days"
                                renderItem={(a) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium text-graphite-700 dark:text-slate-200">{a.label}</p>
                                            <p className="text-xs text-amber-600">{a.type}</p>
                                        </div>
                                        <span className="shrink-0 text-xs text-graphite-400">{new Date(a.date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>
                </div>

                {/* LEVEL 4 -- module shortcuts */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {HR_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                {/* LEVEL 3 -- recent activity + calendar */}
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm">Recent Leave Requests</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentLeaveRequests}
                                getHref={(lr) => route('leave-requests.show', lr.id)}
                                emptyIcon={CalendarDays}
                                emptyTitle="No leave requests yet"
                                renderItem={(lr) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{lr.employee?.full_name}</span>
                                        <span className="shrink-0 capitalize text-graphite-400">{lr.leave_type}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">
                                            {new Date(lr.start_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}
                                            {' - '}
                                            {new Date(lr.end_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}
                                        </span>
                                        <StatusBadge value={lr.status} label={lr.status === 'submitted' ? 'Waiting Approval' : undefined} />
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <DepartmentCalendarWidget events={[]} title="HR Calendar" description="Leave & company events, next 3 weeks" />
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
