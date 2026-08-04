import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import ApprovalActions from '@/Components/shared/ApprovalActions';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, XCircle } from 'lucide-react';

export default function LeaveShow({ leaveRequest: lr, approval, activities, canDecide, canManage }) {
    function cancel() {
        if (confirm('Cancel this leave request?')) {
            router.post(route('leave-requests.cancel', lr.id));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={lr.leave_number} />

            <Link href={route('leave-requests.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Leave
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">
                        {lr.leave_number}
                        <StatusBadge value={lr.status} label={lr.status === 'submitted' ? 'Waiting Approval' : undefined} />
                    </h1>
                    <p className="text-xs text-graphite-500">
                        {lr.employee?.full_name} ({lr.employee?.employee_id}) · <span className="capitalize">{lr.leave_type}</span> leave ·{' '}
                        {new Date(lr.start_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })}
                        {' - '}
                        {new Date(lr.end_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                        {' '}({lr.days} day{lr.days !== 1 ? 's' : ''})
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {lr.status === 'submitted' && <ApprovalActions approval={approval} canDecide={canDecide} />}
                    {['draft', 'submitted', 'approved'].includes(lr.status) && canManage && (
                        <Button variant="outline" onClick={cancel}><XCircle className="h-4 w-4" /> Cancel</Button>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Requested By</span><p>{lr.requester?.name}</p></div>
                        <div><span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Reason</span><p className="whitespace-pre-wrap">{lr.reason || '-'}</p></div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Activity</CardTitle></CardHeader>
                    <CardContent><ActivityTimeline activities={activities} /></CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
