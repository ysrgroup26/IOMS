import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, FileText, Trash2, Users, Plus, CheckCircle2, XCircle } from 'lucide-react';

export default function ContractorShow({ contractor: c, canManage, documentTypes, hseStatuses }) {
    const docForm = useForm({ document_type: 'safety_document', expiry_date: '', file: null });
    const workerForm = useForm({ name: '', worker_id_number: '', position: '', competency: '', hse_status: 'pending' });
    const [workerOpen, setWorkerOpen] = useState(false);

    function submitDoc(e) {
        e.preventDefault();
        docForm.post(route('contractors.documents.store', c.id), { preserveScroll: true, forceFormData: true, onSuccess: () => docForm.reset() });
    }

    function destroyDoc(doc) {
        if (confirm(`Remove document "${doc.original_name || doc.document_type}"?`)) {
            router.delete(route('contractors.documents.destroy', [c.id, doc.id]), { preserveScroll: true });
        }
    }

    function submitWorker(e) {
        e.preventDefault();
        workerForm.post(route('contractors.workers.store', c.id), { preserveScroll: true, onSuccess: () => { workerForm.reset(); setWorkerOpen(false); } });
    }

    function updateWorkerStatus(worker, hse_status) {
        router.put(route('contractors.workers.update', [c.id, worker.id]), { ...worker, hse_status }, { preserveScroll: true });
    }

    function destroyWorker(worker) {
        if (confirm(`Remove worker "${worker.name}"?`)) router.delete(route('contractors.workers.destroy', [c.id, worker.id]), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title={c.company_name} />

            <Link href={route('contractors.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Contractors
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-[22px] font-semibold tracking-tight text-graphite-900">
                        {c.company_name}
                        <StatusBadge value={c.approval_status === 'approved' ? 'approved' : c.approval_status === 'rejected' ? 'rejected' : c.approval_status} />
                    </h1>
                    <p className="text-xs text-graphite-500">{c.code} · {c.pic_name || 'No PIC'}</p>
                </div>
                {canManage && c.approval_status === 'pending' && (
                    <div className="flex items-center gap-2">
                        <Button onClick={() => router.post(route('contractors.approval', c.id), { approval_status: 'approved' })}><CheckCircle2 className="h-4 w-4" /> Approve</Button>
                        <Button variant="outline" className="text-red-600" onClick={() => router.post(route('contractors.approval', c.id), { approval_status: 'rejected' })}><XCircle className="h-4 w-4" /> Reject</Button>
                    </div>
                )}
            </div>

            <div className="space-y-4">
                <Card>
                    <CardHeader className="flex flex-row items-center gap-2"><FileText className="h-4 w-4 text-graphite-400" /><CardTitle>Documents</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        {canManage && (
                            <form onSubmit={submitDoc} className="flex flex-wrap items-end gap-2 rounded-md border border-graphite-100 p-3">
                                <Select value={docForm.data.document_type} onValueChange={(v) => docForm.setData('document_type', v)}>
                                    <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
                                    <SelectContent>{documentTypes.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                </Select>
                                <Input type="date" className="w-40" value={docForm.data.expiry_date} onChange={(e) => docForm.setData('expiry_date', e.target.value)} />
                                <Input type="file" onChange={(e) => docForm.setData('file', e.target.files[0])} />
                                <Button type="submit" size="sm" disabled={docForm.processing}>Upload</Button>
                            </form>
                        )}
                        {c.documents.length === 0 ? (
                            <EmptyState icon={FileText} title="No documents uploaded" />
                        ) : (
                            <ul className="divide-y divide-graphite-100">
                                {c.documents.map((d) => (
                                    <li key={d.id} className="flex items-center justify-between py-2 text-sm">
                                        <a href={d.url} target="_blank" rel="noreferrer" className="text-brand-700 hover:underline">{d.original_name || d.document_type} <span className="capitalize text-graphite-400">({d.document_type.replace('_', ' ')})</span></a>
                                        <div className="flex items-center gap-2">
                                            {d.expiry_date && <span className={d.is_expired ? 'text-red-600' : 'text-graphite-400'}>exp. {new Date(d.expiry_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</span>}
                                            {canManage && <Button variant="ghost" size="icon" onClick={() => destroyDoc(d)}><Trash2 className="h-4 w-4 text-red-500" /></Button>}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div className="flex items-center gap-2"><Users className="h-4 w-4 text-graphite-400" /><CardTitle>Workers</CardTitle></div>
                        {canManage && <Button variant="outline" size="sm" onClick={() => setWorkerOpen((v) => !v)}><Plus className="h-3.5 w-3.5" /> Add Worker</Button>}
                    </CardHeader>
                    <CardContent>
                        {workerOpen && (
                            <form onSubmit={submitWorker} className="mb-4 grid grid-cols-2 gap-2 rounded-md border border-graphite-100 p-3 sm:grid-cols-4">
                                <Input placeholder="Name" value={workerForm.data.name} onChange={(e) => workerForm.setData('name', e.target.value)} />
                                <Input placeholder="ID Number" value={workerForm.data.worker_id_number} onChange={(e) => workerForm.setData('worker_id_number', e.target.value)} />
                                <Input placeholder="Position" value={workerForm.data.position} onChange={(e) => workerForm.setData('position', e.target.value)} />
                                <Input placeholder="Competency" value={workerForm.data.competency} onChange={(e) => workerForm.setData('competency', e.target.value)} />
                                <div className="col-span-2 sm:col-span-4"><Button type="submit" size="sm" disabled={workerForm.processing}>Save Worker</Button></div>
                            </form>
                        )}
                        {c.workers.length === 0 ? (
                            <EmptyState icon={Users} title="No workers registered" />
                        ) : (
                            <Table>
                                <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>ID Number</TableHead><TableHead>Position</TableHead><TableHead>Competency</TableHead><TableHead>HSE Status</TableHead>{canManage && <TableHead />}</TableRow></TableHeader>
                                <TableBody>
                                    {c.workers.map((w) => (
                                        <TableRow key={w.id}>
                                            <TableCell className="font-medium">{w.name}</TableCell>
                                            <TableCell>{w.worker_id_number || '-'}</TableCell>
                                            <TableCell>{w.position || '-'}</TableCell>
                                            <TableCell>{w.competency || '-'}</TableCell>
                                            <TableCell>
                                                {canManage ? (
                                                    <Select value={w.hse_status} onValueChange={(v) => updateWorkerStatus(w, v)}>
                                                        <SelectTrigger className="h-8 w-36"><SelectValue /></SelectTrigger>
                                                        <SelectContent>{hseStatuses.map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                                    </Select>
                                                ) : (<StatusBadge value={w.hse_status === 'fit_for_work' ? 'approved' : w.hse_status === 'not_fit' ? 'rejected' : w.hse_status} label={w.hse_status.replace('_', ' ')} />)}
                                            </TableCell>
                                            {canManage && <TableCell><Button variant="ghost" size="icon" onClick={() => destroyWorker(w)}><Trash2 className="h-4 w-4 text-red-500" /></Button></TableCell>}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
