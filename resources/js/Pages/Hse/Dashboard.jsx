import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { FolderKanban, AlertTriangle, HardHat, History } from 'lucide-react';

/**
 * HSE Dashboard (v1.10.0). Distinct from PPE's own dashboard
 * (route('ppe.dashboard'), still the detailed PPE-specific page) -- see
 * HseDashboardController's own doc comment for which spec widgets were
 * intentionally left out.
 */
export default function HseDashboard({
    activeProjectsCount, openIncidentsCount, incidentsBySeverity, ppeAlertCount,
    recentIncidents, recentActivity,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="HSE Dashboard" />
            <PageHeader title="HSE Dashboard" subtitle="Operational HSE overview." />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={FolderKanban} value={activeProjectsCount} label="Active Projects" href={route('projects.index')} />
                <StatCard icon={AlertTriangle} value={openIncidentsCount} label="Open Incidents" accent={openIncidentsCount > 0 ? 'red' : null} href={route('incidents.index')} />
                <StatCard icon={HardHat} value={ppeAlertCount} label="PPE Alerts" accent={ppeAlertCount > 0 ? 'amber' : null} href={route('ppe.dashboard')} />
                <StatCard icon={AlertTriangle} value={incidentsBySeverity?.critical ?? 0} label="Critical Incidents" accent={(incidentsBySeverity?.critical ?? 0) > 0 ? 'red' : null} href={route('incidents.index', { severity: 'critical' })} />
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>Recent Incidents</CardTitle></CardHeader>
                    <CardContent>
                        {recentIncidents.length === 0 ? (
                            <EmptyState icon={AlertTriangle} title="No incidents recorded" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {recentIncidents.map((i) => (
                                    <li key={i.id}>
                                        <Link href={route('incidents.show', i.id)} className="flex items-center justify-between gap-2 py-2.5 text-sm hover:text-brand-700">
                                            <span className="min-w-0 flex-1 truncate font-medium text-graphite-700">{i.title}</span>
                                            <StatusBadge value={i.severity} />
                                            <StatusBadge value={i.status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center gap-2 space-y-0"><History className="h-4 w-4 text-graphite-400" /><CardTitle>Recent Activity</CardTitle></CardHeader>
                    <CardContent>
                        {recentActivity.length === 0 ? (
                            <EmptyState icon={History} title="No recent activity" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {recentActivity.map((a) => (
                                    <li key={a.id} className="py-2.5 text-sm">
                                        <p className="text-graphite-700">{a.description}</p>
                                        <p className="text-xs text-graphite-400">{a.user?.name} · {new Date(a.created_at).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</p>
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
