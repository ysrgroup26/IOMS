import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, Search, CheckCircle2, AlertTriangle } from 'lucide-react';

export default function IncidentShow({ incident: i, activities, canManage, users, investigationMethods }) {
    const [findingOpen, setFindingOpen] = useState(false);

    const investigationForm = useForm({
        method: i.investigation?.method || '5_why',
        root_cause: i.investigation?.root_cause || '',
        findings: i.investigation?.findings || '',
        recommendations: i.investigation?.recommendations || '',
        investigated_at: i.investigation?.investigated_at?.slice(0, 10) || new Date().toISOString().slice(0, 10),
    });
    const findingForm = useForm({ action: '', assigned_to: '', due_date: '', priority: 'medium' });

    function transition(status, confirmMessage) {
        if (!confirm(confirmMessage)) return;
        router.post(route('incidents.transition', i.id), { status });
    }

    function submitInvestigation(e) {
        e.preventDefault();
        investigationForm.post(route('incidents.investigation.store', i.id), { preserveScroll: true });
    }

    function submitFinding(e) {
        e.preventDefault();
        findingForm.post(route('incidents.raise-finding', i.id), { preserveScroll: true, onSuccess: () => { findingForm.reset(); setFindingOpen(false); } });
    }

    return (
        <AuthenticatedLayout>
            <Head title={i.incident_number} />

            <Link href={route('incidents.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Incident Management
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight text-graphite-900">
                        {i.incident_number}
                        <StatusBadge value={i.severity} />
                        <StatusBadge value={i.status} />
                    </h1>
                    <p className="text-xs text-graphite-500">
                        {i.title} · {new Date(i.incident_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}
                        {i.location && ` · ${i.location}`}
                        {i.project && ` · ${i.project.name}`}
                    </p>
                </div>
                {canManage && (
                    <div className="flex items-center gap-2">
                        {i.status === 'reported' && (
                            <Button variant="outline" onClick={() => transition('investigating', 'Start investigating this incident?')}>
                                <Search className="h-4 w-4" /> Start Investigation
                            </Button>
                        )}
                        {i.status !== 'closed' && (
                            <Button onClick={() => transition('closed', 'Close this incident?')}>
                                <CheckCircle2 className="h-4 w-4" /> Close
                            </Button>
                        )}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Category</span><p className="capitalize">{i.category.replace('_', ' ')}</p></div>
                            <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Description</span><p className="whitespace-pre-wrap">{i.description || '-'}</p></div>
                            <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Reported By</span><p>{i.reporter?.name}</p></div>
                            {i.company && <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Company</span><p>{i.company.name}</p></div>}
                        </CardContent>
                    </Card>

                    {(i.status === 'investigating' || i.status === 'closed') && (
                        <Card>
                            <CardHeader><CardTitle>Investigation</CardTitle></CardHeader>
                            <CardContent>
                                {canManage ? (
                                    <form onSubmit={submitInvestigation} className="space-y-3">
                                        <div className="grid grid-cols-2 gap-3">
                                            <div className="space-y-1.5">
                                                <Label>Method</Label>
                                                <Select value={investigationForm.data.method} onValueChange={(v) => investigationForm.setData('method', v)}>
                                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                                    <SelectContent>{investigationMethods.map((m) => <SelectItem key={m} value={m} className="capitalize">{m.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-1.5"><Label>Investigated On</Label><Input type="date" value={investigationForm.data.investigated_at} onChange={(e) => investigationForm.setData('investigated_at', e.target.value)} /></div>
                                        </div>
                                        <div className="space-y-1.5"><Label>Root Cause</Label><Textarea value={investigationForm.data.root_cause} onChange={(e) => investigationForm.setData('root_cause', e.target.value)} rows={2} /></div>
                                        <div className="space-y-1.5"><Label>Findings</Label><Textarea value={investigationForm.data.findings} onChange={(e) => investigationForm.setData('findings', e.target.value)} rows={2} /></div>
                                        <div className="space-y-1.5"><Label>Recommendations</Label><Textarea value={investigationForm.data.recommendations} onChange={(e) => investigationForm.setData('recommendations', e.target.value)} rows={2} /></div>
                                        <Button type="submit" size="sm" disabled={investigationForm.processing}>Save Investigation</Button>
                                    </form>
                                ) : (
                                    <div className="space-y-2 text-sm">
                                        <p><span className="text-xs uppercase text-graphite-400">Root Cause</span><br />{i.investigation?.root_cause || '-'}</p>
                                        <p><span className="text-xs uppercase text-graphite-400">Findings</span><br />{i.investigation?.findings || '-'}</p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Corrective Actions</CardTitle>
                            {canManage && <Button variant="outline" size="sm" onClick={() => setFindingOpen((v) => !v)}><AlertTriangle className="h-3.5 w-3.5" /> {findingOpen ? 'Cancel' : 'Raise CAPA'}</Button>}
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {findingOpen && (
                                <form onSubmit={submitFinding} className="space-y-3 rounded-md border border-graphite-100 p-3">
                                    <div className="space-y-1.5"><Label>Action</Label><Input value={findingForm.data.action} onChange={(e) => findingForm.setData('action', e.target.value)} /></div>
                                    <div className="grid grid-cols-3 gap-3">
                                        <div className="space-y-1.5">
                                            <Label>Assigned To</Label>
                                            <Select value={findingForm.data.assigned_to} onValueChange={(v) => findingForm.setData('assigned_to', v)}>
                                                <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                                <SelectContent>{users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1.5"><Label>Due Date</Label><Input type="date" value={findingForm.data.due_date} onChange={(e) => findingForm.setData('due_date', e.target.value)} /></div>
                                        <div className="space-y-1.5">
                                            <Label>Priority</Label>
                                            <Select value={findingForm.data.priority} onValueChange={(v) => findingForm.setData('priority', v)}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent><SelectItem value="low">Low</SelectItem><SelectItem value="medium">Medium</SelectItem><SelectItem value="high">High</SelectItem></SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                    <Button type="submit" size="sm" disabled={findingForm.processing}>Save</Button>
                                </form>
                            )}
                            {(!i.corrective_actions || i.corrective_actions.length === 0) ? (
                                <p className="text-sm text-graphite-400">No corrective actions raised.</p>
                            ) : (
                                i.corrective_actions.map((a) => (
                                    <div key={a.id} className="rounded-md border border-graphite-100 p-3 text-sm">
                                        <p>{a.action}</p>
                                        <p className="mt-1 text-xs text-graphite-500">{a.assignee?.name || 'Unassigned'} {a.due_date && `· due ${new Date(a.due_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`} · <StatusBadge value={a.status} /></p>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
