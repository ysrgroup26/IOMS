/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0); the steps table became the visual Approval Flow Builder (A7). */
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Plus, Trash2 } from 'lucide-react';
import { moduleLabel } from '@/lib/moduleLabels';
import ApprovalFlowEditor from './approval-flow/ApprovalFlowEditor';

export default function ApprovalFlowsTab({ approvalFlows, positions = [], users = [], versions = {}, moduleKeys }) {
    const [activeFlowId, setActiveFlowId] = useState(approvalFlows?.[0]?.id ?? null);
    const activeFlow = (approvalFlows ?? []).find((f) => f.id === activeFlowId);
    const createForm = useForm({ module_key: moduleKeys?.[0] ?? '', name: '' });

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

                    <form onSubmit={createFlow} className="flex flex-wrap items-end gap-2 border-t border-graphite-100 pt-4">
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
                            <Input value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} placeholder="e.g. Two-Level Approval" className="w-full sm:w-56" />
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
                        <CardTitle>{activeFlow.name}</CardTitle>
                        <CardDescription>
                            Tahap dijalankan berurutan dari kiri ke kanan. Penyetuju dapat berupa role, posisi dari struktur
                            perusahaan, atau orang tertentu; satu tahap dengan beberapa penyetuju dapat memerlukan salah satu
                            atau semuanya. Menyimpan menerbitkan versi baru; permintaan yang sedang berjalan tetap memakai versi
                            saat permintaan itu dimulai.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ApprovalFlowEditor
                            key={activeFlow.id}
                            flow={activeFlow}
                            positions={positions}
                            users={users}
                            versionInfo={versions[activeFlow.id]}
                        />
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
