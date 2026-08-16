import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardShell from '@/Components/shared/DashboardShell';
import StatCard from '@/Components/shared/StatCard';
import ModuleCard from '@/Components/shared/ModuleCard';
import ActivityList from '@/Components/shared/ActivityList';
import DepartmentCalendarWidget from '@/Components/shared/DepartmentCalendarWidget';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Boxes, AlertTriangle, XCircle, PackageCheck, ArrowRightLeft, Warehouse, Box, ClipboardList } from 'lucide-react';

const HEALTH_BADGES = {
    out_of_stock: { label: 'Out of Stock', className: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' },
    critical: { label: 'Critical', className: 'bg-red-50 text-red-600 dark:bg-red-950/50 dark:text-red-400' },
    low: { label: 'Low', className: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' },
    healthy: { label: 'Healthy', className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' },
};

function HealthBadge({ status }) {
    const cfg = HEALTH_BADGES[status] || HEALTH_BADGES.healthy;
    return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${cfg.className}`}>{cfg.label}</span>;
}

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
    goodsReceiptsThisMonth, movementsThisMonth, recentReceiving, recentIssuing, lowStockItems, inventoryHealth, departmentCalendar,
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

                {/* Inventory Health -- compact table, Phase 6 */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm">Inventory Health</CardTitle>
                        <CardDescription>Lowest stock relative to minimum, first</CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {(!inventoryHealth || inventoryHealth.length === 0) ? (
                            <p className="py-6 text-center text-sm text-graphite-400">No stock records yet.</p>
                        ) : (
                            <table className="w-full min-w-[640px] text-sm">
                                <thead>
                                    <tr className="border-b border-graphite-100 text-left text-xs text-graphite-400 dark:border-slate-800">
                                        <th className="py-1.5 font-medium">Item</th>
                                        <th className="py-1.5 font-medium">Category</th>
                                        <th className="py-1.5 font-medium">Location</th>
                                        <th className="py-1.5 font-medium">Stock</th>
                                        <th className="py-1.5 font-medium">Min. Reorder</th>
                                        <th className="py-1.5 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-graphite-50 dark:divide-slate-800/60">
                                    {inventoryHealth.map((row) => (
                                        <tr key={row.id} className="hover:bg-graphite-25 dark:hover:bg-slate-900/40">
                                            <td className="py-2 pr-2">
                                                <p className="font-medium text-graphite-700 dark:text-slate-200">{row.item_name}</p>
                                                <p className="text-xs text-graphite-400">{row.item_code}</p>
                                            </td>
                                            <td className="py-2 pr-2 capitalize text-graphite-500">{row.category ?? '—'}</td>
                                            <td className="py-2 pr-2 text-graphite-500">{row.location ?? '—'}</td>
                                            <td className="py-2 pr-2 tabular-nums text-graphite-700 dark:text-slate-200">{row.quantity} {row.unit}</td>
                                            <td className="py-2 pr-2 tabular-nums text-graphite-500">{row.min_stock ?? '—'}</td>
                                            <td className="py-2"><HealthBadge status={row.status} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        <Link href={route('stock.index')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">View full inventory</Link>
                    </CardContent>
                </Card>

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
