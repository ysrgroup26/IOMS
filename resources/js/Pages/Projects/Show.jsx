import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/Components/ui/dialog';
import { ArrowLeft, Pencil, Trash2, MapPin, Calendar, UserPlus, X, Clock, Search, ListChecks } from 'lucide-react';

const STATUS_VARIANT = {
    planned: 'secondary',
    ongoing: 'success',
    completed: 'outline',
    cancelled: 'destructive',
};

export default function ProjectShow({ project, manpowerGrouped, timeline, availableEmployees, can }) {
    const [manpowerDialogOpen, setManpowerDialogOpen] = useState(false);

    function destroy() {
        if (confirm(`Remove project "${project.name}"? This cannot be undone.`)) {
            router.delete(route('projects.destroy', project.id));
        }
    }

    function removeManpower(employeeId, employeeName) {
        if (confirm(`Remove ${employeeName} from this project?`)) {
            router.delete(route('projects.manpower.remove', [project.id, employeeId]));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={project.name} />

            <Link href={route('projects.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Projects
            </Link>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-2xl font-bold tracking-tight text-graphite-900">{project.name}</h1>
                        <Badge variant={STATUS_VARIANT[project.status]} className="capitalize">{project.status}</Badge>
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-graphite-500">
                        <span>{project.company?.name}</span>
                        {project.vessel_name && (
                            <span className="flex items-center gap-1"><MapPin className="h-3.5 w-3.5" /> {project.vessel_name}</span>
                        )}
                        {(project.start_date || project.end_date) && (
                            <span className="flex items-center gap-1">
                                <Calendar className="h-3.5 w-3.5" />
                                {project.start_date ? new Date(project.start_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '—'}
                                {' – '}
                                {project.end_date ? new Date(project.end_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : 'Ongoing'}
                            </span>
                        )}
                    </div>
                </div>
                <div className="flex gap-2">
                    {/* v1.10.5: was previously unreachable from any UI --
                        the backend/route (`projects.activities`) has existed
                        since Acceleration Part 3, but nothing linked to it.
                        Viewable by everyone who can view the project itself,
                        matching the "view is open, mutation is gated"
                        pattern used throughout this page. */}
                    <Button variant="outline" asChild>
                        <Link href={route('projects.activities', project.id)}><ListChecks className="h-4 w-4" /> Activities</Link>
                    </Button>
                    {can.manage && (
                        <>
                            <Button variant="outline" asChild>
                                <Link href={route('projects.edit', project.id)}><Pencil className="h-4 w-4" /> Edit</Link>
                            </Button>
                            <Button variant="destructive" onClick={destroy}>
                                <Trash2 className="h-4 w-4" /> Delete
                            </Button>
                        </>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {project.description && (
                        <Card>
                            <CardContent className="p-3.5 text-[13px] text-graphite-600">{project.description}</CardContent>
                        </Card>
                    )}

                    {/* Manpower, grouped by department per spec */}
                    <Card>
                        <CardHeader className="flex-row items-center justify-between space-y-0">
                            <div>
                                <CardTitle>Manpower</CardTitle>
                                <CardDescription>Employees assigned to this project, grouped by department</CardDescription>
                            </div>
                            {can.manage && (
                                <Button size="sm" onClick={() => setManpowerDialogOpen(true)}>
                                    <UserPlus className="h-4 w-4" /> Add
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent>
                            {manpowerGrouped.length === 0 ? (
                                <p className="py-6 text-center text-sm text-graphite-400">No manpower assigned yet.</p>
                            ) : (
                                <div className="space-y-4">
                                    {manpowerGrouped.map((group) => (
                                        <div key={group.department}>
                                            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-graphite-400">
                                                {group.department}
                                            </p>
                                            <div className="flex flex-wrap gap-2">
                                                {group.employees.map((emp) => (
                                                    <div
                                                        key={emp.id}
                                                        className="flex items-center gap-1 rounded-full border border-graphite-200 bg-graphite-50 py-0.5 pl-2 pr-0.5 text-[11px] text-graphite-700"
                                                    >
                                                        {emp.full_name}
                                                        {can.manage && (
                                                            <button
                                                                onClick={() => removeManpower(emp.id, emp.full_name)}
                                                                className="rounded-full p-0.5 text-graphite-400 hover:bg-graphite-200 hover:text-red-600"
                                                            >
                                                                <X className="h-2.5 w-2.5" />
                                                            </button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Timeline, auto-collected from project activity */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2"><Clock className="h-4 w-4" /> Timeline</CardTitle>
                        <CardDescription>Activity automatically collected from this project</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {timeline.length === 0 ? (
                            <p className="py-6 text-center text-sm text-graphite-400">No activity yet.</p>
                        ) : (
                            <ol className="relative space-y-5 border-l border-graphite-200 pl-5">
                                {timeline.map((event) => (
                                    <li key={event.id} className="relative">
                                        <span className="absolute -left-[26px] top-0.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-primary" />
                                        <p className="text-xs font-semibold text-graphite-400">{event.event_date}</p>
                                        <p className="text-sm font-medium text-graphite-800">{event.title}</p>
                                        {event.description && (
                                            <p className="text-xs text-graphite-500">{event.description}</p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </CardContent>
                </Card>
            </div>

            {can.manage && (
                <AddManpowerDialog
                    open={manpowerDialogOpen}
                    onOpenChange={setManpowerDialogOpen}
                    project={project}
                    availableEmployees={availableEmployees}
                />
            )}
        </AuthenticatedLayout>
    );
}

function AddManpowerDialog({ open, onOpenChange, project, availableEmployees }) {
    const { data, setData, post, processing, reset } = useForm({ employee_ids: [] });
    const [departmentFilter, setDepartmentFilter] = useState('all');
    const [search, setSearch] = useState('');

    // Employees are always CHOSEN from Employee Master via checklist, never
    // typed manually -- same Quick Attendance pattern used in Input KPI,
    // grouped by department here for readability. The Department filter
    // and search box (v1.5.2) address the same problem: when a company
    // has many employees, scrolling through every group to find one
    // person is slow -- filtering narrows the list instantly.
    const departmentNames = [...new Set(availableEmployees.map((e) => e.department?.name || 'Unassigned'))].sort();

    const filteredEmployees = availableEmployees.filter((emp) => {
        const deptName = emp.department?.name || 'Unassigned';
        if (departmentFilter !== 'all' && deptName !== departmentFilter) return false;
        if (search && !emp.full_name.toLowerCase().includes(search.toLowerCase())) return false;
        return true;
    });

    const grouped = filteredEmployees.reduce((acc, emp) => {
        const deptName = emp.department?.name || 'Unassigned';
        (acc[deptName] ||= []).push(emp);
        return acc;
    }, {});

    function toggle(employeeId) {
        setData('employee_ids', data.employee_ids.includes(employeeId)
            ? data.employee_ids.filter((id) => id !== employeeId)
            : [...data.employee_ids, employeeId]);
    }

    function submit(e) {
        e.preventDefault();
        post(route('projects.manpower.add', project.id), {
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[80vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Add Manpower</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    {availableEmployees.length === 0 ? (
                        <p className="py-6 text-center text-sm text-graphite-400">
                            All employees from this company are already assigned.
                        </p>
                    ) : (
                        <>
                            <div className="flex gap-2">
                                <div className="relative flex-1">
                                    <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                                    <Input className="pl-8" placeholder="Search employees..." value={search} onChange={(e) => setSearch(e.target.value)} />
                                </div>
                                <Select value={departmentFilter} onValueChange={setDepartmentFilter}>
                                    <SelectTrigger className="w-40"><SelectValue placeholder="Department" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Departments</SelectItem>
                                        {departmentNames.map((name) => <SelectItem key={name} value={name}>{name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>

                            {Object.keys(grouped).length === 0 ? (
                                <p className="py-6 text-center text-sm text-graphite-400">No employees match your search.</p>
                            ) : (
                                <div className="max-h-80 space-y-4 overflow-y-auto pr-1">
                                    {Object.entries(grouped).map(([deptName, employees]) => (
                                        <div key={deptName}>
                                            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-graphite-400">{deptName}</p>
                                            <div className="space-y-1.5">
                                                {employees.map((emp) => (
                                                    <label key={emp.id} className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-graphite-50">
                                                        <Checkbox
                                                            checked={data.employee_ids.includes(emp.id)}
                                                            onCheckedChange={() => toggle(emp.id)}
                                                        />
                                                        {emp.full_name}
                                                        <span className="text-xs text-graphite-400">{emp.employee_id}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing || data.employee_ids.length === 0}>
                            Add {data.employee_ids.length > 0 ? `(${data.employee_ids.length})` : ''}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
