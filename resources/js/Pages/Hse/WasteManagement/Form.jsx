import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

/** v1.11.4 (HSE Waste Management, Part 13/14). Create a Waste Record -- reuses the existing Project table for source, no separate source system. */
export default function WasteRecordForm({ wasteTypes, storageLocations, projects, companies }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        waste_type_id: '', project_id: '', location: '', storage_location_id: '',
        quantity: '', unit: 'kg', container: '', generated_date: new Date().toISOString().slice(0, 10),
        received_date: '', notes: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('waste-records.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="New Waste Record" />

            <Link href={route('waste-records.index')} className="mb-3 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Waste Records
            </Link>

            <PageHeader title="New Waste Record" subtitle="Catat kejadian timbulan limbah baru." />

            <Card>
                <CardContent className="p-4">
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Waste Type</Label>
                                <Select value={data.waste_type_id} onValueChange={(v) => setData('waste_type_id', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select waste type" /></SelectTrigger>
                                    <SelectContent>{wasteTypes.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name} ({t.category === 'b3' ? 'B3' : 'Non-B3'})</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.waste_type_id && <p className="text-xs text-red-600">{errors.waste_type_id}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Project (optional)</Label>
                                <Select value={data.project_id || '__none'} onValueChange={(v) => setData('project_id', v === '__none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="Not linked to a project" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none">Not linked</SelectItem>
                                        {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1.5"><Label>Location / Work Area (optional)</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} placeholder="e.g. Workshop B" /></div>
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-1.5">
                                <Label>Quantity</Label>
                                <Input type="number" min="0.01" step="0.01" value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                                {errors.quantity && <p className="text-xs text-red-600">{errors.quantity}</p>}
                            </div>
                            <div className="space-y-1.5"><Label>Unit</Label><Input value={data.unit} onChange={(e) => setData('unit', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Container (optional)</Label><Input value={data.container} onChange={(e) => setData('container', e.target.value)} placeholder="drum, IBC" /></div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Generated Date</Label><Input type="date" value={data.generated_date} onChange={(e) => setData('generated_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Received / Stored Date (optional)</Label><Input type="date" value={data.received_date} onChange={(e) => setData('received_date', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Storage Location / TPS (optional)</Label>
                            <Select value={data.storage_location_id || '__none'} onValueChange={(v) => setData('storage_location_id', v === '__none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="Not yet stored" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none">Not yet stored</SelectItem>
                                    {storageLocations.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Notes (optional)</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <div className="flex justify-end gap-2">
                            <Link href={route('waste-records.index')}><Button type="button" variant="outline">Cancel</Button></Link>
                            <Button type="submit" disabled={processing}>Save Waste Record</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
