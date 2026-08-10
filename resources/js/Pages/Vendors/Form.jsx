import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function VendorForm({ vendor, companies, types, vendorCode }) {
    const editing = !!vendor;
    const { data, setData, post, put, processing, errors } = useForm({
        company_id: editing ? String(vendor.company_id) : (companies[0]?.id ? String(companies[0].id) : ''),
        name: vendor?.name || '',
        type: vendor?.type || 'goods',
        legal_entity_name: vendor?.legal_entity_name || '',
        address: vendor?.address || '',
        city: vendor?.city || '',
        province: vendor?.province || '',
        country: vendor?.country || 'Indonesia',
        pic_name: vendor?.pic_name || '',
        pic_phone: vendor?.pic_phone || '',
        pic_email: vendor?.pic_email || '',
        website: vendor?.website || '',
        npwp: vendor?.npwp || '',
        nib: vendor?.nib || '',
        bank_name: vendor?.bank_name || '',
        bank_account_number: vendor?.bank_account_number || '',
        bank_account_holder: vendor?.bank_account_holder || '',
        payment_terms: vendor?.payment_terms || '',
        tax_info: vendor?.tax_info || '',
        category: vendor?.category || '',
        capability: vendor?.capability || '',
        is_active: vendor?.is_active ?? true,
        notes: vendor?.notes || '',
    });

    function submit(e) {
        e.preventDefault();
        if (editing) { put(route('vendors.update', vendor.id)); } else { post(route('vendors.store')); }
    }

    return (
        <AuthenticatedLayout>
            <Head title={editing ? vendor.name : 'Add Vendor'} />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={editing ? route('vendors.show', vendor.id) : route('vendors.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-3xl space-y-4">
                <Card>
                    <CardHeader><CardTitle>{editing ? `Edit Vendor -- ${vendor.vendor_code}` : `Add Vendor -- ${vendorCode}`}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Vendor Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                            <div className="space-y-1.5">
                                <Label>Type</Label>
                                <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Legal Entity Name</Label><Input value={data.legal_entity_name} onChange={(e) => setData('legal_entity_name', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Category</Label><Input value={data.category} onChange={(e) => setData('category', e.target.value)} placeholder="e.g. Safety Equipment, Spare Parts" /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Address</Label><Textarea value={data.address} onChange={(e) => setData('address', e.target.value)} rows={2} /></div>
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-1.5"><Label>City</Label><Input value={data.city} onChange={(e) => setData('city', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Province</Label><Input value={data.province} onChange={(e) => setData('province', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Country</Label><Input value={data.country} onChange={(e) => setData('country', e.target.value)} /></div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Contact</CardTitle></CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5"><Label>PIC Name</Label><Input value={data.pic_name} onChange={(e) => setData('pic_name', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>PIC Phone</Label><Input value={data.pic_phone} onChange={(e) => setData('pic_phone', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>PIC Email</Label><Input type="email" value={data.pic_email} onChange={(e) => setData('pic_email', e.target.value)} />{errors.pic_email && <p className="text-xs text-red-600">{errors.pic_email}</p>}</div>
                        <div className="space-y-1.5"><Label>Website</Label><Input value={data.website} onChange={(e) => setData('website', e.target.value)} /></div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Business</CardTitle></CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5"><Label>NPWP</Label><Input value={data.npwp} onChange={(e) => setData('npwp', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>NIB</Label><Input value={data.nib} onChange={(e) => setData('nib', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Bank Name</Label><Input value={data.bank_name} onChange={(e) => setData('bank_name', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Bank Account Number</Label><Input value={data.bank_account_number} onChange={(e) => setData('bank_account_number', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Bank Account Holder</Label><Input value={data.bank_account_holder} onChange={(e) => setData('bank_account_holder', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Payment Terms</Label><Input value={data.payment_terms} onChange={(e) => setData('payment_terms', e.target.value)} placeholder="e.g. Net 30" /></div>
                        <div className="space-y-1.5"><Label>Tax Info</Label><Input value={data.tax_info} onChange={(e) => setData('tax_info', e.target.value)} /></div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Capability &amp; Notes</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5"><Label>Capability</Label><Textarea value={data.capability} onChange={(e) => setData('capability', e.target.value)} rows={2} /></div>
                        <div className="space-y-1.5"><Label>Notes</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <label className="flex items-center gap-2 text-sm"><Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} /> Active</label>
                    </CardContent>
                </Card>

                <Button type="submit" disabled={processing}>{editing ? 'Save Changes' : 'Add Vendor'}</Button>
            </form>
        </AuthenticatedLayout>
    );
}
