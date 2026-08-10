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

const BLANK_ITEM = { description: '', specification: '', quantity: '1', unit: 'pcs', estimated_unit_price: '0' };

export default function PurchaseRequisitionForm({ purchaseRequisition, companies, projects, departments, materialRequests, prNumber, priorities }) {
    const editing = !!purchaseRequisition;
    const { data, setData, post, put, processing, errors } = useForm({
        company_id: editing ? String(purchaseRequisition.company_id) : (companies[0]?.id ? String(companies[0].id) : ''),
        project_id: editing && purchaseRequisition.project_id ? String(purchaseRequisition.project_id) : '',
        department_id: editing && purchaseRequisition.department_id ? String(purchaseRequisition.department_id) : '',
        source_material_request_id: editing && purchaseRequisition.source_material_request_id ? String(purchaseRequisition.source_material_request_id) : '',
        cost_center: purchaseRequisition?.cost_center || '',
        request_date: purchaseRequisition?.request_date?.slice(0, 10) || new Date().toISOString().slice(0, 10),
        priority: purchaseRequisition?.priority || 'medium',
        required_date: purchaseRequisition?.required_date?.slice(0, 10) || '',
        justification: purchaseRequisition?.justification || '',
        notes: purchaseRequisition?.notes || '',
        items: purchaseRequisition?.items?.length ? purchaseRequisition.items : [{ ...BLANK_ITEM }],
    });

    const estimatedTotal = data.items.reduce((sum, i) => sum + (Number(i.quantity) || 0) * (Number(i.estimated_unit_price) || 0), 0);

    function updateItem(i, field, value) {
        const items = [...data.items];
        items[i] = { ...items[i], [field]: value };
        setData('items', items);
    }

    function submit(e) {
        e.preventDefault();
        if (editing) { put(route('purchase-requisitions.update', purchaseRequisition.id)); } else { post(route('purchase-requisitions.store')); }
    }

    return (
        <AuthenticatedLayout>
            <Head title={editing ? purchaseRequisition.pr_number : 'New Purchase Requisition'} />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('purchase-requisitions.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <Card>
                    <CardHeader><CardTitle>{editing ? purchaseRequisition.pr_number : `New Purchase Requisition -- ${prNumber}`}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-3 gap-3">
                            <div className="space-y-1.5"><Label>Request Date</Label><Input type="date" value={data.request_date} onChange={(e) => setData('request_date', e.target.value)} /></div>
                            <div className="space-y-1.5">
                                <Label>Priority</Label>
                                <Select value={data.priority} onValueChange={(v) => setData('priority', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{priorities.map((p) => <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5"><Label>Required Date</Label><Input type="date" value={data.required_date} onChange={(e) => setData('required_date', e.target.value)} /></div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Department (optional)</Label>
                                <Select value={data.department_id || 'none'} onValueChange={(v) => setData('department_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">None</SelectItem>{departments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
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
                                <Label>Source Material Request (optional)</Label>
                                <Select value={data.source_material_request_id || 'none'} onValueChange={(v) => setData('source_material_request_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">None</SelectItem>{materialRequests.map((m) => <SelectItem key={m.id} value={String(m.id)}>{m.request_number}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1.5"><Label>Justification</Label><Textarea value={data.justification} onChange={(e) => setData('justification', e.target.value)} rows={2} /></div>
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
                            <TableHeader><TableRow><TableHead className="min-w-[180px]">Description</TableHead><TableHead className="min-w-[160px]">Specification</TableHead><TableHead className="w-24">Qty</TableHead><TableHead className="w-24">Unit</TableHead><TableHead className="w-36">Est. Unit Price</TableHead><TableHead className="w-36">Line Total</TableHead><TableHead /></TableRow></TableHeader>
                            <TableBody>
                                {data.items.map((item, i) => (
                                    <TableRow key={i}>
                                        <TableCell><Input value={item.description} onChange={(e) => updateItem(i, 'description', e.target.value)} /></TableCell>
                                        <TableCell><Input value={item.specification} onChange={(e) => updateItem(i, 'specification', e.target.value)} /></TableCell>
                                        <TableCell><Input type="number" min="0.01" step="0.01" value={item.quantity} onChange={(e) => updateItem(i, 'quantity', e.target.value)} /></TableCell>
                                        <TableCell><Input value={item.unit} onChange={(e) => updateItem(i, 'unit', e.target.value)} /></TableCell>
                                        <TableCell><Input type="number" min="0" step="0.01" value={item.estimated_unit_price} onChange={(e) => updateItem(i, 'estimated_unit_price', e.target.value)} /></TableCell>
                                        <TableCell>{((Number(item.quantity) || 0) * (Number(item.estimated_unit_price) || 0)).toLocaleString('id-ID')}</TableCell>
                                        <TableCell><Button type="button" variant="ghost" size="icon" onClick={() => setData('items', data.items.filter((_, idx) => idx !== i))}><Trash2 className="h-4 w-4 text-red-500" /></Button></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <p className="mt-3 text-right text-sm font-semibold">Estimated Total: {estimatedTotal.toLocaleString('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 })}</p>
                    </CardContent>
                </Card>

                <Button type="submit" disabled={processing}>{editing ? 'Save Changes' : 'Create PR'}</Button>
            </form>
        </AuthenticatedLayout>
    );
}
