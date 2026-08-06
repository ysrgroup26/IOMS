import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import EmptyState from '@/Components/shared/EmptyState';
import { History, ChevronLeft, ChevronRight } from 'lucide-react';

const ACTION_COLOR = {
    created: 'text-graphite-500', updated: 'text-graphite-500',
    submitted: 'text-amber-600', approved: 'text-emerald-600', rejected: 'text-red-600',
};

/**
 * Milestone 3 (Activity Center, Task #50). Every existing module already
 * writes to `ActivityLog` (32+ call sites, per docs/ARCHITECTURE.md) --
 * this page is the first genuinely cross-record, filterable view of it.
 * Was a disabled "Audit Logs" placeholder in the Administration
 * workspace since v1.9.0.
 */
export default function ActivityCenterIndex({ activities, filters, options }) {
    function applyFilter(key, value) {
        router.get(route('activity-center.index'), { ...filters, [key]: value || null }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Activity Center" />

            <div className="mb-6">
                <h1 className="text-lg font-bold tracking-tight text-graphite-900">Activity Center</h1>
                <p className="mt-1 text-sm text-graphite-500">Audit trail across every module -- filterable by user, company, department, module, and date.</p>
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-3 pt-6">
                    <Select value={filters.user_id ?? 'all'} onValueChange={(v) => applyFilter('user_id', v === 'all' ? null : v)}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="All Users" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Users</SelectItem>
                            {options.users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.company_id ?? 'all'} onValueChange={(v) => applyFilter('company_id', v === 'all' ? null : v)}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="All Companies" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
                            {options.companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.department_id ?? 'all'} onValueChange={(v) => applyFilter('department_id', v === 'all' ? null : v)}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="All Departments" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Departments</SelectItem>
                            {options.departments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.module ?? 'all'} onValueChange={(v) => applyFilter('module', v === 'all' ? null : v)}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="All Modules" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Modules</SelectItem>
                            {options.modules.map((m) => <SelectItem key={m} value={m}>{m}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Input type="date" className="w-40" value={filters.date_from ?? ''} onChange={(e) => applyFilter('date_from', e.target.value)} />
                    <Input type="date" className="w-40" value={filters.date_to ?? ''} onChange={(e) => applyFilter('date_to', e.target.value)} />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{activities.total} record(s)</CardTitle>
                </CardHeader>
                <CardContent>
                    {activities.data.length === 0 ? (
                        <EmptyState icon={History} title="No activity found" description="Try widening or clearing the filters above." />
                    ) : (
                        <div className="space-y-4">
                            {activities.data.map((a) => (
                                <div key={a.id} className="flex items-start gap-2.5 border-b border-graphite-100 pb-3 text-[13px] last:border-0">
                                    <div className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-graphite-300" />
                                    <div className="min-w-0 flex-1">
                                        <p className={ACTION_COLOR[a.action] || 'text-graphite-600'}>
                                            <span className="font-medium text-graphite-800">{a.user?.name || 'System'}</span> {a.description}
                                        </p>
                                        <p className="mt-0.5 flex flex-wrap gap-x-2 text-[11px] text-graphite-400">
                                            <span>{new Date(a.created_at).toLocaleString('en-US', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })}</span>
                                            {a.company && <span>&middot; {a.company.name}</span>}
                                            {a.module && <span>&middot; {a.module}</span>}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {activities.last_page > 1 && (
                        <div className="mt-4 flex items-center justify-between">
                            <Button variant="outline" size="sm" disabled={!activities.prev_page_url} onClick={() => router.get(activities.prev_page_url, {}, { preserveState: true })}>
                                <ChevronLeft className="h-4 w-4" /> Prev
                            </Button>
                            <span className="text-xs text-graphite-400">Page {activities.current_page} of {activities.last_page}</span>
                            <Button variant="outline" size="sm" disabled={!activities.next_page_url} onClick={() => router.get(activities.next_page_url, {}, { preserveState: true })}>
                                Next <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    )}
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
