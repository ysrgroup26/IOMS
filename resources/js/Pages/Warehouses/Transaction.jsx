import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft, PackageMinus, ArrowRightLeft, SlidersHorizontal, ClipboardCheck } from 'lucide-react';

function ItemSelect({ items, value, onChange }) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger><SelectValue placeholder="Select item" /></SelectTrigger>
            <SelectContent>{items.map((i) => <SelectItem key={i.id} value={String(i.id)}>{i.name} ({i.item_code})</SelectItem>)}</SelectContent>
        </Select>
    );
}

function WarehouseSelect({ warehouses, value, onChange, placeholder = 'Select warehouse' }) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger><SelectValue placeholder={placeholder} /></SelectTrigger>
            <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.name} ({w.code})</SelectItem>)}</SelectContent>
        </Select>
    );
}

export default function WarehouseTransaction({ warehouses, items, materialRequests }) {
    const issueForm = useForm({ warehouse_id: '', item_id: '', quantity: '1', material_request_id: '', movement_date: new Date().toISOString().slice(0, 10), notes: '' });
    const transferForm = useForm({ item_id: '', from_warehouse_id: '', to_warehouse_id: '', quantity: '1', movement_date: new Date().toISOString().slice(0, 10), notes: '' });
    const adjustForm = useForm({ warehouse_id: '', item_id: '', direction: 'in', quantity: '1', movement_date: new Date().toISOString().slice(0, 10), notes: '' });
    const opnameForm = useForm({ warehouse_id: '', item_id: '', counted_quantity: '0', movement_date: new Date().toISOString().slice(0, 10), notes: '' });

    return (
        <AuthenticatedLayout>
            <Head title="Stock Transactions" />

            <Link href={route('stock.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Stock Summary
            </Link>

            <PageHeader title="Stock Transactions" subtitle="Goods Issue, Stock Transfer, Stock Adjustment, and Stock Opname." />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle className="flex items-center gap-2"><PackageMinus className="h-4 w-4" /> Goods Issue</CardTitle><CardDescription>Material Request -&gt; stock check -&gt; issue.</CardDescription></CardHeader>
                    <CardContent>
                        <form onSubmit={(e) => { e.preventDefault(); issueForm.post(route('stock.issue'), { preserveScroll: true, onSuccess: () => issueForm.reset() }); }} className="space-y-3">
                            <WarehouseSelect warehouses={warehouses} value={issueForm.data.warehouse_id} onChange={(v) => issueForm.setData('warehouse_id', v)} />
                            <ItemSelect items={items} value={issueForm.data.item_id} onChange={(v) => issueForm.setData('item_id', v)} />
                            <div className="grid grid-cols-2 gap-2">
                                <Input type="number" min="0.01" step="0.01" placeholder="Quantity" value={issueForm.data.quantity} onChange={(e) => issueForm.setData('quantity', e.target.value)} />
                                <Input type="date" value={issueForm.data.movement_date} onChange={(e) => issueForm.setData('movement_date', e.target.value)} />
                            </div>
                            <Select value={issueForm.data.material_request_id || 'none'} onValueChange={(v) => issueForm.setData('material_request_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="Material Request (optional)" /></SelectTrigger>
                                <SelectContent><SelectItem value="none">Not linked</SelectItem>{materialRequests.map((m) => <SelectItem key={m.id} value={String(m.id)}>{m.request_number}</SelectItem>)}</SelectContent>
                            </Select>
                            <Textarea placeholder="Notes" rows={2} value={issueForm.data.notes} onChange={(e) => issueForm.setData('notes', e.target.value)} />
                            {issueForm.errors.quantity && <p className="text-xs text-red-600">{issueForm.errors.quantity}</p>}
                            <Button type="submit" disabled={issueForm.processing} className="w-full">Issue Stock</Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle className="flex items-center gap-2"><ArrowRightLeft className="h-4 w-4" /> Stock Transfer</CardTitle><CardDescription>Move an item between two warehouses.</CardDescription></CardHeader>
                    <CardContent>
                        <form onSubmit={(e) => { e.preventDefault(); transferForm.post(route('stock.transfer'), { preserveScroll: true, onSuccess: () => transferForm.reset() }); }} className="space-y-3">
                            <ItemSelect items={items} value={transferForm.data.item_id} onChange={(v) => transferForm.setData('item_id', v)} />
                            <WarehouseSelect warehouses={warehouses} value={transferForm.data.from_warehouse_id} onChange={(v) => transferForm.setData('from_warehouse_id', v)} placeholder="From warehouse" />
                            <WarehouseSelect warehouses={warehouses} value={transferForm.data.to_warehouse_id} onChange={(v) => transferForm.setData('to_warehouse_id', v)} placeholder="To warehouse" />
                            <div className="grid grid-cols-2 gap-2">
                                <Input type="number" min="0.01" step="0.01" placeholder="Quantity" value={transferForm.data.quantity} onChange={(e) => transferForm.setData('quantity', e.target.value)} />
                                <Input type="date" value={transferForm.data.movement_date} onChange={(e) => transferForm.setData('movement_date', e.target.value)} />
                            </div>
                            <Textarea placeholder="Notes" rows={2} value={transferForm.data.notes} onChange={(e) => transferForm.setData('notes', e.target.value)} />
                            {transferForm.errors.to_warehouse_id && <p className="text-xs text-red-600">{transferForm.errors.to_warehouse_id}</p>}
                            <Button type="submit" disabled={transferForm.processing} className="w-full">Transfer Stock</Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle className="flex items-center gap-2"><SlidersHorizontal className="h-4 w-4" /> Stock Adjustment</CardTitle><CardDescription>Manual correction with a required reason.</CardDescription></CardHeader>
                    <CardContent>
                        <form onSubmit={(e) => { e.preventDefault(); adjustForm.post(route('stock.adjust'), { preserveScroll: true, onSuccess: () => adjustForm.reset() }); }} className="space-y-3">
                            <WarehouseSelect warehouses={warehouses} value={adjustForm.data.warehouse_id} onChange={(v) => adjustForm.setData('warehouse_id', v)} />
                            <ItemSelect items={items} value={adjustForm.data.item_id} onChange={(v) => adjustForm.setData('item_id', v)} />
                            <div className="grid grid-cols-3 gap-2">
                                <Select value={adjustForm.data.direction} onValueChange={(v) => adjustForm.setData('direction', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value="in">Increase</SelectItem><SelectItem value="out">Decrease</SelectItem></SelectContent>
                                </Select>
                                <Input type="number" min="0.01" step="0.01" placeholder="Quantity" value={adjustForm.data.quantity} onChange={(e) => adjustForm.setData('quantity', e.target.value)} />
                                <Input type="date" value={adjustForm.data.movement_date} onChange={(e) => adjustForm.setData('movement_date', e.target.value)} />
                            </div>
                            <Textarea placeholder="Reason (required)" rows={2} value={adjustForm.data.notes} onChange={(e) => adjustForm.setData('notes', e.target.value)} />
                            {adjustForm.errors.notes && <p className="text-xs text-red-600">{adjustForm.errors.notes}</p>}
                            <Button type="submit" disabled={adjustForm.processing} className="w-full">Adjust Stock</Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle className="flex items-center gap-2"><ClipboardCheck className="h-4 w-4" /> Stock Opname</CardTitle><CardDescription>Physical count reconciliation -- records only the variance.</CardDescription></CardHeader>
                    <CardContent>
                        <form onSubmit={(e) => { e.preventDefault(); opnameForm.post(route('stock.opname'), { preserveScroll: true, onSuccess: () => opnameForm.reset() }); }} className="space-y-3">
                            <WarehouseSelect warehouses={warehouses} value={opnameForm.data.warehouse_id} onChange={(v) => opnameForm.setData('warehouse_id', v)} />
                            <ItemSelect items={items} value={opnameForm.data.item_id} onChange={(v) => opnameForm.setData('item_id', v)} />
                            <div className="grid grid-cols-2 gap-2">
                                <Input type="number" min="0" step="0.01" placeholder="Counted quantity" value={opnameForm.data.counted_quantity} onChange={(e) => opnameForm.setData('counted_quantity', e.target.value)} />
                                <Input type="date" value={opnameForm.data.movement_date} onChange={(e) => opnameForm.setData('movement_date', e.target.value)} />
                            </div>
                            <Textarea placeholder="Notes" rows={2} value={opnameForm.data.notes} onChange={(e) => opnameForm.setData('notes', e.target.value)} />
                            <Button type="submit" disabled={opnameForm.processing} className="w-full">Record Opname</Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
