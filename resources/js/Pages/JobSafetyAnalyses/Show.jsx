import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import { ArrowLeft, Pencil, Send, CheckCircle2, Archive, XCircle } from 'lucide-react';

export default function JobSafetyAnalysisShow({ jsa: j, activities, canManage }) {
    function transition(status, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route('job-safety-analyses.transition', j.id), { status });
    }

    return (
        <AuthenticatedLayout>
            <Head title={j.jsa_number} />

            <Link href={route('job-safety-analyses.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to JSA
            </Link>

            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="flex items-center gap-2 text-lg font-bold tracking-tight text-graphite-900">{j.jsa_number}<StatusBadge value={j.status} /></h1>
                    <p className="text-xs text-graphite-500">{j.job_title} · {new Date(j.jsa_date).toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })}{j.location && ` · ${j.location}`}{j.project && ` · ${j.project.name}`}</p>
                </div>
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2">
                        {j.status === 'draft' && (<>
                            <Button variant="outline" asChild><Link href={route('job-safety-analyses.edit', j.id)}><Pencil className="h-4 w-4" /> Edit</Link></Button>
                            <Button variant="outline" onClick={() => transition('submitted', 'Submit for review?')}><Send className="h-4 w-4" /> Submit</Button>
                        </>)}
                        {j.status === 'submitted' && (<Button onClick={() => transition('approved', 'Approve this JSA?')}><CheckCircle2 className="h-4 w-4" /> Approve</Button>)}
                        {j.status === 'approved' && (<Button variant="outline" onClick={() => transition('archived', 'Archive this JSA?')}><Archive className="h-4 w-4" /> Archive</Button>)}
                        {!['archived', 'cancelled'].includes(j.status) && (
                            <Button variant="ghost" className="text-red-600" onClick={() => transition('cancelled', 'Cancel this JSA?')}><XCircle className="h-4 w-4" /> Cancel</Button>
                        )}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {j.required_ppe?.length > 0 && (
                        <Card>
                            <CardHeader><CardTitle>Required PPE</CardTitle></CardHeader>
                            <CardContent className="flex flex-wrap gap-2">
                                {j.required_ppe.map((p, i) => <Badge key={i} variant="outline">{p}</Badge>)}
                            </CardContent>
                        </Card>
                    )}
                    <Card>
                        <CardHeader><CardTitle>Task Steps</CardTitle></CardHeader>
                        <CardContent className="overflow-x-auto">
                            <Table>
                                <TableHeader><TableRow><TableHead className="w-10">#</TableHead><TableHead>Task Step</TableHead><TableHead>Potential Hazard</TableHead><TableHead>Control Measure</TableHead></TableRow></TableHeader>
                                <TableBody>
                                    {(j.steps || []).map((step, i) => (
                                        <TableRow key={i}>
                                            <TableCell>{i + 1}</TableCell>
                                            <TableCell>{step.task_step}</TableCell>
                                            <TableCell>{step.potential_hazard}</TableCell>
                                            <TableCell>{step.control_measure}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle>Sign-off</CardTitle></CardHeader>
                        <CardContent className="grid grid-cols-3 gap-3 text-sm">
                            <div><span className="text-xs uppercase text-graphite-400">Prepared By</span><p>{j.preparer?.name}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Reviewed By</span><p>{j.reviewer?.name || '-'}</p></div>
                            <div><span className="text-xs uppercase text-graphite-400">Approved By</span><p>{j.approver?.name || '-'}</p></div>
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
