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
import { Plus, Search, UserCheck, ChevronLeft, ChevronRight } from 'lucide-react';

export default function VisitorsIndex({ visitors, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('visitors.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Visitor Management" />
            <PageHeader title="Visitor Management" subtitle="Registration, approval, and site check-in/out.">
                <Button asChild><Link href={route('visitors.create')}><Plus className="h-4 w-4" /> Register Visitor</Link></Button>
            </PageHeader>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px]">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search visitor name or number..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {['pending', 'approved', 'rejected', 'checked_in', 'checked_out'].map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace('_', ' ')}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {visitors.data.length === 0 ? (
                        <EmptyState icon={UserCheck} title="No visitors registered" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Visitor No.</TableHead><TableHead>Name</TableHead><TableHead>Company</TableHead><TableHead>Host</TableHead><TableHead>Visit Date</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {visitors.data.map((v) => (
                                    <TableRow key={v.id} className="cursor-pointer" onClick={() => router.visit(route('visitors.show', v.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{v.visitor_number}</TableCell>
                                        <TableCell>{v.name}</TableCell>
                                        <TableCell>{v.visitor_company || '-'}</TableCell>
                                        <TableCell>{v.host_employee?.full_name || '-'}</TableCell>
                                        <TableCell>{new Date(v.visit_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell><StatusBadge value={v.status === 'approved' || v.status === 'checked_in' ? 'approved' : v.status === 'rejected' ? 'rejected' : v.status} label={v.status.replace('_', ' ')} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {visitors.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {visitors.current_page} of {visitors.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!visitors.prev_page_url} onClick={() => router.get(visitors.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!visitors.next_page_url} onClick={() => router.get(visitors.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
