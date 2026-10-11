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
import PersonChip from '@/Components/shared/PersonChip';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import { Search, Plus, Download, Upload, ChevronLeft, ChevronRight, FileText, Trash2, AlertTriangle } from 'lucide-react';

/*
 * v2.108.0 -- the word the server requires, typed by hand. Kept in one
 * place so the hint and the check can never drift apart; the authority
 * is BulkDestroyEmployeesRequest::CONFIRMATION, this is only the client
 * side of the same agreement.
 */
const DELETE_CONFIRMATION = 'HAPUS';

export default function EmployeesIndex({ employees, companies, departments, filters, can }) {
    const [search, setSearch] = useState(filters.search || '');
    const [importOpen, setImportOpen] = useState(false);

    /*
     * Selection is held as an array of ids rather than a page-shaped
     * structure, because the thing being confirmed is "these people",
     * not "these rows". It is cleared whenever the list is refetched:
     * a selection that outlived the page it was made on is exactly what
     * the server's agreed-count check refuses, and offering it here
     * would only produce a rejected request.
     */
    const [selected, setSelected] = useState([]);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [confirmation, setConfirmation] = useState('');
    const [deleting, setDeleting] = useState(false);

    const pageIds = employees.data.map((e) => e.id);
    const allOnPageSelected = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));

    function toggle(id) {
        setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));
    }

    function togglePage() {
        setSelected((current) => (allOnPageSelected ? current.filter((id) => !pageIds.includes(id)) : [...new Set([...current, ...pageIds])]));
    }

    function closeDelete() {
        setDeleteOpen(false);
        setConfirmation('');
    }

    function confirmDelete() {
        setDeleting(true);
        router.delete(route('employees.bulk-destroy'), {
            data: { ids: selected, expected_count: selected.length, confirmation },
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                closeDelete();
                setSelected([]);
            },
        });
    }

    function applyFilters(overrides = {}) {
        setSelected([]);
        router.get(route('employees.index'), {
            search, company_id: filters.company_id, department_id: filters.department_id, status: filters.status, profile_status: filters.profile_status, ...overrides,
        }, { preserveState: true, replace: true });
    }

    // v2.102.0: one set of filters, two formats -- the spreadsheet and
    // the printable register must always describe the same list.
    function exportUrl(routeName) {
        const params = new URLSearchParams({
            ...(filters.company_id ? { company_id: filters.company_id } : {}),
            ...(filters.department_id ? { department_id: filters.department_id } : {}),
            ...(search ? { search } : {}),
        });
        return route(routeName) + '?' + params.toString();
    }

    return (
        <AuthenticatedLayout>
            <Head title="Employees" />

            <PageHeader title="Employees" subtitle={`${employees.total} karyawan terdaftar di seluruh perusahaan.`}>
                <Button variant="outline" asChild>
                    <a href={exportUrl('employees.export')}><Download className="h-4 w-4" /> Export Excel</a>
                </Button>
                <Button variant="outline" asChild>
                    <a href={exportUrl('employees.export-pdf')} target="_blank" rel="noopener noreferrer">
                        <FileText className="h-4 w-4" /> Export PDF
                    </a>
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
            <div className="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-steel-200/70 bg-gradient-to-br from-steel-50/80 via-white to-white p-2.5 shadow-card dark:border-slate-800 dark:from-slate-900 dark:via-slate-900 dark:to-slate-900">
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
                    <SelectTrigger className="w-40 bg-white"><SelectValue placeholder="Operating Unit" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Operating Units</SelectItem>
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

            {/* v2.108.0: appears only once something is selected, so the
                ordinary list stays uncluttered, and it states the count
                it is about to act on rather than just "Delete". */}
            {can.manage && selected.length > 0 && (
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50/70 px-3 py-2.5 dark:border-amber-900/50 dark:bg-amber-950/30">
                    <p className="text-sm font-semibold text-amber-900 dark:text-amber-200">
                        {selected.length} Selected
                    </p>
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" size="sm" onClick={() => setSelected([])}>Clear Selection</Button>
                        <Button variant="destructive" size="sm" onClick={() => setDeleteOpen(true)}>
                            <Trash2 className="h-4 w-4" /> Delete Selected
                        </Button>
                    </div>
                </div>
            )}

            <Card>
                <CardContent className="p-0">
                    {employees.data.length === 0 ? (
                        <div className="py-10 text-center">
                            <p className="text-sm font-semibold text-navy-800">Belum ada data karyawan.</p>
                            {can.manage && (
                                <Button asChild size="sm" className="mt-3">
                                    <Link href={route('employees.create')}><Plus className="h-4 w-4" /> Add Employee</Link>
                                </Button>
                            )}
                        </div>
                    ) : (
                        <>
                            {/* v2.23.0: mobile card list, v2.32.0
                                (Interior UI Completion Phase 3B, Part 3):
                                avatar switched from a plain neutral-gray
                                initial to the shared `PersonChip` -- same
                                brand-tinted identity treatment Employee
                                Profile already uses, and two-letter
                                initials instead of just the first
                                character. Reuses the shared component
                                rather than a fourth hand-rolled copy, per
                                this pass's own "reuse shared components"
                                instruction. */}
                            <div className="divide-y divide-graphite-100 md:hidden">
                                {employees.data.map((emp) => (
                                    <Link
                                        key={emp.id}
                                        href={route('employees.show', emp.id)}
                                        className="flex items-center gap-3 px-4 py-3 transition-colors active:bg-graphite-50"
                                    >
                                        <PersonChip
                                            className="min-w-0 flex-1"
                                            name={emp.full_name}
                                            subtitle={`${emp.employee_id} · ${emp.department?.name || emp.company?.name || '-'}`}
                                        />
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
                                        {can.manage && (
                                            <TableHead className="w-10">
                                                <Checkbox
                                                    checked={allOnPageSelected}
                                                    onCheckedChange={togglePage}
                                                    aria-label="Select all employees on this page"
                                                />
                                            </TableHead>
                                        )}
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Company / Department</TableHead>
                                        <TableHead>Position</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {employees.data.map((emp) => (
                                        <TableRow key={emp.id} className="cursor-pointer" onClick={() => router.visit(route('employees.show', emp.id))}>
                                            {can.manage && (
                                                /* Stops the click from opening the profile: a
                                                   checkbox inside a navigating row has to swallow
                                                   its own event or selecting is impossible. */
                                                <TableCell onClick={(e) => e.stopPropagation()}>
                                                    <Checkbox
                                                        checked={selected.includes(emp.id)}
                                                        onCheckedChange={() => toggle(emp.id)}
                                                        aria-label={`Select ${emp.full_name}`}
                                                    />
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                <PersonChip name={emp.full_name} subtitle={emp.employee_id} />
                                            </TableCell>
                                            <TableCell>
                                                <p className="text-graphite-800">{emp.company?.name ?? '-'}</p>
                                                <p className="text-xs text-graphite-500">
                                                    {emp.department?.name || <Badge variant="destructive">Need Completion</Badge>}
                                                </p>
                                            </TableCell>
                                            <TableCell className="text-graphite-500">{emp.position?.name ?? '-'}</TableCell>
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

            {/* v2.108.0 -- DELIBERATE CONFIRMATION.
                The count is stated, the consequence is stated, and the
                word has to be typed. The server checks all three again
                (BulkDestroyEmployeesRequest): this dialog is the honest
                explanation, not the enforcement. */}
            <Dialog open={deleteOpen} onOpenChange={(open) => (open ? setDeleteOpen(true) : closeDelete())}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5 text-destructive" />
                            Delete {selected.length} Employee{selected.length === 1 ? '' : 's'}
                        </DialogTitle>
                        <DialogDescription>
                            {selected.length} karyawan akan dihapus dari daftar aktif. Riwayat KPI, man-hour, PPE dan
                            persetujuan mereka tetap tersimpan dan tidak ikut terhapus. Tindakan ini tidak dapat
                            dibatalkan sendiri dari halaman ini.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-2">
                        <label htmlFor="bulk-delete-confirmation" className="text-sm font-medium text-graphite-700">
                            Type {DELETE_CONFIRMATION} to confirm
                        </label>
                        <Input
                            id="bulk-delete-confirmation"
                            value={confirmation}
                            autoComplete="off"
                            placeholder={DELETE_CONFIRMATION}
                            onChange={(e) => setConfirmation(e.target.value)}
                        />
                        <p className="text-xs text-graphite-500">
                            Konfirmasi ini diminta karena penghapusan massal tidak bisa dibatalkan dari halaman ini.
                        </p>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={closeDelete} disabled={deleting}>Cancel</Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={confirmation !== DELETE_CONFIRMATION || deleting}
                            onClick={confirmDelete}
                        >
                            {deleting ? 'Deleting...' : `Delete ${selected.length} Employee${selected.length === 1 ? '' : 's'}`}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
