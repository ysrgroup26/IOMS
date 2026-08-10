import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';

const BLANK_ITEM = { description: '', specification: '', quantity: '1', unit: 'pcs', unit_price: '0', discount: '0', tax: '0' };

export default function PurchaseOrderForm({ companies, vendors, purchaseRequisitions, projects, departments, poNumber, prefill }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: prefill ? String(vendors.find((v) => v.id === prefill.vendor_id)?.company_id || (companies[0]?.id ?? '')) : (companies[0]?.id ? String(companies[0].id) : ''),
        vendor_id: prefill?.vendor_id ? String(prefill.vendor_id) : '',
        purchase_requisition_id: prefill?.purchase_requisition_id ? String(prefill.purchase_requisition_id) : '',
        rfq_id: prefill?.rfq_id ? String(prefill.rfq_id) : '',
        vendor_quotation_id: prefill?.vendor_quotation_id ? String(prefill.vendor_quotation_id) : '',
        project_id: '',
        department_id: '',
        cost_center: '',
        po_date: new Date().toISOString().slice(0, 10),
        delivery_date: '',
        delivery_location: '',
        payment_terms: prefill?.payment_terms || '',
        currency: prefill?.currency || 'IDR',
        shipping_amount: String(prefill?.shipping_amount ?? '0'),
        other_charges: String(prefill?.other_charges ?? '0'),
        notes: '',
        terms_conditions: '',
        items: prefill?.items?.length ? prefill.items.map((i) => ({ ...i, quantity: String(i.quantity), unit_price: String(i.unit_price), discount: String(i.discount), tax: String(i.tax) })) : [{ ...BLANK_ITEM }],
    });

    const subtotal = data.items.reduce((s, i) => s + (Number(i.quantity) || 0) * (Number(i.unit_price) || 0), 0);
    const discountTotal = data.items.reduce((s, i) => s + (Number(i.discount) || 0), 0);
    const taxTotal = data.items.reduce((s, i) => s + (Number(i.tax) || 0), 0);
    const grandTotal = subtotal - discountTotal + taxTotal + (Number(data.shipping_amount) || 0) + (Number(data.other_charges) || 0);

    function updateItem(i, field, value) {
        const items = [...data.items];
        items[i] = { ...items[i], [field]: value };
        setData('items', items);
    }

    function submit(e) {
        e.preventDefault();
        post(route('purchase-orders.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="New Purchase Order" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('purchase-orders.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <Card>
                    <CardHeader><CardTitle>New Purchase Order -- {poNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Vendor</Label>
                                <Select value={data.vendor_id} onValueChange={(v) => setData('vendor_id', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select vendor" /></SelectTrigger>
                                    <SelectContent>{vendors.map((v) => <SelectItem key={v.id} value={String(v.id)}>{v.name} ({v.vendor_code})</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.vendor_id && <p className="text-xs text-red-600">{errors.vendor_id}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Source PR (optional)</Label>
                                <Select value={data.purchase_requisition_id || 'none'} onValueChange={(v) => setData('purchase_requisition_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">None</SelectItem>{purchaseRequisitions.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.pr_number}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <div className="space-y-1.5"><Label>PO Date</Label><Input type="date" value={data.po_date} onChange={(e) => setData('po_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Delivery Date</Label><Input type="date" value={data.delivery_date} onChange={(e) => setData('delivery_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Delivery Location</Label><Input value={data.delivery_location} onChange={(e) => setData('delivery_location', e.target.value)} /></div>
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <div className="space-y-1.5"><Label>Payment Terms</Label><Input value={data.payment_terms} onChange={(e) => setData('payment_terms', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Currency</Label><Input value={data.currency} onChange={(e) => setData('currency', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Cost Center (optional)</Label><Input value={data.cost_center} onChange={(e) => setData('cost_center', e.target.value)} /></div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Project (optional)</Label>
                                <Select value={data.project_id || 'none'} onValueChange={(v) => setData('project_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="No project" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">No project</SelectItem>{projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Department (optional)</Label>
                                <Select value={data.department_id || 'none'} onValueChange={(v) => setData('department_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">None</SelectItem>{departments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1.5"><Label>Terms &amp; Conditions</Label><Textarea value={data.terms_conditions} onChange={(e) => setData('terms_conditions', e.target.value)} rows={2} /></div>
                        <div className="space-y-1.5"><Label>Notes</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>Items</CardTitle>
                        <Button type="button" variant="outline" size="sm" onClick={() => setData('items', [...data.items, { ...BLANK_ITEM }])}><Plus className="h-4 w-4" /> Add Item</Button>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader><TableRow><TableHead className="min-w-[160px]">Description</TableHead><TableHead className="min-w-[140px]">Specification</TableHead><TableHead className="w-20">Qty</TableHead><TableHead className="w-20">Unit</TableHead><TableHead className="w-28">Unit Price</TableHead><TableHead className="w-24">Discount</TableHead><TableHead className="w-24">Tax</TableHead><TableHead /></TableRow></TableHeader>
                            <TableBody>
                                {data.items.map((item, i) => (
                                    <TableRow key={i}>
                                        <TableCell><Input value={item.description} onChange={(e) => updateItem(i, 'description', e.target.value)} /></TableCell>
                                        <TableCell><Input value={item.specification} onChange={(e) => updateItem(i, 'specification', e.target.value)} /></TableCell>
                                        <TableCell><Input type="number" value={item.quantity} onChange={(e) => updateItem(i, 'quantity', e.target.value)} /></TableCell>
                                        <TableCell><Input value={item.unit} onChange={(e) => updateItem(i, 'unit', e.target.value)} /></TableCell>
                                        <TableCell><Input type="number" value={item.unit_price} onChange={(e) => updateItem(i, 'unit_price', e.target.value)} /></TableCell>
                                        <TableCell><Input type="number" value={item.discount} onChange={(e) => updateItem(i, 'discount', e.target.value)} /></TableCell>
                                        <TableCell><Input type="number" value={item.tax} onChange={(e) => updateItem(i, 'tax', e.target.value)} /></TableCell>
                                        <TableCell><Button type="button" variant="ghost" size="icon" onClick={() => setData('items', data.items.filter((_, idx) => idx !== i))}><Trash2 className="h-4 w-4 text-red-500" /></Button></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <div className="mt-3 grid grid-cols-2 gap-3 sm:w-80 sm:ml-auto">
                            <div className="space-y-1.5"><Label className="text-xs">Shipping</Label><Input type="number" value={data.shipping_amount} onChange={(e) => setData('shipping_amount', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label className="text-xs">Other Charges</Label><Input type="number" value={data.other_charges} onChange={(e) => setData('other_charges', e.target.value)} /></div>
                        </div>
                        <p className="mt-3 text-right text-sm font-semibold">Grand Total: {grandTotal.toLocaleString('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })}</p>
                    </CardContent>
                </Card>

                <Button type="submit" disabled={processing}>Create PO</Button>
            </form>
        </AuthenticatedLayout>
    );
}
