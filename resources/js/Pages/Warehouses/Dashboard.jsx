import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Boxes, AlertTriangle, XCircle, PackageCheck, ArrowRightLeft, Warehouse, Box, ClipboardList } from 'lucide-react';

const WAREHOUSE_MODULES = [
    { icon: Warehouse, title: 'Warehouse Master', description: 'Warehouses & storage locations.', href: 'warehouses.master' },
    { icon: Box, title: 'Item Master', description: 'Item catalog & stock levels.', href: 'items.index' },
    { icon: Boxes, title: 'Inventory', description: 'Current stock by warehouse.', href: 'stock.index' },
    { icon: PackageCheck, title: 'Goods Receipt', description: 'Incoming stock records.', href: 'goods-receipts.index' },
    { icon: ArrowRightLeft, title: 'Issue / Transfer / Adjust', description: 'Outgoing & internal stock moves.', href: 'stock.transactions.create' },
    { icon: ClipboardList, title: 'Movement History', description: 'Full stock movement log.', href: 'stock.movements' },
];

/**
 * Warehouse Overview (v1.11.3.2 -- Priority Pass Part 9). Warehouse was
 * confirmed to have real, substantial backend already (Stock/
 * StockMovement/GoodsReceipt/Item, all reused here -- none duplicated)
 * but no Overview of its own; `warehouses.master` is the warehouse
 * REGISTER/config page, not a dashboard. This is that Overview, built on
 * the shared component set from day one, matching the other priority
 * department dashboards.
 */
export default function WarehouseDashboard({
    totalItemsCount, totalWarehousesCount, lowStockCount, outOfStockCount,
    goodsReceiptsThisMonth, movementsThisMonth, recentReceiving, recentIssuing, lowStockItems, departmentCalendar,
}) {
    return (
        <AuthenticatedLayout>
            <Head title="Warehouse Overview" />
            <DashboardShell title="Warehouse" subtitle="Stock health, receiving, and issuing overview.">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard icon={Box} value={totalItemsCount} label="Active Items" href={route('items.index')} />
                    <StatCard icon={Warehouse} value={totalWarehousesCount} label="Warehouses" href={route('warehouses.master')} />
                    <StatCard icon={AlertTriangle} value={lowStockCount} label="Low Stock" accent={lowStockCount > 0 ? 'amber' : null} href={route('stock.index', { low_stock: 1 })} />
                    <StatCard icon={XCircle} value={outOfStockCount} label="Out of Stock" accent={outOfStockCount > 0 ? 'red' : null} href={route('stock.index')} />
                    <StatCard icon={PackageCheck} value={goodsReceiptsThisMonth} label="Received This Month" href={route('goods-receipts.index')} />
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {WAREHOUSE_MODULES.map((m) => <ModuleCard key={m.title} {...m} />)}
                </div>

                <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <Card>
                        <CardHeader><CardTitle>Low Stock Items</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={lowStockItems}
                                emptyIcon={Boxes}
                                emptyTitle="Nothing below minimum stock"
                                renderItem={(s) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{s.item?.item_code} -- {s.item?.name}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{s.warehouse?.name}</span>
                                        <span className="shrink-0 text-xs font-semibold text-amber-600">{s.quantity}/{s.item?.min_stock} {s.item?.unit}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Recent Receiving</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentReceiving}
                                getHref={(g) => route('goods-receipts.show', g.id)}
                                emptyIcon={PackageCheck}
                                emptyTitle="No goods receipts yet"
                                renderItem={(g) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{g.receipt_number}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{g.warehouse?.name}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{new Date(g.received_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Recent Issuing / Transfers</CardTitle></CardHeader>
                        <CardContent>
                            <ActivityList
                                items={recentIssuing}
                                emptyIcon={ArrowRightLeft}
                                emptyTitle="No issuing or transfers yet"
                                renderItem={(m) => (
                                    <div className="flex items-center justify-between gap-2 py-2 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700 dark:text-slate-200">{m.item?.name}</span>
                                        <span className="shrink-0 text-xs capitalize text-graphite-400">{m.type.replace('_', ' ')}</span>
                                        <span className="shrink-0 text-xs text-graphite-400">{m.quantity}</span>
                                    </div>
                                )}
                            />
                        </CardContent>
                    </Card>
                </div>

                <DepartmentCalendarWidget events={departmentCalendar} title="Warehouse Calendar" description="Next 3 weeks" />
            </DashboardShell>
        </AuthenticatedLayout>
    );
}
