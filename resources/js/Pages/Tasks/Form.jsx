import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft, Loader2 } from 'lucide-react';

function humanize(value) {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Universal Task Engine Foundation -- Task Create/Edit (v1.6.4). Shared
 * form for both, matching the existing pattern used by DailyReports/Form,
 * Ppe forms, etc. `related_module`/`related_record_id`/`task_source` are
 * intentionally not exposed as form fields here -- they're meant to be
 * set programmatically by whichever future module creates a task on a
 * user's behalf (e.g. auto-generating a task from a PPE replacement
 * request), not typed in by hand on this general-purpose form.
 */
export default function TaskForm({ task, users, companies, statuses, priorities }) {
    const isEdit = !!task;
    const { data, setData, post, put, processing, errors } = useForm({
        title: task?.title || '',
        description: task?.description || '',
        priority: task?.priority || 'medium',
        status: task?.status || 'open',
        task_type: task?.task_type || '',
        company_id: task?.company_id ? String(task.company_id) : '',
        assigned_user_id: task?.assigned_user_id ? String(task.assigned_user_id) : '',
        due_date: task?.due_date || '',
        start_date: task?.start_date || '',
    });

    function submit(e) {
        e.preventDefault();
        if (isEdit) {
            put(route('tasks.update', task.id));
        } else {
            post(route('tasks.store'));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={isEdit ? `Edit ${task.task_number}` : 'New Task'} />

            <Link href={isEdit ? route('tasks.show', task.id) : route('tasks.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back
            </Link>

            <h1 className="mb-6 text-[22px] font-semibold tracking-tight text-graphite-900">{isEdit ? `Edit ${task.task_number}` : 'New Task'}</h1>

            <Card className="max-w-2xl">
                <CardHeader>
                    <CardTitle>Task Details</CardTitle>
                    <CardDescription>General-purpose task -- usable by any module or as a standalone to-do.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Title</Label>
                            <Input value={data.title} onChange={(e) => setData('title', e.target.value)} />
                            {errors.title && <p className="text-xs text-red-600">{errors.title}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Description</Label>
                            <textarea
                                className="min-h-24 w-full rounded-md border border-graphite-200 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Priority</Label>
                                <Select value={data.priority} onValueChange={(v) => setData('priority', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {priorities.map((p) => <SelectItem key={p} value={p}>{humanize(p)}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            {isEdit && (
                                <div className="space-y-1.5">
                                    <Label>Status</Label>
                                    <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {statuses.map((s) => <SelectItem key={s} value={s}>{humanize(s)}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Assigned To (optional)</Label>
                                <Select value={data.assigned_user_id || 'none'} onValueChange={(v) => setData('assigned_user_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="Unassigned" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Unassigned</SelectItem>
                                        {users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Company (optional)</Label>
                                <Select value={data.company_id || 'none'} onValueChange={(v) => setData('company_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="No specific company" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">No specific company</SelectItem>
                                        {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label>Start Date (optional)</Label>
                                <Input type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Due Date (optional)</Label>
                                <Input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} />
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Task Type (optional)</Label>
                            <Input value={data.task_type} onChange={(e) => setData('task_type', e.target.value)} placeholder="e.g. follow_up, general" />
                        </div>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline" asChild>
                                <Link href={isEdit ? route('tasks.show', task.id) : route('tasks.index')}>Cancel</Link>
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                                {isEdit ? 'Save Changes' : 'Create Task'}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
