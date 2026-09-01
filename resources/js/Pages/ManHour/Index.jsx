import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Clock, Plus, Trash2, ChevronLeft, ChevronRight } from 'lucide-react';

/**
 * Man-Hour (v1.11.6, Production Readiness pass, Part 4). A minimal
 * operational log -- see ManHourController's own doc comment for why
 * this exists as new, real data rather than derived from employee count
 * or scheduled shift length. Intentionally plain: filter bar + table +
 * an Add dialog, no workflow states to manage.
 */
export default function ManHourIndex({ logs, employees, projects, companies, filters, can, summary }) {
    const [open, setOpen] = useState(false);

    function applyFilters(overrides = {}) {
        router.get(route('man-hour.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    function destroy(id) {
        if (confirm('Remove this man-hour record?')) {
            router.delete(route('man-hour.destroy', id));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title="Man-Hour" />
            <PageHeader title="Man-Hour" subtitle="Actual worked hours per employee -- regular + overtime, entered explicitly, never assumed." />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap gap-2">
                    <Input type="date" className="w-40" value={filters.from || ''} onChange={(e) => applyFilters({ from: e.target.value })} />
                    <Input type="date" className="w-40" value={filters.to || ''} onChange={(e) => applyFilters({ to: e.target.value })} />
                    <Select value={filters.company_id ? String(filters.company_id) : 'all'} onValueChange={(v) => applyFilters({ company_id: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>
                {can.manage && <Button onClick={() => setOpen(true)}><Plus className="h-4 w-4" /> Add Record</Button>}
            </div>

            <div className="mb-4 grid grid-cols-2 gap-3">
                <Card><CardContent className="p-3.5"><p className="text-xl font-bold text-graphite-900 dark:text-slate-50">{summary.total_hours.toFixed(1)}</p><p className="text-xs text-graphite-400">Total Hours (this page)</p></CardContent></Card>
                <Card><CardContent className="p-3.5"><p className="text-xl font-bold text-graphite-900 dark:text-slate-50">{summary.record_count}</p><p className="text-xs text-graphite-400">Records in Range</p></CardContent></Card>
            </div>

            <Card>
                <CardContent className="p-0">
                    {logs.data.length === 0 ? (
                        <EmptyState icon={Clock} title="No man-hour records in this range" description="Add the first record using the button above." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Project</TableHead>
                                    <TableHead>Regular</TableHead>
                                    <TableHead>Overtime</TableHead>
                                    <TableHead>Total</TableHead>
                                    {can.manage && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {logs.data.map((l) => (
                                    <TableRow key={l.id}>
                                        <TableCell>{new Date(l.work_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                        <TableCell>
                                            <p className="font-medium text-graphite-900 dark:text-slate-100">{l.employee?.full_name}</p>
                                            <p className="text-xs text-graphite-500 dark:text-slate-400">{l.employee?.department?.name ?? '—'}</p>
                                        </TableCell>
                                        <TableCell>{l.project?.name ?? '—'}</TableCell>
                                        <TableCell>{Number(l.regular_hours).toFixed(1)}</TableCell>
                                        <TableCell>{Number(l.overtime_hours).toFixed(1)}</TableCell>
                                        <TableCell className="font-semibold">{(Number(l.regular_hours) + Number(l.overtime_hours)).toFixed(1)}</TableCell>
                                        {can.manage && (
                                            <TableCell>
                                                <Button variant="ghost" size="icon" onClick={() => destroy(l.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {logs.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-graphite-500">
                    <span>Page {logs.current_page} of {logs.last_page}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!logs.prev_page_url} onClick={() => router.get(logs.prev_page_url, {}, { preserveState: true })}><ChevronLeft className="h-4 w-4" /> Prev</Button>
                        <Button variant="outline" size="sm" disabled={!logs.next_page_url} onClick={() => router.get(logs.next_page_url, {}, { preserveState: true })}>Next <ChevronRight className="h-4 w-4" /></Button>
                    </div>
                </div>
            )}

            {can.manage && <AddRecordDialog open={open} onOpenChange={setOpen} employees={employees} projects={projects} />}
        </AuthenticatedLayout>
    );
}

function AddRecordDialog({ open, onOpenChange, employees, projects }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        employee_id: '', project_id: '', work_date: new Date().toISOString().slice(0, 10),
        regular_hours: '8', overtime_hours: '0', notes: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('man-hour.store'), {
            onSuccess: () => { reset(); onOpenChange(false); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader><DialogTitle>Add Man-Hour Record</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>Employee</Label>
                        <Select value={data.employee_id} onValueChange={(v) => setData('employee_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select employee" /></SelectTrigger>
                            <SelectContent>
                                {employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.full_name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        {errors.employee_id && <p className="text-xs text-red-600">{errors.employee_id}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label>Project / Work Area (optional)</Label>
                        <Select value={data.project_id || 'none'} onValueChange={(v) => setData('project_id', v === 'none' ? '' : v)}>
                            <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">None</SelectItem>
                                {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Work Date</Label>
                        <Input type="date" value={data.work_date} onChange={(e) => setData('work_date', e.target.value)} />
                        {errors.work_date && <p className="text-xs text-red-600">{errors.work_date}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label>Regular Hours</Label>
                            <Input type="number" step="0.5" min="0" max="24" value={data.regular_hours} onChange={(e) => setData('regular_hours', e.target.value)} />
                            {errors.regular_hours && <p className="text-xs text-red-600">{errors.regular_hours}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Overtime Hours</Label>
                            <Input type="number" step="0.5" min="0" max="24" value={data.overtime_hours} onChange={(e) => setData('overtime_hours', e.target.value)} />
                            {errors.overtime_hours && <p className="text-xs text-red-600">{errors.overtime_hours}</p>}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Notes (optional)</Label>
                        <Input value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
