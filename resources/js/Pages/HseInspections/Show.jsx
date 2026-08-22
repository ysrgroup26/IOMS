import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, AlertTriangle } from 'lucide-react';

export default function HseInspectionShow({ inspection: i, activities, canManage, users }) {
    const [raiseFor, setRaiseFor] = useState(null);
    const findingForm = useForm({ action: '', assigned_to: '', due_date: '', priority: 'medium' });

    function submitFinding(e) {
        e.preventDefault();
        findingForm.post(route('hse-inspections.raise-finding', i.id), { preserveScroll: true, onSuccess: () => { findingForm.reset(); setRaiseFor(null); } });
    }

    return (
        <AuthenticatedLayout>
            <Head title={i.inspection_number} />

            <Link href={route('hse-inspections.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to HSE Inspection
            </Link>

            <div className="mb-4">
                <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight text-graphite-900">
                    {i.inspection_number}
                    <StatusBadge value={i.overall_result === 'fail' ? 'rejected' : 'approved'} label={i.overall_result} />
                </h1>
                <p className="text-xs capitalize text-graphite-500">{i.inspection_type.replace('_', ' ')} · {new Date(i.inspection_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}{i.location && ` · ${i.location}`}</p>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Checklist</CardTitle></CardHeader>
                        <CardContent className="overflow-x-auto">
                            <Table>
                                <TableHeader><TableRow><TableHead>Item</TableHead><TableHead>Result</TableHead><TableHead>Remarks</TableHead>{canManage && <TableHead />}</TableRow></TableHeader>
                                <TableBody>
                                    {(i.checklist_items || []).map((item, idx) => (
                                        <TableRow key={idx}>
                                            <TableCell>{item.item}</TableCell>
                                            <TableCell><StatusBadge value={item.result === 'not_ok' ? 'rejected' : item.result === 'ok' ? 'approved' : 'secondary'} label={item.result} /></TableCell>
                                            <TableCell>{item.remarks}</TableCell>
                                            {canManage && (
                                                <TableCell>
                                                    {item.result === 'not_ok' && (
                                                        <Button variant="outline" size="sm" onClick={() => { setRaiseFor(idx); findingForm.setData('action', `Address: ${item.item}${item.remarks ? ' -- ' + item.remarks : ''}`); }}>
                                                            <AlertTriangle className="h-3.5 w-3.5" /> Raise CAPA
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            {i.notes && <p className="mt-3 whitespace-pre-wrap text-sm text-graphite-600">{i.notes}</p>}
                        </CardContent>
                    </Card>

                    {raiseFor !== null && (
                        <Card>
                            <CardHeader><CardTitle>Raise Corrective Action</CardTitle></CardHeader>
                            <CardContent>
                                <form onSubmit={submitFinding} className="space-y-3">
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
                                    <div className="flex gap-2">
                                        <Button type="button" variant="outline" onClick={() => setRaiseFor(null)}>Cancel</Button>
                                        <Button type="submit" disabled={findingForm.processing}>Save</Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    )}

                    {i.corrective_actions?.length > 0 && (
                        <Card>
                            <CardHeader><CardTitle>Corrective Actions</CardTitle></CardHeader>
                            <CardContent className="space-y-2">
                                {i.corrective_actions.map((a) => (
                                    <div key={a.id} className="rounded-md border border-graphite-100 p-3 text-sm">
                                        <p>{a.action}</p>
                                        <p className="mt-1 text-xs text-graphite-500">{a.assignee?.name || 'Unassigned'} {a.due_date && `· due ${new Date(a.due_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`} · <StatusBadge value={a.status} /></p>
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
        </AuthenticatedLayout>
    );
}
