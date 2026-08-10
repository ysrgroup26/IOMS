import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, PlayCircle, CheckCircle2, XCircle, Wrench, Plus } from 'lucide-react';

export default function WorkOrderShow({ workOrder: wo, activities, canManage, warehouses, items }) {
    const [completeOpen, setCompleteOpen] = useState(false);
    const [spareOpen, setSpareOpen] = useState(false);
    const completeForm = useForm({ completion_notes: '' });
    const spareForm = useForm({ item_id: '', warehouse_id: '', quantity_used: '1' });

    function transition(status, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route('work-orders.transition', wo.id), { status });
    }

    function submitComplete(e) {
        e.preventDefault();
        completeForm.transform((data) => ({ ...data, status: 'completed' }));
        completeForm.post(route('work-orders.transition', wo.id), { onSuccess: () => setCompleteOpen(false) });
    }

    function submitSpare(e) {
        e.preventDefault();
        spareForm.post(route('work-orders.spare-parts.store', wo.id), { preserveScroll: true, onSuccess: () => { spareForm.reset(); setSpareOpen(false); } });
    }

    return (
        <AuthenticatedLayout>
            <Head title={wo.wo_number} />

            <Link href={route('work-orders.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Work Orders
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">{wo.wo_number}<StatusBadge value={wo.status} /></h1>
                    <p className="text-xs capitalize text-graphite-500">{wo.maintenance_type} · {wo.asset?.name} ({wo.asset?.asset_code}) · planned {new Date(wo.planned_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
                </div>
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2">
                        {wo.status === 'draft' && (<Button variant="outline" onClick={() => transition('scheduled', 'Schedule this work order?')}>Schedule</Button>)}
                        {wo.status === 'scheduled' && (<Button variant="outline" onClick={() => transition('in_progress', 'Start execution?')}><PlayCircle className="h-4 w-4" /> Start</Button>)}
                        {wo.status === 'in_progress' && (<Button onClick={() => setCompleteOpen((v) => !v)}><CheckCircle2 className="h-4 w-4" /> Complete</Button>)}
                        {!['completed', 'cancelled'].includes(wo.status) && (
                            <Button variant="ghost" className="text-red-600" onClick={() => transition('cancelled', 'Cancel this work order?')}><XCircle className="h-4 w-4" /> Cancel</Button>
                        )}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {wo.work_description && <div><span className="text-xs uppercase text-graphite-400">Work Description</span><p className="whitespace-pre-wrap">{wo.work_description}</p></div>}
                            <div className="grid grid-cols-2 gap-3">
                                <div><span className="text-xs uppercase text-graphite-400">Technician</span><p>{wo.technician?.full_name || '-'}</p></div>
                                <div><span className="text-xs uppercase text-graphite-400">Actual Date</span><p>{wo.actual_date ? new Date(wo.actual_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'}</p></div>
                            </div>
                            {wo.completion_notes && <div><span className="text-xs uppercase text-graphite-400">Completion Notes</span><p className="whitespace-pre-wrap">{wo.completion_notes}</p></div>}
                            {wo.maintenance_request && <Link href={route('maintenance-requests.show', wo.maintenance_request.id)} className="text-brand-700 hover:underline">Source: {wo.maintenance_request.request_number}</Link>}
                        </CardContent>
                    </Card>

                    {completeOpen && (
                        <Card>
                            <CardHeader><CardTitle>Complete Work Order</CardTitle></CardHeader>
                            <CardContent>
                                <form onSubmit={submitComplete} className="space-y-3">
                                    <Textarea placeholder="Completion notes" rows={3} value={completeForm.data.completion_notes} onChange={(e) => completeForm.setData('completion_notes', e.target.value)} />
                                    <div className="flex gap-2">
                                        <Button type="button" variant="outline" onClick={() => setCompleteOpen(false)}>Cancel</Button>
                                        <Button type="submit" disabled={completeForm.processing}>Mark Completed</Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div className="flex items-center gap-2"><Wrench className="h-4 w-4 text-graphite-400" /><CardTitle>Spare Parts Used</CardTitle></div>
                            {canManage && <Button variant="outline" size="sm" onClick={() => setSpareOpen((v) => !v)}><Plus className="h-3.5 w-3.5" /> Add</Button>}
                        </CardHeader>
                        <CardContent>
                            {spareOpen && (
                                <form onSubmit={submitSpare} className="mb-4 grid grid-cols-3 gap-2 rounded-md border border-graphite-100 p-3">
                                    <Select value={spareForm.data.warehouse_id} onValueChange={(v) => spareForm.setData('warehouse_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Warehouse" /></SelectTrigger>
                                        <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>)}</SelectContent>
                                    </Select>
                                    <Select value={spareForm.data.item_id} onValueChange={(v) => spareForm.setData('item_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Item" /></SelectTrigger>
                                        <SelectContent>{items.map((i) => <SelectItem key={i.id} value={String(i.id)}>{i.name}</SelectItem>)}</SelectContent>
                                    </Select>
                                    <Input type="number" min="0.01" step="0.01" placeholder="Qty" value={spareForm.data.quantity_used} onChange={(e) => spareForm.setData('quantity_used', e.target.value)} />
                                    <div className="col-span-3"><Button type="submit" size="sm" disabled={spareForm.processing}>Record Usage</Button></div>
                                </form>
                            )}
                            {(!wo.spare_parts || wo.spare_parts.length === 0) ? (
                                <EmptyState icon={Wrench} title="No spare parts recorded" />
                            ) : (
                                <Table>
                                    <TableHeader><TableRow><TableHead>Item</TableHead><TableHead>Warehouse</TableHead><TableHead>Qty Used</TableHead></TableRow></TableHeader>
                                    <TableBody>
                                        {wo.spare_parts.map((sp) => (
                                            <TableRow key={sp.id}><TableCell>{sp.item?.name}</TableCell><TableCell>{sp.warehouse?.name}</TableCell><TableCell>{sp.quantity_used} {sp.item?.unit}</TableCell></TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
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
