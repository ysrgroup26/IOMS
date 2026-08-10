import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function AssetForm({ companies, vendors, purchaseOrders, employees, categories, assetCode, prefill }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: prefill?.company_id ? String(prefill.company_id) : (companies[0]?.id ? String(companies[0].id) : ''),
        name: '', category: categories[0] || '', serial_number: '', brand: '', model: '',
        purchase_date: prefill?.purchase_date || '', vendor_id: prefill?.vendor_id ? String(prefill.vendor_id) : '',
        purchase_order_id: prefill?.purchase_order_id ? String(prefill.purchase_order_id) : '',
        location: '', responsible_employee_id: '', notes: '', attachment: null,
    });

    function submit(e) {
        e.preventDefault();
        post(route('assets.store'), { forceFormData: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Register Asset" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('assets.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-2xl">
                <Card>
                    <CardHeader><CardTitle>Register Asset -- {assetCode}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select value={data.category} onValueChange={(v) => setData('category', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{categories.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-1.5"><Label>Serial Number</Label><Input value={data.serial_number} onChange={(e) => setData('serial_number', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Brand</Label><Input value={data.brand} onChange={(e) => setData('brand', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Model</Label><Input value={data.model} onChange={(e) => setData('model', e.target.value)} /></div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Purchase Date</Label><Input type="date" value={data.purchase_date} onChange={(e) => setData('purchase_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Location</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} /></div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Vendor (optional)</Label>
                                <Select value={data.vendor_id || 'none'} onValueChange={(v) => setData('vendor_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">None</SelectItem>{vendors.map((v) => <SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Source PO (optional)</Label>
                                <Select value={data.purchase_order_id || 'none'} onValueChange={(v) => setData('purchase_order_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">None</SelectItem>{purchaseOrders.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.po_number}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Responsible Employee (optional)</Label>
                            <Select value={data.responsible_employee_id || 'none'} onValueChange={(v) => setData('responsible_employee_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="Unassigned" /></SelectTrigger>
                                <SelectContent><SelectItem value="none">Unassigned</SelectItem>{employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.full_name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Notes</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <div className="space-y-1.5"><Label>Attachment</Label><Input type="file" onChange={(e) => setData('attachment', e.target.files[0])} /></div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <Button type="submit" disabled={processing} className="w-full">Register Asset</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
