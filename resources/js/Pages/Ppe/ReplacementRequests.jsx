import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PpeTabNav from '@/Components/shared/PpeTabNav';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import EmptyState from '@/Components/shared/EmptyState';
import { FileText, ChevronLeft, ChevronRight } from 'lucide-react';

const STATUS_VARIANT = { draft: 'secondary', submitted: 'success' };

export default function PpeReplacementRequests({ requests }) {
    return (
        <AuthenticatedLayout>
            <Head title="Replacement Requests" />

            <PpeTabNav />

            <div className="mb-4">
                <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">Replacement Requests</h1>
                <p className="text-xs text-graphite-500 dark:text-slate-400">Created from the Replacement Due list.</p>
            </div>

            <Card>
                <CardContent className="p-0">
                    {requests.data.length === 0 ? (
                        <EmptyState icon={FileText} title="No replacement requests yet" description="Create one from the Replacement Due list." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Request No.</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Requested By</TableHead>
                                    <TableHead>Items</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {requests.data.map((r) => (
                                    <TableRow key={r.id} className="cursor-pointer" onClick={() => router.visit(route('ppe.replacement-requests.show', r.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{r.request_number}</TableCell>
                                        <TableCell>{new Date(r.request_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell>{r.requester?.name}</TableCell>
                                        <TableCell>{r.items_count}</TableCell>
                                        <TableCell><Badge variant={STATUS_VARIANT[r.status]}>{r.status}</Badge></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {requests.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {requests.current_page} of {requests.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!requests.prev_page_url} onClick={() => router.get(requests.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <button disabled={!requests.next_page_url} onClick={() => router.get(requests.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
