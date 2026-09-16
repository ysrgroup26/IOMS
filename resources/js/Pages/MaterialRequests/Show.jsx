import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import ApprovalActions from '@/Components/shared/ApprovalActions';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import AgingIndicator from '@/Components/shared/AgingIndicator';
import { FormField } from '@/Components/shared/form';
import { ArrowLeft, Pencil, FileDown, Printer, PackageCheck, CheckCheck, RotateCcw, XCircle, Layers, PlayCircle, Truck } from 'lucide-react';
import PageHeader from '@/Components/shared/PageHeader';

function formatDate(value) {
    if (!value) return null;
    return new Date(value).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function MaterialRequestShow({ materialRequest: mr, approval, activities, canDecide, canProcess, canOverride, canConsolidate }) {
    const [holdOpen, setHoldOpen] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);

    const holdForm = useForm({ reason: '' });
    const cancelForm = useForm({ reason: '' });

    function act(action, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route(`material-requests.${action}`, mr.id));
    }

    function submitHold(e) {
        e.preventDefault();
        holdForm.post(route('material-requests.consolidate', mr.id), {
            preserveScroll: true,
            onSuccess: () => { setHoldOpen(false); holdForm.reset(); },
        });
    }

    function submitCancel(e) {
        e.preventDefault();
        cancelForm.post(route('material-requests.cancel', mr.id), {
            preserveScroll: true,
            onSuccess: () => { setCancelOpen(false); cancelForm.reset(); },
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title={mr.request_number} />

            <Link href={route('material-requests.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Material Requests
            </Link>

            <PageHeader title={<>{mr.request_number} <StatusBadge value={mr.status} label={mr.status === 'submitted' ? 'Waiting Approval' : undefined} /></>} subtitle={<>{new Date(mr.request_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })} {mr.department && ` · ${mr.department.name}`} {mr.project && ` · ${mr.project.name}`} {mr.completed_at && ` · Completed ${new Date(mr.completed_at).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`}</>}>
                <div className="flex items-center gap-2">
                    {/* Draft: Edit only (Submit happens via the Form's own
                        Submit button, not a Show-page action) */}
                    {mr.status === 'draft' && (
                        <Button variant="outline" asChild>
                            <Link href={route('material-requests.edit', mr.id)}><Pencil className="h-4 w-4" /> Edit</Link>
                        </Button>
                    )}

                    {/* Submitted: Approve/Reject via the reusable
                        ApprovalActions component -- nothing
                        Material-Request-specific here. */}
                    {mr.status === 'submitted' && <ApprovalActions approval={approval} canDecide={canDecide} />}

                    {/* Approved: Warehouse (or Super Admin) starts
                        processing. */}
                    {/*
                        v2.69.0 -- hold approved demand so it can be bought
                        together with related requests. Offered only from
                        `approved`: consolidating something already being
                        fulfilled would mean un-processing it.
                    */}
                    {mr.status === 'approved' && canConsolidate && (
                        <Button variant="outline" onClick={() => setHoldOpen(true)}>
                            <Layers className="h-4 w-4" /> Hold for consolidation
                        </Button>
                    )}

                    {mr.status === 'consolidating' && canConsolidate && (
                        <Button variant="outline" onClick={() => act('release-consolidation', 'Release this request back into the approved queue?')}>
                            <PlayCircle className="h-4 w-4" /> Release hold
                        </Button>
                    )}

                    {['approved', 'consolidating'].includes(mr.status) && canProcess && (
                        <Button onClick={() => act('process', 'Start processing this request?')}>
                            <PackageCheck className="h-4 w-4" /> Start Processing
                        </Button>
                    )}

                    {/* Processing: Warehouse (or Super Admin) marks it
                        complete. */}
                    {mr.status === 'processing' && canProcess && (
                        <Button onClick={() => act('complete', 'Mark this request as completed?')}>
                            <CheckCheck className="h-4 w-4" /> Complete
                        </Button>
                    )}

                    {/* Rejected: read-only for everyone except an
                        explicit Company Admin override (reopens to
                        Draft) -- never a standard action, matching the
                        spec's "Rejected: read-only, View Rejection
                        Reason" for regular users. */}
                    {mr.status === 'rejected' && canOverride && (
                        <Button variant="outline" onClick={() => act('reopen', 'Reopen this rejected request back to Draft?')}>
                            <RotateCcw className="h-4 w-4" /> Reopen to Draft
                        </Button>
                    )}

                    {/* Cancel is available as an override from any
                        non-final state -- not a normal user action,
                        matching "Company Admin: Override if required." */}
                    {/*
                        v2.69.0 -- cancelling now records WHY. A separate
                        terminal "closed" state was rejected as a duplicate
                        of this one: the missing information was never a
                        status, it was the reason. See ADR/030.
                    */}
                    {canOverride && !['completed', 'cancelled'].includes(mr.status) && (
                        <Button variant="outline" onClick={() => setCancelOpen(true)}>
                            <XCircle className="h-4 w-4" /> Cancel
                        </Button>
                    )}

                    <Button variant="outline" asChild>
                        <a href={route('material-requests.pdf', mr.id)} target="_blank" rel="noopener noreferrer"><Printer className="h-4 w-4" /> Print</a>
                    </Button>
                    <Button asChild>
                        <a href={route('material-requests.pdf', mr.id)} target="_blank" rel="noopener noreferrer"><FileDown className="h-4 w-4" /> PDF</a>
                    </Button>
                </div>
            </PageHeader>

            {mr.status === 'rejected' && approval?.comments && (
                <Card className="mb-4 border-red-200 bg-red-50/50">
                    <CardContent className="p-4">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-red-600">Rejection Reason</p>
                        <p className="mt-1 text-[13px] text-graphite-700">{approval.comments}</p>
                        {approval.approver && <p className="mt-1 text-xs text-graphite-400">by {approval.approver.name}</p>}
                    </CardContent>
                </Card>
            )}

            <Card className="mb-4">
                <CardContent className="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4">
                    <div>
                        <p className="text-[11px] uppercase tracking-wide text-graphite-400">Requested By</p>
                        <p className="text-[13px] font-medium text-graphite-800">{mr.requester?.name}</p>
                    </div>
                    <div>
                        <p className="text-[11px] uppercase tracking-wide text-graphite-400">Operating Unit</p>
                        <p className="text-[13px] font-medium text-graphite-800">{mr.company?.name || '-'}</p>
                    </div>
                    <div>
                        <p className="text-[11px] uppercase tracking-wide text-graphite-400">Department</p>
                        <p className="text-[13px] font-medium text-graphite-800">{mr.department?.name || '-'}</p>
                    </div>
                    <div>
                        <p className="text-[11px] uppercase tracking-wide text-graphite-400">Project</p>
                        <p className="text-[13px] font-medium text-graphite-800">{mr.project?.name || '-'}</p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader><CardTitle>Items</CardTitle></CardHeader>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Item</TableHead>
                                <TableHead>Specification</TableHead>
                                <TableHead>Qty</TableHead>
                                <TableHead>Unit</TableHead>
                                <TableHead>Reference</TableHead>
                                <TableHead>Remarks</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {mr.items.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="font-medium text-graphite-800">{item.item_name}</TableCell>
                                    <TableCell>{item.specification || '-'}</TableCell>
                                    <TableCell>{parseFloat(item.quantity)}</TableCell>
                                    <TableCell>{item.unit}</TableCell>
                                    <TableCell>
                                        {item.reference_image_url ? (
                                            <a href={item.reference_image_url} target="_blank" rel="noopener noreferrer">
                                                <img src={item.reference_image_url} className="h-10 w-10 rounded object-cover" alt="" />
                                            </a>
                                        ) : '-'}
                                    </TableCell>
                                    <TableCell>{item.remarks || '-'}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {/*
                v2.69.0 -- WHY THIS REQUEST IS STILL OPEN.
                The panel a requester needed and never had. It answers three
                questions the status alone could not: how long has this been
                waiting, is the wait deliberate, and who is holding it now.

                v2.71.0 -- AND NOW IT ANSWERS THEM IN PLAIN LANGUAGE, FOR
                EVERY STATUS.

                Two gaps, both reported: the panel rendered only while a
                request was outstanding or cancelled -- so a COMPLETED
                request, the one case where a requester most wants
                confirmation, showed nothing at all -- and even when it did
                render, it stated the status and expected the reader to
                already know what `approved` means about who is holding it.
                A requester should not have to learn the Procurement/Stores
                workflow to find out whether their gloves are coming.

                The stage line below names, in one sentence: what has
                happened, who holds it now, and what happens next.
            */}
            <Card className="mt-4">
                <CardHeader className="pb-2">
                    <CardTitle>Progress</CardTitle>
                    <CardDescription>Posisi permintaan ini pada alur pengadaan.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3 text-[13px]">
                        <RequestStage status={mr.status} />

                        {mr.is_outstanding && (
                            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span className="text-graphite-500">Open for</span>
                                <AgingIndicator days={mr.open_age_days} level={mr.aging_level} className="text-sm" />
                                <span className="text-graphite-400">&middot;</span>
                                <StatusBadge value={mr.status} label={mr.status === 'submitted' ? 'Waiting Approval' : undefined} />
                            </div>
                        )}

                        {mr.status === 'consolidating' && (
                            <div className="rounded-lg border border-steel-200 bg-steel-100/60 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                                <p className="flex items-center gap-1.5 font-medium text-navy-900 dark:text-slate-100">
                                    <Layers className="h-4 w-4" /> Ditahan untuk konsolidasi
                                </p>
                                <p className="mt-1 text-graphite-600 dark:text-slate-300">{mr.consolidation_reason}</p>
                                <p className="mt-1 text-xs text-graphite-400">
                                    {mr.consolidated_by?.name ? mr.consolidated_by.name + ' \u00b7 ' : ''}
                                    {formatDate(mr.consolidated_at)}
                                </p>
                                <p className="mt-2 text-xs text-graphite-500">
                                    Ini keputusan yang disengaja, bukan permintaan yang terlupakan &mdash; pengadaan menunggu kebutuhan sejenis agar dibeli sekaligus.
                                </p>
                            </div>
                        )}

                        {mr.status === 'cancelled' && mr.cancellation_reason && (
                            <div className="rounded-lg border border-graphite-200 bg-graphite-50 p-3 dark:border-slate-800 dark:bg-slate-800/50">
                                <p className="font-medium text-graphite-800 dark:text-slate-100">Alasan pembatalan</p>
                                <p className="mt-1 text-graphite-600 dark:text-slate-300">{mr.cancellation_reason}</p>
                            </div>
                        )}

                        {/*
                            The downstream chain, present only when a
                            purchase actually exists. An empty "no orders
                            yet" block would say nothing the status has not
                            already said.
                        */}
                        {mr.purchase_requisitions?.length > 0 && (
                            <div>
                                <p className="mb-1.5 flex items-center gap-1.5 font-medium text-graphite-800 dark:text-slate-100">
                                    <Truck className="h-4 w-4" /> Pengadaan terkait
                                </p>
                                <ul className="space-y-1.5">
                                    {mr.purchase_requisitions.map((pr) => (
                                        <li key={pr.id} className="rounded-md border border-graphite-100 px-2.5 py-2 dark:border-slate-800">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium text-graphite-800 dark:text-slate-100">{pr.pr_number}</span>
                                                <StatusBadge value={pr.status} />
                                            </div>
                                            {pr.purchase_orders?.length > 0 && (
                                                <ul className="mt-1.5 space-y-1 pl-3 text-xs text-graphite-600 dark:text-slate-300">
                                                    {pr.purchase_orders.map((po) => (
                                                        <li key={po.id} className="flex flex-wrap items-center gap-2">
                                                            <span className="font-medium">{po.po_number}</span>
                                                            <StatusBadge value={po.status} />
                                                            {po.delivery_date && <span className="text-graphite-400">due {formatDate(po.delivery_date)}</span>}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                </CardContent>
            </Card>

            {mr.notes && (
                <Card className="mt-4">
                    <CardHeader><CardTitle>Notes</CardTitle></CardHeader>
                    <CardContent className="text-[13px] text-graphite-600">{mr.notes}</CardContent>
                </Card>
            )}

            <Card className="mt-4">
                <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                <CardContent>
                    <ActivityTimeline activities={activities} />
                </CardContent>
            </Card>

            <Dialog open={holdOpen} onOpenChange={setHoldOpen}>
                <DialogContent>
                    <form onSubmit={submitHold}>
                        <DialogHeader>
                            <DialogTitle>Hold for consolidation</DialogTitle>
                            <DialogDescription>
                                Permintaan tetap disetujui dan tetap terhitung sebagai kebutuhan terbuka. Alasan inilah yang membedakannya dari permintaan yang terlupakan.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="py-3">
                            <FormField
                                label="Reason"
                                name="reason"
                                required
                                error={holdForm.errors.reason}
                                hint="Contoh: menunggu permintaan APD lain agar pembelian digabung."
                            >
                                <Textarea
                                    id="field-reason"
                                    rows={3}
                                    value={holdForm.data.reason}
                                    onChange={(e) => holdForm.setData('reason', e.target.value)}
                                />
                            </FormField>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setHoldOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={holdForm.processing}>Hold for consolidation</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                <DialogContent>
                    <form onSubmit={submitCancel}>
                        <DialogHeader>
                            <DialogTitle>Cancel this request</DialogTitle>
                            <DialogDescription>Tidak dapat dibatalkan. Alasannya tersimpan pada catatan permintaan.</DialogDescription>
                        </DialogHeader>

                        <div className="py-3">
                            <FormField label="Reason" name="cancel_reason" required error={cancelForm.errors.reason}>
                                <Textarea
                                    id="field-cancel_reason"
                                    rows={3}
                                    value={cancelForm.data.reason}
                                    onChange={(e) => cancelForm.setData('reason', e.target.value)}
                                />
                            </FormField>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setCancelOpen(false)}>Keep request</Button>
                            <Button type="submit" variant="outline" disabled={cancelForm.processing}>Cancel request</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}

/**
 * v2.71.0 -- WHERE IS MY REQUEST, IN ONE SENTENCE.
 *
 * A requester raises a Material Request and then has to work out what
 * `approved` versus `processing` versus `consolidating` means about
 * whether anything is actually happening. Those words are accurate, and
 * they are Procurement's vocabulary, not the requester's.
 *
 * Each entry answers the same three things in order: what has happened,
 * who is holding it now, and what happens next. English for the stage
 * NAME and the holder LABEL, Indonesian for the explanation -- the
 * product's language hierarchy, applied to a single component.
 *
 * `consolidating` deliberately gets the same calm steel treatment as an
 * ordinary in-progress stage, never a warning tone. It is a correct
 * buying decision, and colouring it as a problem would recreate exactly
 * the confusion v2.69.0 introduced the state to remove -- the deliberate
 * hold and the forgotten request must not look alike, in either
 * direction. What earns emphasis on a held request is its AGE, which the
 * aging indicator above already supplies.
 */
const STAGES = {
    draft: {
        holder: 'You',
        tone: 'neutral',
        text: 'Masih berupa draf dan belum diajukan. Kirim permintaan ini agar mulai diproses.',
    },
    submitted: {
        holder: 'Approver',
        tone: 'waiting',
        text: 'Menunggu keputusan persetujuan. Belum ada pembelian yang dimulai.',
    },
    approved: {
        holder: 'Procurement',
        tone: 'progress',
        text: 'Sudah disetujui dan menunggu diambil oleh tim pengadaan.',
    },
    consolidating: {
        holder: 'Procurement',
        tone: 'progress',
        text: 'Ditahan sengaja agar dibeli bersama kebutuhan sejenis. Permintaan Anda tetap tercatat dan usianya tetap berjalan.',
    },
    processing: {
        holder: 'Procurement',
        tone: 'progress',
        text: 'Sedang dipenuhi, baik dari stok maupun melalui pembelian. Dokumen pengadaan terkait muncul di bawah begitu diterbitkan.',
    },
    completed: {
        holder: null,
        tone: 'done',
        text: 'Sudah dipenuhi. Tidak ada tindakan lanjutan yang diperlukan.',
    },
    rejected: {
        holder: 'You',
        tone: 'stopped',
        text: 'Ditolak. Permintaan dapat diperbaiki dan diajukan kembali dari draf.',
    },
    cancelled: {
        holder: null,
        tone: 'stopped',
        text: 'Dibatalkan. Tidak ada pengadaan yang berjalan untuk permintaan ini.',
    },
};

const STAGE_TONE = {
    neutral: 'border-graphite-200 bg-graphite-50 text-graphite-700 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-300',
    waiting: 'border-warning/25 bg-warning/[0.07] text-amber-900 dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-200',
    progress: 'border-steel-200 bg-steel-50 text-navy-800 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300',
    done: 'border-success/25 bg-success/[0.07] text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/20 dark:text-emerald-200',
    stopped: 'border-graphite-200 bg-graphite-50 text-graphite-600 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400',
};

function RequestStage({ status }) {
    const stage = STAGES[status];

    if (! stage) return null;

    return (
        <div className={`rounded-lg border p-3 ${STAGE_TONE[stage.tone]}`}>
            <p className="leading-relaxed">{stage.text}</p>
            {stage.holder && (
                <p className="mt-1.5 text-xs opacity-80">
                    Saat ini pada: <span className="font-semibold">{stage.holder}</span>
                </p>
            )}
        </div>
    );
}
