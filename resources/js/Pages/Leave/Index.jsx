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
import PersonChip from '@/Components/shared/PersonChip';
import { Plus, Search, CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';

function formatRange(start, end) {
    return `${new Date(start).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })} - ${new Date(end).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`;
}

/**
 * Leave (v1.10.0) -- HR's first real module. Same list/filter/paginate
 * pattern as Material Requests.
 *
 * v2.32.0 (Interior UI Completion Phase 3B, Part 4): this page previously
 * had 7 equal-weight columns, a boxed filter card, and no mobile card
 * fallback -- it relied entirely on the shared Table's own
 * `overflow-auto`, meaning a narrow viewport just horizontally scrolled a
 * 7-column table instead of getting a compact identity-first list.
 * Reworked around the exact same pattern already proven on Employees/PTW/
 * Incidents this session: PersonChip for the employee identity, PageHeader,
 * an unboxed filter row, a mobile card list, and desktop columns
 * consolidated from 7 to 4 (Employee, Leave, Dates, Status -- Leave No./
 * Type/Requested By grouped under their natural parent instead of each
 * getting an equal-weight column). No data dropped, no workflow/business
 * logic touched.
 */
export default function LeaveIndex({ leaveRequests, filters, can }) {
    function applyFilters(overrides = {}) {
        router.get(route('leave-requests.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Leave" />

            <PageHeader title="Leave" subtitle="Pengajuan cuti karyawan dan status persetujuannya.">
                {can.manage && (
                    <Button asChild>
                        <Link href={route('leave-requests.create')}><Plus className="h-4 w-4" /> New Leave Request</Link>
                    </Button>
                )}
            </PageHeader>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="relative min-w-[220px] flex-1">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                    <Input
                        className="border-graphite-200 bg-white pl-8 shadow-none"
                        placeholder="Search leave number..."
                        defaultValue={filters.search || ''}
                        onChange={(e) => applyFilters({ search: e.target.value || null })}
                    />
                </div>
                <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-44 bg-white"><SelectValue placeholder="Status" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Statuses</SelectItem>
                        <SelectItem value="draft">Draft</SelectItem>
                        <SelectItem value="submitted">Submitted / Pending Approval</SelectItem>
                        <SelectItem value="approved">Approved</SelectItem>
                        <SelectItem value="rejected">Rejected</SelectItem>
                        <SelectItem value="cancelled">Cancelled</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <Card>
                <CardContent className="p-0">
                    {leaveRequests.data.length === 0 ? (
                        <EmptyState icon={CalendarDays} title="Belum ada pengajuan cuti" description="Buat pengajuan cuti pertama untuk memulai." />
                    ) : (
                        <>
                            <div className="divide-y divide-graphite-100 md:hidden">
                                {leaveRequests.data.map((r) => (
                                    <Link
                                        key={r.id}
                                        href={route('leave-requests.show', r.id)}
                                        className="block px-4 py-3 transition-colors active:bg-graphite-50"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <PersonChip name={r.employee?.full_name} subtitle={`${r.leave_number} · ${r.leave_type}`} />
                                            <StatusBadge value={r.status} label={r.status === 'submitted' ? 'Waiting Approval' : undefined} />
                                        </div>
                                        <div className="mt-1.5 flex items-center justify-between text-xs text-graphite-400">
                                            <span>{formatRange(r.start_date, r.end_date)}</span>
                                            <span>{r.days} hari</span>
                                        </div>
                                    </Link>
                                ))}
                            </div>

                            <Table className="hidden md:table">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Leave</TableHead>
                                        <TableHead>Dates</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {leaveRequests.data.map((r) => (
                                        <TableRow key={r.id} className="cursor-pointer" onClick={() => router.visit(route('leave-requests.show', r.id))}>
                                            <TableCell><PersonChip name={r.employee?.full_name} subtitle={r.requester?.name ? `Requested by ${r.requester.name}` : undefined} /></TableCell>
                                            <TableCell>
                                                <p className="capitalize text-graphite-800 dark:text-slate-100">{r.leave_type}</p>
                                                <p className="text-xs text-graphite-500">{r.leave_number}</p>
                                            </TableCell>
                                            <TableCell>
                                                <p className="text-graphite-800 dark:text-slate-100">{formatRange(r.start_date, r.end_date)}</p>
                                                <p className="text-xs text-graphite-500">{r.days} hari</p>
                                            </TableCell>
                                            <TableCell><StatusBadge value={r.status} label={r.status === 'submitted' ? 'Waiting Approval' : undefined} /></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
                    )}
                </CardContent>
            </Card>

            {leaveRequests.last_page > 1 && (
                <div className="mt-3 flex items-center justify-between text-xs text-graphite-500 dark:text-slate-400">
                    <span>Page {leaveRequests.current_page} of {leaveRequests.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!leaveRequests.prev_page_url} onClick={() => router.get(leaveRequests.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <button disabled={!leaveRequests.next_page_url} onClick={() => router.get(leaveRequests.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
