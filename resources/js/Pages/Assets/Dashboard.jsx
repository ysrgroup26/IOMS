import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import StatusBadge from '@/Components/shared/StatusBadge';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Box, CheckCircle2, Wrench, Archive, ClipboardList } from 'lucide-react';

const ASSET_MODULES = [
    { icon: Box, title: 'Assets', description: 'Company asset register.', href: 'assets.index' },
    { icon: ClipboardList, title: 'Maintenance Requests', description: 'Reported issues awaiting review.', href: 'maintenance-requests.index' },
    { icon: Wrench, title: 'Work Orders', description: 'Scheduled & in-progress repairs.', href: 'work-orders.index' },
];

/**
 * Asset Management Overview (v1.11.3 -- Global Dashboard/Overview UX
 * Rework, Part 4). New page -- this department had no Overview at all
 * before this pass. Built directly on the shared component set
 * (DashboardShell/StatCard/ModuleCard/ActivityList/DepartmentCalendarWidget)
 * from day one -- no legacy local-component pattern to inherit, unlike the
 * older department dashboards that predate these components.
 */
export default function AssetDashboard({
    totalAssetsCount, activeAssetsCount, underMaintenanceCount, retiredCount,
    openMaintenanceRequestsCount, openWorkOrdersCount, assetsByCategory, recentAssets, departmentCalendar,
}) {
    const categoryEntries = Object.entries(assetsByCategory || {});

    return (
        <AuthenticatedLayout>
            <Head title="Asset Management Overview" />
            <DashboardShell title="Asset Management" subtitle="Company asset register, maintenance requests, and work orders.">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard icon={Box} value={totalAssetsCount} label="Total Assets" href={route('assets.index')} />
                    <StatCard icon={CheckCircle2} value={activeAssetsCount} label="Active" href={route('assets.index', { status: 'active' })} />
                    <StatCard icon={Wrench} value={underMaintenanceCount} label="Under Maintenance" accent={underMaintenanceCount > 0 ? 'amber' : null} href={route('assets.index', { status: 'under_maintenance' })} />
                    <StatCard icon={Archive} value={retiredCount} label="Retired / Disposed" />
                    <StatCard icon={ClipboardList} value={openMaintenanceRequestsCount} label="Open Requests" accent={openMaintenanceRequestsCount > 0 ? 'amber' : null} href={route('maintenance-requests.index')} />
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {ASSET_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader><CardTitle>Assets by Category</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={categoryEntries}
                                getKey={([name]) => name}
                                emptyIcon={Box}
                                emptyTitle="No assets registered yet"
                                renderItem={([name, count]) => (
                                    <div className="flex items-center justify-between py-2 text-sm">
                                        <span className="text-graphite-700 dark:text-slate-200">{name}</span>
                                        <span className="font-semibold text-graphite-800 dark:text-slate-100">{count}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Recently Added Assets</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentAssets}
                                getHref={(a) => route('assets.show', a.id)}
                                emptyIcon={Box}
                                emptyTitle="No assets yet"
                                renderItem={(a) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{a.asset_code} -- {a.name}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{a.location || '-'}</span>
                                        <StatusBadge value={a.status} />
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>
                </div>

                <DepartmentCalendarWidget events={departmentCalendar} title="Asset Management Calendar" description="Next 3 weeks" />
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
