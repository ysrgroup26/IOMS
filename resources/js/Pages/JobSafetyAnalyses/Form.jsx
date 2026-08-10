import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';

const BLANK_STEP = { task_step: '', potential_hazard: '', control_measure: '' };

export default function JobSafetyAnalysisForm({ jsa, companies, projects, jsaNumber }) {
    const editing = !!jsa;
    const { data, setData, post, put, processing, errors } = useForm({
        company_id: editing ? String(jsa.company_id) : (companies[0]?.id ? String(companies[0].id) : ''),
        project_id: editing && jsa.project_id ? String(jsa.project_id) : '',
        job_title: jsa?.job_title || '',
        location: jsa?.location || '',
        jsa_date: jsa?.jsa_date?.slice(0, 10) || new Date().toISOString().slice(0, 10),
        required_ppe: jsa?.required_ppe || [],
        steps: jsa?.steps?.length ? jsa.steps : [{ ...BLANK_STEP }],
    });

    function updateStep(i, field, value) {
        const steps = [...data.steps];
        steps[i] = { ...steps[i], [field]: value };
        setData('steps', steps);
    }

    function addStep() { setData('steps', [...data.steps, { ...BLANK_STEP }]); }
    function removeStep(i) { setData('steps', data.steps.filter((_, idx) => idx !== i)); }

    function submit(e) {
        e.preventDefault();
        if (editing) { put(route('job-safety-analyses.update', jsa.id)); } else { post(route('job-safety-analyses.store')); }
    }

    return (
        <AuthenticatedLayout>
            <Head title={editing ? jsa.jsa_number : 'New JSA'} />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('job-safety-analyses.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <Card>
                    <CardHeader><CardTitle>{editing ? jsa.jsa_number : `New JSA -- ${jsaNumber}`}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Job Title</Label>
                            <Input value={data.job_title} onChange={(e) => setData('job_title', e.target.value)} placeholder="e.g. Confined Space Entry -- Tank Cleaning" />
                            {errors.job_title && <p className="text-xs text-red-600">{errors.job_title}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5"><Label>Date</Label><Input type="date" value={data.jsa_date} onChange={(e) => setData('jsa_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Location</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} /></div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Company</Label>
                                <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Project (optional)</Label>
                                <Select value={data.project_id || 'none'} onValueChange={(v) => setData('project_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="No project" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">No project</SelectItem>{projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Required PPE (comma-separated)</Label>
                            <Input
                                value={data.required_ppe.join(', ')}
                                onChange={(e) => setData('required_ppe', e.target.value.split(',').map((s) => s.trim()).filter(Boolean))}
                                placeholder="e.g. Safety Harness, Gas Detector, Face Shield"
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>Task Steps</CardTitle>
                        <Button type="button" variant="outline" size="sm" onClick={addStep}><Plus className="h-4 w-4" /> Add Step</Button>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader><TableRow><TableHead className="w-10">#</TableHead><TableHead className="min-w-[180px]">Task Step</TableHead><TableHead className="min-w-[180px]">Potential Hazard</TableHead><TableHead className="min-w-[180px]">Control Measure</TableHead><TableHead /></TableRow></TableHeader>
                            <TableBody>
                                {data.steps.map((step, i) => (
                                    <TableRow key={i}>
                                        <TableCell>{i + 1}</TableCell>
                                        <TableCell><Input value={step.task_step} onChange={(e) => updateStep(i, 'task_step', e.target.value)} /></TableCell>
                                        <TableCell><Input value={step.potential_hazard} onChange={(e) => updateStep(i, 'potential_hazard', e.target.value)} /></TableCell>
                                        <TableCell><Input value={step.control_measure} onChange={(e) => updateStep(i, 'control_measure', e.target.value)} /></TableCell>
                                        <TableCell><Button type="button" variant="ghost" size="icon" onClick={() => removeStep(i)}><Trash2 className="h-4 w-4 text-red-500" /></Button></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Button type="submit" disabled={processing}>{editing ? 'Save Changes' : 'Create JSA'}</Button>
            </form>
        </AuthenticatedLayout>
    );
}
