import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import MultiImageUpload from '@/Components/shared/MultiImageUpload';
import { ArrowLeft } from 'lucide-react';

export default function SafetyObservationForm({ companies, projects, hazardCategories, observationNumber, types, severities }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        project_id: '',
        hazard_category_id: '',
        observed_at: new Date().toISOString().slice(0, 16),
        location: '',
        type: 'unsafe_condition',
        description: '',
        immediate_action: '',
        severity: '',
        photos: [],
    });

    function submit(e) {
        e.preventDefault();
        post(route('safety-observations.store'), { forceFormData: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Report Safety Observation" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('safety-observations.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>Report Safety Observation -- {observationNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Type</Label>
                            <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Description</Label>
                            <Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={4} placeholder="What was observed" />
                            {errors.description && <p className="text-xs text-red-600">{errors.description}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Immediate Action Taken (optional)</Label>
                            <Textarea value={data.immediate_action} onChange={(e) => setData('immediate_action', e.target.value)} rows={2} />
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Date &amp; Time</Label>
                                <Input type="datetime-local" value={data.observed_at} onChange={(e) => setData('observed_at', e.target.value)} />
                                {errors.observed_at && <p className="text-xs text-red-600">{errors.observed_at}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Location</Label>
                                <Input value={data.location} onChange={(e) => setData('location', e.target.value)} placeholder="Where it was observed" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Hazard Category (optional)</Label>
                                <Select value={data.hazard_category_id || 'none'} onValueChange={(v) => setData('hazard_category_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">None</SelectItem>
                                        {hazardCategories.map((h) => <SelectItem key={h.id} value={String(h.id)}>{h.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Severity (optional)</Label>
                                <Select value={data.severity || 'none'} onValueChange={(v) => setData('severity', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="Not applicable" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Not applicable</SelectItem>
                                        {severities.map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Project (optional)</Label>
                            <Select value={data.project_id ? String(data.project_id) : 'none'} onValueChange={(v) => setData('project_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="No project" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">No project</SelectItem>
                                    {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>

                        <MultiImageUpload
                            label="Photo Evidence (optional)"
                            files={data.photos}
                            onFilesChange={(files) => setData('photos', files)}
                            error={errors.photos}
                        />

                        <Button type="submit" disabled={processing} className="w-full">Report Observation</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
