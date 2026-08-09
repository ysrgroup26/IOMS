import { Head, useForm, router, Link } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Checkbox } from '@/Components/ui/checkbox';
import { Plus, Pencil, Trash2, Moon, CalendarClock } from 'lucide-react';

/**
 * Milestone 4, Workstream A3 (Shift & Roster Management). Shift + Roster
 * Pattern master data on one setup page -- mirrors Competency Master's
 * shape and dialog patterns exactly.
 */
export default function ShiftsMaster({ shifts, rosterPatterns, companies, can }) {
    return (
        <AuthenticatedLayout>
            <Head title="Shift & Roster Setup" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-bold tracking-tight text-graphite-900">Shift &amp; Roster Setup</h1>
                    <p className="mt-1 text-sm text-graphite-500">
                        Configure shifts and rotation patterns. Nothing here is hard-coded -- everything is editable.
                    </p>
                </div>
                <Button variant="outline" asChild>
                    <Link href={route('rosters.overview')}><CalendarClock className="h-4 w-4" /> Roster Overview</Link>
                </Button>
            </div>

            <div className="space-y-6">
                <ShiftSection shifts={shifts} companies={companies} can={can} />
                <RosterPatternSection rosterPatterns={rosterPatterns} companies={companies} can={can} />
            </div>
        </AuthenticatedLayout>
    );
}

function ShiftSection({ shifts, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '',
        code: '',
        start_time: '08:00',
        end_time: '16:00',
        break_duration_minutes: '60',
        is_active: true,
    });

    function openCreate() {
        setEditing(null);
        reset();
        setOpen(true);
    }

    function openEdit(shift) {
        setEditing(shift);
        setData({
            company_id: String(shift.company_id),
            name: shift.name,
            code: shift.code,
            start_time: shift.start_time.slice(0, 5),
            end_time: shift.end_time.slice(0, 5),
            break_duration_minutes: String(shift.break_duration_minutes),
            is_active: shift.is_active,
        });
        setOpen(true);
    }

    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) {
            put(route('shifts.update', editing.id), options);
        } else {
            post(route('shifts.store'), options);
        }
    }

    function destroy(shift) {
        if (confirm(`Remove shift "${shift.name}"? Only possible if no employee/roster uses it.`)) {
            router.delete(route('shifts.destroy', shift.id));
        }
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div>
                    <CardTitle>Shifts</CardTitle>
                    <CardDescription>{shifts.length} configured</CardDescription>
                </div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Shift</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Code</TableHead>
                            <TableHead>Time</TableHead>
                            <TableHead>Break</TableHead>
                            <TableHead>Working Hours</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead>Assignments</TableHead>
                            <TableHead>Status</TableHead>
                            {can.manage && <TableHead />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {shifts.map((s) => (
                            <TableRow key={s.id}>
                                <TableCell className="font-medium">{s.name}</TableCell>
                                <TableCell className="text-graphite-500">{s.code}</TableCell>
                                <TableCell>{s.start_time.slice(0, 5)} - {s.end_time.slice(0, 5)}</TableCell>
                                <TableCell>{s.break_duration_minutes} min</TableCell>
                                <TableCell>{s.working_hours} h</TableCell>
                                <TableCell>
                                    {s.is_night_shift
                                        ? <Badge variant="outline" className="gap-1"><Moon className="h-3 w-3" /> Night</Badge>
                                        : <Badge variant="outline">Day</Badge>}
                                </TableCell>
                                <TableCell>{s.shift_assignments_count}</TableCell>
                                <TableCell><Badge variant={s.is_active ? 'success' : 'secondary'}>{s.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                {can.manage && (
                                    <TableCell className="flex gap-1">
                                        <Button variant="ghost" size="icon" onClick={() => openEdit(s)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(s)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit Shift' : 'Add Shift'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Shift Name</Label>
                                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Morning Shift" />
                                {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Code</Label>
                                <Input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. M" />
                                {errors.code && <p className="text-xs text-red-600">{errors.code}</p>}
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Start Time</Label>
                                <Input type="time" value={data.start_time} onChange={(e) => setData('start_time', e.target.value)} />
                                {errors.start_time && <p className="text-xs text-red-600">{errors.start_time}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>End Time</Label>
                                <Input type="time" value={data.end_time} onChange={(e) => setData('end_time', e.target.value)} />
                                {errors.end_time && <p className="text-xs text-red-600">{errors.end_time}</p>}
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Break Duration (minutes)</Label>
                            <Input type="number" min="0" max="480" value={data.break_duration_minutes} onChange={(e) => setData('break_duration_minutes', e.target.value)} />
                            {errors.break_duration_minutes && <p className="text-xs text-red-600">{errors.break_duration_minutes}</p>}
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} />
                            Active
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={processing}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

function RosterPatternSection({ rosterPatterns, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '',
        code: '',
        days_on: '6',
        days_off: '1',
        description: '',
        is_active: true,
    });

    function openCreate() {
        setEditing(null);
        reset();
        setOpen(true);
    }

    function openEdit(pattern) {
        setEditing(pattern);
        setData({
            company_id: String(pattern.company_id),
            name: pattern.name,
            code: pattern.code ?? '',
            days_on: String(pattern.days_on),
            days_off: String(pattern.days_off),
            description: pattern.description ?? '',
            is_active: pattern.is_active,
        });
        setOpen(true);
    }

    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) {
            put(route('roster-patterns.update', editing.id), options);
        } else {
            post(route('roster-patterns.store'), options);
        }
    }

    function destroy(pattern) {
        if (confirm(`Remove pattern "${pattern.name}"? Only possible if no roster uses it.`)) {
            router.delete(route('roster-patterns.destroy', pattern.id));
        }
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div>
                    <CardTitle>Rotation Patterns</CardTitle>
                    <CardDescription>{rosterPatterns.length} configured -- e.g. site/marine rotation cycles</CardDescription>
                </div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Pattern</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Cycle</TableHead>
                            <TableHead>Rosters Using</TableHead>
                            <TableHead>Status</TableHead>
                            {can.manage && <TableHead />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rosterPatterns.map((p) => (
                            <TableRow key={p.id}>
                                <TableCell className="font-medium">{p.name}</TableCell>
                                <TableCell>{p.days_on} on / {p.days_off} off</TableCell>
                                <TableCell>{p.rosters_count}</TableCell>
                                <TableCell><Badge variant={p.is_active ? 'success' : 'secondary'}>{p.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                {can.manage && (
                                    <TableCell className="flex gap-1">
                                        <Button variant="ghost" size="icon" onClick={() => openEdit(p)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(p)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit Rotation Pattern' : 'Add Rotation Pattern'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Pattern Name</Label>
                            <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Site Rotation 6/1" />
                            {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Days On</Label>
                                <Input type="number" min="1" value={data.days_on} onChange={(e) => setData('days_on', e.target.value)} />
                                {errors.days_on && <p className="text-xs text-red-600">{errors.days_on}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Days Off</Label>
                                <Input type="number" min="1" value={data.days_off} onChange={(e) => setData('days_off', e.target.value)} />
                                {errors.days_off && <p className="text-xs text-red-600">{errors.days_off}</p>}
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Description (optional)</Label>
                            <Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} />
                            Active
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={processing}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
