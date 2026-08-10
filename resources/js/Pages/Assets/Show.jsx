import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, UserPlus, MapPin, ClipboardCheck, RefreshCw, History } from 'lucide-react';

export default function AssetShow({ asset: a, employees, canManage }) {
    const [dialog, setDialog] = useState(null); // 'assign' | 'transfer' | 'inspect' | 'status'
    const assignForm = useForm({ to_employee_id: '', notes: '' });
    const transferForm = useForm({ to_location: '', notes: '' });
    const inspectForm = useForm({ inspection_result: 'pass', notes: '' });
    const statusForm = useForm({ status: a.status, notes: '' });

    function close() { setDialog(null); }

    return (
        <AuthenticatedLayout>
            <Head title={a.name} />

            <Link href={route('assets.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Assets
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">{a.name}<StatusBadge value={a.status === 'active' || a.status === 'assigned' ? 'active' : a.status} label={a.status.replace('_', ' ')} /></h1>
                    <p className="text-xs text-graphite-500">{a.asset_code} · {a.category || 'Uncategorized'} {a.location && `· ${a.location}`}</p>
                </div>
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" onClick={() => setDialog('assign')}><UserPlus className="h-4 w-4" /> Assign</Button>
                        <Button variant="outline" onClick={() => setDialog('transfer')}><MapPin className="h-4 w-4" /> Transfer</Button>
                        <Button variant="outline" onClick={() => setDialog('inspect')}><ClipboardCheck className="h-4 w-4" /> Inspect</Button>
                        <Button variant="outline" onClick={() => setDialog('status')}><RefreshCw className="h-4 w-4" /> Change Status</Button>
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                        <CardContent className="grid grid-cols-2 gap-3 text-sm">
                            <div><span className="text-xs uppercase text-graphite-400">Serial Number</span><p>{a.serial_number || '-'}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Brand / Model</span><p>{a.brand || '-'} {a.model || ''}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Purchase Date</span><p>{a.purchase_date ? new Date(a.purchase_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Vendor</span><p>{a.vendor?.name || '-'}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Responsible</span><p>{a.responsible_employee?.full_name || '-'}</p></div>
                            {a.purchase_order && <div><span className="text-xs uppercase text-graphite-400">Source PO</span><p><Link href={route('purchase-orders.show', a.purchase_order.id)} className="text-brand-700 hover:underline">{a.purchase_order.po_number}</Link></p></div>}
                            {a.notes && <div className="col-span-2"><span className="text-xs uppercase text-graphite-400">Notes</span><p className="whitespace-pre-wrap">{a.notes}</p></div>}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center gap-2"><History className="h-4 w-4 text-graphite-400" /><CardTitle>Transaction History</CardTitle></CardHeader>
                        <CardContent>
                            {a.transactions.length === 0 ? (
                                <EmptyState icon={History} title="No transactions recorded" />
                            ) : (
                                <ul className="divide-y divide-graphite-100">
                                    {a.transactions.map((t) => (
                                        <li key={t.id} className="py-2.5 text-sm">
                                            <p className="capitalize font-medium text-graphite-700">
                                                {t.type.replace('_', ' ')}
                                                {t.type === 'assignment' && ` -- ${t.from_employee?.full_name || 'Unassigned'} -> ${t.to_employee?.full_name}`}
                                                {t.type === 'transfer' && ` -- ${t.from_location || '-'} -> ${t.to_location}`}
                                                {t.type === 'inspection' && ` -- ${t.inspection_result}`}
                                                {t.type === 'status_change' && ` -- ${t.previous_status} -> ${t.new_status}`}
                                            </p>
                                            <p className="text-xs text-graphite-400">{t.performer?.name} · {new Date(t.transaction_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}{t.notes && ` · ${t.notes}`}</p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Dialog open={dialog === 'assign'} onOpenChange={(v) => !v && close()}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Assign Asset</DialogTitle></DialogHeader>
                    <form onSubmit={(e) => { e.preventDefault(); assignForm.post(route('assets.assign', a.id), { preserveScroll: true, onSuccess: close }); }} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Employee</Label>
                            <Select value={assignForm.data.to_employee_id} onValueChange={(v) => assignForm.setData('to_employee_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                <SelectContent>{employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.full_name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <Input placeholder="Notes (optional)" value={assignForm.data.notes} onChange={(e) => assignForm.setData('notes', e.target.value)} />
                        <DialogFooter><Button type="button" variant="outline" onClick={close}>Cancel</Button><Button type="submit" disabled={assignForm.processing}>Assign</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={dialog === 'transfer'} onOpenChange={(v) => !v && close()}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Transfer Asset</DialogTitle></DialogHeader>
                    <form onSubmit={(e) => { e.preventDefault(); transferForm.post(route('assets.transfer', a.id), { preserveScroll: true, onSuccess: close }); }} className="space-y-4">
                        <Input placeholder="New location" value={transferForm.data.to_location} onChange={(e) => transferForm.setData('to_location', e.target.value)} />
                        <Input placeholder="Notes (optional)" value={transferForm.data.notes} onChange={(e) => transferForm.setData('notes', e.target.value)} />
                        <DialogFooter><Button type="button" variant="outline" onClick={close}>Cancel</Button><Button type="submit" disabled={transferForm.processing}>Transfer</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={dialog === 'inspect'} onOpenChange={(v) => !v && close()}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Record Inspection</DialogTitle></DialogHeader>
                    <form onSubmit={(e) => { e.preventDefault(); inspectForm.post(route('assets.inspect', a.id), { preserveScroll: true, onSuccess: close }); }} className="space-y-4">
                        <Select value={inspectForm.data.inspection_result} onValueChange={(v) => inspectForm.setData('inspection_result', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent><SelectItem value="pass">Pass</SelectItem><SelectItem value="fail">Fail</SelectItem><SelectItem value="needs_attention">Needs Attention</SelectItem></SelectContent>
                        </Select>
                        <Input placeholder="Notes (optional)" value={inspectForm.data.notes} onChange={(e) => inspectForm.setData('notes', e.target.value)} />
                        <DialogFooter><Button type="button" variant="outline" onClick={close}>Cancel</Button><Button type="submit" disabled={inspectForm.processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={dialog === 'status'} onOpenChange={(v) => !v && close()}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Change Status</DialogTitle></DialogHeader>
                    <form onSubmit={(e) => { e.preventDefault(); statusForm.post(route('assets.change-status', a.id), { preserveScroll: true, onSuccess: close }); }} className="space-y-4">
                        <Select value={statusForm.data.status} onValueChange={(v) => statusForm.setData('status', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>{['active', 'assigned', 'under_maintenance', 'retired', 'disposed'].map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace('_', ' ')}</SelectItem>)}</SelectContent>
                        </Select>
                        <Input placeholder="Notes (optional)" value={statusForm.data.notes} onChange={(e) => statusForm.setData('notes', e.target.value)} />
                        <DialogFooter><Button type="button" variant="outline" onClick={close}>Cancel</Button><Button type="submit" disabled={statusForm.processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
