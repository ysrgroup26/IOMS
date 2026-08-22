import { Head, router, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Plus, Search, ChevronLeft, ChevronRight, ArrowUpDown, AlertTriangle } from 'lucide-react';

const PRIORITY_VARIANT = { low: 'secondary', medium: 'outline', high: 'destructive', critical: 'destructive' };
const STATUS_VARIANT = {
    draft: 'secondary', open: 'outline', in_progress: 'success',
    on_hold: 'secondary', waiting: 'secondary', completed: 'success', cancelled: 'secondary',
};

function humanize(value) {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Universal Task Engine Foundation -- Task List (v1.6.4). Scoped exactly
 * to what this version's spec asks for: search, sorting, pagination, and
 * priority/status/assigned-user/due-date filters. (Bulk selection/bulk
 * status update were in an earlier, superseded draft of this request and
 * were deliberately left out here to match the authoritative, scoped-down
 * version rather than adding unrequested scope.) Reuses the existing
 * Table/Card/Select/Badge components and filter-in-URL pattern already
 * used by PPE Index, Daily Reports Index, etc.
 */
export default function TasksIndex({ tasks, users, filters, statuses, priorities }) {
    function applyFilters(overrides = {}) {
        router.get(route('tasks.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    function toggleSort(column) {
        const direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
        applyFilters({ sort: column, direction });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Tasks" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">Tasks</h1>
                    <p className="mt-1 text-sm text-graphite-500">{tasks.total} task(s) total</p>
                </div>
                <Button asChild><Link href={route('tasks.create')}><Plus className="h-4 w-4" /> New Task</Link></Button>
            </div>

            <Card>
                <CardContent className="flex flex-wrap gap-2 p-4">
                    <div className="relative w-56">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input
                            className="pl-8"
                            placeholder="Search title or number..."
                            defaultValue={filters.search}
                            onChange={(e) => applyFilters({ search: e.target.value || null })}
                        />
                    </div>
                    <Select value={filters.status || 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            {statuses.map((s) => <SelectItem key={s} value={s}>{humanize(s)}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.priority || 'all'} onValueChange={(v) => applyFilters({ priority: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Priority" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Priorities</SelectItem>
                            {priorities.map((p) => <SelectItem key={p} value={p}>{humanize(p)}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.assigned_user_id ? String(filters.assigned_user_id) : 'all'} onValueChange={(v) => applyFilters({ assigned_user_id: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Assigned To" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Users</SelectItem>
                            {users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Input
                        type="date"
                        className="w-40"
                        defaultValue={filters.due_date_from}
                        onChange={(e) => applyFilters({ due_date_from: e.target.value || null })}
                        title="Due date from"
                    />
                    <Input
                        type="date"
                        className="w-40"
                        defaultValue={filters.due_date_to}
                        onChange={(e) => applyFilters({ due_date_to: e.target.value || null })}
                        title="Due date to"
                    />
                </CardContent>
            </Card>

            <Card className="mt-4">
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="cursor-pointer" onClick={() => toggleSort('task_number')}>
                                    <span className="flex items-center gap-1">Task # <ArrowUpDown className="h-3 w-3" /></span>
                                </TableHead>
                                <TableHead className="cursor-pointer" onClick={() => toggleSort('title')}>
                                    <span className="flex items-center gap-1">Title <ArrowUpDown className="h-3 w-3" /></span>
                                </TableHead>
                                <TableHead className="cursor-pointer" onClick={() => toggleSort('priority')}>Priority</TableHead>
                                <TableHead className="cursor-pointer" onClick={() => toggleSort('status')}>Status</TableHead>
                                <TableHead>Assigned User</TableHead>
                                <TableHead className="cursor-pointer" onClick={() => toggleSort('due_date')}>Due Date</TableHead>
                                <TableHead>Created</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tasks.data.length === 0 ? (
                                <TableRow><TableCell colSpan={7} className="py-10 text-center text-graphite-400">No tasks found.</TableCell></TableRow>
                            ) : tasks.data.map((task) => (
                                <TableRow key={task.id} className="cursor-pointer" onClick={() => router.visit(route('tasks.show', task.id))}>
                                    <TableCell className="font-medium text-graphite-700">{task.task_number}</TableCell>
                                    <TableCell className="max-w-xs truncate">{task.title}</TableCell>
                                    <TableCell><Badge variant={PRIORITY_VARIANT[task.priority]}>{humanize(task.priority)}</Badge></TableCell>
                                    <TableCell><Badge variant={STATUS_VARIANT[task.status]}>{humanize(task.status)}</Badge></TableCell>
                                    <TableCell>{task.assigned_user?.name ?? '—'}</TableCell>
                                    <TableCell>
                                        {task.due_date ? (
                                            <span className={task.is_overdue ? 'flex items-center gap-1 text-red-600' : ''}>
                                                {task.is_overdue && <AlertTriangle className="h-3.5 w-3.5" />}
                                                {new Date(task.due_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                                            </span>
                                        ) : '—'}
                                    </TableCell>
                                    <TableCell className="text-graphite-400">{new Date(task.created_at).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {tasks.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-graphite-500">
                    <span>Page {tasks.current_page} of {tasks.last_page}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!tasks.prev_page_url} onClick={() => router.get(tasks.prev_page_url, {}, { preserveState: true })}>
                            <ChevronLeft className="h-4 w-4" /> Prev
                        </Button>
                        <Button variant="outline" size="sm" disabled={!tasks.next_page_url} onClick={() => router.get(tasks.next_page_url, {}, { preserveState: true })}>
                            Next <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
