import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function WorkOrderForm({ assets, employees, woNumber, types, preselectedMr }) {
    const { data, setData, post, processing, errors } = useForm({
        asset_id: preselectedMr?.asset_id ? String(preselectedMr.asset_id) : '',
        maintenance_request_id: preselectedMr?.id ? String(preselectedMr.id) : '',
        maintenance_type: 'corrective',
        technician_id: '',
        planned_date: new Date().toISOString().slice(0, 10),
        work_description: preselectedMr?.problem || '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('work-orders.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="New Work Order" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('work-orders.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>New Work Order -- {woNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Asset</Label>
                            <Select value={data.asset_id} onValueChange={(v) => setData('asset_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select asset" /></SelectTrigger>
                                <SelectContent>{assets.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.name} ({a.asset_code})</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.asset_id && <p className="text-xs text-red-600">{errors.asset_id}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Maintenance Type</Label>
                                <Select value={data.maintenance_type} onValueChange={(v) => setData('maintenance_type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5"><Label>Planned Date</Label><Input type="date" value={data.planned_date} onChange={(e) => setData('planned_date', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Technician (optional)</Label>
                            <Select value={data.technician_id || 'none'} onValueChange={(v) => setData('technician_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="Unassigned" /></SelectTrigger>
                                <SelectContent><SelectItem value="none">Unassigned</SelectItem>{employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.full_name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Work Description</Label><Textarea value={data.work_description} onChange={(e) => setData('work_description', e.target.value)} rows={3} /></div>
                        <Button type="submit" disabled={processing} className="w-full">Create Work Order</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
