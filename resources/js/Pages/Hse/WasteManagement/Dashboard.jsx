import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Recycle, AlertTriangle, Truck, CheckCircle2, Warehouse as WarehouseIcon, ClipboardList, Settings, ArrowLeft, Boxes } from 'lucide-react';

const WASTE_MODULES = [
    { icon: ClipboardList, title: 'Waste Records', description: 'Full generation-to-disposal register.', href: 'waste-records.index' },
    // v2.3.0 (HSE Operations + IOMS OS Ecosystem pass, Part 7/11) --
    // Waste Container Inventory (physical drums/IBC tanks/jumbo bags --
    // NOT the waste material itself, see Waste Records above for that).
    { icon: Boxes, title: 'Waste Inventory', description: 'Container/equipment stock: total, available, in use, damaged.', href: 'waste-containers.index' },
    { icon: Settings, title: 'Waste Master Data', description: 'Waste types & storage/TPS locations.', href: 'waste.master' },
];

/**
 * v1.11.4 (HSE Waste Management, Part 18). Every KPI here is a real,
 * tenant-scoped count -- see WasteDashboardController's own doc comment.
 * Storage alerts reuse the tenant-configured operational threshold, never
 * a hardcoded legal value.
 */
export default function WasteDashboard({
    totalRecordsCount, b3StoredCount, nonB3StoredCount, awaitingPickupCount, inTransitCount,
    disposedCount, approachingLimitCount, overdueStorageCount, storedRecords, recentMovements,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Waste Management" />

            <Link href={route('hse.dashboard')} className="mb-3 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to HSE Overview
            </Link>

            <DashboardShell title="Waste Management" subtitle="Waste generation, storage, and disposal overview.">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatCard icon={Recycle} value={totalRecordsCount} label="Total Records" href={route('waste-records.index')} />
                    <StatCard icon={AlertTriangle} value={b3StoredCount} label="B3 Currently Stored" accent={b3StoredCount > 0 ? 'amber' : null} href={route('waste-records.index', { status: 'stored' })} />
                    <StatCard icon={WarehouseIcon} value={nonB3StoredCount} label="Non-B3 Currently Stored" />
                    <StatCard icon={Truck} value={awaitingPickupCount + inTransitCount} label="Awaiting Pickup / In Transit" />
                    <StatCard icon={CheckCircle2} value={disposedCount} label="Disposed / Closed" />
                    <StatCard icon={AlertTriangle} value={approachingLimitCount} label="Approaching Storage Limit" accent={approachingLimitCount > 0 ? 'amber' : null} />
                    <StatCard icon={AlertTriangle} value={overdueStorageCount} label="Storage Threshold Exceeded" accent={overdueStorageCount > 0 ? 'red' : null} />
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {WASTE_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <Card>
                        <CardHeader><CardTitle>Currently Stored (oldest first)</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={storedRecords}
                                getHref={(r) => route('waste-records.show', r.id)}
                                emptyIcon={Recycle}
                                emptyTitle="Nothing currently in storage"
                                renderItem={(r) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{r.record_number} -- {r.waste_type?.name}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{r.storage_location?.name || '-'}</span>
                                        {r.is_storage_overdue && <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-red-500" />}
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Recent Movements</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentMovements}
                                getHref={(m) => route('waste-records.show', m.waste_record_id)}
                                emptyIcon={Truck}
                                emptyTitle="No movements recorded yet"
                                renderItem={(m) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{m.waste_record?.record_number}</span>
                                        <span className="shrink-0 text-xs capitalize text-graphite-400">{m.status.replace('_', ' ')}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{m.vendor?.name || '-'}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>
                </div>
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
