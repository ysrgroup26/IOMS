import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, AlertTriangle, CheckCircle2 } from 'lucide-react';

export default function NcrShow({ ncr: n, canManage, users }) {
    const [raiseOpen, setRaiseOpen] = useState(false);
    const capaForm = useForm({ action: '', assigned_to: '', due_date: '', priority: 'medium' });

    function submitCapa(e) {
        e.preventDefault();
        capaForm.post(route('ncrs.raise-corrective-action', n.id), { preserveScroll: true, onSuccess: () => { capaForm.reset(); setRaiseOpen(false); } });
    }

    return (
        <AuthenticatedLayout>
            <Head title={n.ncr_number} />

            <Link href={route('ncrs.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to NCRs
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">{n.ncr_number}<StatusBadge value={n.severity === 'critical' ? 'critical' : n.severity} /><StatusBadge value={n.status === 'closed' ? 'approved' : n.status} label={n.status.replace('_', ' ')} /></h1>
                    <p className="text-xs text-graphite-500">{new Date(n.raised_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })} · Raised by {n.raiser?.name}</p>
                </div>
                {canManage && n.status !== 'closed' && (
                    <Button variant="outline" onClick={() => router.post(route('ncrs.close', n.id))}><CheckCircle2 className="h-4 w-4" /> Close NCR</Button>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div><span className="text-xs uppercase text-graphite-400">Description</span><p className="whitespace-pre-wrap">{n.description}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Responsible Party</span><p>{n.responsible_party || '-'}</p></div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Corrective Actions</CardTitle>
                            {canManage && <Button variant="outline" size="sm" onClick={() => setRaiseOpen((v) => !v)}><AlertTriangle className="h-3.5 w-3.5" /> {raiseOpen ? 'Cancel' : 'Raise CAPA'}</Button>}
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {raiseOpen && (
                                <form onSubmit={submitCapa} className="space-y-3 rounded-md border border-graphite-100 p-3">
                                    <Input placeholder="Action" value={capaForm.data.action} onChange={(e) => capaForm.setData('action', e.target.value)} />
                                    <div className="grid grid-cols-3 gap-3">
                                        <Select value={capaForm.data.assigned_to} onValueChange={(v) => capaForm.setData('assigned_to', v)}>
                                            <SelectTrigger><SelectValue placeholder="Assign to" /></SelectTrigger>
                                            <SelectContent>{users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                        <Input type="date" value={capaForm.data.due_date} onChange={(e) => capaForm.setData('due_date', e.target.value)} />
                                        <Select value={capaForm.data.priority} onValueChange={(v) => capaForm.setData('priority', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent><SelectItem value="low">Low</SelectItem><SelectItem value="medium">Medium</SelectItem><SelectItem value="high">High</SelectItem></SelectContent>
                                        </Select>
                                    </div>
                                    <Button type="submit" size="sm" disabled={capaForm.processing}>Save</Button>
                                </form>
                            )}
                            {(!n.corrective_actions || n.corrective_actions.length === 0) ? (
                                <p className="text-sm text-graphite-400">No corrective actions raised.</p>
                            ) : (
                                n.corrective_actions.map((a) => (
                                    <div key={a.id} className="rounded-md border border-graphite-100 p-3 text-sm">
                                        <p>{a.action}</p>
                                        <p className="mt-1 text-xs text-graphite-500">{a.assignee?.name || 'Unassigned'} {a.due_date && `· due ${new Date(a.due_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`} · <StatusBadge value={a.status} /></p>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
