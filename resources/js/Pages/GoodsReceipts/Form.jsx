import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';

function emptyItem() {
    return { description: '', quantity_received: '1', unit: '', purchase_order_item_id: null };
}

/**
 * Goods Receipt create form (v1.10.0, extended Milestone 4 Workstream C5
 * for PO integration). Dynamic item table, same add/remove-row pattern as
 * Material Request -- no image uploads (unneeded here), no edit route (a
 * receipt is an immutable record of what arrived). A receipt links to
 * EITHER a Material Request OR a Purchase Order, never both -- picking
 * one clears the other. Selecting a PO offers "Load Remaining Items" to
 * prefill rows from that PO's own outstanding quantities, still fully
 * editable (a delivery is never forced to match the order exactly).
 */
export default function GoodsReceiptForm({ materialRequests, purchaseOrders, projects, receiptNumber, preselectedPo }) {
    const { data, setData, post, processing, errors } = useForm({
        received_date: new Date().toISOString().slice(0, 10),
        material_request_id: '',
        purchase_order_id: preselectedPo ? String(preselectedPo.id) : '',
        project_id: '',
        notes: '',
        items: preselectedPo ? preselectedPo.items.filter((i) => i.remaining_quantity > 0).map((i) => ({ description: i.description, quantity_received: String(i.remaining_quantity), unit: i.unit, purchase_order_item_id: i.id })) : [emptyItem()],
    });

    function loadPoItems() {
        const po = purchaseOrders.find((p) => String(p.id) === data.purchase_order_id);
        if (!po) return;
        setData('items', po.items.filter((i) => i.remaining_quantity > 0).map((i) => ({ description: i.description, quantity_received: String(i.remaining_quantity), unit: i.unit, purchase_order_item_id: i.id })));
    }

    function updateItem(index, field, value) {
        const items = [...data.items];
        items[index] = { ...items[index], [field]: value };
        setData('items', items);
    }

    function addItem() {
        setData('items', [...data.items, emptyItem()]);
    }

    function removeItem(index) {
        setData('items', data.items.filter((_, i) => i !== index));
    }

    function submit(e) {
        e.preventDefault();
        post(route('goods-receipts.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="New Goods Receipt" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('goods-receipts.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-3xl space-y-4">
                <Card>
                    <CardHeader><CardTitle>Goods Receipt -- {receiptNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Received Date</Label>
                                <Input type="date" value={data.received_date} onChange={(e) => setData('received_date', e.target.value)} />
                                {errors.received_date && <p className="text-xs text-red-600">{errors.received_date}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Material Request (optional)</Label>
                                <Select value={data.material_request_id ? String(data.material_request_id) : 'none'} onValueChange={(v) => setData({ ...data, material_request_id: v === 'none' ? '' : v, purchase_order_id: v === 'none' ? data.purchase_order_id : '' })}>
                                    <SelectTrigger><SelectValue placeholder="Not linked" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Not linked</SelectItem>
                                        {materialRequests.map((mr) => <SelectItem key={mr.id} value={String(mr.id)}>{mr.request_number}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="flex items-end gap-2">
                            <div className="flex-1 space-y-1.5">
                                <Label>Purchase Order (optional)</Label>
                                <Select value={data.purchase_order_id ? String(data.purchase_order_id) : 'none'} onValueChange={(v) => setData({ ...data, purchase_order_id: v === 'none' ? '' : v, material_request_id: v === 'none' ? data.material_request_id : '' })}>
                                    <SelectTrigger><SelectValue placeholder="Not linked" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Not linked</SelectItem>
                                        {purchaseOrders.map((po) => <SelectItem key={po.id} value={String(po.id)}>{po.po_number}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            {data.purchase_order_id && <Button type="button" variant="outline" size="sm" onClick={loadPoItems}>Load Remaining Items</Button>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Project (optional)</Label>
                            <Select value={data.project_id ? String(data.project_id) : 'none'} onValueChange={(v) => setData('project_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="No project" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">No project</SelectItem>
                                    {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Notes</Label>
                            <Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <CardTitle>Items Received</CardTitle>
                        <Button type="button" variant="outline" size="sm" onClick={addItem}><Plus className="h-3.5 w-3.5" /> Add Item</Button>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {data.items.map((item, index) => (
                            <div key={index} className="grid grid-cols-12 items-start gap-2 rounded-lg border border-graphite-100 p-2.5 dark:border-slate-800">
                                <div className="col-span-6 space-y-1">
                                    <Label className="text-[11px]">Description</Label>
                                    <Input value={item.description} onChange={(e) => updateItem(index, 'description', e.target.value)} />
                                    {errors[`items.${index}.description`] && <p className="text-xs text-red-600">{errors[`items.${index}.description`]}</p>}
                                </div>
                                <div className="col-span-2 space-y-1">
                                    <Label className="text-[11px]">Qty Received</Label>
                                    <Input type="number" step="0.01" min="0" value={item.quantity_received} onChange={(e) => updateItem(index, 'quantity_received', e.target.value)} />
                                </div>
                                <div className="col-span-3 space-y-1">
                                    <Label className="text-[11px]">Unit</Label>
                                    <Input value={item.unit} onChange={(e) => updateItem(index, 'unit', e.target.value)} placeholder="pcs, box, etc." />
                                </div>
                                <div className="col-span-1 flex items-end justify-end pb-1">
                                    {data.items.length > 1 && (
                                        <Button type="button" variant="ghost" size="icon" onClick={() => removeItem(index)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Button type="submit" disabled={processing} className="w-full">Save Goods Receipt</Button>
            </form>
        </AuthenticatedLayout>
    );
}
