import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import EmptyState from '@/Components/shared/EmptyState';
import StatCard from '@/Components/shared/StatCard';
import PersonChip from '@/Components/shared/PersonChip';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Clock, Plus, Trash2, ChevronLeft, ChevronRight, ClipboardList } from 'lucide-react';

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
        if (confirm('Hapus catatan Man-Hour ini?')) {
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

            {/* v2.32.0 (Interior UI Completion Phase 3B, Part 6): both
                values here were already real, backend-provided totals
                (`summary.total_hours`/`summary.record_count`) -- only the
                presentation was plain white boxes with no icon or visual
                family. Reused StatCard (same "TOTAL HOURS / period
                context" concept this pass's own directive suggested,
                built only because the backend already supplies it). */}
            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatCard icon={Clock} value={summary.total_hours.toFixed(1)} label="Total Hours" hint="periode saat ini" />
                <StatCard icon={ClipboardList} value={summary.record_count} label="Records in Range" hint="periode saat ini" />
            </div>

            <Card>
                <CardContent className="p-0">
                    {logs.data.length === 0 ? (
                        <EmptyState icon={Clock} title="No man-hour records in this range" description="Add the first record using the button above." />
                    ) : (
                        <>
                            {/* v2.32.0: this numeric/tabular dataset stays a
                                table on desktop (per this pass's own "don't
                                turn tabular data into decorative cards"
                                instruction) -- but it had no mobile
                                fallback at all before, so a narrow viewport
                                just horizontally scrolled a 6-7 column
                                table. Compact identity-first cards here
                                instead, same pattern as every other list
                                page this session. */}
                            <div className="divide-y divide-graphite-100 md:hidden">
                                {logs.data.map((l) => (
                                    <div key={l.id} className="flex items-center justify-between gap-2 px-4 py-3">
                                        <PersonChip
                                            className="min-w-0 flex-1"
                                            name={l.employee?.full_name}
                                            subtitle={`${new Date(l.work_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short' })} · ${l.project?.name ?? l.employee?.department?.name ?? '-'}`}
                                        />
                                        <div className="shrink-0 text-right">
                                            <p className="text-sm font-semibold text-graphite-900">{(Number(l.regular_hours) + Number(l.overtime_hours)).toFixed(1)}h</p>
                                            {Number(l.overtime_hours) > 0 && <p className="text-[11px] text-amber-600">+{Number(l.overtime_hours).toFixed(1)} OT</p>}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <Table className="hidden md:table">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Project</TableHead>
                                        <TableHead className="text-right">Regular</TableHead>
                                        <TableHead className="text-right">Overtime</TableHead>
                                        <TableHead className="text-right">Total</TableHead>
                                        {can.manage && <TableHead />}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {logs.data.map((l) => (
                                        <TableRow key={l.id}>
                                            <TableCell>{new Date(l.work_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                            <TableCell><PersonChip name={l.employee?.full_name} subtitle={l.employee?.department?.name ?? '—'} /></TableCell>
                                            <TableCell>{l.project?.name ?? '—'}</TableCell>
                                            <TableCell className="text-right tabular-nums">{Number(l.regular_hours).toFixed(1)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{Number(l.overtime_hours).toFixed(1)}</TableCell>
                                            <TableCell className="text-right font-semibold tabular-nums">{(Number(l.regular_hours) + Number(l.overtime_hours)).toFixed(1)}</TableCell>
                                            {can.manage && (
                                                <TableCell>
                                                    <Button variant="ghost" size="icon" onClick={() => destroy(l.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
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
