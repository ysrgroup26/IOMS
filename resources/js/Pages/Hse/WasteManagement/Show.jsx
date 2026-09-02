import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, Truck, FileText, AlertCircle } from 'lucide-react';

const STATUS_LABELS = {
    generated: 'Generated', stored: 'Stored', scheduled_pickup: 'Scheduled Pickup',
    in_transit: 'In Transit', disposed: 'Disposed', closed: 'Closed',
};

/**
 * v1.11.4 (HSE Waste Management, Part 13/16/17). Waste Record detail --
 * lifecycle status, full movement history (mirrors
 * EquipmentInspectionDialog's own "history list + record-new-event form"
 * shape), and a document-attach control per movement (reuses the
 * existing document-upload convention -- multipart form, Inertia's
 * useForm auto-switches to FormData when a File is present in `data`).
 */
export default function WasteRecordShow({ record, wasteVendors, can }) {
    const [movementOpen, setMovementOpen] = useState(false);

    function transition(status) {
        if (confirm(`Move this record to "${STATUS_LABELS[status] || status}"?`)) {
            router.post(route('waste-records.transition', record.id), { status });
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={record.record_number} />

            <Link href={route('waste-records.index')} className="mb-3 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Waste Records
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold text-graphite-900 dark:text-slate-50">
                        {record.record_number}
                        <Badge variant={record.waste_type?.category === 'b3' ? 'destructive' : 'secondary'}>{record.waste_type?.category === 'b3' ? 'B3' : 'Non-B3'}</Badge>
                    </h1>
                    <p className="mt-0.5 text-xs text-graphite-500">{record.waste_type?.name} -- {record.quantity} {record.unit}</p>
                </div>
                <Badge variant="outline" className="text-sm">{STATUS_LABELS[record.status] || record.status}</Badge>
            </div>

            {record.is_storage_overdue && (
                <div className="mb-3 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/30 dark:text-red-400">
                    <AlertCircle className="h-4 w-4 shrink-0" /> Storage duration has exceeded the configured operational threshold ({record.waste_type?.storage_limit_days} days) -- operational flag only, not a legal determination.
                </div>
            )}
            {!record.is_storage_overdue && record.is_approaching_storage_limit && (
                <div className="mb-3 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-400">
                    <AlertCircle className="h-4 w-4 shrink-0" /> Approaching the configured storage threshold ({record.days_in_storage}/{record.waste_type?.storage_limit_days} days).
                </div>
            )}

            <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
                <Card className="lg:col-span-1">
                    <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <div className="flex justify-between"><span className="text-graphite-400">Project</span><span>{record.project?.name || '-'}</span></div>
                        <div className="flex justify-between"><span className="text-graphite-400">Location</span><span>{record.location || '-'}</span></div>
                        <div className="flex justify-between"><span className="text-graphite-400">Storage</span><span>{record.storage_location?.name || '-'}</span></div>
                        <div className="flex justify-between"><span className="text-graphite-400">Container</span><span>{record.container || '-'}</span></div>
                        <div className="flex justify-between"><span className="text-graphite-400">Generated</span><span>{new Date(record.generated_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</span></div>
                        <div className="flex justify-between"><span className="text-graphite-400">Received</span><span>{record.received_date ? new Date(record.received_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'}</span></div>
                        <div className="flex justify-between"><span className="text-graphite-400">Days in Storage</span><span>{record.days_in_storage ?? '-'}</span></div>
                        {record.notes && <p className="mt-2 border-t border-graphite-100 pt-2 text-xs text-graphite-500">{record.notes}</p>}
                        {can.manage && record.status !== 'closed' && (
                            <div className="mt-3 flex flex-wrap gap-1.5 border-t border-graphite-100 pt-3">
                                {record.status === 'generated' && <Button size="sm" variant="outline" onClick={() => transition('stored')}>Mark Stored</Button>}
                                {record.status === 'disposed' && <Button size="sm" variant="outline" onClick={() => transition('closed')}>Close Record</Button>}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2"><Truck className="h-4 w-4 text-graphite-400" /> Movement / Disposal History</CardTitle>
                            <CardDescription>Pickup, transport, and disposal events for this record</CardDescription>
                        </div>
                        {can.manage && record.status !== 'closed' && <Button size="sm" onClick={() => setMovementOpen((v) => !v)}>{movementOpen ? 'Cancel' : 'Record Movement'}</Button>}
                    </CardHeader>
                    <CardContent>
                        {movementOpen && <MovementForm record={record} wasteVendors={wasteVendors} onDone={() => setMovementOpen(false)} />}

                        {record.movements.length === 0 ? (
                            <EmptyState icon={Truck} title="No movements recorded yet" />
                        ) : (
                            <ul className="mt-3 divide-y divide-graphite-100 dark:divide-slate-800">
                                {record.movements.map((m) => (
                                    <li key={m.id} className="py-2.5 text-sm">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium capitalize">{m.status.replace('_', ' ')}{m.vendor ? ` -- ${m.vendor.name}` : ''}</span>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <span className="text-xs text-graphite-400">{m.disposal_date ? new Date(m.disposal_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) : m.pickup_date ? new Date(m.pickup_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) : '-'}</span>
                                                {/* v2.33.0 (Phase 4, Operational Document System, Part
                                                    14): a real, previously-missing capability -- a
                                                    vendor handover had no formal document that could
                                                    leave IOMS. Reuses the same PdfGeneratorService/
                                                    DocumentEngine pipeline PTW/Material Request already
                                                    use. */}
                                                <a
                                                    href={route('waste-movements.pdf', [record.id, m.id])}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline"
                                                >
                                                    <FileText className="h-3 w-3" /> Manifest
                                                </a>
                                            </div>
                                        </div>
                                        {m.manifest_number && <p className="text-xs text-graphite-400">Manifest: {m.manifest_number}{m.destination ? ` -- ${m.destination}` : ''}</p>}
                                        {m.notes && <p className="text-xs text-graphite-500">{m.notes}</p>}
                                        {m.documents?.length > 0 && (
                                            <div className="mt-1 flex flex-wrap gap-2">
                                                {m.documents.map((d) => (
                                                    <a key={d.id} href={d.url} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-xs text-brand-600 hover:underline">
                                                        <FileText className="h-3 w-3" /> {d.original_name || d.document_type}
                                                    </a>
                                                ))}
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function MovementForm({ record, wasteVendors, onDone }) {
    const { data, setData, post, processing, reset } = useForm({
        vendor_id: '', manifest_number: '', pickup_date: '', destination: '', disposal_date: '', notes: '',
        documents: [{ document_type: 'manifest', file: null }],
    });

    function submit(e) {
        e.preventDefault();
        post(route('waste-movements.store', record.id), {
            forceFormData: true,
            onSuccess: () => { reset(); onDone(); },
        });
    }

    function updateDoc(i, field, value) {
        const documents = [...data.documents];
        documents[i] = { ...documents[i], [field]: value };
        setData('documents', documents);
    }

    return (
        <form onSubmit={submit} className="mb-4 space-y-3 rounded-lg border border-graphite-200 p-3 dark:border-slate-800">
            <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1"><Label className="text-xs">Waste Vendor / Transporter</Label>
                    <Select value={data.vendor_id || '__none'} onValueChange={(v) => setData('vendor_id', v === '__none' ? '' : v)}>
                        <SelectTrigger><SelectValue placeholder="Select vendor" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none">Not selected</SelectItem>
                            {wasteVendors.map((v) => <SelectItem key={v.id} value={String(v.id)}>{v.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-1"><Label className="text-xs">Manifest / Reference #</Label><Input value={data.manifest_number} onChange={(e) => setData('manifest_number', e.target.value)} /></div>
            </div>
            <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1"><Label className="text-xs">Pickup Date</Label><Input type="date" value={data.pickup_date} onChange={(e) => setData('pickup_date', e.target.value)} /></div>
                <div className="space-y-1"><Label className="text-xs">Destination</Label><Input value={data.destination} onChange={(e) => setData('destination', e.target.value)} /></div>
            </div>
            <div className="space-y-1"><Label className="text-xs">Disposal / Treatment Date (leave blank if not yet disposed)</Label><Input type="date" value={data.disposal_date} onChange={(e) => setData('disposal_date', e.target.value)} /></div>
            <div className="space-y-1"><Label className="text-xs">Notes</Label><Textarea rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} /></div>
            <div className="space-y-1">
                <Label className="text-xs">Supporting Document (optional)</Label>
                <div className="flex items-center gap-2">
                    <Select value={data.documents[0].document_type} onValueChange={(v) => updateDoc(0, 'document_type', v)}>
                        <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="manifest">Manifest</SelectItem>
                            <SelectItem value="disposal_certificate">Disposal Certificate</SelectItem>
                            <SelectItem value="transporter_document">Transporter Document</SelectItem>
                            <SelectItem value="photo">Photo</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                    </Select>
                    <Input type="file" onChange={(e) => updateDoc(0, 'file', e.target.files[0] || null)} />
                </div>
            </div>
            <div className="flex justify-end"><Button type="submit" size="sm" disabled={processing}>Save Movement</Button></div>
        </form>
    );
}
