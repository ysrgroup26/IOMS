import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft } from 'lucide-react';

export default function IncidentForm({ companies, projects, incidentNumber, severities, categories }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        incident_date: new Date().toISOString().slice(0, 10),
        location: '',
        severity: 'minor',
        category: 'near_miss',
        company_id: '',
        project_id: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('incidents.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Report Incident" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('incidents.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl">
                <Card>
                    <CardHeader><CardTitle>Report Incident -- {incidentNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Title</Label>
                            <Input value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="Brief summary of what happened" />
                            {errors.title && <p className="text-xs text-red-600">{errors.title}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Description</Label>
                            <Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={4} />
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Date</Label>
                                <Input type="date" value={data.incident_date} onChange={(e) => setData('incident_date', e.target.value)} />
                                {errors.incident_date && <p className="text-xs text-red-600">{errors.incident_date}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Location</Label>
                                <Input value={data.location} onChange={(e) => setData('location', e.target.value)} placeholder="Where it happened" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Severity</Label>
                                <Select value={data.severity} onValueChange={(v) => setData('severity', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {severities.map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select value={data.category} onValueChange={(v) => setData('category', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {categories.map((c) => <SelectItem key={c} value={c} className="capitalize">{c.replace('_', ' ')}</SelectItem>)}
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
                            <Label>Company (optional)</Label>
                            <Select value={data.company_id ? String(data.company_id) : 'none'} onValueChange={(v) => setData('company_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="No company" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">No company</SelectItem>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <Button type="submit" disabled={processing} className="w-full">Report Incident</Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
