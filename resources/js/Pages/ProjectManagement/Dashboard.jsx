import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import ModuleCard from '@/Components/shared/ModuleCard';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { FolderKanban, AlertTriangle, Flag, ClipboardList, ListTodo, CheckSquare, FileWarning, ClipboardCheck } from 'lucide-react';

const PM_MODULES = [
    { icon: FolderKanban, title: 'Projects', description: 'Project register & timeline.', href: 'projects.index' },
    { icon: Flag, title: 'Milestones', description: 'Milestone tracking across projects.', href: 'milestones.index' },
    { icon: ClipboardList, title: 'Daily Reports', description: 'Daily activity progress logs.', href: 'daily-reports.index' },
    { icon: CheckSquare, title: 'Tasks', description: 'Task assignment & tracking.', href: 'tasks.index' },
    { icon: ClipboardCheck, title: 'Inspection Requests', description: 'Quality inspection requests.', href: 'inspection-requests.index' },
    { icon: FileWarning, title: 'NCR', description: 'Non-conformance reports.', href: 'ncrs.index' },
];

/**
 * Project Management Dashboard (v1.10.0, ModuleCard grid added v1.11.2 --
 * Final Completion Pass Part 1). Milestone 4, Acceleration Part 3/7: average
 * Activity Progress now has a real backing data model (ProjectActivity) --
 * see ProjectManagementDashboardController's own doc comment for what's
 * still intentionally left out (no Calendar model -- department calendar
 * widget added separately, see CalendarController).
 */
export default function ProjectManagementDashboard({
    activeProjectsCount, delayedProjectsCount, milestoneCompletionPercent, avgActivityProgressPercent, todaysActivitiesCount,
    upcomingMilestones, delayedProjects, departmentCalendar,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Project Management Dashboard" />
            <PageHeader title="Project Management Dashboard" subtitle="Operational project overview." />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                <StatCard icon={FolderKanban} value={activeProjectsCount} label="Active Projects" href={route('projects.index')} />
                <StatCard icon={AlertTriangle} value={delayedProjectsCount} label="Delayed Projects" accent={delayedProjectsCount > 0 ? 'red' : null} href={route('projects.index')} />
                <StatCard icon={Flag} value={milestoneCompletionPercent === null ? '—' : `${milestoneCompletionPercent}%`} label="Milestone Completion" href={route('milestones.index')} />
                <StatCard icon={ListTodo} value={avgActivityProgressPercent === null ? '—' : `${avgActivityProgressPercent}%`} label="Avg. Activity Progress" />
                <StatCard icon={ClipboardList} value={todaysActivitiesCount} label="Today's Activities" href={route('daily-reports.index')} />
            </div>

            <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {PM_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                <Card>
                    <CardHeader><CardTitle>Upcoming Milestones</CardTitle></CardHeader>
                    <CardContent>
                        {upcomingMilestones.length === 0 ? (
                            <EmptyState icon={Flag} title="No upcoming milestones" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {upcomingMilestones.map((m) => (
                                    <li key={m.id} className="flex items-center justify-between gap-2 py-2.5 text-sm">
                                        <div className="min-w-0">
                                            <p className="truncate font-medium text-graphite-700">{m.title}</p>
                                            <p className="text-xs text-graphite-400">{m.project?.name}</p>
                                        </div>
                                        <span className="shrink-0 text-xs text-graphite-400">{new Date(m.target_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                        <StatusBadge value={m.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Link href={route('milestones.index')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">View all</Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Delayed Projects</CardTitle></CardHeader>
                    <CardContent>
                        {delayedProjects.length === 0 ? (
                            <EmptyState icon={FolderKanban} title="No delayed projects" description="Nothing is past its end date." />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {delayedProjects.map((p) => (
                                    <li key={p.id}>
                                        <Link href={route('projects.show', p.id)} className="flex items-center justify-between gap-2 py-2.5 text-sm hover:text-brand-700">
                                            <span className="font-medium text-graphite-700">{p.name}</span>
                                            <span className="text-xs text-red-600">Ended {new Date(p.end_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <DepartmentCalendarWidget events={departmentCalendar} title="Project Calendar" description="Milestones & deadlines, next 3 weeks" />
            </div>
        </AuthenticatedLayout>
    );
}
