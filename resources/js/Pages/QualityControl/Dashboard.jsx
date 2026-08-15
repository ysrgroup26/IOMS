import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import StatusBadge from '@/Components/shared/StatusBadge';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { ClipboardCheck, XCircle, FileWarning, AlertTriangle, CheckCircle2 } from 'lucide-react';

const QC_MODULES = [
    { icon: ClipboardCheck, title: 'Inspection Requests', description: 'Quality inspection requests & results.', href: 'inspection-requests.index' },
    { icon: FileWarning, title: 'NCR', description: 'Non-conformance reports.', href: 'ncrs.index' },
];

/**
 * Quality Control Overview (v1.11.3 -- Global Dashboard/Overview UX
 * Rework, Part 4). New page -- this department had no Overview before
 * this pass. KPIs from real InspectionRequest/Ncr data only (Milestone 4,
 * Acceleration Part 3 -- QC Foundation).
 */
export default function QualityControlDashboard({
    openInspectionsCount, completedThisMonthCount, failedInspectionsCount,
    openNcrCount, criticalNcrCount, recentInspections, recentNcrs, departmentCalendar,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Quality Control Overview" />
            <DashboardShell title="Quality Control" subtitle="Inspection requests and non-conformance reports overview.">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard icon={ClipboardCheck} value={openInspectionsCount} label="Open Inspections" href={route('inspection-requests.index')} />
                    <StatCard icon={CheckCircle2} value={completedThisMonthCount} label="Completed This Month" />
                    <StatCard icon={XCircle} value={failedInspectionsCount} label="Failed Inspections" accent={failedInspectionsCount > 0 ? 'red' : null} />
                    <StatCard icon={FileWarning} value={openNcrCount} label="Open NCRs" accent={openNcrCount > 0 ? 'amber' : null} href={route('ncrs.index')} />
                    <StatCard icon={AlertTriangle} value={criticalNcrCount} label="Critical NCRs" accent={criticalNcrCount > 0 ? 'red' : null} href={route('ncrs.index', { severity: 'critical' })} />
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {QC_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader><CardTitle>Recent Inspections</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentInspections}
                                getHref={(i) => route('inspection-requests.show', i.id)}
                                emptyIcon={ClipboardCheck}
                                emptyTitle="No inspections yet"
                                renderItem={(i) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{i.inspection_number}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{i.inspection_date ? new Date(i.inspection_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) : '-'}</span>
                                        <StatusBadge value={i.result || i.status} />
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Recent NCRs</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentNcrs}
                                getHref={(n) => route('ncrs.show', n.id)}
                                emptyIcon={FileWarning}
                                emptyTitle="No NCRs raised yet"
                                renderItem={(n) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{n.ncr_number}</span>
                                        <span className="shrink-0 text-xs capitalize text-graphite-400">{n.severity}</span>
                                        <StatusBadge value={n.status} />
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>
                </div>

                <DepartmentCalendarWidget events={departmentCalendar} title="Quality Control Calendar" description="Next 3 weeks" />
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
