import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import '@/lib/chartSetup';
import { CHART_COLORS } from '@/lib/chartSetup';
import { Bar } from 'react-chartjs-2';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import KpiSummaryCard from '@/Components/shared/KpiSummaryCard';
import { ArrowLeft, Pencil, Trash2, Phone, Calendar, Briefcase, FolderKanban, HardHat, GraduationCap, Plus } from 'lucide-react';
import { MONTH_NAMES } from '@/lib/utils';

const COMPETENCY_STATUS_VARIANT = {
    valid: 'success',
    expiring_soon: 'default',
    expired: 'destructive',
    no_expiry: 'secondary',
};

const COMPETENCY_STATUS_LABEL = {
    valid: 'Valid',
    expiring_soon: 'Expiring Soon',
    expired: 'Expired',
    no_expiry: 'No Expiry',
};

const CATEGORY_META = [
    { code: 'fatality', label: 'Fatality', negative: true },
    { code: 'lti', label: 'LTI', negative: true },
    { code: 'fac', label: 'FAC', negative: true },
    { code: 'ppe_violation', label: 'PPE Viol.', negative: true },
    { code: 'bbs_nearmiss', label: 'BBS', negative: false },
    { code: 'drill', label: 'Drill', negative: false },
    { code: 'campaign', label: 'Campaign', negative: false },
    { code: 'tbm', label: 'TBM', negative: false },
];

