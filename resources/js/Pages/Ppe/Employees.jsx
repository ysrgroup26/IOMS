import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import EmptyState from '@/Components/shared/EmptyState';
import PpeTabNav from '@/Components/shared/PpeTabNav';
import EmployeePpeImportDialog from '@/Components/shared/EmployeePpeImportDialog';
import { Button } from '@/Components/ui/button';
import { Search, Users, ChevronLeft, ChevronRight, X, Upload } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';

/*
 * v2.121.0 -- THE COMPLETENESS VERDICT, as the three states the owner
 * approved rather than a pass/fail. "Needs Replacement" exists because
 * somebody who was never issued boots and somebody whose boots are simply
 * due are two different problems with two different actions, and HSE has
 * to be able to tell them apart in a list.
 *
 * Labels are English (status labels, per CLAUDE.md's language rule).
 */
const COMPLIANCE = {
    compliant: { label: 'Compliant', variant: 'success' },
    needs_replacement: { label: 'Needs Replacement', variant: 'outline' },
    incomplete: { label: 'Incomplete', variant: 'destructive' },
};

const FILTER_LABELS = {
    active: 'Active PPE',
    expiring_soon: 'Expiring Soon',
    expired: 'Expired',
};

/**
 * Employee PPE (v1.6.6/v1.6.7) -- the employee *selector*, deliberately
 * minimal per explicit instruction: each item shows only Name,
 * Department, and a single Total Assigned PPE count. Per-status detail
 * (active/expiring/expired) already lives on the Dashboard and belongs
 * inside the Employee PPE Profile once you've picked someone -- showing
 * it again here would be exactly the "duplicate information" the spec
 * calls out. The entire card is the click target (no separate "View"
 * button, also per explicit instruction).
 *
 * v1.11.6 (Production Readiness pass, Part 2): root cause of "filters
 * reset after Add PPE" was that navigating into an employee's profile
 * dropped the current company/department/search/page context from the
 * URL entirely -- the profile page's "Back to Employee PPE" link had
 * nothing to reconstruct it from. Filters were already URL-driven here
 * (the actual list page); the fix is carrying that same query string
 * along into the profile URL so it survives the round trip, not
 * inventing new global/session state.
 */
