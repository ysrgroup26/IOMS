import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import EmptyState from '@/Components/shared/EmptyState';
import { CalendarClock } from 'lucide-react';

/**
 * Milestone 4, Workstream A3. Cross-employee Roster Overview -- who is
 * on/off duty today, on which shift, at which site. Mirrors
 * Competency/ExpiringSoon.jsx's role as a real, data-backed cross-employee
 * report, scoped server-side to the current tenant only (see
 * RosterController::overview()'s own doc comment).
 */
export default function RosterOverview({ rosters, companies, filters }) {
    function changeCompany(value) {
        router.get(route('rosters.overview'), { company_id: value === 'all' ? undefined : value }, { preserveState: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Roster Overview" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-bold tracking-tight text-graphite-900">Roster Overview</h1>
                    <p className="mt-1 text-sm text-graphite-500">Current employee rosters -- shift, site, and today's duty status.</p>
                </div>
                <Select value={filters.company_id ? String(filters.company_id) : 'all'} onValueChange={changeCompany}>
                    <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Companies</SelectItem>
                        {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                    </SelectContent>
                </Select>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Current Rosters</CardTitle>
                    <CardDescription>{rosters.length} active roster{rosters.length !== 1 ? 's' : ''}</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    {rosters.length === 0 ? (
                        <EmptyState icon={CalendarClock} title="No active rosters" description="No employee has an active roster entry right now." />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Company</TableHead>
                                        <TableHead>Department</TableHead>
                                        <TableHead>Shift</TableHead>
                                        <TableHead>Pattern</TableHead>
                                        <TableHead>Site</TableHead>
                                        <TableHead>Period</TableHead>
                                        <TableHead>Today</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rosters.map((r) => (
                                        <TableRow key={r.id}>
                                            <TableCell className="font-medium">
                                                <Link href={route('employees.show', r.employee_id)} className="hover:underline">
                                                    {r.employee_name}
                                                </Link>
                                                <div className="text-xs text-graphite-400">{r.employee_code}</div>
                                            </TableCell>
                                            <TableCell className="text-graphite-500">{r.company ?? '—'}</TableCell>
                                            <TableCell className="text-graphite-500">{r.department ?? '—'}</TableCell>
                                            <TableCell>{r.shift ? `${r.shift.name} (${r.shift.code})` : '—'}</TableCell>
                                            <TableCell className="text-graphite-500">{r.roster_pattern ?? '—'}</TableCell>
                                            <TableCell className="text-graphite-500">{r.site ?? '—'}</TableCell>
                                            <TableCell className="text-xs text-graphite-500">{r.start_date}{r.end_date ? ` - ${r.end_date}` : ' - ongoing'}</TableCell>
                                            <TableCell>
                                                <Badge variant={r.duty_today === 'on' ? 'success' : 'secondary'} className="capitalize">
                                                    {r.duty_today} duty
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>
        </AuthenticatedLayout>
    );
}
