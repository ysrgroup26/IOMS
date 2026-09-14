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
import { Plus, Search, ShieldAlert, ChevronLeft, ChevronRight } from 'lucide-react';

function humanize(value) {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function EmployeeCasesIndex({ cases, filters, summary, options }) {
    function applyFilters(overrides = {}) {
        router.get(route('employee-cases.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    const activeOnly = !!filters.active;

    return (
        <AuthenticatedLayout>
            <Head title="Employee Cases" />
            <PageHeader
                title="Employee Cases"
                subtitle="Catatan resmi permasalahan karyawan, dari saat dilaporkan sampai ditutup."
            >
                <Button asChild><Link href={route('employee-cases.create')}><Plus className="h-4 w-4" /> Open Case</Link></Button>
            </PageHeader>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap items-center gap-2 p-3">
                    <div className="relative min-w-[240px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input
                            className="pl-8"
                            placeholder="Search case number, subject or employee..."
                            defaultValue={filters.search || ''}
                            onChange={(e) => applyFilters({ search: e.target.value || null })}
                        />
                    </div>

                    {/*
                        "Needs attention" is every unresolved state at once,
                        which is the question a caseload owner actually asks
                        and which no single status value can express.
                    */}
                    <Button
                        type="button"
                        variant={activeOnly ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => applyFilters({ active: activeOnly ? null : 1 })}
                        aria-pressed={activeOnly}
                    >
                        Needs attention
                        {summary.active > 0 && (
                            <span className="ml-1.5 rounded-full bg-white/20 px-1.5 text-[11px] tabular-nums">{summary.active}</span>
                        )}
                    </Button>

                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {options.statuses.map((s) => <SelectItem key={s} value={s}>{humanize(s)}</SelectItem>)}
                        </SelectContent>
                    </Select>

                    <Select value={filters.category || 'all'} onValueChange={(v) => applyFilters({ category: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Category" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Categories</SelectItem>
                            {options.categories.map((c) => <SelectItem key={c} value={c}>{humanize(c)}</SelectItem>)}
                        </SelectContent>
                    </Select>

                    <Select value={filters.severity || 'all'} onValueChange={(v) => applyFilters({ severity: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-36"><SelectValue placeholder="Severity" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Severities</SelectItem>
                            {options.severities.map((s) => <SelectItem key={s} value={s}>{humanize(s)}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {cases.data.length === 0 ? (
                        <EmptyState
                            icon={ShieldAlert}
                            title="Belum ada kasus tercatat"
                            description="Kasus yang dibuka akan tercatat di sini beserta tindakan yang diterbitkan."
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Case No.</TableHead>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Subject</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Severity</TableHead>
                                        <TableHead>Raised</TableHead>
                                        <TableHead>Handler</TableHead>
                                        <TableHead>Actions</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {cases.data.map((c) => (
                                        <TableRow key={c.id} className="cursor-pointer" onClick={() => router.visit(route('employee-cases.show', c.id))}>
                                            <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{c.case_number}</TableCell>
                                            <TableCell>
                                                <div className="font-medium text-graphite-800 dark:text-slate-100">{c.employee?.full_name || '-'}</div>
                                                <div className="text-xs text-graphite-400">{c.employee?.employee_id}</div>
                                            </TableCell>
                                            <TableCell className="max-w-[240px]"><span className="line-clamp-2">{c.title}</span></TableCell>
                                            <TableCell className="capitalize">{c.category}</TableCell>
                                            <TableCell><StatusBadge value={c.severity} /></TableCell>
                                            <TableCell className="whitespace-nowrap text-xs text-graphite-500">
                                                {new Date(c.reported_at).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                                            </TableCell>
                                            <TableCell>{c.assignee?.name || <span className="text-graphite-300">Unassigned</span>}</TableCell>
                                            <TableCell className="tabular-nums">{c.actions_count}</TableCell>
                                            <TableCell><StatusBadge value={c.status} /></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {cases.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between">
                    <p className="text-xs text-graphite-500">
                        Showing {cases.from}–{cases.to} of {cases.total}
                    </p>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!cases.prev_page_url} onClick={() => router.visit(cases.prev_page_url)}>
                            <ChevronLeft className="h-4 w-4" /> Previous
                        </Button>
                        <Button variant="outline" size="sm" disabled={!cases.next_page_url} onClick={() => router.visit(cases.next_page_url)}>
                            Next <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
