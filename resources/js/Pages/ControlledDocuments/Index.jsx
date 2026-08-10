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
import { Plus, Search, FileText, ChevronLeft, ChevronRight } from 'lucide-react';

export default function ControlledDocumentsIndex({ documents, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('controlled-documents.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Document Control" />
            <PageHeader title="Document Control" subtitle="Controlled documents: SOPs, policies, drawings, with version and approval history.">
                {can.manage && (<Button asChild><Link href={route('controlled-documents.create')}><Plus className="h-4 w-4" /> New Document</Link></Button>)}
            </PageHeader>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px]">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search title or document number..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {['draft', 'review', 'approved', 'effective', 'obsolete'].map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {documents.data.length === 0 ? (
                        <EmptyState icon={FileText} title="No controlled documents" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Doc No.</TableHead><TableHead>Title</TableHead><TableHead>Category</TableHead><TableHead>Department</TableHead><TableHead>Version</TableHead><TableHead>Owner</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {documents.data.map((d) => (
                                    <TableRow key={d.id} className="cursor-pointer" onClick={() => router.visit(route('controlled-documents.show', d.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{d.document_number}</TableCell>
                                        <TableCell>{d.title}</TableCell>
                                        <TableCell>{d.category || '-'}</TableCell>
                                        <TableCell>{d.department?.name || '-'}</TableCell>
                                        <TableCell>{d.version}</TableCell>
                                        <TableCell>{d.owner?.name}</TableCell>
                                        <TableCell><StatusBadge value={d.status === 'effective' ? 'approved' : d.status === 'obsolete' ? 'secondary' : d.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {documents.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {documents.current_page} of {documents.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!documents.prev_page_url} onClick={() => router.get(documents.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!documents.next_page_url} onClick={() => router.get(documents.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
