import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatCard from '@/Components/shared/StatCard';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { PackageSearch, ClipboardCheck, PackageCheck, Boxes, ArrowRightLeft } from 'lucide-react';

/**
 * Logistics Dashboard (v1.10.0). Milestone 4, Acceleration Part 1B/7: Low
 * Stock + Recent Movement now have a real backing data model (Warehouse/
 * Stock/StockMovement) -- see LogisticsDashboardController's own doc
 * comment.
 */
export default function LogisticsDashboard({
    pendingMaterialRequests, waitingApprovals, goodsReceiptsThisMonth, materialRequestsByStatus, recentGoodsReceipts,
    lowStockCount, recentStockMovements,
}) {
    const statusEntries = Object.entries(materialRequestsByStatus || {});

    return (
        <AuthenticatedLayout>
            <Head title="Logistics Dashboard" />
            <PageHeader title="Logistics Dashboard" subtitle="Operational logistics overview." />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={PackageSearch} value={pendingMaterialRequests} label="Pending Material Requests" href={route('material-requests.index', { status: 'submitted' })} />
                <StatCard icon={ClipboardCheck} value={waitingApprovals} label="Waiting Approvals" accent={waitingApprovals > 0 ? 'amber' : null} href={route('work-center.index')} />
                <StatCard icon={PackageCheck} value={goodsReceiptsThisMonth} label="Goods Received This Month" href={route('goods-receipts.index')} />
                <StatCard icon={Boxes} value={lowStockCount} label="Low Stock Items" accent={lowStockCount > 0 ? 'red' : null} href={route('stock.index', { low_stock: 1 })} />
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>Material Requests by Status</CardTitle></CardHeader>
                    <CardContent>
                        {statusEntries.length === 0 ? (
                            <EmptyState icon={PackageSearch} title="No material requests yet" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {statusEntries.map(([status, count]) => (
                                    <li key={status} className="flex items-center justify-between py-2.5 text-sm">
                                        <span className="capitalize text-graphite-700">{status}</span>
                                        <span className="font-semibold text-graphite-800">{count}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Link href={route('material-requests.index')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">View all</Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Recent Goods Receipts</CardTitle></CardHeader>
                    <CardContent>
                        {recentGoodsReceipts.length === 0 ? (
                            <EmptyState icon={PackageCheck} title="No goods receipts yet" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {recentGoodsReceipts.map((gr) => (
                                    <li key={gr.id}>
                                        <Link href={route('goods-receipts.show', gr.id)} className="flex items-center justify-between gap-2 py-2.5 text-sm hover:text-brand-700">
                                            <span className="font-medium text-graphite-700">{gr.receipt_number}</span>
                                            <span className="text-xs text-graphite-400">{gr.material_request?.request_number || '-'}</span>
                                            <span className="text-xs text-graphite-400">{new Date(gr.received_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Link href={route('goods-receipts.index')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">View all</Link>
                    </CardContent>
                </Card>
            </div>

            <div className="mt-4">
                <Card>
                    <CardHeader className="flex flex-row items-center gap-2"><ArrowRightLeft className="h-4 w-4 text-graphite-400" /><CardTitle>Recent Stock Movements</CardTitle></CardHeader>
                    <CardContent>
                        {(!recentStockMovements || recentStockMovements.length === 0) ? (
                            <EmptyState icon={Boxes} title="No stock movements yet" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {recentStockMovements.map((m) => (
                                    <li key={m.id} className="flex items-center justify-between py-2.5 text-sm">
                                        <span className="min-w-0 flex-1 truncate font-medium text-graphite-700">{m.item?.name} -- {m.warehouse?.name}</span>
                                        <span className="capitalize text-xs text-graphite-400">{m.type.replace('_', ' ')}</span>
                                        <span className="text-xs text-graphite-400">{m.quantity}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Link href={route('stock.movements')} className="mt-2 inline-block text-xs font-medium text-brand-600 hover:underline">View all</Link>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
