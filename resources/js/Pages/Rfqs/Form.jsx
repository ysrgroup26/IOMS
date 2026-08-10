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

export default function RfqForm({ companies, purchaseRequisitions, vendors, rfqNumber, preselectedPrId }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        purchase_requisition_id: preselectedPrId ? String(preselectedPrId) : '',
        issue_date: new Date().toISOString().slice(0, 10),
        quotation_deadline: new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10),
        currency: 'IDR',
        delivery_location: '',
        delivery_requirement: '',
        payment_terms: '',
        notes: '',
        vendor_ids: [],
    });

    function toggleVendor(id) {
        setData('vendor_ids', data.vendor_ids.includes(id) ? data.vendor_ids.filter((x) => x !== id) : [...data.vendor_ids, id]);
    }

    function submit(e) {
        e.preventDefault();
        post(route('rfqs.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="New RFQ" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('rfqs.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>New RFQ -- {rfqNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Purchase Requisition</Label>
                            <Select value={data.purchase_requisition_id} onValueChange={(v) => setData('purchase_requisition_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select an approved PR" /></SelectTrigger>
                                <SelectContent>{purchaseRequisitions.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.pr_number}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.purchase_requisition_id && <p className="text-xs text-red-600">{errors.purchase_requisition_id}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5"><Label>Issue Date</Label><Input type="date" value={data.issue_date} onChange={(e) => setData('issue_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Quotation Deadline</Label><Input type="date" value={data.quotation_deadline} onChange={(e) => setData('quotation_deadline', e.target.value)} />{errors.quotation_deadline && <p className="text-xs text-red-600">{errors.quotation_deadline}</p>}</div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5"><Label>Delivery Location</Label><Input value={data.delivery_location} onChange={(e) => setData('delivery_location', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Currency</Label><Input value={data.currency} onChange={(e) => setData('currency', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Delivery Requirement</Label><Textarea value={data.delivery_requirement} onChange={(e) => setData('delivery_requirement', e.target.value)} rows={2} /></div>
                        <div className="space-y-1.5"><Label>Payment Terms</Label><Input value={data.payment_terms} onChange={(e) => setData('payment_terms', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Notes</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Invite Vendors ({data.vendor_ids.length} selected)</Label>
                            <div className="max-h-64 overflow-y-auto rounded-md border border-graphite-200 p-2">
                                {vendors.map((v) => (
                                    <label key={v.id} className="flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-graphite-50">
                                        <Checkbox checked={data.vendor_ids.includes(v.id)} onCheckedChange={() => toggleVendor(v.id)} />
                                        {v.name} <span className="text-graphite-400">({v.vendor_code})</span>
                                    </label>
                                ))}
                            </div>
                            {errors.vendor_ids && <p className="text-xs text-red-600">{errors.vendor_ids}</p>}
                        </div>
                        <Button type="submit" disabled={processing} className="w-full">Issue RFQ</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
