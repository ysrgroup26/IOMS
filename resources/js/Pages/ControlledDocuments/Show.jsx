import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, Send, CheckCircle2, PlayCircle, Archive, Upload } from 'lucide-react';

export default function ControlledDocumentShow({ document: d, activities, canManage }) {
    const [versionOpen, setVersionOpen] = useState(false);
    const versionForm = useForm({ version: '', file: null, notes: '' });

    function transition(status, confirmMessage) {
        if (!confirm(confirmMessage)) return;
        router.post(route('controlled-documents.transition', d.id), { status });
    }

    function submitVersion(e) {
        e.preventDefault();
        versionForm.post(route('controlled-documents.versions.store', d.id), { forceFormData: true, preserveScroll: true, onSuccess: () => { versionForm.reset(); setVersionOpen(false); } });
    }

    return (
        <AuthenticatedLayout>
            <Head title={d.title} />

            <Link href={route('controlled-documents.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Document Control
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight text-graphite-900">{d.title}<StatusBadge value={d.status === 'effective' ? 'approved' : d.status === 'obsolete' ? 'secondary' : d.status} /></h1>
                    <p className="text-xs text-graphite-500">{d.document_number} · v{d.version} {d.category && `· ${d.category}`} {d.department && `· ${d.department.name}`}</p>
                </div>
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2">
                        {d.status === 'draft' && (<Button variant="outline" onClick={() => transition('review', 'Submit for review?')}><Send className="h-4 w-4" /> Submit for Review</Button>)}
                        {d.status === 'review' && (<>
                            <Button onClick={() => transition('approved', 'Approve this document?')}><CheckCircle2 className="h-4 w-4" /> Approve</Button>
                            <Button variant="outline" onClick={() => transition('draft', 'Send back to draft?')}>Send Back</Button>
                        </>)}
                        {d.status === 'approved' && (<Button onClick={() => transition('effective', 'Mark as effective?')}><PlayCircle className="h-4 w-4" /> Make Effective</Button>)}
                        {d.status === 'effective' && (<Button variant="outline" onClick={() => transition('obsolete', 'Mark as obsolete?')}><Archive className="h-4 w-4" /> Mark Obsolete</Button>)}
                        <Button variant="outline" onClick={() => setVersionOpen((v) => !v)}><Upload className="h-4 w-4" /> New Revision</Button>
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {d.file_url && (
                        <Card><CardContent className="p-3.5"><a href={d.file_url} target="_blank" rel="noreferrer" className="text-brand-700 hover:underline">Download current version (v{d.version})</a></CardContent></Card>
                    )}

                    {versionOpen && (
                        <Card>
                            <CardHeader><CardTitle>Upload New Revision</CardTitle></CardHeader>
                            <CardContent>
                                <form onSubmit={submitVersion} className="space-y-3">
                                    <Input placeholder="Version (e.g. 1.1)" value={versionForm.data.version} onChange={(e) => versionForm.setData('version', e.target.value)} />
                                    <Input type="file" onChange={(e) => versionForm.setData('file', e.target.files[0])} />
                                    <Input placeholder="Notes (optional)" value={versionForm.data.notes} onChange={(e) => versionForm.setData('notes', e.target.value)} />
                                    <Button type="submit" size="sm" disabled={versionForm.processing}>Upload</Button>
                                </form>
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader><CardTitle>Revision History</CardTitle></CardHeader>
                        <CardContent>
                            <ul className="divide-y divide-graphite-100">
                                {d.versions.map((v) => (
                                    <li key={v.id} className="py-2.5 text-sm">
                                        <a href={v.url} target="_blank" rel="noreferrer" className="font-medium text-brand-700 hover:underline">v{v.version} -- {v.original_name}</a>
                                        <p className="text-xs text-graphite-400">{v.uploader?.name} · {new Date(v.created_at).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}{v.notes && ` · ${v.notes}`}</p>
                                    </li>
                                ))}
                            </ul>
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
