import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, Pencil, Send, CheckCircle2, Archive, XCircle } from 'lucide-react';

export default function RiskAssessmentShow({ riskAssessment: r, activities, canManage }) {
    function transition(status, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route('risk-assessments.transition', r.id), { status });
    }

    return (
        <AuthenticatedLayout>
            <Head title={r.ra_number} />

            <Link href={route('risk-assessments.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to HIRADC
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">{r.ra_number}<StatusBadge value={r.status} /></h1>
                    <p className="text-xs text-graphite-500">{r.title} · {new Date(r.assessment_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}{r.location && ` · ${r.location}`}{r.project && ` · ${r.project.name}`}</p>
                </div>
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2">
                        {r.status === 'draft' && (<>
                            <Button variant="outline" asChild><Link href={route('risk-assessments.edit', r.id)}><Pencil className="h-4 w-4" /> Edit</Link></Button>
                            <Button variant="outline" onClick={() => transition('submitted', 'Submit for review?')}><Send className="h-4 w-4" /> Submit</Button>
                        </>)}
                        {r.status === 'submitted' && (
                            <Button onClick={() => transition('approved', 'Approve this HIRADC?')}><CheckCircle2 className="h-4 w-4" /> Approve</Button>
                        )}
                        {r.status === 'approved' && (
                            <Button variant="outline" onClick={() => transition('archived', 'Archive this HIRADC?')}><Archive className="h-4 w-4" /> Archive</Button>
                        )}
                        {!['archived', 'cancelled'].includes(r.status) && (
                            <Button variant="ghost" className="text-red-600" onClick={() => transition('cancelled', 'Cancel this HIRADC?')}><XCircle className="h-4 w-4" /> Cancel</Button>
                        )}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Hazard / Risk / Control</CardTitle></CardHeader>
                        <CardContent className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Activity</TableHead><TableHead>Hazard</TableHead><TableHead>Existing Control</TableHead>
                                        <TableHead>L</TableHead><TableHead>S</TableHead><TableHead>Risk</TableHead>
                                        <TableHead>Additional Control</TableHead><TableHead>PIC</TableHead><TableHead>Target</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {(r.items || []).map((item, i) => (
                                        <TableRow key={i}>
                                            <TableCell>{item.activity}</TableCell>
                                            <TableCell>{item.hazard}</TableCell>
                                            <TableCell>{item.existing_control}</TableCell>
                                            <TableCell>{item.likelihood}</TableCell>
                                            <TableCell>{item.severity}</TableCell>
                                            <TableCell>{(Number(item.likelihood) || 0) * (Number(item.severity) || 0)}</TableCell>
                                            <TableCell>{item.additional_control}</TableCell>
                                            <TableCell>{item.pic}</TableCell>
                                            <TableCell>{item.target_date}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>Sign-off</CardTitle></CardHeader>
                        <CardContent className="grid grid-cols-3 gap-3 text-sm">
                            <div><span className="text-xs uppercase text-graphite-400">Prepared By</span><p>{r.preparer?.name}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Reviewed By</span><p>{r.reviewer?.name || '-'}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Approved By</span><p>{r.approver?.name || '-'}</p></div>
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
