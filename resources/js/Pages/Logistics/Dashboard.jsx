import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatCard from '@/Components/shared/StatCard';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { PackageSearch, ClipboardCheck, PackageCheck } from 'lucide-react';

/** Logistics Dashboard (v1.10.0). See LogisticsDashboardController's doc comment for what was intentionally left out (no Inventory/Goods Issue/Stock Movement data model yet). */
export default function LogisticsDashboard({
    pendingMaterialRequests, waitingApprovals, goodsReceiptsThisMonth, materialRequestsByStatus, recentGoodsReceipts,
}) {
    const statusEntries = Object.entries(materialRequestsByStatus || {});

    return (
        <AuthenticatedLayout>
            <Head title="Logistics Dashboard" />
            <PageHeader title="Logistics Dashboard" subtitle="Operational logistics overview." />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                <StatCard icon={PackageSearch} value={pendingMaterialRequests} label="Pending Material Requests" href={route('material-requests.index', { status: 'submitted' })} />
                <StatCard icon={ClipboardCheck} value={waitingApprovals} label="Waiting Approvals" accent={waitingApprovals > 0 ? 'amber' : null} href={route('work-center.index')} />
                <StatCard icon={PackageCheck} value={goodsReceiptsThisMonth} label="Goods Received This Month" href={route('goods-receipts.index')} />
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
        </AuthenticatedLayout>
    );
}
