import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { FileStack, FileQuestion, ShoppingCart, AlertTriangle, Truck, CheckCircle2, Building2, Clock, ScaleIcon } from 'lucide-react';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function idr(n) {
    return Number(n || 0).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
}

/**
 * Procurement Dashboard (Milestone 4, Workstream C6). Every widget reads
 * from real transactions -- see ProcurementDashboardController's own doc
 * comment.
 */
export default function ProcurementDashboard({
    pendingPRCount, openRfqCount, quotationsAwaitingEvaluationCount, pendingPOApprovalCount,
    openPOCount, overdueDeliveryCount, partiallyDeliveredCount, completedPOCount,
    procurementValueYtd, monthlyTrend, departmentBreakdown, purchaseCycleDaysAvg,
    activeVendorCount, recentPOs,
}) {
    const maxTrend = Math.max(1, ...Object.values(monthlyTrend));

    return (
        <AuthenticatedLayout>
            <Head title="Procurement Dashboard" />
            <PageHeader title="Procurement Dashboard" subtitle="Purchase Requisition, RFQ, and Purchase Order overview." />

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={FileStack} value={pendingPRCount} label="Pending PRs" accent={pendingPRCount > 0 ? 'amber' : null} href={route('purchase-requisitions.index', { status: 'submitted' })} />
                <StatCard icon={FileQuestion} value={openRfqCount} label="Open RFQs" href={route('rfqs.index', { status: 'issued' })} />
                <StatCard icon={ScaleIcon} value={quotationsAwaitingEvaluationCount} label="Awaiting Evaluation" accent={quotationsAwaitingEvaluationCount > 0 ? 'amber' : null} href={route('rfqs.index')} />
                <StatCard icon={ShoppingCart} value={pendingPOApprovalCount} label="Pending PO Approval" accent={pendingPOApprovalCount > 0 ? 'amber' : null} href={route('purchase-orders.index', { status: 'submitted' })} />
                <StatCard icon={ShoppingCart} value={openPOCount} label="Open POs" href={route('purchase-orders.index')} />
                <StatCard icon={AlertTriangle} value={overdueDeliveryCount} label="Overdue Deliveries" accent={overdueDeliveryCount > 0 ? 'red' : null} href={route('purchase-orders.index')} />
                <StatCard icon={Truck} value={partiallyDeliveredCount} label="Partially Delivered" href={route('purchase-orders.index', { status: 'partially_delivered' })} />
                <StatCard icon={CheckCircle2} value={completedPOCount} label="Completed POs" href={route('purchase-orders.index', { status: 'closed' })} />
            </div>

            <div className="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={Building2} value={activeVendorCount} label="Active Vendors" href={route('vendors.index')} />
                <StatCard icon={Clock} value={purchaseCycleDaysAvg !== null ? `${purchaseCycleDaysAvg}d` : '-'} label="Avg. Purchase Cycle" />
                <div className="col-span-2 flex items-center rounded-xl border border-graphite-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                    <div>
                        <p className="text-xs text-graphite-500 dark:text-slate-400">Procurement Value (YTD)</p>
                        <p className="text-xl font-bold text-graphite-900 dark:text-slate-50">{idr(procurementValueYtd)}</p>
                    </div>
                </div>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>Monthly Procurement Trend</CardTitle></CardHeader>
                    <CardContent>
                        <div className="flex h-40 items-end gap-2">
                            {MONTHS.map((m, i) => {
                                const value = monthlyTrend[i + 1] || 0;
                                return (
                                    <div key={m} className="flex flex-1 flex-col items-center gap-1">
                                        <div className="w-full rounded-t bg-brand-500" style={{ height: `${Math.max(2, (value / maxTrend) * 100)}%` }} title={idr(value)} />
                                        <span className="text-[10px] text-graphite-400">{m}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Procurement by Department</CardTitle></CardHeader>
                    <CardContent>
                        {Object.keys(departmentBreakdown).length === 0 ? (
                            <EmptyState icon={Building2} title="No department data yet" />
                        ) : (
                            <ul className="space-y-2">
                                {Object.entries(departmentBreakdown).map(([name, value]) => (
                                    <li key={name} className="flex items-center justify-between text-sm">
                                        <span>{name}</span>
                                        <span className="font-medium">{idr(value)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="mt-4">
                <Card>
                    <CardHeader><CardTitle>Recent Purchase Orders</CardTitle></CardHeader>
                    <CardContent>
                        {recentPOs.length === 0 ? (
                            <EmptyState icon={ShoppingCart} title="No purchase orders yet" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {recentPOs.map((po) => (
                                    <li key={po.id}>
                                        <Link href={route('purchase-orders.show', po.id)} className="flex items-center justify-between gap-2 py-2.5 text-sm hover:text-brand-700">
                                            <span className="min-w-0 flex-1 truncate font-medium text-graphite-700">{po.po_number} -- {po.vendor?.name}</span>
                                            <span>{idr(po.grand_total)}</span>
                                            <StatusBadge value={po.status} />
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
