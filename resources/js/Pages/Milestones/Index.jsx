import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogTrigger } from '@/Components/ui/dialog';
import StatusBadge from '@/Components/shared/StatusBadge';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Flag, Pencil, Trash2 } from 'lucide-react';

export default function MilestonesIndex({ milestones, projects, filters, can }) {
    const [addOpen, setAddOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    function applyFilter(key, value) {
        router.get(route('milestones.index'), { ...filters, [key]: value || null }, { preserveState: true, replace: true });
    }

    function destroy(id) {
        if (confirm('Remove this milestone?')) router.delete(route('milestones.destroy', id));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Milestones" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">Milestones</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Key dates across every project.</p>
                </div>
                {can.manage && (
                    <Dialog open={addOpen} onOpenChange={setAddOpen}>
                        <DialogTrigger asChild><Button><Plus className="h-4 w-4" /> Add Milestone</Button></DialogTrigger>
                        <DialogContent>
                            <DialogHeader><DialogTitle>Add Milestone</DialogTitle></DialogHeader>
                            <MilestoneForm projects={projects} onDone={() => setAddOpen(false)} />
                        </DialogContent>
                    </Dialog>
                )}
            </div>

            <Card className="mb-4">
                <CardContent className="flex flex-wrap gap-2 p-3">
                    <Select value={filters.project_id ? String(filters.project_id) : 'all'} onValueChange={(v) => applyFilter('project_id', v === 'all' ? null : v)}>
                        <SelectTrigger className="w-52"><SelectValue placeholder="Project" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Projects</SelectItem>
                            {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilter('status', v === 'all' ? null : v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="in_progress">In Progress</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="delayed">Delayed</SelectItem>
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {milestones.length === 0 ? (
                        <EmptyState icon={Flag} title="No milestones yet" description="Add a milestone to start tracking key project dates." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Title</TableHead>
                                    <TableHead>Project</TableHead>
                                    <TableHead>Target Date</TableHead>
                                    <TableHead>Status</TableHead>
                                    {can.manage && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {milestones.map((m) => (
                                    <TableRow key={m.id}>
                                        <TableCell className="font-medium text-graphite-800 dark:text-slate-100">{m.title}</TableCell>
                                        <TableCell>{m.project?.name}</TableCell>
                                        <TableCell>
                                            {new Date(m.target_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                                            {m.is_overdue && <span className="ml-1.5 text-xs text-red-600">Overdue</span>}
                                        </TableCell>
                                        <TableCell><StatusBadge value={m.status} /></TableCell>
                                        {can.manage && (
                                            <TableCell>
                                                <div className="flex items-center gap-1">
                                                    <Button variant="ghost" size="icon" onClick={() => setEditing(m)}><Pencil className="h-4 w-4" /></Button>
                                                    <Button variant="ghost" size="icon" onClick={() => destroy(m.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            <Dialog open={!!editing} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Edit Milestone</DialogTitle></DialogHeader>
                    {editing && <MilestoneForm projects={projects} milestone={editing} onDone={() => setEditing(null)} />}
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}

function MilestoneForm({ projects, milestone, onDone }) {
    const { data, setData, post, put, processing, errors } = useForm({
        project_id: milestone?.project_id ? String(milestone.project_id) : '',
        title: milestone?.title || '',
        description: milestone?.description || '',
        target_date: milestone?.target_date || '',
        status: milestone?.status || 'pending',
    });

    function submit(e) {
        e.preventDefault();
        const options = { onSuccess: onDone };
        if (milestone) {
            put(route('milestones.update', milestone.id), options);
        } else {
            post(route('milestones.store'), options);
        }
    }

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="space-y-1.5">
                <Label>Project</Label>
                <Select value={data.project_id} onValueChange={(v) => setData('project_id', v)} disabled={!!milestone}>
                    <SelectTrigger><SelectValue placeholder="Select project" /></SelectTrigger>
                    <SelectContent>
                        {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                    </SelectContent>
                </Select>
                {errors.project_id && <p className="text-xs text-red-600">{errors.project_id}</p>}
            </div>
            <div className="space-y-1.5">
                <Label>Title</Label>
                <Input value={data.title} onChange={(e) => setData('title', e.target.value)} />
                {errors.title && <p className="text-xs text-red-600">{errors.title}</p>}
            </div>
            <div className="space-y-1.5">
                <Label>Description</Label>
                <Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} />
            </div>
            <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                    <Label>Target Date</Label>
                    <Input type="date" value={data.target_date} onChange={(e) => setData('target_date', e.target.value)} />
                    {errors.target_date && <p className="text-xs text-red-600">{errors.target_date}</p>}
                </div>
                <div className="space-y-1.5">
                    <Label>Status</Label>
                    <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="in_progress">In Progress</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="delayed">Delayed</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <DialogFooter><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
        </form>
    );
}
