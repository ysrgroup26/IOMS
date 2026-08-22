import { Head, useForm, router } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import GroupedDepartmentSelect from '@/Components/shared/GroupedDepartmentSelect';
import * as Tabs from '@radix-ui/react-tabs';
import { cn } from '@/lib/utils';
import { Loader2, Search, CheckSquare, Square, X, AlertTriangle, ChevronDown } from 'lucide-react';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/Components/ui/dialog';

export default function KpiInputIndex({ departments, companies, categories, quickAttendanceCategories, recentRecords }) {
    return (
        <AuthenticatedLayout>
            <Head title="Input KPI" />

            <div className="mb-6">
                <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">Input KPI</h1>
                <p className="mt-1 text-sm text-graphite-500">Record HSE activity occurrences. Every entry adds +1 to the selected category.</p>
            </div>

            <Tabs.Root defaultValue="single" className="space-y-4">
                <Tabs.List className="inline-flex rounded-lg border border-graphite-200 bg-white p-1 shadow-sm">
                    <Tabs.Trigger
                        value="single"
                        className="rounded-md px-4 py-1.5 text-sm font-medium text-graphite-500 data-[state=active]:bg-brand-50 data-[state=active]:text-brand-700"
                    >
                        Single Input
                    </Tabs.Trigger>
                    <Tabs.Trigger
                        value="attendance"
                        className="rounded-md px-4 py-1.5 text-sm font-medium text-graphite-500 data-[state=active]:bg-brand-50 data-[state=active]:text-brand-700"
                    >
                        Quick Attendance
                    </Tabs.Trigger>
                </Tabs.List>

                <Tabs.Content value="single">
                    <SingleInputForm departments={departments} companies={companies} categories={categories} />
                </Tabs.Content>

                <Tabs.Content value="attendance">
                    <QuickAttendanceForm departments={departments} companies={companies} categories={quickAttendanceCategories} />
                </Tabs.Content>
            </Tabs.Root>

            <Card className="mt-6">
                <CardHeader><CardTitle>Recently Recorded</CardTitle></CardHeader>
                <CardContent>
                    {recentRecords.length === 0 ? (
                        <p className="py-4 text-center text-sm text-graphite-400">No records yet.</p>
                    ) : (
                        <ul className="divide-y divide-graphite-100">
                            {recentRecords.map((r) => (
                                <li key={r.id} className="flex items-center justify-between py-2 text-[13px]">
                                    <span className="font-medium text-graphite-700">{r.employee.full_name}</span>
                                    <span className="text-graphite-400">{r.kpi_category.short_label} +{r.quantity}</span>
                                    <span className="text-xs text-graphite-400">{r.record_date}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}

function SingleInputForm({ departments, companies, categories }) {
    const [employees, setEmployees] = useState([]);
    const [deptFilter, setDeptFilter] = useState('');
    const { data, setData, post, processing, errors, reset } = useForm({
        employee_id: undefined,
        kpi_category_id: undefined,
        record_date: new Date().toISOString().slice(0, 10),
        remarks: '',
    });

    useEffect(() => {
        const params = deptFilter ? `?department_id=${deptFilter}` : '';
        fetch(route('kpi-input.attendance-employees') + params)
            .then((r) => {
                if (!r.ok) throw new Error(`Failed to load employees (${r.status})`);
                return r.json();
            })
            .then(setEmployees)
            .catch((err) => console.error('Failed to load employees for department filter:', err));
    }, [deptFilter]);

    function submit(e) {
        e.preventDefault();
        post(route('kpi-input.single'), { onSuccess: () => reset('employee_id', 'remarks') });
    }

    return (
        <Card className="max-w-xl">
            <CardHeader>
                <CardTitle>Record Single Entry</CardTitle>
                <CardDescription>Pick one employee and one KPI category.</CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>Department</Label>
                        <GroupedDepartmentSelect
                            departments={departments}
                            companies={companies}
                            value={deptFilter}
                            onChange={(v) => setDeptFilter(v || '')}
                            placeholder="All departments"
                        />
                    </div>

                    <div className="space-y-1.5">
                        <Label>Employee</Label>
                        <Select value={data.employee_id} onValueChange={(v) => setData('employee_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select employee" /></SelectTrigger>
                            <SelectContent>
                                {employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.full_name} ({e.employee_id})</SelectItem>)}
                            </SelectContent>
                        </Select>
                        {errors.employee_id && <p className="text-xs text-red-600">{errors.employee_id}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <Label>KPI Category</Label>
                        <Select value={data.kpi_category_id} onValueChange={(v) => setData('kpi_category_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select category" /></SelectTrigger>
                            <SelectContent>
                                {categories.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        {errors.kpi_category_id && <p className="text-xs text-red-600">{errors.kpi_category_id}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <Label>Date</Label>
                        <Input type="date" value={data.record_date} onChange={(e) => setData('record_date', e.target.value)} />
                    </div>

                    <div className="space-y-1.5">
                        <Label>Remarks (optional)</Label>
                        <Input value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} placeholder="Additional notes..." />
                    </div>

                    <Button type="submit" disabled={processing} className="w-full">
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                        Save Record (+1)
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

/**
 * Quick Attendance (v1.3.1 rework): the Department selector is now purely
 * a navigation/filter tool for BROWSING the checklist -- it never clears
 * or replaces the underlying selection. Selections accumulate in a single
 * draft (`selectedIds`) across as many department switches as needed;
 * one "Save All KPI" button submits everyone, from every department, in
 * one request. Leaving the page with unsaved selections prompts
 * Save / Discard / Cancel instead of silently discarding the draft.
 *
 * Previous bug this fixes: "Select All" used to replace the entire
 * employee_ids array with just the currently-visible department's
 * employees, silently wiping any selections made in other departments
 * before switching. It's now a merge/unmerge against the full draft.
 */
function QuickAttendanceForm({ departments, companies, categories }) {
    const [visibleEmployees, setVisibleEmployees] = useState([]);
    const [deptFilter, setDeptFilter] = useState('');
    const [search, setSearch] = useState('');

    // Accumulates every employee ever fetched (across every department
    // switch), keyed by id, purely so we can still show a name/department
    // for someone selected earlier even after switching away from their
    // department -- this is what makes the draft visibly trustworthy.
    const [employeeCache, setEmployeeCache] = useState({});

    const { data, setData, post, processing, errors, reset, isDirty } = useForm({
        kpi_category_id: undefined,
        record_date: new Date().toISOString().slice(0, 10),
        remarks: '',
        employee_ids: [],
    });

    const [guardOpen, setGuardOpen] = useState(false);
    const [pendingVisit, setPendingVisit] = useState(null);
    // Selected Employees panel: collapsed by default so the employee list
    // stays near the top of the page regardless of how many people are
    // selected (v1.4.0) -- previously this panel sat above the search/list
    // and grew with every selection, pushing the list further down.
    const [selectedPanelOpen, setSelectedPanelOpen] = useState(false);

    // One-shot bypass for navigations WE explicitly approved (the save
    // POST itself, and the follow-up "continue where the user was trying
    // to go" visit after Save/Discard resolves the guard). A ref (not
    // state) is required here: it must be readable synchronously, in the
    // same tick the visit fires, without waiting for a re-render -- state
    // updates from reset()/setGuardOpen() are still pending at that point
    // and would otherwise leave the listener holding a stale isDirty=true
    // closure that re-blocks our own follow-up navigation.
    const allowNextVisitRef = useRef(false);

    useEffect(() => {
        const params = deptFilter ? `?department_id=${deptFilter}` : '';
        fetch(route('kpi-input.attendance-employees') + params)
            .then((r) => {
                if (!r.ok) throw new Error(`Failed to load employees (${r.status})`);
                return r.json();
            })
            .then((list) => {
                setVisibleEmployees(list);
                setEmployeeCache((prev) => {
                    const next = { ...prev };
                    list.forEach((e) => { next[e.id] = e; });
                    return next;
                });
            })
            .catch((err) => console.error('Failed to load employees for department filter:', err));
    }, [deptFilter]);

    // Intercepts in-app (Inertia) navigation -- e.g. clicking a sidebar
    // link -- while there's an unsaved draft, and asks Save/Discard/Cancel
    // instead of silently losing it.
    //
    // CRITICAL: this listener fires for EVERY Inertia visit, including
    // this very form's own save POST -- so the bypass-ref check must come
    // first, or clicking Save cancels its own request before it ever
    // reaches the network (the v1.3.2 regression: no console errors, no
    // failed network call, because Inertia's client router cancels the
    // visit before any HTTP request is made).
    useEffect(() => {
        return router.on('before', (event) => {
            if (allowNextVisitRef.current) {
                allowNextVisitRef.current = false; // one-shot: consume it, don't leave it armed
                return;
            }
            if (!isDirty) return;
            setPendingVisit(event.detail.visit);
            setGuardOpen(true);
            return false;
        });
    }, [isDirty]);

    // Also warns on an actual tab close/refresh (browser-native dialog,
    // can't be customized to Save/Discard/Cancel, but still prevents
    // silent data loss).
    useEffect(() => {
        function handler(e) {
            if (isDirty) { e.preventDefault(); e.returnValue = ''; }
        }
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    }, [isDirty]);

    const filtered = visibleEmployees.filter((e) => e.full_name.toLowerCase().includes(search.toLowerCase()));

    const selectedEmployees = data.employee_ids.map((id) => employeeCache[id]).filter(Boolean);

    function toggle(id) {
        setData('employee_ids', data.employee_ids.includes(id)
            ? data.employee_ids.filter((x) => x !== id)
            : [...data.employee_ids, id]);
    }

    // Merges the currently-visible department into the draft -- never
    // replaces it, so other departments' selections are preserved.
    function toggleAllVisible() {
        const visibleIds = filtered.map((e) => e.id);
        const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => data.employee_ids.includes(id));

        if (allVisibleSelected) {
            setData('employee_ids', data.employee_ids.filter((id) => !visibleIds.includes(id)));
        } else {
            setData('employee_ids', [...new Set([...data.employee_ids, ...visibleIds])]);
        }
    }

    function removeSelected(id) {
        setData('employee_ids', data.employee_ids.filter((x) => x !== id));
    }

    function doSubmit(onSuccess) {
        allowNextVisitRef.current = true; // let this save's own POST through the guard
        post(route('kpi-input.quick-attendance'), {
            onSuccess: () => {
                reset('employee_ids', 'remarks');
                setEmployeeCache({});
                onSuccess?.();
            },
            onError: () => {
                // Save failed validation -- don't leave a stale bypass
                // armed for some unrelated future navigation.
                allowNextVisitRef.current = false;
            },
        });
    }

    function submit(e) {
        e.preventDefault();
        doSubmit();
    }

    function continuePendingVisit() {
        if (pendingVisit) {
            allowNextVisitRef.current = true; // let this follow-up navigation through the guard too
            const url = typeof pendingVisit.url === 'string' ? pendingVisit.url : pendingVisit.url.href;
            router.visit(url, { method: pendingVisit.method });
        }
        setPendingVisit(null);
        setGuardOpen(false);
    }

    function handleGuardSave() {
        doSubmit(continuePendingVisit);
    }

    function handleGuardDiscard() {
        reset('employee_ids', 'remarks');
        setEmployeeCache({});
        continuePendingVisit();
    }

    function handleGuardCancel() {
        setPendingVisit(null);
        setGuardOpen(false);
    }

    const allVisibleSelected = filtered.length > 0 && filtered.every((e) => data.employee_ids.includes(e.id));

    return (
        <Card>
            <CardHeader>
                <CardTitle>Quick Attendance</CardTitle>
                <CardDescription>
                    Select a category and date, check everyone who attended -- across as many departments as
                    you need -- then Save All KPI once. The department filter is just for browsing; it never
                    clears your selections.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-5">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label>KPI Category</Label>
                            <Select value={data.kpi_category_id} onValueChange={(v) => setData('kpi_category_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select category" /></SelectTrigger>
                                <SelectContent>
                                    {categories.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {errors.kpi_category_id && <p className="text-xs text-red-600">{errors.kpi_category_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Date</Label>
                            <Input type="date" value={data.record_date} onChange={(e) => setData('record_date', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Department</Label>
                            <GroupedDepartmentSelect
                                departments={departments}
                                companies={companies}
                                value={deptFilter}
                                onChange={(v) => setDeptFilter(v || '')}
                                placeholder="All departments"
                            />
                        </div>
                    </div>

                    <div className="flex items-center gap-2.5">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                            <Input className="pl-8" placeholder="Search employees..." value={search} onChange={(e) => setSearch(e.target.value)} />
                        </div>
                        <Button type="button" variant="outline" size="sm" onClick={toggleAllVisible}>
                            {allVisibleSelected ? <CheckSquare className="h-4 w-4" /> : <Square className="h-4 w-4" />}
                            {allVisibleSelected ? 'Unselect Visible' : 'Select Visible'}
                        </Button>
                    </div>

                    <div className="max-h-80 overflow-y-auto rounded-lg border border-graphite-200">
                        {filtered.length === 0 ? (
                            <p className="p-6 text-center text-sm text-graphite-400">No employees found.</p>
                        ) : filtered.map((emp) => (
                            <label
                                key={emp.id}
                                className={cn(
                                    'flex cursor-pointer items-center gap-3 border-b border-graphite-100 px-4 py-2 text-[13px] last:border-0 hover:bg-graphite-50',
                                    data.employee_ids.includes(emp.id) && 'bg-brand-50/60'
                                )}
                            >
                                <Checkbox checked={data.employee_ids.includes(emp.id)} onCheckedChange={() => toggle(emp.id)} />
                                <span className="font-medium text-graphite-700">{emp.full_name}</span>
                                <span className="ml-auto text-xs text-graphite-400">{emp.employee_id}</span>
                            </label>
                        ))}
                    </div>
                    {errors.employee_ids && <p className="text-xs text-red-600">{errors.employee_ids}</p>}

                    {/* Selected Employees: collapsed by default so this panel
                        never pushes the employee list down the page, no
                        matter how many people are selected. Same chip
                        design, remove (X) button, and count as before --
                        only the position and collapse behavior changed. */}
                    {data.employee_ids.length > 0 && (
                        <div className="rounded-lg border border-brand-100 bg-brand-50/40">
                            <button
                                type="button"
                                onClick={() => setSelectedPanelOpen((v) => !v)}
                                className="flex w-full items-center justify-between px-3 py-2.5 text-left"
                            >
                                <p className="text-xs font-semibold uppercase tracking-wide text-brand-700">
                                    Selected Employees ({data.employee_ids.length})
                                </p>
                                <span className="flex items-center gap-1 text-xs font-medium text-brand-700">
                                    {selectedPanelOpen ? 'Hide' : 'Show'}
                                    <ChevronDown className={cn('h-3.5 w-3.5 transition-transform duration-200', selectedPanelOpen && 'rotate-180')} />
                                </span>
                            </button>

                            {/* CSS-grid expand/collapse: animates to the
                                content's natural height without needing to
                                measure it in JS. */}
                            <div
                                className={cn(
                                    'grid transition-[grid-template-rows] duration-200 ease-in-out',
                                    selectedPanelOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'
                                )}
                            >
                                <div className="overflow-hidden">
                                    <div className="flex flex-wrap gap-1.5 px-3 pb-3">
                                        {selectedEmployees.map((e) => (
                                            <span key={e.id} className="flex items-center gap-1.5 rounded-full border border-brand-200 bg-white py-1 pl-2.5 pr-1 text-xs text-graphite-700">
                                                {e.full_name}
                                                <button type="button" onClick={() => removeSelected(e.id)} className="rounded-full p-0.5 text-graphite-400 hover:bg-graphite-100 hover:text-red-600">
                                                    <X className="h-3 w-3" />
                                                </button>
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label>Remarks (optional, applies to all)</Label>
                        <Input value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
                    </div>

                    <Button type="submit" disabled={processing || data.employee_ids.length === 0} className="w-full sm:w-auto">
                        {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                        Save All KPI ({data.employee_ids.length} selected)
                    </Button>
                </form>
            </CardContent>

            <Dialog open={guardOpen} onOpenChange={(open) => { if (!open) handleGuardCancel(); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2"><AlertTriangle className="h-4 w-4 text-amber-500" /> Unsaved KPI Selections</DialogTitle>
                        <DialogDescription>
                            You have {data.employee_ids.length} employee(s) selected that haven't been saved yet.
                            What would you like to do before leaving this page?
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="sm:justify-between">
                        <Button variant="ghost" onClick={handleGuardCancel}>Cancel</Button>
                        <div className="flex gap-2">
                            <Button variant="outline" onClick={handleGuardDiscard}>Discard</Button>
                            <Button onClick={handleGuardSave} disabled={processing}>
                                {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                                Save
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
