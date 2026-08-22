import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, CheckCircle2, XCircle, LogIn, LogOut } from 'lucide-react';

export default function VisitorShow({ visitor: v, canManage }) {
    function act(routeName, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route(routeName, v.id));
    }

    return (
        <AuthenticatedLayout>
            <Head title={v.name} />

            <Link href={route('visitors.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Visitors
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight text-graphite-900">{v.name}<StatusBadge value={v.status === 'approved' || v.status === 'checked_in' ? 'approved' : v.status === 'rejected' ? 'rejected' : v.status} label={v.status.replace('_', ' ')} /></h1>
                    <p className="text-xs text-graphite-500">{v.visitor_number} · {v.visitor_company || 'No company'} · Host: {v.host_employee?.full_name}</p>
                </div>
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2">
                        {v.status === 'pending' && (<>
                            <Button onClick={() => act('visitors.approve', 'Approve this visitor?')}><CheckCircle2 className="h-4 w-4" /> Approve</Button>
                            <Button variant="outline" className="text-red-600" onClick={() => act('visitors.reject', 'Reject this visitor?')}><XCircle className="h-4 w-4" /> Reject</Button>
                        </>)}
                        {v.status === 'approved' && (<Button onClick={() => act('visitors.check-in', 'Check in this visitor?')}><LogIn className="h-4 w-4" /> Check In</Button>)}
                        {v.status === 'checked_in' && (<Button variant="outline" onClick={() => act('visitors.check-out', 'Check out this visitor?')}><LogOut className="h-4 w-4" /> Check Out</Button>)}
                    </div>
                )}
            </div>

            <Card>
                <CardHeader><CardTitle>Details</CardTitle></CardHeader>
                <CardContent className="space-y-3 text-sm">
                    <div><span className="text-xs uppercase text-graphite-400">Purpose</span><p>{v.purpose || '-'}</p></div>
                    <div className="grid grid-cols-2 gap-3">
                        <div><span className="text-xs uppercase text-graphite-400">Phone</span><p>{v.contact_phone || '-'}</p></div>
                        <div><span className="text-xs uppercase text-graphite-400">Email</span><p>{v.contact_email || '-'}</p></div>
                    </div>
                    <div>
                        <span className="text-xs uppercase text-graphite-400">HSE Induction</span>
                        <div className="mt-1 flex items-center gap-2">
                            <Checkbox checked={v.hse_induction_completed} disabled={!canManage} onCheckedChange={() => canManage && router.post(route('visitors.induction', v.id))} />
                            <span>{v.hse_induction_completed ? 'Completed' : 'Not completed'}</span>
                        </div>
                    </div>
                    {v.checked_in_at && <div><span className="text-xs uppercase text-graphite-400">Checked In</span><p>{new Date(v.checked_in_at).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</p></div>}
                    {v.checked_out_at && <div><span className="text-xs uppercase text-graphite-400">Checked Out</span><p>{new Date(v.checked_out_at).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</p></div>}
                    {v.notes && <div><span className="text-xs uppercase text-graphite-400">Notes</span><p className="whitespace-pre-wrap">{v.notes}</p></div>}
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
