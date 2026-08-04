import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { FolderKanban, AlertTriangle, Flag, ClipboardList } from 'lucide-react';

/** Project Management Dashboard (v1.10.0). See ProjectManagementDashboardController's doc comment for what was intentionally left out (no Calendar model). */
export default function ProjectManagementDashboard({
    activeProjectsCount, delayedProjectsCount, milestoneCompletionPercent, todaysActivitiesCount,
    upcomingMilestones, delayedProjects,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Project Management Dashboard" />
            <PageHeader title="Project Management Dashboard" subtitle="Operational project overview." />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={FolderKanban} value={activeProjectsCount} label="Active Projects" href={route('projects.index')} />
                <StatCard icon={AlertTriangle} value={delayedProjectsCount} label="Delayed Projects" accent={delayedProjectsCount > 0 ? 'red' : null} href={route('projects.index')} />
                <StatCard icon={Flag} value={milestoneCompletionPercent === null ? '—' : `${milestoneCompletionPercent}%`} label="Milestone Completion" href={route('milestones.index')} />
                <StatCard icon={ClipboardList} value={todaysActivitiesCount} label="Today's Activities" href={route('daily-reports.index')} />
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
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
            </div>
        </AuthenticatedLayout>
    );
}
