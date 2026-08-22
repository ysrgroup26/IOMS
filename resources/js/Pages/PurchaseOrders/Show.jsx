import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, Send, CheckCircle2, XCircle, PackageCheck, Truck, PackagePlus } from 'lucide-react';

export default function PurchaseOrderShow({ purchaseOrder: po, activities, canManage, canDecide, canOverride }) {
    function act(routeName, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route(routeName, po.id));
    }

    return (
        <AuthenticatedLayout>
            <Head title={po.po_number} />

            <Link href={route('purchase-orders.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Purchase Orders
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-[22px] font-semibold tracking-tight text-graphite-900">{po.po_number}<StatusBadge value={po.status} /></h1>
                    <p className="text-xs text-graphite-500">{po.vendor?.name} · {new Date(po.po_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}{po.delivery_date && ` · due ${new Date(po.delivery_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`}</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {canManage && po.status === 'draft' && (
                        <Button variant="outline" onClick={() => act('purchase-orders.submit', 'Submit this PO for approval?')}><Send className="h-4 w-4" /> Submit</Button>
                    )}
                    {canDecide && po.status === 'submitted' && (<>
                        <Button onClick={() => act('purchase-orders.approve', 'Approve this PO?')}><CheckCircle2 className="h-4 w-4" /> Approve</Button>
                        <Button variant="outline" className="text-red-600" onClick={() => act('purchase-orders.reject', 'Reject this PO?')}>Reject</Button>
                    </>)}
                    {canManage && po.status === 'approved' && (
                        <Button onClick={() => act('purchase-orders.issue', 'Issue this PO to the vendor?')}><PackageCheck className="h-4 w-4" /> Issue</Button>
                    )}
                    {canManage && ['issued', 'partially_delivered'].includes(po.status) && (
                        <Button variant="outline" asChild><Link href={route('goods-receipts.create', { po: po.id })}><Truck className="h-4 w-4" /> Record Delivery</Link></Button>
                    )}
                    {canManage && po.status === 'fully_delivered' && (
                        <Button onClick={() => act('purchase-orders.close', 'Close this PO?')}>Close PO</Button>
                    )}
                    {canOverride && !['closed', 'cancelled'].includes(po.status) && (
                        <Button variant="ghost" className="text-red-600" onClick={() => act('purchase-orders.cancel', 'Cancel this PO?')}><XCircle className="h-4 w-4" /> Cancel</Button>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="flex flex-row items-center gap-2"><PackagePlus className="h-4 w-4 text-graphite-400" /><CardTitle>Items &amp; Delivery Tracking</CardTitle></CardHeader>
                        <CardContent className="overflow-x-auto">
                            <Table>
                                <TableHeader><TableRow><TableHead>Description</TableHead><TableHead>Qty</TableHead><TableHead>Unit Price</TableHead><TableHead>Line Total</TableHead><TableHead>Delivered</TableHead><TableHead>Remaining</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                                <TableBody>
                                    {po.items.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell>{item.description}</TableCell>
                                            <TableCell>{item.quantity} {item.unit}</TableCell>
                                            <TableCell>{Number(item.unit_price).toLocaleString('id-ID')}</TableCell>
                                            <TableCell>{Number(item.line_total).toLocaleString('id-ID')}</TableCell>
                                            <TableCell>{item.delivered_quantity} {item.unit}</TableCell>
                                            <TableCell>{item.remaining_quantity} {item.unit}</TableCell>
                                            <TableCell><StatusBadge value={item.delivery_status === 'fully_delivered' ? 'approved' : item.delivery_status === 'partially_delivered' ? 'submitted' : 'secondary'} label={item.delivery_status.replace('_', ' ')} /></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <div className="mt-3 space-y-1 text-right text-sm">
                                <p>Subtotal: {Number(po.subtotal).toLocaleString('id-ID')}</p>
                                <p>Discount: -{Number(po.discount_amount).toLocaleString('id-ID')}</p>
                                <p>Tax: {Number(po.tax_amount).toLocaleString('id-ID')}</p>
                                <p>Shipping: {Number(po.shipping_amount).toLocaleString('id-ID')}</p>
                                <p>Other: {Number(po.other_charges).toLocaleString('id-ID')}</p>
                                <p className="font-semibold">Grand Total: {Number(po.grand_total).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })}</p>
                            </div>
                        </CardContent>
                    </Card>

                    {po.goods_receipts?.length > 0 && (
                        <Card>
                            <CardHeader><CardTitle>Goods Receipts</CardTitle></CardHeader>
                            <CardContent>
                                <ul className="divide-y divide-graphite-100">
                                    {po.goods_receipts.map((g) => (
                                        <li key={g.id} className="py-2 text-sm">
                                            <Link href={route('goods-receipts.show', g.id)} className="text-brand-700 hover:underline">{g.receipt_number}</Link>
                                            {' · '}{new Date(g.received_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })} · {g.items?.length ?? 0} line(s)
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader><CardTitle>Document Trail</CardTitle></CardHeader>
                        <CardContent className="flex flex-wrap gap-4 text-sm">
                            {po.purchase_requisition && <Link href={route('purchase-requisitions.show', po.purchase_requisition.id)} className="text-brand-700 hover:underline">PR: {po.purchase_requisition.pr_number}</Link>}
                            {po.rfq && <Link href={route('rfqs.show', po.rfq.id)} className="text-brand-700 hover:underline">RFQ: {po.rfq.rfq_number}</Link>}
                            <Link href={route('vendors.show', po.vendor.id)} className="text-brand-700 hover:underline">Vendor: {po.vendor.name}</Link>
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
