import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, UserPlus, PlayCircle, CheckCircle2, XCircle, Image as ImageIcon, ClipboardCheck } from 'lucide-react';

export default function SafetyObservationShow({ observation: o, activities, canManage, users }) {
    const [assignOpen, setAssignOpen] = useState(false);
    const [closeOpen, setCloseOpen] = useState(false);

    // useForm's own post() (not a bare router.post()) so errors/processing
    // actually reflect these specific forms' state -- a bare router.post()
    // call would silently leave assignForm.errors/processing frozen.
    const assignForm = useForm({ status: 'assigned', assigned_to: o.assigned_to ? String(o.assigned_to) : '', due_date: o.due_date || '' });
    const closeForm = useForm({ status: 'closed', closure_notes: '' });

    function transition(status, extra = {}, confirmMessage = null) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route('safety-observations.transition', o.id), { status, ...extra });
    }

    function submitAssign(e) {
        e.preventDefault();
        assignForm.post(route('safety-observations.transition', o.id), { preserveScroll: true, onSuccess: () => setAssignOpen(false) });
    }

    function submitClose(e) {
        e.preventDefault();
        closeForm.post(route('safety-observations.transition', o.id), { preserveScroll: true, onSuccess: () => setCloseOpen(false) });
    }

    return (
        <AuthenticatedLayout>
            <Head title={o.observation_number} />

            <Link href={route('safety-observations.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Safety Observation
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight text-graphite-900">
                        {o.observation_number}
                        {o.severity && <StatusBadge value={o.severity} />}
                        <StatusBadge value={o.status} />
                    </h1>
                    <p className="text-xs text-graphite-500 capitalize">
                        {o.type.replace('_', ' ')} · {new Date(o.observed_at).toLocaleString('en-US', { day: 'numeric', month: 'long', year: 'numeric', hour: 'numeric', minute: '2-digit' })}
                        {o.location && ` · ${o.location}`}
                        {o.project && ` · ${o.project.name}`}
                    </p>
                </div>
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2">
                        {o.status === 'open' && (
                            <Button variant="outline" onClick={() => setAssignOpen(true)}><UserPlus className="h-4 w-4" /> Assign</Button>
                        )}
                        {o.status === 'assigned' && (
                            <Button variant="outline" onClick={() => transition('in_progress', {}, 'Mark this observation as in progress?')}>
                                <PlayCircle className="h-4 w-4" /> Start Progress
                            </Button>
                        )}
                        {o.status === 'in_progress' && (
                            <Button variant="outline" onClick={() => transition('pending_verification', {}, 'Submit for verification?')}>
                                <ClipboardCheck className="h-4 w-4" /> Submit for Verification
                            </Button>
                        )}
                        {o.status === 'pending_verification' && (
                            <>
                                <Button variant="outline" onClick={() => transition('in_progress', {}, 'Send back to In Progress?')}>Reopen</Button>
                                <Button onClick={() => setCloseOpen(true)}><CheckCircle2 className="h-4 w-4" /> Close</Button>
                            </>
                        )}
                        {!['closed', 'cancelled'].includes(o.status) && (
                            <Button variant="ghost" className="text-red-600" onClick={() => transition('cancelled', {}, 'Cancel this observation?')}>
                                <XCircle className="h-4 w-4" /> Cancel
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
                            <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Description</span><p className="whitespace-pre-wrap">{o.description}</p></div>
                            {o.immediate_action && <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Immediate Action</span><p className="whitespace-pre-wrap">{o.immediate_action}</p></div>}
                            <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Hazard Category</span><p>{o.hazard_category?.name || '-'}</p></div>
                            <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Reported By</span><p>{o.reporter?.name}</p></div>
                            {o.assignee && <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Assigned To</span><p>{o.assignee.name}{o.due_date && ` · due ${new Date(o.due_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`}</p></div>}
                            {o.company && <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Company</span><p>{o.company.name}</p></div>}
                            {o.status === 'closed' && (
                                <div>
                                    <span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Closed By</span>
                                    <p>{o.closer?.name} · {o.closed_at && new Date(o.closed_at).toLocaleString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                                    {o.closure_notes && <p className="mt-1 whitespace-pre-wrap text-graphite-600">{o.closure_notes}</p>}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Photo Evidence</CardTitle></CardHeader>
                        <CardContent>
                            {(!o.photos || o.photos.length === 0) ? (
                                <EmptyState icon={ImageIcon} title="No photos attached" />
                            ) : (
                                <div className="grid grid-cols-3 gap-3 sm:grid-cols-4">
                                    {o.photos.map((p) => (
                                        <a key={p.id} href={p.url} target="_blank" rel="noreferrer" className="aspect-square overflow-hidden rounded-lg border border-graphite-200">
                                            <img src={p.url} alt={p.caption || ''} className="h-full w-full object-cover" />
                                        </a>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {o.corrective_actions && o.corrective_actions.length > 0 && (
                        <Card>
                            <CardHeader><CardTitle>Corrective Action</CardTitle></CardHeader>
                            <CardContent className="space-y-2">
                                {o.corrective_actions.map((a) => (
                                    <div key={a.id} className="rounded-md border border-graphite-100 p-3 text-sm">
                                        <p>{a.action}</p>
                                        <p className="mt-1 text-xs text-graphite-500">
                                            {a.assignee?.name || 'Unassigned'} {a.due_date && `· due ${new Date(a.due_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`} · <StatusBadge value={a.status} />
                                        </p>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}
                </div>

                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>

            <Dialog open={assignOpen} onOpenChange={setAssignOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Assign Responsible Person</DialogTitle></DialogHeader>
                    <form onSubmit={submitAssign} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Responsible Person</Label>
                            <Select value={assignForm.data.assigned_to} onValueChange={(v) => assignForm.setData('assigned_to', v)}>
                                <SelectTrigger><SelectValue placeholder="Select person" /></SelectTrigger>
                                <SelectContent>
                                    {users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {assignForm.errors.assigned_to && <p className="text-xs text-red-600">{assignForm.errors.assigned_to}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Due Date</Label>
                            <Input type="date" value={assignForm.data.due_date} onChange={(e) => assignForm.setData('due_date', e.target.value)} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAssignOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={assignForm.processing}>Assign</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={closeOpen} onOpenChange={setCloseOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Close Safety Observation</DialogTitle></DialogHeader>
                    <form onSubmit={submitClose} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Closure Notes (optional)</Label>
                            <Textarea value={closeForm.data.closure_notes} onChange={(e) => closeForm.setData('closure_notes', e.target.value)} rows={3} placeholder="Verification evidence / notes" />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setCloseOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={closeForm.processing}>Close</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
