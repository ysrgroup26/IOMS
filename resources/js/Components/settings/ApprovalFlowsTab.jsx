/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Plus, Trash2, Loader2 } from 'lucide-react';
import { moduleLabel } from '@/lib/moduleLabels';
import { ROLE_LABELS, APPROVAL_ROLE_OPTIONS } from './shared';

/*
 * v2.141.0 -- a step's approver is ONE choice: an IOMS role, or a position
 * from the company's own structure (Settings -> Positions). The select
 * encodes it as `role:<key>` / `position:<id>`; the form still sends the two
 * columns the server validates.
 */
const approverValue = (step) => (step.approver_position_id ? `position:${step.approver_position_id}` : `role:${step.approver_role ?? ''}`);
const approverPatch = (value) => {
    const [kind, id] = value.split(':');
    return kind === 'position'
        ? { approver_position_id: Number(id), approver_role: null }
        : { approver_role: id, approver_position_id: null };
};

export default function ApprovalFlowsTab({ approvalFlows, positions = [], moduleKeys }) {
    const [activeFlowId, setActiveFlowId] = useState(approvalFlows?.[0]?.id ?? null);
    const activeFlow = (approvalFlows ?? []).find((f) => f.id === activeFlowId);
    const createForm = useForm({ module_key: moduleKeys?.[0] ?? '', name: '' });
    const stepsForm = useForm({ steps: activeFlow?.steps ?? [] });

    useEffect(() => {
        stepsForm.setData('steps', (approvalFlows ?? []).find((f) => f.id === activeFlowId)?.steps ?? []);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeFlowId]);

    function createFlow(e) {
        e.preventDefault();
        createForm.post(route('settings.approval-flows.store'), { preserveScroll: true, onSuccess: () => createForm.reset('name') });
    }

    function deleteFlow(flow) {
        if (confirm(`Remove the "${flow.name}" flow? ${flow.module_key} will go back to standard single-step approval.`)) {
            router.delete(route('settings.approval-flows.destroy', flow.id), { preserveScroll: true });
            if (activeFlowId === flow.id) setActiveFlowId(null);
        }
    }

    function addStep() {
        const nextStepNumber = (stepsForm.data.steps.at(-1)?.step_number ?? 0) + 1;
        stepsForm.setData('steps', [...stepsForm.data.steps, { step_number: nextStepNumber, mode: 'single', approver_role: 'manager', escalate_after_hours: '', escalate_to_role: '' }]);
    }

    function updateStep(index, patch) {
        stepsForm.setData('steps', stepsForm.data.steps.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    }

    function removeStep(index) {
        stepsForm.setData('steps', stepsForm.data.steps.filter((_, i) => i !== index));
    }

    function saveSteps(e) {
        e.preventDefault();
        stepsForm.put(route('settings.approval-flows.steps', activeFlowId), { preserveScroll: true });
    }

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader>
                    <CardTitle>Approval Flows</CardTitle>
                    <CardDescription>
                        Multi-level, parallel, or escalating approval chains per module. A module without a
                        flow here keeps its standard single-step approval.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="flex flex-wrap items-center gap-2">
                        {(approvalFlows ?? []).map((flow) => (
                            <div key={flow.id} className="inline-flex items-center">
                                <button
                                    type="button"
                                    onClick={() => setActiveFlowId(flow.id)}
                                    className={`rounded-l-md px-3 py-1.5 text-sm font-medium ${flow.id === activeFlowId ? 'bg-brand-50 text-brand-700' : 'bg-graphite-100 text-graphite-600 hover:bg-graphite-200'}`}
                                >
                                    {flow.name} <span className="text-xs text-graphite-400">({flow.module_key})</span>
                                </button>
                                <button type="button" onClick={() => deleteFlow(flow)} className="rounded-r-md bg-graphite-100 px-1.5 py-1.5 text-graphite-400 hover:bg-red-50 hover:text-red-600" title="Delete this flow">
                                    <Trash2 className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        ))}
                        {(approvalFlows ?? []).length === 0 && <p className="text-sm text-graphite-400">No custom approval flows configured yet.</p>}
                    </div>

                    <form onSubmit={createFlow} className="flex items-end gap-2 border-t border-graphite-100 pt-4">
                        <div className="space-y-1.5">
                            <Label>Module</Label>
                            <Select value={createForm.data.module_key} onValueChange={(v) => createForm.setData('module_key', v)}>
                                <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {(moduleKeys ?? []).map((k) => <SelectItem key={k} value={k}>{moduleLabel(k)}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Flow Name</Label>
                            <Input value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} placeholder="e.g. Two-Level Approval" className="w-56" />
                        </div>
                        <Button type="submit" disabled={createForm.processing || !createForm.data.name}>
                            <Plus className="h-4 w-4" /> Create Flow
                        </Button>
                    </form>
                </CardContent>
            </Card>

            {activeFlow && (
                <Card>
                    <CardHeader>
                        <CardTitle>Steps for "{activeFlow.name}"</CardTitle>
                        <CardDescription>
                            Langkah dijalankan berurutan. Untuk langkah paralel, gunakan nomor langkah yang sama pada beberapa baris:
                            "any" cukup satu penyetuju, "all" memerlukan semuanya. Penyetuju dapat berupa role atau posisi dari
                            struktur perusahaan. Menyimpan menerbitkan versi baru; permintaan yang sedang berjalan tetap memakai
                            versi saat permintaan itu dimulai.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={saveSteps} className="space-y-3">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-16">Step</TableHead>
                                            <TableHead className="w-32">Mode</TableHead>
                                            <TableHead className="w-48">Approver</TableHead>
                                            <TableHead className="w-28">Escalate After (h)</TableHead>
                                            <TableHead className="w-32">Escalate To</TableHead>
                                            <TableHead />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {stepsForm.data.steps.map((step, i) => (
                                            <TableRow key={i}>
                                                <TableCell>
                                                    <Input type="number" min={1} value={step.step_number} onChange={(e) => updateStep(i, { step_number: Number(e.target.value) })} />
                                                </TableCell>
                                                <TableCell>
                                                    <Select value={step.mode} onValueChange={(v) => updateStep(i, { mode: v })}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="single">Single</SelectItem>
                                                            <SelectItem value="parallel_any">Parallel (any)</SelectItem>
                                                            <SelectItem value="parallel_all">Parallel (all)</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Select value={approverValue(step)} onValueChange={(v) => updateStep(i, approverPatch(v))}>
                                                        <SelectTrigger aria-label={`Approver for step ${step.step_number}`}><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            {APPROVAL_ROLE_OPTIONS.map((r) => <SelectItem key={`role:${r}`} value={`role:${r}`}>Role: {ROLE_LABELS[r] ?? r}</SelectItem>)}
                                                            {positions.map((p) => (
                                                                <SelectItem key={`position:${p.id}`} value={`position:${p.id}`}>
                                                                    Position: {p.name}{p.company ? ` (${p.company})` : ''}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Input type="number" min={1} value={step.escalate_after_hours ?? ''} onChange={(e) => updateStep(i, { escalate_after_hours: e.target.value ? Number(e.target.value) : null })} placeholder="none" />
                                                </TableCell>
                                                <TableCell>
                                                    <Select value={step.escalate_to_role ?? ''} onValueChange={(v) => updateStep(i, { escalate_to_role: v || null })}>
                                                        <SelectTrigger><SelectValue placeholder="none" /></SelectTrigger>
                                                        <SelectContent>
                                                            {APPROVAL_ROLE_OPTIONS.map((r) => <SelectItem key={r} value={r}>{ROLE_LABELS[r] ?? r}</SelectItem>)}
                                                        </SelectContent>
                                                    </Select>
                                                </TableCell>
                                                <TableCell>
                                                    <Button type="button" variant="ghost" size="icon" onClick={() => removeStep(i)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            <div className="flex gap-2">
                                <Button type="button" variant="outline" onClick={addStep}><Plus className="h-4 w-4" /> Add Step</Button>
                                <Button type="submit" disabled={stepsForm.processing}>
                                    {stepsForm.processing && <Loader2 className="h-4 w-4 animate-spin" />} Save Steps
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
