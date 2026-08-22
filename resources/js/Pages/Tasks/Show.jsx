import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { ArrowLeft, Pencil, Trash2, AlertTriangle, Calendar, User as UserIcon, Building2 } from 'lucide-react';

const PRIORITY_VARIANT = { low: 'secondary', medium: 'outline', high: 'destructive', critical: 'destructive' };
const STATUS_VARIANT = {
    draft: 'secondary', open: 'outline', in_progress: 'success',
    on_hold: 'secondary', waiting: 'secondary', completed: 'success', cancelled: 'secondary',
};

function humanize(value) {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(value, withTime = false) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-US', {
        day: 'numeric', month: 'short', year: 'numeric',
        ...(withTime ? { hour: 'numeric', minute: '2-digit' } : {}),
    });
}

/**
 * Universal Task Engine Foundation -- Task Detail (v1.6.4). Displays
 * exactly what this version's spec asks for: number, title, description,
 * priority, status, assigned user, created by, due/start/completed
 * dates, related module/record. Comments, attachments, history, and
 * timeline are explicitly out of scope for this version (see
 * ROADMAP.md) -- deliberately not stubbed here with fake "coming soon"
 * UI, since that would be exactly the kind of placeholder this version's
 * spec forbids.
 */
export default function TaskShow({ task, can }) {
    function destroy() {
        if (confirm(`Delete task ${task.task_number}? This cannot be undone.`)) {
            router.delete(route('tasks.destroy', task.id));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={task.task_number} />

            <Link href={route('tasks.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Tasks
            </Link>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">{task.title}</h1>
                        {task.is_overdue && (
                            <span className="flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-600">
                                <AlertTriangle className="h-3.5 w-3.5" /> Overdue
                            </span>
                        )}
                    </div>
                    <p className="mt-1 text-sm text-graphite-500">{task.task_number}</p>
                </div>
                {can?.manage && (
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" asChild><Link href={route('tasks.edit', task.id)}><Pencil className="h-4 w-4" /> Edit</Link></Button>
                        <Button variant="outline" size="sm" onClick={destroy}><Trash2 className="h-4 w-4 text-red-500" /> Delete</Button>
                    </div>
                )}
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Description</CardTitle></CardHeader>
                        <CardContent>
                            <p className="whitespace-pre-wrap text-sm text-graphite-700">{task.description || 'No description provided.'}</p>
                        </CardContent>
                    </Card>

                    {(task.related_module || task.related_record_id) && (
                        <Card>
                            <CardHeader><CardTitle>Related Record</CardTitle></CardHeader>
                            <CardContent className="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <p className="text-xs text-graphite-400">Module</p>
                                    <p className="font-medium text-graphite-800">{task.related_module ? humanize(task.related_module) : '—'}</p>
                                </div>
                                <div>
                                    <p className="text-xs text-graphite-400">Record ID</p>
                                    <p className="font-medium text-graphite-800">{task.related_record_id ?? '—'}</p>
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader><CardTitle>Status</CardTitle></CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-graphite-500">Status</span>
                                <Badge variant={STATUS_VARIANT[task.status]}>{humanize(task.status)}</Badge>
                            </div>
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-graphite-500">Priority</span>
                                <Badge variant={PRIORITY_VARIANT[task.priority]}>{humanize(task.priority)}</Badge>
                            </div>
                            {task.task_type && (
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-graphite-500">Type</span>
                                    <span className="font-medium text-graphite-800">{humanize(task.task_type)}</span>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>People</CardTitle></CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center gap-2 text-sm">
                                <UserIcon className="h-4 w-4 shrink-0 text-graphite-400" />
                                <div>
                                    <p className="text-xs text-graphite-400">Assigned To</p>
                                    <p className="font-medium text-graphite-800">{task.assigned_user?.name ?? 'Unassigned'}</p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2 text-sm">
                                <UserIcon className="h-4 w-4 shrink-0 text-graphite-400" />
                                <div>
                                    <p className="text-xs text-graphite-400">Created By</p>
                                    <p className="font-medium text-graphite-800">{task.creator?.name ?? '—'}</p>
                                </div>
                            </div>
                            {task.company && (
                                <div className="flex items-center gap-2 text-sm">
                                    <Building2 className="h-4 w-4 shrink-0 text-graphite-400" />
                                    <div>
                                        <p className="text-xs text-graphite-400">Company</p>
                                        <p className="font-medium text-graphite-800">{task.company.name}</p>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Dates</CardTitle></CardHeader>
                        <CardContent className="space-y-3">
                            <DateRow icon={Calendar} label="Start Date" value={formatDate(task.start_date)} />
                            <DateRow icon={Calendar} label="Due Date" value={formatDate(task.due_date)} accent={task.is_overdue ? 'red' : null} />
                            <DateRow icon={Calendar} label="Completed" value={formatDate(task.completed_date, true)} />
                            <DateRow icon={Calendar} label="Created" value={formatDate(task.created_at, true)} />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function DateRow({ icon: Icon, label, value, accent }) {
    return (
        <div className="flex items-center gap-2 text-sm">
            <Icon className="h-4 w-4 shrink-0 text-graphite-400" />
            <div>
                <p className="text-xs text-graphite-400">{label}</p>
                <p className={accent === 'red' ? 'font-medium text-red-600' : 'font-medium text-graphite-800'}>{value}</p>
            </div>
        </div>
    );
}
