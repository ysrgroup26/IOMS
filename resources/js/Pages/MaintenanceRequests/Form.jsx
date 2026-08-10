import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function MaintenanceRequestForm({ assets, requestNumber, priorities }) {
    const { data, setData, post, processing, errors } = useForm({
        asset_id: '', problem: '', description: '', priority: 'medium',
        request_date: new Date().toISOString().slice(0, 10), attachment: null,
    });

    function submit(e) {
        e.preventDefault();
        post(route('maintenance-requests.store'), { forceFormData: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Report Maintenance Problem" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('maintenance-requests.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>Report Maintenance Problem -- {requestNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Asset</Label>
                            <Select value={data.asset_id} onValueChange={(v) => setData('asset_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select asset" /></SelectTrigger>
                                <SelectContent>{assets.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.name} ({a.asset_code})</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.asset_id && <p className="text-xs text-red-600">{errors.asset_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Problem</Label>
                            <Input value={data.problem} onChange={(e) => setData('problem', e.target.value)} placeholder="Brief summary" />
                            {errors.problem && <p className="text-xs text-red-600">{errors.problem}</p>}
                        </div>
                        <div className="space-y-1.5"><Label>Description</Label><Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={3} /></div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Priority</Label>
                                <Select value={data.priority} onValueChange={(v) => setData('priority', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{priorities.map((p) => <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5"><Label>Date</Label><Input type="date" value={data.request_date} onChange={(e) => setData('request_date', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Attachment</Label><Input type="file" onChange={(e) => setData('attachment', e.target.files[0])} /></div>
                        <Button type="submit" disabled={processing} className="w-full">Submit Request</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
