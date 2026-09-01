import { Head, router, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import GroupedDepartmentSelect from '@/Components/shared/GroupedDepartmentSelect';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import EmployeeImportDialog from '@/Components/shared/EmployeeImportDialog';
import { Search, Plus, Download, Upload, ChevronLeft, ChevronRight } from 'lucide-react';

export default function EmployeesIndex({ employees, companies, departments, filters, can }) {
    const [search, setSearch] = useState(filters.search || '');
    const [importOpen, setImportOpen] = useState(false);

    function applyFilters(overrides = {}) {
        router.get(route('employees.index'), {
            search, company_id: filters.company_id, department_id: filters.department_id, status: filters.status, profile_status: filters.profile_status, ...overrides,
        }, { preserveState: true, replace: true });
    }

    function exportUrl() {
        const params = new URLSearchParams({
            ...(filters.company_id ? { company_id: filters.company_id } : {}),
            ...(filters.department_id ? { department_id: filters.department_id } : {}),
            ...(search ? { search } : {}),
        });
        return route('employees.export') + '?' + params.toString();
    }

    return (
        <AuthenticatedLayout>
            <Head title="Employees" />

            <PageHeader title="Employees" subtitle={`${employees.total} karyawan terdaftar di seluruh perusahaan.`}>
                <Button variant="outline" asChild>
                    <a href={exportUrl()}><Download className="h-4 w-4" /> Export Excel</a>
                </Button>
                {can.manage && (
                    <>
                        <Button variant="outline" onClick={() => setImportOpen(true)}><Upload className="h-4 w-4" /> Import Excel</Button>
                        <Button asChild>
                            <Link href={route('employees.create')}><Plus className="h-4 w-4" /> Add Employee</Link>
                        </Button>
                    </>
                )}
            </PageHeader>

            <EmployeeImportDialog open={importOpen} onOpenChange={setImportOpen} companies={companies} />

            {/* v2.23.0 (Complete Product UI/UX Transformation, cont'd):
                filter bar unboxed, same treatment as the rest of this
                pass's list pages. */}
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <div className="relative flex-1 min-w-[200px]">
                    <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                    <Input
                        className="border-graphite-200 bg-white pl-8 shadow-none"
                        placeholder="Search name or employee ID..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                    />
                </div>
                <Select
                    value={filters.company_id ? String(filters.company_id) : 'all'}
                    onValueChange={(v) => applyFilters({ company_id: v === 'all' ? null : v, department_id: null })}
                >
                    <SelectTrigger className="w-40 bg-white"><SelectValue placeholder="Company" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Companies</SelectItem>
                        {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                    </SelectContent>
                </Select>
                <GroupedDepartmentSelect
                    className="w-48 bg-white"
                    departments={departments}
                    companies={companies}
                    value={filters.department_id}
                    onChange={(v) => applyFilters({ department_id: v })}
                />
                <Select value={filters.profile_status || 'all'} onValueChange={(v) => applyFilters({ profile_status: v === 'all' ? null : v })}>
                    <SelectTrigger className="w-44 bg-white"><SelectValue placeholder="Profile" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Profiles</SelectItem>
                        <SelectItem value="complete">Complete</SelectItem>
                        <SelectItem value="needs_completion">Need Completion</SelectItem>
                    </SelectContent>
                </Select>
                <Button variant="secondary" onClick={() => applyFilters()}>Search</Button>
            </div>

            <Card>
                <CardContent className="p-0">
                    {employees.data.length === 0 ? (
                        <div className="py-10 text-center">
                            <p className="text-sm font-medium text-graphite-500">Belum ada data karyawan.</p>
                            {can.manage && (
                                <Button asChild size="sm" className="mt-3">
                                    <Link href={route('employees.create')}><Plus className="h-4 w-4" /> Add Employee</Link>
                                </Button>
                            )}
                        </div>
                    ) : (
                        <>
                            {/* v2.23.0: mobile card list -- this page had no
                                mobile fallback before (relied entirely on
                                the shared Table's own overflow-auto), same
                                pattern proven across this transformation
                                pass. */}
                            <div className="divide-y divide-graphite-100 md:hidden">
                                {employees.data.map((emp) => (
                                    <Link
                                        key={emp.id}
                                        href={route('employees.show', emp.id)}
                                        className="flex items-center gap-3 px-4 py-3 active:bg-graphite-50"
                                    >
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-graphite-100 text-xs font-semibold text-graphite-600">
                                            {emp.full_name.charAt(0)}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium text-graphite-900">{emp.full_name}</p>
                                            <p className="truncate text-xs text-graphite-500">{emp.employee_id} &middot; {emp.department?.name || emp.company?.name || '-'}</p>
                                        </div>
                                        <Badge variant={emp.status === 'active' ? 'success' : 'secondary'} className="shrink-0 capitalize">{emp.status}</Badge>
                                    </Link>
                                ))}
                            </div>

                            {/* v2.23.0: consolidated from 6 equal-weight
                                columns to 4 grouped cells -- Employee ID
                                folded under the name (already what the
                                mobile card and PTW/Incidents pattern do),
                                Company+Department grouped together since
                                they're the same organizational fact at two
                                levels. No data dropped. */}
                            <Table className="hidden md:table">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Company / Department</TableHead>
                                        <TableHead>Position</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {employees.data.map((emp) => (
                                        <TableRow key={emp.id} className="cursor-pointer" onClick={() => router.visit(route('employees.show', emp.id))}>
                                            <TableCell>
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-graphite-100 text-xs font-semibold text-graphite-600">
                                                        {emp.full_name.charAt(0)}
                                                    </div>
                                                    <div className="min-w-0">
                                                        <p className="truncate font-medium text-graphite-900">{emp.full_name}</p>
                                                        <p className="truncate text-xs text-graphite-500">{emp.employee_id}</p>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <p className="text-graphite-800">{emp.company?.name ?? '—'}</p>
                                                <p className="text-xs text-graphite-500">
                                                    {emp.department?.name || <Badge variant="destructive">Need Completion</Badge>}
                                                </p>
                                            </TableCell>
                                            <TableCell className="text-graphite-500">{emp.position?.name ?? '—'}</TableCell>
                                            <TableCell>
                                                <Badge variant={emp.status === 'active' ? 'success' : 'secondary'} className="capitalize">
                                                    {emp.status}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
                    )}
                </CardContent>
            </Card>

            {employees.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-graphite-500">
                    <span>Page {employees.current_page} of {employees.last_page}</span>
                    <div className="flex gap-2">
                        <Button
                            variant="outline" size="sm"
                            disabled={!employees.prev_page_url}
                            onClick={() => router.get(employees.prev_page_url, {}, { preserveState: true })}
                        >
                            <ChevronLeft className="h-4 w-4" /> Prev
                        </Button>
                        <Button
                            variant="outline" size="sm"
                            disabled={!employees.next_page_url}
                            onClick={() => router.get(employees.next_page_url, {}, { preserveState: true })}
                        >
                            Next <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
