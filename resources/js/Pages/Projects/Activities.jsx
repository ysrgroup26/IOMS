import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { ArrowLeft, Plus, Pencil, Trash2, ListTodo } from 'lucide-react';

export default function ProjectActivities({ project, activities, employees, statuses, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        name: '', assigned_employee_id: '', progress: '0', status: 'not_started',
    });

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(a) {
        setEditing(a);
        setData({ name: a.name, assigned_employee_id: a.assigned_employee_id ? String(a.assigned_employee_id) : '', progress: String(a.progress), status: a.status });
        setOpen(true);
    }
    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('projects.activities.update', [project.id, editing.id]), options); } else { post(route('projects.activities.store', project.id), options); }
    }
    function destroy(a) {
        if (confirm(`Remove activity "${a.name}"?`)) router.delete(route('projects.activities.destroy', [project.id, a.id]));
    }

    return (
        <AuthenticatedLayout>
            <Head title={`Activities -- ${project.name}`} />

            <Link href={route('projects.show', project.id)} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to {project.name}
            </Link>

            <PageHeader title="Project Activities" subtitle={`${project.project_code || project.name} -- tracked tasks, assignees, and progress.`}>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Activity</Button>}
            </PageHeader>

            <Card>
                <CardContent className="p-0">
                    {activities.length === 0 ? (
                        <EmptyState icon={ListTodo} title="No activities tracked" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Activity</TableHead><TableHead>Assigned To</TableHead><TableHead>Progress</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                            <TableBody>
                                {activities.map((a) => (
                                    <TableRow key={a.id}>
                                        <TableCell className="font-medium">{a.name}</TableCell>
                                        <TableCell>{a.assigned_employee?.full_name || '-'}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <div className="h-2 w-24 overflow-hidden rounded-full bg-graphite-100"><div className="h-full bg-brand-500" style={{ width: `${a.progress}%` }} /></div>
                                                <span className="text-xs text-graphite-500">{a.progress}%</span>
                                            </div>
                                        </TableCell>
                                        <TableCell><StatusBadge value={a.status === 'completed' ? 'approved' : a.status} label={a.status.replace('_', ' ')} /></TableCell>
                                        {can.manage && (
                                            <TableCell className="flex gap-1">
                                                <Button variant="ghost" size="icon" onClick={() => openEdit(a)}><Pencil className="h-4 w-4" /></Button>
                                                <Button variant="ghost" size="icon" onClick={() => destroy(a)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} Activity</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5"><Input placeholder="Activity name" value={data.name} onChange={(e) => setData('name', e.target.value)} />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                        <Select value={data.assigned_employee_id || 'none'} onValueChange={(v) => setData('assigned_employee_id', v === 'none' ? '' : v)}>
                            <SelectTrigger><SelectValue placeholder="Unassigned" /></SelectTrigger>
                            <SelectContent><SelectItem value="none">Unassigned</SelectItem>{employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.full_name}</SelectItem>)}</SelectContent>
                        </Select>
                        <div className="grid grid-cols-2 gap-4">
                            <Input type="number" min="0" max="100" placeholder="Progress %" value={data.progress} onChange={(e) => setData('progress', e.target.value)} />
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{statuses.map((s) => <SelectItem key={s} value={s} className="capitalize">{s.replace('_', ' ')}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
