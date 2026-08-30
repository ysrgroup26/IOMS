import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { assessRisk } from '@/lib/riskMatrix';

/**
 * v1.10.9 (HSE Domain Hardening, Part H/I): JSA never had a risk matrix at
 * all -- `steps` only ever carried task_step/potential_hazard/
 * control_measure (see the owning migration's own doc comment). Extended
 * here with consequence/likelihood/severity/additional_controls/
 * residual_likelihood/residual_severity/pic -- same shape as HIRADC's own
 * `items` (this migration is a JSON column, so this is a pure application-
 * level shape change, no schema migration needed; old steps missing these
 * keys just fall back to blanks/1, handled explicitly below, not a
 * breaking change for existing JSA records).
 */
const BLANK_STEP = {
    task_step: '', potential_hazard: '', consequence: '', control_measure: '',
    likelihood: 1, severity: 1,
    additional_controls: '', residual_likelihood: 1, residual_severity: 1,
    pic: '',
};

/** Same shared engine RiskAssessments/Form.jsx uses -- one risk matrix, not two. */
function RiskBadge({ likelihood, severity }) {
    const { score, label, badge } = assessRisk(likelihood, severity);

    return <Badge variant={badge}>{score ?? '-'} {label !== '-' && `· ${label}`}</Badge>;
}

export default function JobSafetyAnalysisForm({ jsa, companies, projects, jsaNumber }) {
    const editing = !!jsa;
    const { data, setData, post, put, processing, errors } = useForm({
        company_id: editing ? String(jsa.company_id) : (companies[0]?.id ? String(companies[0].id) : ''),
        project_id: editing && jsa.project_id ? String(jsa.project_id) : '',
        job_title: jsa?.job_title || '',
        location: jsa?.location || '',
        jsa_date: jsa?.jsa_date?.slice(0, 10) || new Date().toISOString().slice(0, 10),
        required_ppe: jsa?.required_ppe || [],
        // v1.10.9: existing JSA records saved before the risk matrix
        // fields existed won't have consequence/likelihood/etc. at all --
        // merge each saved step onto BLANK_STEP's shape rather than
        // trusting the saved keys alone, so an old record opens with
        // real, editable defaults (1/1) instead of `undefined` silently
        // breaking the number inputs.
        steps: jsa?.steps?.length ? jsa.steps.map((s) => ({ ...BLANK_STEP, ...s })) : [{ ...BLANK_STEP }],
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
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5"><Label>Date</Label><Input type="date" value={data.jsa_date} onChange={(e) => setData('jsa_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Location</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} /></div>
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
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
                        <div>
                            <CardTitle>Task Steps &amp; Risk Matrix</CardTitle>
                            <p className="text-xs text-graphite-500 dark:text-slate-400">Each step: what could go wrong, how risky before controls, what closes the gap, and what risk remains.</p>
                        </div>
                        <Button type="button" variant="outline" size="sm" onClick={addStep}><Plus className="h-4 w-4" /> Add Step</Button>
                    </CardHeader>
                    {/* v1.10.9: a 13-column table (step/hazard/consequence/
                        controls/L/S/risk/add'l controls/res.L/res.S/
                        residual risk/pic/delete) would be exactly the
                        "spreadsheet" this was explicitly asked not to
                        become -- one stacked card per step instead,
                        matching Part K's own STEP -> HAZARD -> CONSEQUENCE
                        -> INITIAL RISK -> CONTROLS -> RESIDUAL RISK
                        vertical structure, and still fully usable at
                        mobile width. */}
                    <CardContent className="space-y-4">
                        {data.steps.map((step, i) => (
                            <div key={i} className="rounded-lg border border-graphite-200 p-3.5 dark:border-slate-700">
                                <div className="mb-3 flex items-center justify-between">
                                    <span className="text-xs font-semibold uppercase tracking-wide text-graphite-400">Step {i + 1}</span>
                                    <Button type="button" variant="ghost" size="icon" onClick={() => removeStep(i)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                </div>

                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="space-y-1"><Label className="text-xs">Job Step</Label><Input value={step.task_step} onChange={(e) => updateStep(i, 'task_step', e.target.value)} /></div>
                                    <div className="space-y-1"><Label className="text-xs">Hazard</Label><Input value={step.potential_hazard} onChange={(e) => updateStep(i, 'potential_hazard', e.target.value)} /></div>
                                    <div className="space-y-1 sm:col-span-2"><Label className="text-xs">Potential Consequence</Label><Input value={step.consequence} onChange={(e) => updateStep(i, 'consequence', e.target.value)} placeholder="e.g. Eye injury" /></div>
                                    <div className="space-y-1 sm:col-span-2"><Label className="text-xs">Existing / Current Controls</Label><Input value={step.control_measure} onChange={(e) => updateStep(i, 'control_measure', e.target.value)} /></div>
                                </div>

                                <div className="mt-3 flex flex-wrap items-end gap-3 rounded-md bg-graphite-50 p-2.5 dark:bg-slate-800/60">
                                    <span className="text-xs font-semibold uppercase text-graphite-400">Initial Risk</span>
                                    <div className="space-y-1"><Label className="text-[11px]">Likelihood</Label><Input type="number" min="1" max="5" className="w-16" value={step.likelihood} onChange={(e) => updateStep(i, 'likelihood', e.target.value)} /></div>
                                    <span className="pb-2 text-graphite-400">×</span>
                                    <div className="space-y-1"><Label className="text-[11px]">Severity</Label><Input type="number" min="1" max="5" className="w-16" value={step.severity} onChange={(e) => updateStep(i, 'severity', e.target.value)} /></div>
                                    <span className="pb-2 text-graphite-400">=</span>
                                    <RiskBadge likelihood={step.likelihood} severity={step.severity} />
                                </div>

                                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="space-y-1 sm:col-span-2"><Label className="text-xs">Additional Controls</Label><Input value={step.additional_controls} onChange={(e) => updateStep(i, 'additional_controls', e.target.value)} placeholder="e.g. Face shield + exclusion zone" /></div>
                                </div>

                                <div className="mt-3 flex flex-wrap items-end gap-3 rounded-md bg-graphite-50 p-2.5 dark:bg-slate-800/60">
                                    <span className="text-xs font-semibold uppercase text-graphite-400">Residual Risk</span>
                                    <div className="space-y-1"><Label className="text-[11px]">Likelihood</Label><Input type="number" min="1" max="5" className="w-16" value={step.residual_likelihood} onChange={(e) => updateStep(i, 'residual_likelihood', e.target.value)} /></div>
                                    <span className="pb-2 text-graphite-400">×</span>
                                    <div className="space-y-1"><Label className="text-[11px]">Severity</Label><Input type="number" min="1" max="5" className="w-16" value={step.residual_severity} onChange={(e) => updateStep(i, 'residual_severity', e.target.value)} /></div>
                                    <span className="pb-2 text-graphite-400">=</span>
                                    <RiskBadge likelihood={step.residual_likelihood} severity={step.residual_severity} />
                                </div>

                                <div className="mt-3 space-y-1 sm:w-1/2"><Label className="text-xs">Responsible Person</Label><Input value={step.pic} onChange={(e) => updateStep(i, 'pic', e.target.value)} /></div>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Button type="submit" disabled={processing}>{editing ? 'Save Changes' : 'Create JSA'}</Button>
            </form>
        </AuthenticatedLayout>
    );
}
