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
import PersonChip from '@/Components/shared/PersonChip';
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

            {/* v2.32.0 (Interior UI Completion Phase 3B, Part 7): unboxed,
                matching the established filter-toolbar convention used
                across every other list page this session (was the only
                remaining boxed filter Card on this page). */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="relative min-w-[220px] flex-1">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                    <Input className="border-graphite-200 bg-white pl-8 shadow-none" placeholder="Search WO number..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                </div>
                <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-44 bg-white"><SelectValue placeholder="Status" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Statuses</SelectItem>
                        {['draft', 'scheduled', 'in_progress', 'completed', 'cancelled'].map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace('_', ' ')}</SelectItem>)}
                    </SelectContent>
                </Select>
            </div>

            <Card>
                <CardContent className="p-0">
                    {workOrders.data.length === 0 ? (
                        <EmptyState icon={ClipboardList} title="No work orders" />
                    ) : (
                        <>
                            {/* v2.32.0: this page had no mobile fallback --
                                a 6-column table just scrolled horizontally
                                on a narrow viewport. Compact identity-first
                                cards, same pattern as every other list page
                                this session. */}
                            <div className="divide-y divide-graphite-100 md:hidden">
                                {workOrders.data.map((wo) => (
                                    <Link
                                        key={wo.id}
                                        href={route('work-orders.show', wo.id)}
                                        className="block px-4 py-3 transition-colors active:bg-graphite-50"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="font-medium text-graphite-900">{wo.wo_number}</p>
                                                <p className="truncate text-xs capitalize text-graphite-500">{wo.maintenance_type} · {wo.asset?.name}</p>
                                            </div>
                                            <StatusBadge value={wo.status} />
                                        </div>
                                        <div className="mt-1.5 flex items-center justify-between text-xs text-graphite-400">
                                            <span>{wo.technician?.full_name || 'Belum ditugaskan'}</span>
                                            <span>{new Date(wo.planned_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                        </div>
                                    </Link>
                                ))}
                            </div>

                            <Table className="hidden md:table">
                                <TableHeader><TableRow><TableHead>Work Order</TableHead><TableHead>Asset</TableHead><TableHead>Technician</TableHead><TableHead>Planned Date</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
                                <TableBody>
                                    {workOrders.data.map((wo) => (
                                        <TableRow key={wo.id} className="cursor-pointer" onClick={() => router.visit(route('work-orders.show', wo.id))}>
                                            <TableCell>
                                                <p className="font-medium text-graphite-800 dark:text-slate-100">{wo.wo_number}</p>
                                                <p className="text-xs capitalize text-graphite-500">{wo.maintenance_type}</p>
                                            </TableCell>
                                            <TableCell>{wo.asset?.name}</TableCell>
                                            <TableCell>{wo.technician ? <PersonChip name={wo.technician.full_name} size="sm" /> : <span className="text-graphite-400">—</span>}</TableCell>
                                            <TableCell>{new Date(wo.planned_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                            <TableCell><StatusBadge value={wo.status} /></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
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
