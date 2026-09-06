import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import { ArrowLeft, Printer } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import PageHeader from '@/Components/shared/PageHeader';

export default function GoodsReceiptShow({ goodsReceipt: gr, activities }) {
    return (
        <AuthenticatedLayout>
            <Head title={gr.receipt_number} />

            <Link href={route('goods-receipts.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Goods Receipt
            </Link>

            <PageHeader
                title={gr.receipt_number}
                subtitle={<>
                    {new Date(gr.received_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}
                    {gr.material_request && ` · ${gr.material_request.request_number}`}
                    {gr.purchase_order && ` · `}
                    {gr.purchase_order && <Link href={route('purchase-orders.show', gr.purchase_order.id)} className="text-brand-700 hover:underline">{gr.purchase_order.po_number}</Link>}
                    {gr.warehouse && ` · posted to ${gr.warehouse.name}`}
                    {gr.project && ` · ${gr.project.name}`}
                    {` · Received by ${gr.receiver?.name}`}
                </>}
            >
                {/* v2.52.0: this is a RECEIVING document, not a Berita Acara -- see
                    pdf/goods-receipt.blade.php. Formal handover is a separate BAST. */}
                <Button variant="outline" asChild>
                    <a href={route('goods-receipts.pdf', gr.id)} target="_blank" rel="noreferrer"><Printer className="h-4 w-4" /> Print Goods Receipt</a>
                </Button>
            </PageHeader>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader><CardTitle>Items Received</CardTitle></CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow><TableHead>Description</TableHead><TableHead>Qty Received</TableHead><TableHead>Unit</TableHead></TableRow>
                            </TableHeader>
                            <TableBody>
                                {gr.items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>{item.description}</TableCell>
                                        <TableCell>{item.quantity_received}</TableCell>
                                        <TableCell>{item.unit}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        {gr.notes && (
                            <div className="border-t border-graphite-100 p-4">
                                <p className="text-xs font-medium uppercase tracking-wide text-graphite-400">Notes</p>
                                <p className="mt-1 whitespace-pre-wrap text-sm text-graphite-700">{gr.notes}</p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