export default function EmployeeProfile({ employee, yearSummary, monthlyBreakdown, year, yearsOfService, projects, competencyTypes, can }) {
    const [competencyDialogOpen, setCompetencyDialogOpen] = useState(false);

    function destroy() {
        if (confirm(`Remove ${employee.full_name}? This cannot be undone.`)) {
            router.delete(route('employees.destroy', employee.id));
        }
    }

    function removeCompetency(id) {
        if (confirm('Remove this competency record?')) {
            router.delete(route('employee-competencies.destroy', id));
        }
    }

    const tbmSeries = monthlyBreakdown.map((m) => m.totals.tbm || 0);
    const drillSeries = monthlyBreakdown.map((m) => m.totals.drill || 0);
    const campaignSeries = monthlyBreakdown.map((m) => m.totals.campaign || 0);
    const bbsSeries = monthlyBreakdown.map((m) => m.totals.bbs_nearmiss || 0);

    const chartData = {
        labels: MONTH_NAMES.map((m) => m.slice(0, 3)),
        datasets: [
            { label: 'TBM', data: tbmSeries, backgroundColor: CHART_COLORS[0] },
            { label: 'Drill', data: drillSeries, backgroundColor: CHART_COLORS[1] },
            { label: 'Campaign', data: campaignSeries, backgroundColor: CHART_COLORS[2] },
            { label: 'BBS', data: bbsSeries, backgroundColor: CHART_COLORS[3] },
        ],
    };

    return (
        <AuthenticatedLayout>
            <Head title={employee.full_name} />

            <Link href={route('employees.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Employees
            </Link>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                {/* Profile card */}
                <Card className="lg:col-span-1">
                    <CardContent className="flex flex-col items-center p-4 text-center">
                        <div className="mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-graphite-100 text-xl font-bold text-graphite-500">
                            {employee.photo_path
                                ? <img src={employee.photo_url} className="h-16 w-16 rounded-full object-cover" alt="" />
                                : employee.full_name.charAt(0)
                            }
                        </div>
                        <h2 className="text-sm font-bold text-graphite-900">{employee.full_name}</h2>
                        <p className="text-xs text-graphite-400">{employee.employee_id}</p>
                        <Badge variant={employee.status === 'active' ? 'success' : 'secondary'} className="mt-2 capitalize">
                            {employee.status}
                        </Badge>

                        <div className="mt-5 w-full space-y-2.5 border-t border-graphite-100 pt-5 text-left text-sm">
                            <InfoRow icon={Briefcase} label="Company" value={employee.company?.name ?? '—'} />
                            <InfoRow label="Department" value={employee.department?.name} />
                            <InfoRow label="Position" value={employee.position?.name ?? '—'} />
                            <InfoRow label="Workforce Type" value={employee.employment_type_label ?? '—'} />
                            {employee.nik && <InfoRow label="NIK" value={employee.nik} />}
                            {employee.phone && <InfoRow icon={Phone} label="Phone" value={employee.phone} />}
                            {employee.join_date && (
                                <InfoRow icon={Calendar} label="Joined" value={new Date(employee.join_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} />
                            )}
                            {employee.contract_end_date && (
                                <InfoRow label="Contract Ends" value={new Date(employee.contract_end_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })} />
                            )}
                            {yearsOfService !== null && yearsOfService !== undefined && (
                                <InfoRow label="Years of Service" value={`${yearsOfService} yrs`} />
                            )}
                        </div>

                        {/* View PPE (v1.6.6 restructure) -- Employee stays
                            responsible only for employee information; PPE
                            management itself lives entirely in the PPE
                            module. This is just a doorway: Employee ->
                            View PPE -> PPE -> Employee PPE -> this
                            specific employee, reusing the same
                            ppe.employees.show route the PPE module's own
                            employee list links to. */}
                        <Button variant="outline" className="mt-3 w-full" asChild>
                            <Link href={route('ppe.employees.show', employee.id)}><HardHat className="h-4 w-4" /> View PPE</Link>
                        </Button>

                        {can.manage && (
                            <div className="mt-3 flex w-full gap-2">
                                <Button variant="outline" className="flex-1" asChild>
                                    <Link href={route('employees.edit', employee.id)}><Pencil className="h-4 w-4" /> Edit</Link>
                                </Button>
                                <Button variant="destructive" className="flex-1" onClick={destroy}>
                                    <Trash2 className="h-4 w-4" /> Remove
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* KPI summary + chart */}
                <div className="space-y-4 lg:col-span-2">
                    <div>
                        <h3 className="mb-2 text-sm font-semibold text-graphite-700">Year {year} Summary</h3>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {CATEGORY_META.map((cat) => (
                                <KpiSummaryCard
                                    key={cat.code}
                                    code={cat.code}
                                    label={cat.label}
                                    value={yearSummary[cat.code] || 0}
                                    isNegative={cat.negative}
                                />
                            ))}
                        </div>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Monthly History</CardTitle>
                            <CardDescription>Engagement activities by month, {year}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <Bar
                                    data={chartData}
                                    options={{
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                                    }}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {employee.internship && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Intern / PKL Placement</CardTitle>
                                <CardDescription>{employee.internship.institution}</CardDescription>
                            </CardHeader>
                            <CardContent className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                                {employee.internship.program && <InfoRow label="Program" value={employee.internship.program} />}
                                {employee.internship.mentor_name && <InfoRow label="Mentor" value={employee.internship.mentor_name} />}
                                {employee.internship.agreement_number && <InfoRow label="Agreement No." value={employee.internship.agreement_number} />}
                                {employee.internship.work_location && <InfoRow label="Location" value={employee.internship.work_location} />}
                                <InfoRow label="Induction" value={employee.internship.induction_completed ? 'Completed' : 'Pending'} />
                                <InfoRow label="Status" value={<Badge variant="outline" className="capitalize">{employee.internship.completion_status}</Badge>} />
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div>
                                <CardTitle className="flex items-center gap-2"><GraduationCap className="h-4 w-4" /> Training &amp; Certification</CardTitle>
                                <CardDescription>Competency records for this employee</CardDescription>
                            </div>
                            {can.manage && (
                                <Button size="sm" onClick={() => setCompetencyDialogOpen(true)}>
                                    <Plus className="h-3.5 w-3.5" /> Add
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="p-0">
                            {(!employee.competencies || employee.competencies.length === 0) ? (
                                <p className="px-4 pb-4 text-sm text-graphite-400">No training or certification recorded yet.</p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Competency</TableHead>
                                            <TableHead>Certificate No.</TableHead>
                                            <TableHead>Achieved</TableHead>
                                            <TableHead>Expiry</TableHead>
                                            <TableHead>Status</TableHead>
                                            {can.manage && <TableHead />}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {employee.competencies.map((c) => (
                                            <TableRow key={c.id}>
                                                <TableCell className="font-medium">
                                                    {c.competency_type?.name}
                                                    <Badge variant="outline" className="ml-2 capitalize">{c.competency_type?.type}</Badge>
                                                </TableCell>
                                                <TableCell className="text-graphite-500">{c.certificate_number ?? '—'}</TableCell>
                                                <TableCell>{c.achieved_date?.slice(0, 10)}</TableCell>
                                                <TableCell>{c.expiry_date ? c.expiry_date.slice(0, 10) : '—'}</TableCell>
                                                <TableCell>
                                                    <Badge variant={COMPETENCY_STATUS_VARIANT[c.effective_status] ?? 'secondary'}>
                                                        {COMPETENCY_STATUS_LABEL[c.effective_status] ?? c.effective_status}
                                                    </Badge>
                                                </TableCell>
                                                {can.manage && (
                                                    <TableCell>
                                                        <Button variant="ghost" size="icon" onClick={() => removeCompetency(c.id)}>
                                                            <Trash2 className="h-4 w-4 text-red-500" />
                                                        </Button>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>

                    {projects && projects.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2"><FolderKanban className="h-4 w-4" /> Projects</CardTitle>
                                <CardDescription>Projects this employee is currently assigned to</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {projects.map((p) => (
                                    <Link
                                        key={p.id}
                                        href={route('projects.show', p.id)}
                                        className="flex items-center justify-between rounded-lg border border-graphite-100 px-3 py-2 text-sm hover:bg-graphite-50"
                                    >
                                        <span className="font-medium text-graphite-800">{p.name}</span>
                                        <Badge variant="outline" className="capitalize">{p.status}</Badge>
                                    </Link>
                                ))}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>

            <AddCompetencyDialog
                open={competencyDialogOpen}
                onOpenChange={setCompetencyDialogOpen}
                employeeId={employee.id}
                competencyTypes={competencyTypes}
            />
        </AuthenticatedLayout>
    );
}

function AddCompetencyDialog({ open, onOpenChange, employeeId, competencyTypes }) {
    const { data, setData, post, processing, reset, errors } = useForm({
        competency_type_id: '',
        certificate_number: '',
        issuer: '',
        achieved_date: '',
        expiry_date: '',
        notes: '',
        attachment: null,
    });

    function submit(e) {
        e.preventDefault();
        post(route('employees.competencies.store', employeeId), {
            forceFormData: true,
            onSuccess: () => { reset(); onOpenChange(false); },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader><DialogTitle>Add Training / Certification</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>Competency</Label>
                        <Select value={data.competency_type_id} onValueChange={(v) => setData('competency_type_id', v)}>
                            <SelectTrigger><SelectValue placeholder="Select training or certification" /></SelectTrigger>
                            <SelectContent>
                                {competencyTypes.map((t) => (
                                    <SelectItem key={t.id} value={String(t.id)}>{t.name} ({t.type})</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.competency_type_id && <p className="text-xs text-red-600">{errors.competency_type_id}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label>Certificate No. (optional)</Label>
                            <Input value={data.certificate_number} onChange={(e) => setData('certificate_number', e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Issuer (optional)</Label>
                            <Input value={data.issuer} onChange={(e) => setData('issuer', e.target.value)} />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label>Achieved Date</Label>
                            <Input type="date" value={data.achieved_date} onChange={(e) => setData('achieved_date', e.target.value)} />
                            {errors.achieved_date && <p className="text-xs text-red-600">{errors.achieved_date}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Expiry Date (optional)</Label>
                            <Input type="date" value={data.expiry_date} onChange={(e) => setData('expiry_date', e.target.value)} placeholder="Auto-computed if left blank" />
                            {errors.expiry_date && <p className="text-xs text-red-600">{errors.expiry_date}</p>}
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label>Attachment (optional)</Label>
                        <Input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => setData('attachment', e.target.files[0])} />
                        {errors.attachment && <p className="text-xs text-red-600">{errors.attachment}</p>}
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

function InfoRow({ icon: Icon, label, value }) {
    return (
        <div className="flex items-center justify-between">
            <span className="flex items-center gap-1.5 text-graphite-400">
                {Icon && <Icon className="h-3.5 w-3.5" />} {label}
            </span>
            <span className="font-medium text-graphite-700">{value}</span>
        </div>
    );
}
