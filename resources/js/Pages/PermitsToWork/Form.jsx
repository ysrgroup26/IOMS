import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function PermitToWorkForm({ companies, projects, riskAssessments, jsas, ptwNumber, types }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        project_id: '',
        risk_assessment_id: '',
        jsa_id: '',
        permit_type: 'hot_work',
        work_description: '',
        location: '',
        start_datetime: new Date().toISOString().slice(0, 16),
        end_datetime: new Date(Date.now() + 8 * 3600 * 1000).toISOString().slice(0, 16),
        required_qualification: '',
        precautions: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('permits-to-work.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="New Permit To Work" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('permits-to-work.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>New Permit To Work -- {ptwNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Permit Type</Label>
                            <Select value={data.permit_type} onValueChange={(v) => setData('permit_type', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Work Description</Label>
                            <Textarea value={data.work_description} onChange={(e) => setData('work_description', e.target.value)} rows={3} />
                            {errors.work_description && <p className="text-xs text-red-600">{errors.work_description}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Location</Label>
                            <Input value={data.location} onChange={(e) => setData('location', e.target.value)} />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Start</Label>
                                <Input type="datetime-local" value={data.start_datetime} onChange={(e) => setData('start_datetime', e.target.value)} />
                                {errors.start_datetime && <p className="text-xs text-red-600">{errors.start_datetime}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>End</Label>
                                <Input type="datetime-local" value={data.end_datetime} onChange={(e) => setData('end_datetime', e.target.value)} />
                                {errors.end_datetime && <p className="text-xs text-red-600">{errors.end_datetime}</p>}
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Required Qualification (optional)</Label>
                            <Input value={data.required_qualification} onChange={(e) => setData('required_qualification', e.target.value)} placeholder="e.g. Confined Space Entry Certificate -- informational only, not auto-checked" />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Precautions (optional)</Label>
                            <Textarea value={data.precautions} onChange={(e) => setData('precautions', e.target.value)} rows={2} />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Linked HIRADC (optional)</Label>
                                <Select value={data.risk_assessment_id || 'none'} onValueChange={(v) => setData('risk_assessment_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">None</SelectItem>{riskAssessments.map((r) => <SelectItem key={r.id} value={String(r.id)}>{r.ra_number}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Linked JSA (optional)</Label>
                                <Select value={data.jsa_id || 'none'} onValueChange={(v) => setData('jsa_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">None</SelectItem>{jsas.map((j) => <SelectItem key={j.id} value={String(j.id)}>{j.jsa_number}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Project (optional)</Label>
                            <Select value={data.project_id || 'none'} onValueChange={(v) => setData('project_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="No project" /></SelectTrigger>
                                <SelectContent><SelectItem value="none">No project</SelectItem>{projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <Button type="submit" disabled={processing} className="w-full">Create Permit</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
