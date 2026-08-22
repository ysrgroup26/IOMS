import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import ApprovalActions from '@/Components/shared/ApprovalActions';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, Pencil, FileDown, Printer, PackageCheck, CheckCheck, RotateCcw, XCircle } from 'lucide-react';

export default function MaterialRequestShow({ materialRequest: mr, approval, activities, canDecide, canProcess, canOverride }) {
    function act(action, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route(`material-requests.${action}`, mr.id));
    }

    return (
        <AuthenticatedLayout>
            <Head title={mr.request_number} />

            <Link href={route('material-requests.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Material Requests
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight text-graphite-900">
                        {mr.request_number}
                        <StatusBadge value={mr.status} label={mr.status === 'submitted' ? 'Waiting Approval' : undefined} />
                    </h1>
                    <p className="text-xs text-graphite-500">
                        {new Date(mr.request_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}
                        {mr.department && ` · ${mr.department.name}`}
                        {mr.project && ` · ${mr.project.name}`}
                        {mr.completed_at && ` · Completed ${new Date(mr.completed_at).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`}
                    </p>
                </div>
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
                    {mr.status === 'approved' && canProcess && (
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
                    {canOverride && !['completed', 'cancelled'].includes(mr.status) && (
                        <Button variant="outline" onClick={() => act('cancel', 'Cancel this request? This cannot be undone.')}>
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
            </div>

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
                        <p className="text-[11px] uppercase tracking-wide text-graphite-400">Company</p>
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
        </AuthenticatedLayout>
    );
}
