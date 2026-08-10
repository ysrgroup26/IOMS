import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, Plus, Trash2, ScaleIcon, CheckCircle2, FilePlus } from 'lucide-react';

const BLANK_ITEM = { description: '', quantity: '1', unit: 'pcs', unit_price: '0', discount: '0', tax: '0' };

export default function RfqShow({ rfq, activities, canManage }) {
    const [quoteOpen, setQuoteOpen] = useState(null); // vendor_id currently adding a quote for
    const [selectOpen, setSelectOpen] = useState(false);

    const quoteForm = useForm({
        vendor_id: '', vendor_reference_number: '', quotation_date: new Date().toISOString().slice(0, 10),
        valid_until: '', currency: rfq.currency, items: [{ ...BLANK_ITEM }], shipping_cost: '0', other_charges: '0',
        lead_time_days: '', payment_terms: '', delivery_terms: '', notes: '', attachment: null,
    });
    const selectForm = useForm({ selected_vendor_id: rfq.selected_vendor_id ? String(rfq.selected_vendor_id) : '', evaluation_notes: rfq.evaluation_notes || '' });

    function openQuoteFor(vendorId) {
        const existing = rfq.quotations.find((q) => q.vendor_id === vendorId);
        quoteForm.setData({
            vendor_id: String(vendorId),
            vendor_reference_number: existing?.vendor_reference_number || '',
            quotation_date: existing?.quotation_date?.slice(0, 10) || new Date().toISOString().slice(0, 10),
            valid_until: existing?.valid_until?.slice(0, 10) || '',
            currency: existing?.currency || rfq.currency,
            items: existing?.items?.length ? existing.items : [{ ...BLANK_ITEM }],
            shipping_cost: String(existing?.shipping_cost ?? '0'),
            other_charges: String(existing?.other_charges ?? '0'),
            lead_time_days: existing?.lead_time_days ? String(existing.lead_time_days) : '',
            payment_terms: existing?.payment_terms || '',
            delivery_terms: existing?.delivery_terms || '',
            notes: existing?.notes || '',
            attachment: null,
        });
        setQuoteOpen(vendorId);
    }

    function updateItem(i, field, value) {
        const items = [...quoteForm.data.items];
        items[i] = { ...items[i], [field]: value };
        quoteForm.setData('items', items);
    }

    function submitQuote(e) {
        e.preventDefault();
        quoteForm.post(route('rfqs.quotations.store', rfq.id), { preserveScroll: true, forceFormData: true, onSuccess: () => setQuoteOpen(null) });
    }

    function submitSelect(e) {
        e.preventDefault();
        selectForm.post(route('rfqs.select-vendor', rfq.id), { preserveScroll: true, onSuccess: () => setSelectOpen(false) });
    }

    return (
        <AuthenticatedLayout>
            <Head title={rfq.rfq_number} />

            <Link href={route('rfqs.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to RFQ
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">{rfq.rfq_number}<StatusBadge value={rfq.status} /></h1>
                    <p className="text-xs text-graphite-500">
                        <Link href={route('purchase-requisitions.show', rfq.purchase_requisition.id)} className="hover:underline">{rfq.purchase_requisition.pr_number}</Link>
                        {' · '}Deadline {new Date(rfq.quotation_deadline).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })} · Buyer {rfq.buyer?.name}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {canManage && rfq.status === 'issued' && rfq.quotations.length > 0 && (
                        <Button onClick={() => setSelectOpen(true)}><CheckCircle2 className="h-4 w-4" /> {rfq.selected_vendor ? 'Change Selection' : 'Select Vendor'}</Button>
                    )}
                    {canManage && rfq.status === 'issued' && (
                        <Button variant="outline" onClick={() => { if (confirm('Close this RFQ?')) router.post(route('rfqs.close', rfq.id)); }}>Close RFQ</Button>
                    )}
                    {canManage && rfq.selected_vendor && rfq.purchase_requisition.status !== 'converted_to_po' && (
                        <Button variant="outline" asChild><Link href={route('purchase-orders.create', { rfq: rfq.id })}><FilePlus className="h-4 w-4" /> Create PO</Link></Button>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="flex flex-row items-center gap-2"><ScaleIcon className="h-4 w-4 text-graphite-400" /><CardTitle>Quotation Comparison</CardTitle></CardHeader>
                        <CardContent className="overflow-x-auto">
                            {rfq.rfq_vendors.length === 0 ? (
                                <EmptyState icon={ScaleIcon} title="No vendors invited" />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Vendor</TableHead><TableHead>Response</TableHead><TableHead>Total</TableHead>
                                            <TableHead>Lead Time</TableHead><TableHead>Payment Terms</TableHead><TableHead>Valid Until</TableHead>
                                            {canManage && <TableHead />}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rfq.rfq_vendors.map((rv) => {
                                            const q = rfq.quotations.find((x) => x.vendor_id === rv.vendor_id);
                                            const isSelected = rfq.selected_vendor_id === rv.vendor_id;
                                            return (
                                                <TableRow key={rv.id} className={isSelected ? 'bg-green-50/50' : ''}>
                                                    <TableCell className="font-medium">{rv.vendor.name} {isSelected && <StatusBadge value="approved" label="Selected" />}</TableCell>
                                                    <TableCell><StatusBadge value={rv.status === 'responded' ? 'approved' : rv.status === 'declined' ? 'rejected' : rv.status} label={rv.status.replace('_', ' ')} /></TableCell>
                                                    <TableCell>{q ? Number(q.total_amount).toLocaleString('id-ID', { style: 'currency', currency: q.currency, maximumFractionDigits: 0 }) : '-'}</TableCell>
                                                    <TableCell>{q?.lead_time_days ? `${q.lead_time_days}d` : '-'}</TableCell>
                                                    <TableCell>{q?.payment_terms || '-'}</TableCell>
                                                    <TableCell>{q?.valid_until ? new Date(q.valid_until).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'}</TableCell>
                                                    {canManage && (
                                                        <TableCell>
                                                            <Button variant="outline" size="sm" onClick={() => openQuoteFor(rv.vendor_id)}>{q ? 'Edit Quote' : 'Add Quote'}</Button>
                                                        </TableCell>
                                                    )}
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            )}
                            {rfq.evaluation_notes && (
                                <div className="mt-3 rounded-md border border-graphite-100 p-3 text-sm">
                                    <span className="text-xs uppercase text-graphite-400">Evaluation Notes</span>
                                    <p className="whitespace-pre-wrap">{rfq.evaluation_notes}</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {quoteOpen !== null && (
                        <Card>
                            <CardHeader><CardTitle>Vendor Quotation</CardTitle></CardHeader>
                            <CardContent>
                                <form onSubmit={submitQuote} className="space-y-3">
                                    <div className="grid grid-cols-3 gap-3">
                                        <div className="space-y-1"><Label className="text-xs">Quotation Date</Label><Input type="date" value={quoteForm.data.quotation_date} onChange={(e) => quoteForm.setData('quotation_date', e.target.value)} /></div>
                                        <div className="space-y-1"><Label className="text-xs">Valid Until</Label><Input type="date" value={quoteForm.data.valid_until} onChange={(e) => quoteForm.setData('valid_until', e.target.value)} /></div>
                                        <div className="space-y-1"><Label className="text-xs">Lead Time (days)</Label><Input type="number" value={quoteForm.data.lead_time_days} onChange={(e) => quoteForm.setData('lead_time_days', e.target.value)} /></div>
                                    </div>
                                    <Table>
                                        <TableHeader><TableRow><TableHead>Description</TableHead><TableHead className="w-20">Qty</TableHead><TableHead className="w-20">Unit</TableHead><TableHead className="w-28">Unit Price</TableHead><TableHead className="w-24">Discount</TableHead><TableHead className="w-24">Tax</TableHead><TableHead /></TableRow></TableHeader>
                                        <TableBody>
                                            {quoteForm.data.items.map((item, i) => (
                                                <TableRow key={i}>
                                                    <TableCell><Input value={item.description} onChange={(e) => updateItem(i, 'description', e.target.value)} /></TableCell>
                                                    <TableCell><Input type="number" value={item.quantity} onChange={(e) => updateItem(i, 'quantity', e.target.value)} /></TableCell>
                                                    <TableCell><Input value={item.unit} onChange={(e) => updateItem(i, 'unit', e.target.value)} /></TableCell>
                                                    <TableCell><Input type="number" value={item.unit_price} onChange={(e) => updateItem(i, 'unit_price', e.target.value)} /></TableCell>
                                                    <TableCell><Input type="number" value={item.discount} onChange={(e) => updateItem(i, 'discount', e.target.value)} /></TableCell>
                                                    <TableCell><Input type="number" value={item.tax} onChange={(e) => updateItem(i, 'tax', e.target.value)} /></TableCell>
                                                    <TableCell><Button type="button" variant="ghost" size="icon" onClick={() => quoteForm.setData('items', quoteForm.data.items.filter((_, idx) => idx !== i))}><Trash2 className="h-4 w-4 text-red-500" /></Button></TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                    <Button type="button" variant="outline" size="sm" onClick={() => quoteForm.setData('items', [...quoteForm.data.items, { ...BLANK_ITEM }])}><Plus className="h-4 w-4" /> Add Row</Button>
                                    <div className="grid grid-cols-3 gap-3">
                                        <div className="space-y-1"><Label className="text-xs">Shipping</Label><Input type="number" value={quoteForm.data.shipping_cost} onChange={(e) => quoteForm.setData('shipping_cost', e.target.value)} /></div>
                                        <div className="space-y-1"><Label className="text-xs">Other Charges</Label><Input type="number" value={quoteForm.data.other_charges} onChange={(e) => quoteForm.setData('other_charges', e.target.value)} /></div>
                                        <div className="space-y-1"><Label className="text-xs">Payment Terms</Label><Input value={quoteForm.data.payment_terms} onChange={(e) => quoteForm.setData('payment_terms', e.target.value)} /></div>
                                    </div>
                                    <div className="space-y-1"><Label className="text-xs">Attachment</Label><Input type="file" onChange={(e) => quoteForm.setData('attachment', e.target.files[0])} /></div>
                                    <div className="flex gap-2">
                                        <Button type="button" variant="outline" onClick={() => setQuoteOpen(null)}>Cancel</Button>
                                        <Button type="submit" disabled={quoteForm.processing}>Save Quotation</Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    )}
                </div>
                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>

            <Dialog open={selectOpen} onOpenChange={setSelectOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Select Vendor</DialogTitle></DialogHeader>
                    <form onSubmit={submitSelect} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Vendor</Label>
                            <Select value={selectForm.data.selected_vendor_id} onValueChange={(v) => selectForm.setData('selected_vendor_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                <SelectContent>{rfq.quotations.map((q) => <SelectItem key={q.vendor_id} value={String(q.vendor_id)}>{q.vendor.name} -- {Number(q.total_amount).toLocaleString('id-ID')}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Evaluation Notes</Label><Textarea value={selectForm.data.evaluation_notes} onChange={(e) => selectForm.setData('evaluation_notes', e.target.value)} rows={3} placeholder="Why this vendor was selected" /></div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setSelectOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={selectForm.processing}>Confirm Selection</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
