import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, Building2, ChevronLeft, ChevronRight } from 'lucide-react';

export default function ContractorsIndex({ contractors, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('contractors.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Contractor Management" />
            <PageHeader title="Contractor Management" subtitle="Contractor companies, workers, and HSE compliance documents.">
                {can.manage && (<Button asChild><Link href={route('contractors.create')}><Plus className="h-4 w-4" /> Register Contractor</Link></Button>)}
            </PageHeader>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px]">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search contractor name or code..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.approval_status || 'all'} onValueChange={(v) => applyFilters({ approval_status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Approval" /></SelectTrigger>
                        <SelectContent><SelectItem value="all">All</SelectItem><SelectItem value="pending">Pending</SelectItem><SelectItem value="approved">Approved</SelectItem><SelectItem value="rejected">Rejected</SelectItem></SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {contractors.data.length === 0 ? (
                        <EmptyState icon={Building2} title="No contractors registered" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Company</TableHead><TableHead>PIC</TableHead><TableHead>Workers</TableHead><TableHead>Approval</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {contractors.data.map((c) => (
                                    <TableRow key={c.id} className="cursor-pointer" onClick={() => router.visit(route('contractors.show', c.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{c.code}</TableCell>
                                        <TableCell>{c.company_name}</TableCell>
                                        <TableCell>{c.pic_name || '-'}</TableCell>
                                        <TableCell>{c.workers_count}</TableCell>
                                        <TableCell><StatusBadge value={c.approval_status === 'approved' ? 'approved' : c.approval_status === 'rejected' ? 'rejected' : c.approval_status} /></TableCell>
                                        <TableCell><StatusBadge value={c.status === 'active' ? 'active' : c.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {contractors.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {contractors.current_page} of {contractors.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!contractors.prev_page_url} onClick={() => router.get(contractors.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!contractors.next_page_url} onClick={() => router.get(contractors.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
