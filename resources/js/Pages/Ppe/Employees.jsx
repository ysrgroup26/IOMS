import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import EmptyState from '@/Components/shared/EmptyState';
import PpeTabNav from '@/Components/shared/PpeTabNav';
import { Search, Users, ChevronLeft, ChevronRight, X } from 'lucide-react';

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
export default function PpeEmployees({ employees, companies, departments, filters }) {
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

            <PpeTabNav />

            <div className="mb-6">
                <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">Employee PPE</h1>
                <p className="mt-1 text-sm text-graphite-500 dark:text-slate-400">Select an employee to view or manage their PPE</p>
            </div>

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
                        <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
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
                                    className="flex items-center justify-between gap-4 px-4 py-2 transition-colors hover:bg-graphite-50 dark:hover:bg-slate-800/60"
                                >
                                    <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-graphite-900 dark:text-slate-100">{emp.full_name}</span>
                                    <span className="w-40 shrink-0 truncate text-xs text-graphite-500 dark:text-slate-400">{emp.department?.name || '—'}</span>
                                    <span className="w-32 shrink-0 text-right text-xs text-graphite-500 dark:text-slate-400">{emp.total_ppe_count} Assigned PPE</span>
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
