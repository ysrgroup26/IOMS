import { Head, useForm, router, Link } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import GroupedDepartmentSelect from '@/Components/shared/GroupedDepartmentSelect';
import PpeTabNav from '@/Components/shared/PpeTabNav';
import { ChevronLeft, ChevronRight, Search, X, RefreshCw } from 'lucide-react';

// Effective status: expiring_soon/expired are fully computed from
// expiry_date (see EmployeePpe::getEffectiveStatusAttribute()) and only
// ever overlay while the item is issued/in_use. Every other value is a
// manual lifecycle state in the v1.5.1 business workflow:
//   issued -> in_use -> replacement_requested -> replacement_approved
//   -> replacement_completed -> archived
// Replacement is always a manual process -- expiry never auto-replaces.
const STATUS_VARIANT = {
    issued: 'outline',
    in_use: 'success',
    expiring_soon: 'outline',
    expired: 'destructive',
    replacement_requested: 'secondary',
    replacement_approved: 'secondary',
    replacement_completed: 'secondary',
    archived: 'secondary',
};
const STATUS_LABEL = {
    issued: 'Issued',
    in_use: 'In Use',
    expiring_soon: 'Expiring Soon',
    expired: 'Expired',
    replacement_requested: 'Replacement Requested',
    replacement_approved: 'Replacement Approved',
    replacement_completed: 'Replacement Completed',
    archived: 'Archived',
};

