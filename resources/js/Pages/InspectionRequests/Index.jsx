import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, ClipboardCheck, ChevronLeft, ChevronRight } from 'lucide-react';

export default function InspectionRequestsIndex({ inspections, can }) {
    return (
        <AuthenticatedLayout>
            <Head title="QC Inspection Request" />
            <PageHeader title="QC Inspection Request" subtitle="Quality control inspections against project activities.">
                {can.manage && (<Button asChild><Link href={route('inspection-requests.create')}><Plus className="h-4 w-4" /> Request Inspection</Link></Button>)}
            </PageHeader>

            <Card>
                <CardContent className="p-0">
                    {inspections.data.length === 0 ? (
                        <EmptyState icon={ClipboardCheck} title="No inspection requests" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Inspection No.</TableHead><TableHead>Project</TableHead><TableHead>Date</TableHead><TableHead>Inspector</TableHead><TableHead>Result</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {inspections.data.map((i) => (
                                    <TableRow key={i.id} className="cursor-pointer" onClick={() => router.visit(route('inspection-requests.show', i.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{i.inspection_number}</TableCell>
                                        <TableCell>{i.project?.name}</TableCell>
                                        <TableCell>{new Date(i.inspection_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell>{i.inspector?.name}</TableCell>
                                        <TableCell>{i.result ? <StatusBadge value={i.result === 'passed' ? 'approved' : 'rejected'} label={i.result} /> : '-'}</TableCell>
                                        <TableCell><StatusBadge value={i.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {inspections.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {inspections.current_page} of {inspections.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!inspections.prev_page_url} onClick={() => router.get(inspections.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!inspections.next_page_url} onClick={() => router.get(inspections.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
