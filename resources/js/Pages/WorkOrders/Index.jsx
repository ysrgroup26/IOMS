import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import StatCard from '@/Components/shared/StatCard';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Search, ClipboardList, AlertTriangle, ChevronLeft, ChevronRight } from 'lucide-react';

export default function WorkOrdersIndex({ workOrders, filters, openCount, overdueCount, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('work-orders.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Work Order" />
            <PageHeader title="Work Order" subtitle="Preventive and corrective maintenance execution.">
                {can.manage && (<Button asChild><Link href={route('work-orders.create')}><Plus className="h-4 w-4" /> New Work Order</Link></Button>)}
            </PageHeader>

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={ClipboardList} value={openCount} label="Open Work Orders" />
                <StatCard icon={AlertTriangle} value={overdueCount} label="Overdue Maintenance" accent={overdueCount > 0 ? 'red' : null} />
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px]">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input className="pl-8" placeholder="Search WO number..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {['draft', 'scheduled', 'in_progress', 'completed', 'cancelled'].map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace('_', ' ')}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {workOrders.data.length === 0 ? (
                        <EmptyState icon={ClipboardList} title="No work orders" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>WO No.</TableHead><TableHead>Asset</TableHead><TableHead>Type</TableHead><TableHead>Technician</TableHead><TableHead>Planned Date</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {workOrders.data.map((wo) => (
                                    <TableRow key={wo.id} className="cursor-pointer" onClick={() => router.visit(route('work-orders.show', wo.id))}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{wo.wo_number}</TableCell>
                                        <TableCell>{wo.asset?.name}</TableCell>
                                        <TableCell className="capitalize">{wo.maintenance_type}</TableCell>
                                        <TableCell>{wo.technician?.full_name || '-'}</TableCell>
                                        <TableCell>{new Date(wo.planned_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell><StatusBadge value={wo.status} /></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {workOrders.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {workOrders.current_page} of {workOrders.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!workOrders.prev_page_url} onClick={() => router.get(workOrders.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronLeft className="h-4 w-4" /></button>
                        <button disabled={!workOrders.next_page_url} onClick={() => router.get(workOrders.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700"><ChevronRight className="h-4 w-4" /></button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
