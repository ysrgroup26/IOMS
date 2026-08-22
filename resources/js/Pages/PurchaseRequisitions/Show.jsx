import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, Pencil, Send, Eye, CheckCircle2, XCircle, FilePlus } from 'lucide-react';

export default function PurchaseRequisitionShow({ purchaseRequisition: pr, activities, canManage, canDecide, canOverride }) {
    function act(routeName, confirmMessage, extra = {}) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route(routeName, pr.id), extra);
    }

    return (
        <AuthenticatedLayout>
            <Head title={pr.pr_number} />

            <Link href={route('purchase-requisitions.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Purchase Requisitions
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight text-graphite-900">
                        {pr.pr_number}<StatusBadge value={pr.priority} /><StatusBadge value={pr.status} />
                    </h1>
                    <p className="text-xs text-graphite-500">
                        {new Date(pr.request_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}
                        {pr.department && ` · ${pr.department.name}`}{pr.project && ` · ${pr.project.name}`}
                        {pr.source_material_request && ` · from ${pr.source_material_request.request_number}`}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {canManage && pr.status === 'draft' && (<>
                        <Button variant="outline" asChild><Link href={route('purchase-requisitions.edit', pr.id)}><Pencil className="h-4 w-4" /> Edit</Link></Button>
                        <Button variant="outline" onClick={() => act('purchase-requisitions.submit', 'Submit this PR?')}><Send className="h-4 w-4" /> Submit</Button>
                    </>)}
                    {canDecide && pr.status === 'submitted' && (
                        <Button variant="outline" onClick={() => act('purchase-requisitions.start-review', 'Start review?')}><Eye className="h-4 w-4" /> Start Review</Button>
                    )}
                    {canDecide && pr.status === 'under_review' && (<>
                        <Button onClick={() => act('purchase-requisitions.approve', 'Approve this PR?')}><CheckCircle2 className="h-4 w-4" /> Approve</Button>
                        <Button variant="outline" className="text-red-600" onClick={() => act('purchase-requisitions.reject', 'Reject this PR?')}>Reject</Button>
                    </>)}
                    {canManage && pr.status === 'approved' && (
                        <Button asChild><Link href={route('rfqs.create', { pr: pr.id })}><FilePlus className="h-4 w-4" /> Create RFQ</Link></Button>
                    )}
                    {canOverride && !['completed', 'cancelled'].includes(pr.status) && (
                        <Button variant="ghost" className="text-red-600" onClick={() => act('purchase-requisitions.cancel', 'Cancel this PR?')}><XCircle className="h-4 w-4" /> Cancel</Button>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Items</CardTitle></CardHeader>
                        <CardContent className="overflow-x-auto">
                            <Table>
                                <TableHeader><TableRow><TableHead>Description</TableHead><TableHead>Specification</TableHead><TableHead>Qty</TableHead><TableHead>Unit</TableHead><TableHead>Est. Unit Price</TableHead><TableHead>Line Total</TableHead></TableRow></TableHeader>
                                <TableBody>
                                    {(pr.items || []).map((item, i) => (
                                        <TableRow key={i}>
                                            <TableCell>{item.description}</TableCell>
                                            <TableCell>{item.specification}</TableCell>
                                            <TableCell>{item.quantity}</TableCell>
                                            <TableCell>{item.unit}</TableCell>
                                            <TableCell>{Number(item.estimated_unit_price || 0).toLocaleString('id-ID')}</TableCell>
                                            <TableCell>{((Number(item.quantity) || 0) * (Number(item.estimated_unit_price) || 0)).toLocaleString('id-ID')}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <p className="mt-3 text-right text-sm font-semibold">Estimated Total: {Number(pr.estimated_total).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })}</p>
                        </CardContent>
                    </Card>

                    {pr.justification && (
                        <Card><CardHeader><CardTitle>Justification</CardTitle></CardHeader><CardContent className="text-sm whitespace-pre-wrap">{pr.justification}</CardContent></Card>
                    )}

                    {(pr.rfqs?.length > 0 || pr.purchase_orders?.length > 0) && (
                        <Card>
                            <CardHeader><CardTitle>Linked Documents</CardTitle></CardHeader>
                            <CardContent className="flex flex-wrap gap-4 text-sm">
                                {pr.rfqs?.map((r) => <Link key={r.id} href={route('rfqs.show', r.id)} className="text-brand-700 hover:underline">RFQ: {r.rfq_number}</Link>)}
                                {pr.purchase_orders?.map((p) => <Link key={p.id} href={route('purchase-orders.show', p.id)} className="text-brand-700 hover:underline">PO: {p.po_number}</Link>)}
                            </CardContent>
                        </Card>
                    )}
                </div>
                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
