import { Head, router, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
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

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-graphite-900">Employees</h1>
                    <p className="mt-1 text-sm text-graphite-500">{employees.total} employees total</p>
                </div>
                <div className="flex gap-2">
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
                </div>
            </div>

            <EmployeeImportDialog open={importOpen} onOpenChange={setImportOpen} companies={companies} />

            <Card>
                <CardContent className="flex flex-wrap gap-2 p-4">
                    <div className="relative flex-1 min-w-[200px]">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input
                            className="pl-8"
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
                        <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <GroupedDepartmentSelect
                        className="w-48"
                        departments={departments}
                        companies={companies}
                        value={filters.department_id}
                        onChange={(v) => applyFilters({ department_id: v })}
                    />
                    <Select value={filters.profile_status || 'all'} onValueChange={(v) => applyFilters({ profile_status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Profile" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Profiles</SelectItem>
                            <SelectItem value="complete">Complete</SelectItem>
                            <SelectItem value="needs_completion">Need Completion</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button variant="secondary" onClick={() => applyFilters()}>Search</Button>
                </CardContent>
            </Card>

            <Card className="mt-4">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Employee</TableHead>
                            <TableHead>Employee ID</TableHead>
                            <TableHead>Company</TableHead>
                            <TableHead>Department</TableHead>
                            <TableHead>Position</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {employees.data.length === 0 ? (
                            <TableRow><TableCell colSpan={6} className="py-10 text-center text-graphite-400">No employees found.</TableCell></TableRow>
                        ) : employees.data.map((emp) => (
                            <TableRow key={emp.id} className="cursor-pointer" onClick={() => router.visit(route('employees.show', emp.id))}>
                                <TableCell className="flex items-center gap-3 font-medium text-graphite-800">
                                    <div className="flex h-7 w-7 items-center justify-center rounded-full bg-graphite-100 text-xs font-semibold text-graphite-600">
                                        {emp.full_name.charAt(0)}
                                    </div>
                                    {emp.full_name}
                                </TableCell>
                                <TableCell>{emp.employee_id}</TableCell>
                                <TableCell>{emp.company?.name ?? '—'}</TableCell>
                                <TableCell>
                                    {emp.department?.name || <Badge variant="destructive">Need Completion</Badge>}
                                </TableCell>
                                <TableCell>{emp.position?.name ?? '—'}</TableCell>
                                <TableCell>
                                    <Badge variant={emp.status === 'active' ? 'success' : 'secondary'} className="capitalize">
                                        {emp.status}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
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
