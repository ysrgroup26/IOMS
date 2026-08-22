import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, Pencil, FileText, Trash2, ShieldCheck } from 'lucide-react';

export default function VendorShow({ vendor: v, activities, canManage, documentTypes }) {
    const [qualifyOpen, setQualifyOpen] = useState(false);
    const qualifyForm = useForm({ qualification_status: 'qualified', qualified_until: '', rejection_reason: '' });
    const docForm = useForm({ document_type: 'certificate', expiry_date: '', file: null });

    function submitQualify(e) {
        e.preventDefault();
        qualifyForm.post(route('vendors.qualification', v.id), { preserveScroll: true, onSuccess: () => setQualifyOpen(false) });
    }

    function submitDoc(e) {
        e.preventDefault();
        docForm.post(route('vendors.documents.store', v.id), { preserveScroll: true, forceFormData: true, onSuccess: () => docForm.reset() });
    }

    function destroyDoc(doc) {
        if (confirm(`Remove document "${doc.original_name || doc.document_type}"?`)) {
            router.delete(route('vendors.documents.destroy', [v.id, doc.id]), { preserveScroll: true });
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={v.name} />

            <Link href={route('vendors.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Vendors
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-[22px] font-semibold tracking-tight text-graphite-900">
                        {v.name}
                        <StatusBadge value={v.qualification_status === 'qualified' ? 'approved' : v.qualification_status === 'rejected' ? 'rejected' : v.qualification_status} label={v.qualification_status.replace('_', ' ')} />
                        {v.is_qualification_expired && <StatusBadge value="expired" />}
                    </h1>
                    <p className="text-xs text-graphite-500">{v.vendor_code} · {v.category || 'Uncategorized'} · {v.city}</p>
                </div>
                {canManage && (
                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild><Link href={route('vendors.edit', v.id)}><Pencil className="h-4 w-4" /> Edit</Link></Button>
                        <Button onClick={() => setQualifyOpen(true)}><ShieldCheck className="h-4 w-4" /> Review Qualification</Button>
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Contact &amp; Business</CardTitle></CardHeader>
                        <CardContent className="grid grid-cols-2 gap-3 text-sm">
                            <div><span className="text-xs uppercase text-graphite-400">PIC</span><p>{v.pic_name || '-'} {v.pic_phone && `· ${v.pic_phone}`}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Email</span><p>{v.pic_email || '-'}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">NPWP</span><p>{v.npwp || '-'}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Payment Terms</span><p>{v.payment_terms || '-'}</p></div>
                            <div className="col-span-2"><span className="text-xs uppercase text-graphite-400">Address</span><p>{v.address || '-'}</p></div>
                            {v.qualified_until && <div><span className="text-xs uppercase text-graphite-400">Qualified Until</span><p>{new Date(v.qualified_until).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</p></div>}
                            {v.rejection_reason && <div className="col-span-2"><span className="text-xs uppercase text-graphite-400">Rejection Reason</span><p>{v.rejection_reason}</p></div>}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center gap-2"><FileText className="h-4 w-4 text-graphite-400" /><CardTitle>Documents</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            {canManage && (
                                <form onSubmit={submitDoc} className="flex flex-wrap items-end gap-2 rounded-md border border-graphite-100 p-3">
                                    <div className="space-y-1">
                                        <Label className="text-xs">Type</Label>
                                        <Select value={docForm.data.document_type} onValueChange={(v2) => docForm.setData('document_type', v2)}>
                                            <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                                            <SelectContent>{documentTypes.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1"><Label className="text-xs">Expiry (optional)</Label><Input type="date" className="w-40" value={docForm.data.expiry_date} onChange={(e) => docForm.setData('expiry_date', e.target.value)} /></div>
                                    <div className="space-y-1"><Label className="text-xs">File</Label><Input type="file" onChange={(e) => docForm.setData('file', e.target.files[0])} /></div>
                                    <Button type="submit" size="sm" disabled={docForm.processing}>Upload</Button>
                                </form>
                            )}
                            {v.documents.length === 0 ? (
                                <EmptyState icon={FileText} title="No documents uploaded" />
                            ) : (
                                <ul className="divide-y divide-graphite-100">
                                    {v.documents.map((d) => (
                                        <li key={d.id} className="flex items-center justify-between py-2 text-sm">
                                            <a href={d.url} target="_blank" rel="noreferrer" className="text-brand-700 hover:underline">
                                                {d.original_name || d.document_type} <span className="capitalize text-graphite-400">({d.document_type.replace('_', ' ')})</span>
                                            </a>
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
                </div>
                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>

            <Dialog open={qualifyOpen} onOpenChange={setQualifyOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Review Vendor Qualification</DialogTitle></DialogHeader>
                    <form onSubmit={submitQualify} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Qualification Status</Label>
                            <Select value={qualifyForm.data.qualification_status} onValueChange={(v2) => qualifyForm.setData('qualification_status', v2)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="under_review">Under Review</SelectItem>
                                    <SelectItem value="qualified">Qualified</SelectItem>
                                    <SelectItem value="conditionally_qualified">Conditionally Qualified</SelectItem>
                                    <SelectItem value="rejected">Rejected</SelectItem>
                                    <SelectItem value="suspended">Suspended</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Qualified Until (optional)</Label><Input type="date" value={qualifyForm.data.qualified_until} onChange={(e) => qualifyForm.setData('qualified_until', e.target.value)} /></div>
                        {['rejected', 'suspended'].includes(qualifyForm.data.qualification_status) && (
                            <div className="space-y-1.5"><Label>Reason</Label><Input value={qualifyForm.data.rejection_reason} onChange={(e) => qualifyForm.setData('rejection_reason', e.target.value)} /></div>
                        )}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setQualifyOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={qualifyForm.processing}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