export default function PpeEmployees({ employees, companies, departments, filters, can = {}, complianceGaps = [], complianceRoleLabels = {} }) {
    const [importOpen, setImportOpen] = useState(false);

    function applyFilters(overrides = {}) {
        router.get(route('ppe.employees'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    function employeeHref(employeeId) {
        const params = { ...filters, page: employees.current_page > 1 ? employees.current_page : undefined };
        const query = new URLSearchParams(
            Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')
        ).toString();
        return route('ppe.employees.show', employeeId) + (query ? `?${query}` : '');
    }

    const availableDepartments = filters.company_id
        ? departments.filter((d) => d.company_id === Number(filters.company_id))
        : departments;

    const activeFilterLabel = filters.no_ppe_assigned
        ? 'No PPE Assigned'
        : filters.replacement_due
            ? 'Replacement Due'
            : FILTER_LABELS[filters.effective_status];

    return (
        <AuthenticatedLayout>
            <Head title="Employee PPE" />

            <PpeTabNav counts={{ employees: employees.total }} />

            {/* v2.122.0: the house module header, and an Indonesian
                subtitle -- that slot is help text (see CONVENTIONS, the
                language hierarchy), and it was English here. */}
            <PageHeader
                title="Employee PPE"
                subtitle="Pilih karyawan untuk melihat atau mengelola APD mereka."
            >
                {/*
                    v2.120.0 -- bulk issuance lives HERE, beside the manual
                    issue flow, and not on PPE Master: that tab is
                    configuration (its own subtitle says so) and is Super
                    Admin only, while issuing PPE is Super Admin and HSE.
                    `can.import` is the server's own
                    canManagePpeDistribution() answer, never a role guessed
                    in the browser.
                */}
                {can.import && (
                    <Button variant="outline" onClick={() => setImportOpen(true)}>
                        <Upload className="h-4 w-4" /> Import PPE
                    </Button>
                )}
            </PageHeader>

            <EmployeePpeImportDialog open={importOpen} onOpenChange={setImportOpen} companies={companies} />

            {complianceGaps.length > 0 && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-900">
                    <p className="font-semibold">
                        {complianceGaps.map((r) => complianceRoleLabels[r] ?? r).join(', ')} belum memiliki jenis PPE aktif.
                    </p>
                    <p className="mt-1">
                        Karena itu belum ada karyawan yang dapat berstatus Compliant. Buka PPE Master dan tandai
                        jenis yang memenuhi syarat tersebut lewat kolom &ldquo;Counts as&rdquo;.
                    </p>
                </div>
            )}

            {activeFilterLabel && (
                <div className="mb-4 flex items-center justify-between rounded-lg border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm dark:border-brand-900/50 dark:bg-brand-950/30">
                    <span className="text-brand-800 dark:text-brand-300">Showing: <strong>{activeFilterLabel}</strong></span>
                    <button
                        onClick={() => applyFilters({ effective_status: null, replacement_due: null, no_ppe_assigned: null })}
                        className="flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-400"
                    >
                        <X className="h-3.5 w-3.5" /> Clear filter
                    </button>
                </div>
            )}

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <div className="relative min-w-[220px] flex-1">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input
                            className="pl-8"
                            placeholder="Search employees..."
                            defaultValue={filters.search || ''}
                            onChange={(e) => applyFilters({ search: e.target.value || null })}
                        />
                    </div>
                    <Select value={filters.company_id ? String(filters.company_id) : 'all'} onValueChange={(v) => applyFilters({ company_id: v === 'all' ? null : v, department_id: null })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Operating Unit" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Operating Units</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.department_id ? String(filters.department_id) : 'all'} onValueChange={(v) => applyFilters({ department_id: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Department" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Departments</SelectItem>
                            {availableDepartments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.ppe_compliance || 'all'} onValueChange={(v) => applyFilters({ ppe_compliance: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="PPE Completeness" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Completeness</SelectItem>
                            {Object.entries(COMPLIANCE).map(([key, c]) => <SelectItem key={key} value={key}>{c.label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            {employees.data.length === 0 ? (
                <Card><CardContent><EmptyState icon={Users} title="No employees found" description="Try adjusting your search or filters." /></CardContent></Card>
            ) : (
                <Card>
                    <CardContent className="p-0">
                        <div className="divide-y divide-graphite-100 dark:divide-slate-800">
                            {employees.data.map((emp) => (
                                <Link
                                    key={emp.id}
                                    href={employeeHref(emp.id)}
                                    className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2 transition-colors hover:bg-graphite-50 dark:hover:bg-slate-800/60"
                                >
                                    {/* v2.16.0 (Global Mobile UX Hardening). Was three
                                        shrink-0 fixed-width columns (160px+128px) with no
                                        flex-wrap -- on a 320-375px viewport those alone
                                        already exceeded the row's own width before the
                                        name even got space. Department now hides below
                                        `sm` (still available on the employee's own
                                        profile) rather than fighting the name column for
                                        room; the count stays visible at every width. */}
                                    <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-graphite-900 dark:text-slate-100">{emp.full_name}</span>
                                    <span className="hidden w-40 shrink-0 truncate text-xs text-graphite-500 sm:inline dark:text-slate-400">{emp.department?.name || '-'}</span>
                                    {/* v2.121.0: the count says how MUCH, never whether the
                                        person is equipped -- four pairs of glasses and no
                                        boots is four assigned items. The verdict is its own
                                        badge, computed server-side. */}
                                    {emp.ppe_compliance && COMPLIANCE[emp.ppe_compliance] && (
                                        <Badge variant={COMPLIANCE[emp.ppe_compliance].variant} className="shrink-0">
                                            {COMPLIANCE[emp.ppe_compliance].label}
                                        </Badge>
                                    )}
                                    <span className="shrink-0 text-right text-xs text-graphite-500 dark:text-slate-400">{emp.total_ppe_count} Assigned PPE</span>
                                </Link>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}

            {employees.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-graphite-500 dark:text-slate-400">
                    <span>Page {employees.current_page} of {employees.last_page}</span>
                    <div className="flex gap-2">
                        <button disabled={!employees.prev_page_url} onClick={() => router.get(employees.prev_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <button disabled={!employees.next_page_url} onClick={() => router.get(employees.next_page_url, {}, { preserveState: true })} className="rounded-md border border-graphite-200 p-1.5 disabled:opacity-40 dark:border-slate-700">
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
