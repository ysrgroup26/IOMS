import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function NcrForm({ companies, ncrNumber, severities, prefill }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        description: prefill?.description || '',
        severity: 'minor',
        responsible_party: '',
        raised_date: new Date().toISOString().slice(0, 10),
        source_type: prefill?.source_type || '',
        source_id: prefill?.source_id || '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('ncrs.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Raise NCR" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('ncrs.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>Raise NCR -- {ncrNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Description</Label>
                            <Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={3} />
                            {errors.description && <p className="text-xs text-red-600">{errors.description}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Severity</Label>
                                <Select value={data.severity} onValueChange={(v) => setData('severity', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{severities.map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5"><Label>Date</Label><Input type="date" value={data.raised_date} onChange={(e) => setData('raised_date', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Responsible Party</Label><Input value={data.responsible_party} onChange={(e) => setData('responsible_party', e.target.value)} /></div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <Button type="submit" disabled={processing} className="w-full">Raise NCR</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
