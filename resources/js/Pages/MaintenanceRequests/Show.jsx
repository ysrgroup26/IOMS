import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, CheckCircle2, XCircle, FilePlus } from 'lucide-react';

export default function MaintenanceRequestShow({ maintenanceRequest: mr, activities, canManage }) {
    function act(status, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route('maintenance-requests.transition', mr.id), { status });
    }

    return (
        <AuthenticatedLayout>
            <Head title={mr.request_number} />

            <Link href={route('maintenance-requests.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Maintenance Requests
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">{mr.request_number}<StatusBadge value={mr.priority} /><StatusBadge value={mr.status} /></h1>
                    <p className="text-xs text-graphite-500">{mr.asset?.name} ({mr.asset?.asset_code}) · {new Date(mr.request_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}</p>
                </div>
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2">
                        {mr.status === 'reported' && (<>
                            <Button onClick={() => act('approved', 'Approve this maintenance request?')}><CheckCircle2 className="h-4 w-4" /> Approve</Button>
                            <Button variant="outline" className="text-red-600" onClick={() => act('rejected', 'Reject this request?')}>Reject</Button>
                        </>)}
                        {mr.status === 'approved' && (
                            <Button variant="outline" asChild><Link href={route('work-orders.create', { mr: mr.id })}><FilePlus className="h-4 w-4" /> Create Work Order</Link></Button>
                        )}
                        {!['converted_to_wo', 'cancelled'].includes(mr.status) && (
                            <Button variant="ghost" className="text-red-600" onClick={() => act('cancelled', 'Cancel this request?')}><XCircle className="h-4 w-4" /> Cancel</Button>
                        )}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div><span className="text-xs uppercase text-graphite-400">Problem</span><p>{mr.problem}</p></div>
                            {mr.description && <div><span className="text-xs uppercase text-graphite-400">Description</span><p className="whitespace-pre-wrap">{mr.description}</p></div>}
                            <div><span className="text-xs uppercase text-graphite-400">Reported By</span><p>{mr.reporter?.name}</p></div>
                        </CardContent>
                    </Card>

                    {mr.work_orders?.length > 0 && (
                        <Card>
                            <CardHeader><CardTitle>Work Orders</CardTitle></CardHeader>
                            <CardContent className="flex flex-wrap gap-4 text-sm">
                                {mr.work_orders.map((wo) => <Link key={wo.id} href={route('work-orders.show', wo.id)} className="text-brand-700 hover:underline">{wo.wo_number}</Link>)}
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