export default function PpeIndex({ assignments, ppeTypes, companies, departments, filters, can }) {
    const [completingReplacement, setCompletingReplacement] = useState(null);

    function applyFilters(overrides = {}) {
        router.get(route('ppe.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    // "Mark As" only ever moves through the manual lifecycle -- never
    // used for expiry-based classification, which is fully automatic.
    // "Replacement Completed" is handled separately (see
    // CompleteReplacementDialog) since it has the special side-effect of
    // archiving this record and creating a brand-new issuance.
    function updateLifecycle(id, status) {
        router.put(route('ppe.update', id), { status });
    }

    return (
        <AuthenticatedLayout>
            <Head title="PPE Distribution & History" />

            <PpeTabNav />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-graphite-900">PPE Reports</h1>
                    <p className="mt-1 text-sm text-graphite-500">History, analytics, and export -- issuing PPE happens from an employee's PPE profile.</p>
                </div>
            </div>

            <Card>
                <CardContent className="flex flex-wrap gap-2 p-4">
                    <Select value={filters.company_id ? String(filters.company_id) : 'all'} onValueChange={(v) => applyFilters({ company_id: v === 'all' ? null : v, department_id: null })}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <GroupedDepartmentSelect
                        className="w-48"
                        departments={departments}
                        companies={companies}
                        value={filters.department_id}
                        onChange={(v) => applyFilters({ department_id: v })}
                    />
                    <Select value={filters.ppe_type_id ? String(filters.ppe_type_id) : 'all'} onValueChange={(v) => applyFilters({ ppe_type_id: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="PPE Type" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All PPE Types</SelectItem>
                            {ppeTypes.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select value={filters.effective_status || 'all'} onValueChange={(v) => applyFilters({ effective_status: v === 'all' ? null : v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="active">In Service (Issued/In Use)</SelectItem>
                            <SelectItem value="expiring_soon">Expiring Soon</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                            <SelectItem value="replacement_requested">Replacement Requested</SelectItem>
                            <SelectItem value="replacement_approved">Replacement Approved</SelectItem>
                            <SelectItem value="replacement_completed">Replacement Completed</SelectItem>
                            <SelectItem value="archived">Archived</SelectItem>
                        </SelectContent>
                    </Select>
                </CardContent>
            </Card>

            <Card className="mt-4">
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Employee</TableHead>
                                <TableHead>Company</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>PPE Type</TableHead>
                                <TableHead>Issued Date</TableHead>
                                <TableHead>Expiry Date</TableHead>
                                <TableHead>Days Remaining / Overdue</TableHead>
                                <TableHead>Status</TableHead>
                                {can.manage && <TableHead>Mark As</TableHead>}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {assignments.data.length === 0 ? (
                                <TableRow><TableCell colSpan={9} className="py-10 text-center text-graphite-400">No PPE records found.</TableCell></TableRow>
                            ) : assignments.data.map((a) => (
                                <TableRow key={a.id}>
                                    <TableCell className="font-medium">{a.employee.full_name}</TableCell>
                                    <TableCell>{a.employee.company?.name ?? '—'}</TableCell>
                                    <TableCell>{a.employee.department?.name ?? '—'}</TableCell>
                                    <TableCell>{a.ppe_type.name}</TableCell>
                                    <TableCell>{new Date(a.issued_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</TableCell>
                                    <TableCell>
                                        {a.expiry_date
                                            ? new Date(a.expiry_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })
                                            : <span className="text-graphite-400">No expiry</span>}
                                    </TableCell>
                                    <TableCell>
                                        {a.days_remaining === null ? (
                                            <span className="text-graphite-400">—</span>
                                        ) : a.days_remaining >= 0 ? (
                                            <span>{a.days_remaining}d left</span>
                                        ) : (
                                            <span className="text-red-600">{Math.abs(a.days_remaining)}d overdue</span>
                                        )}
                                    </TableCell>
                                    <TableCell><Badge variant={STATUS_VARIANT[a.effective_status]}>{STATUS_LABEL[a.effective_status]}</Badge></TableCell>
                                    {can.manage && (
                                        <TableCell>
                                            {a.status === 'replacement_approved' ? (
                                                <Button size="sm" variant="outline" onClick={() => setCompletingReplacement(a)}>
                                                    <RefreshCw className="h-3.5 w-3.5" /> Complete Replacement
                                                </Button>
                                            ) : (
                                                <Select value={a.status} onValueChange={(v) => updateLifecycle(a.id, v)}>
                                                    <SelectTrigger className="w-40 h-8 text-xs"><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="issued">Issued</SelectItem>
                                                        <SelectItem value="in_use">In Use</SelectItem>
                                                        <SelectItem value="replacement_requested">Replacement Requested</SelectItem>
                                                        <SelectItem value="replacement_approved">Replacement Approved</SelectItem>
                                                        <SelectItem value="archived">Archived</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            )}
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {assignments.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-graphite-500">
                    <span>Page {assignments.current_page} of {assignments.last_page}</span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!assignments.prev_page_url} onClick={() => router.get(assignments.prev_page_url, {}, { preserveState: true })}>
                            <ChevronLeft className="h-4 w-4" /> Prev
                        </Button>
                        <Button variant="outline" size="sm" disabled={!assignments.next_page_url} onClick={() => router.get(assignments.next_page_url, {}, { preserveState: true })}>
                            Next <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}

            {can.manage && completingReplacement && (
                <CompleteReplacementDialog
                    assignment={completingReplacement}
                    onOpenChange={(open) => { if (!open) setCompletingReplacement(null); }}
                />
            )}
        </AuthenticatedLayout>
    );
}

/**
 * "Replacement Completed" is never a plain status flip (see
 * PpeController::completeReplacement()) -- it archives the old record and
 * creates a brand-new issuance. This dialog just asks for the new item's
 * issue date; expiry is auto-computed the same way as any other issuance.
 */
function CompleteReplacementDialog({ assignment, onOpenChange }) {
    const { data, setData, post, processing } = useForm({
        issued_date: new Date().toISOString().slice(0, 10),
    });

    function submit(e) {
        e.preventDefault();
        post(route('ppe.complete-replacement', assignment.id), {
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Complete Replacement</DialogTitle>
                </DialogHeader>
                <p className="text-sm text-graphite-500">
                    This will archive the current <strong>{assignment.ppe_type.name}</strong> for{' '}
                    <strong>{assignment.employee.full_name}</strong> and issue a brand-new item with its own
                    issue date and expiry.
                </p>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>New Issue Date</Label>
                        <Input type="date" value={data.issued_date} onChange={(e) => setData('issued_date', e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Complete Replacement</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
