import { useState, useEffect } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/Components/ui/dialog';
import EmptyState from '@/Components/shared/EmptyState';
import SectionHeader from '@/Components/shared/SectionHeader';
import { ArrowLeft, Plus, Pencil, Trash2, RefreshCw, HardHat, AlertTriangle, History } from 'lucide-react';

const STATUS_VARIANT = {
    issued: 'outline', in_use: 'success', replacement_requested: 'secondary',
    replacement_approved: 'secondary', replacement_completed: 'secondary', archived: 'secondary',
};

function humanize(value) {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Employee PPE Profile (v1.6.7) -- "this is PPE Management," per the
 * stated UX philosophy (Dashboard = Monitoring, Employee PPE = Selector,
 * this page = Management). Organized into the four suggested sections:
 * Overview, Current PPE, History, Management Actions.
 *
 * "Renew PPE" reuses the existing ppe.complete-replacement endpoint --
 * structurally it's the exact same operation as Replace (archive the
 * current record, issue a brand-new one with a fresh issued_date and
 * auto-computed expiry), just offered proactively for still-current
 * items rather than only expired ones. No new backend needed; this is
 * the same dialog with a different label/trigger context.
 */
export default function PpeEmployeeProfile({ employee, assignments, ppeTypes, can, backUrl }) {
    const [issueOpen, setIssueOpen] = useState(false);
    const [editingAssignment, setEditingAssignment] = useState(null);
    const [renewingAssignment, setRenewingAssignment] = useState(null);

    const current = assignments.filter((a) => ['active', 'expiring_soon'].includes(a.effective_status));
    const expired = assignments.filter((a) => a.effective_status === 'expired');
    const history = assignments.filter((a) => ['archived', 'replacement_requested', 'replacement_approved'].includes(a.status) || a.effective_status === 'expired');

    function destroy(assignment) {
        if (confirm(`Remove ${assignment.ppe_type.name} from this employee's record? This cannot be undone.`)) {
            router.delete(route('ppe.destroy', assignment.id));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={`${employee.full_name} - PPE`} />

            <Link href={backUrl || route('ppe.employees')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Employee PPE
            </Link>

            {/* Overview */}
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-graphite-900 dark:text-slate-50">{employee.full_name}</h1>
                    <p className="mt-1 text-sm text-graphite-500 dark:text-slate-400">
                        {employee.employee_id} &middot; {employee.department?.name || '—'} &middot; {employee.company?.name || '—'}
                    </p>
                </div>
            </div>

            <div className="mb-4 grid grid-cols-3 gap-3">
                <Card><CardContent className="p-3.5 text-center"><p className="text-xl font-bold text-emerald-600">{current.length}</p><p className="mt-0.5 text-[11px] text-graphite-400">Current</p></CardContent></Card>
                <Card><CardContent className="p-3.5 text-center"><p className="text-xl font-bold text-red-600">{expired.length}</p><p className="mt-0.5 text-[11px] text-graphite-400">Expired</p></CardContent></Card>
                <Card><CardContent className="p-3.5 text-center"><p className="text-xl font-bold text-graphite-600 dark:text-slate-300">{assignments.length}</p><p className="mt-0.5 text-[11px] text-graphite-400">Total Ever Issued</p></CardContent></Card>
            </div>

            {/* Management Actions */}
            {can?.manage && (
                <Card className="mb-4">
                    <CardContent className="flex flex-wrap gap-2 p-3">
                        <Button onClick={() => setIssueOpen(true)}><Plus className="h-4 w-4" /> Issue PPE</Button>
                        <p className="flex items-center text-xs text-graphite-400 dark:text-slate-500">
                            Renew, Replace, Edit, and Remove are available per-item below.
                        </p>
                    </CardContent>
                </Card>
            )}

            {/* Current PPE */}
            <SectionHeader title="Current PPE" description="Items this employee currently holds" />
            <Card className="mb-4">
                <CardContent className="p-0">
                    {current.length === 0 ? (
                        <EmptyState icon={HardHat} title="No current PPE" description="Issue this employee's first PPE item using the button above." />
                    ) : (
                        <div className="divide-y divide-graphite-100 dark:divide-slate-800">
                            {current.map((a) => (
                                <AssignmentRow
                                    key={a.id} assignment={a} can={can}
                                    onEdit={() => setEditingAssignment(a)}
                                    onRenew={() => setRenewingAssignment(a)}
                                    onRemove={() => destroy(a)}
                                />
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* History */}
            <SectionHeader title="History" description="Expired, replaced, and archived items" action={<History className="h-4 w-4 text-graphite-300" />} />
            <Card>
                <CardContent className="p-0">
                    {history.length === 0 ? (
                        <EmptyState icon={History} title="No history yet" description="Expired and replaced items will appear here." />
                    ) : (
                        <div className="divide-y divide-graphite-100 dark:divide-slate-800">
                            {history.map((a) => (
                                <AssignmentRow
                                    key={a.id} assignment={a} can={can} readOnly={a.status === 'archived'}
                                    onEdit={() => setEditingAssignment(a)}
                                    onRenew={() => setRenewingAssignment(a)}
                                    onRemove={() => destroy(a)}
                                />
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <IssuePpeDialog open={issueOpen} onOpenChange={setIssueOpen} employee={employee} ppeTypes={ppeTypes} />
            <EditAssignmentDialog assignment={editingAssignment} ppeTypes={ppeTypes} onOpenChange={() => setEditingAssignment(null)} />
            <RenewAssignmentDialog assignment={renewingAssignment} onOpenChange={() => setRenewingAssignment(null)} />
        </AuthenticatedLayout>
    );
}

function AssignmentRow({ assignment: a, can, readOnly, onEdit, onRenew, onRemove }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-2">
            <div className="min-w-0">
                <p className="flex items-center gap-2 text-[13px] font-medium text-graphite-800 dark:text-slate-100">
                    {a.ppe_type.name}
                    {a.effective_status === 'expired' && <AlertTriangle className="h-3.5 w-3.5 text-red-500" />}
                </p>
                <p className="text-xs text-graphite-400 dark:text-slate-500">
                    Issued {new Date(a.issued_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}
                    {a.expiry_date && ` · Expires ${new Date(a.expiry_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}`}
                    {a.issued_by && ` · by ${a.issued_by.name}`}
                </p>
                {a.remarks && <p className="mt-0.5 text-xs italic text-graphite-400 dark:text-slate-500">{a.remarks}</p>}
            </div>
            <div className="flex items-center gap-2">
                <Badge variant={a.effective_status === 'expired' ? 'destructive' : (STATUS_VARIANT[a.status] || 'outline')}>
                    {humanize(a.effective_status)}
                </Badge>
                {can?.manage && !readOnly && (
                    <>
                        <Button variant="ghost" size="icon" onClick={onRenew} title="Renew PPE (reissue with a fresh expiry)">
                            <RefreshCw className="h-4 w-4 text-emerald-600" />
                        </Button>
                        <Button variant="ghost" size="icon" onClick={onEdit} title="Edit">
                            <Pencil className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="icon" onClick={onRemove} title="Remove">
                            <Trash2 className="h-4 w-4 text-red-500" />
                        </Button>
                    </>
                )}
            </div>
        </div>
    );
}

/**
 * Simplified Issue PPE dialog -- the employee is already known (passed as
 * a prop from the profile page), so this only ever asks for PPE Item,
 * Issue Date, Expiry Date (optional -- left blank, the backend
 * auto-computes it from the PPE type's replacement interval), and
 * Remarks. No employee search step at all.
 */
function blankPpeItem() {
    return { ppe_type_id: undefined, issued_date: new Date().toISOString().slice(0, 10), expiry_date: '', remarks: '' };
}

/**
 * v1.11.3.2 (production UX fix): the backend (`PpeController::store()`,
 * `StoreEmployeePpeBatchRequest`) has accepted an array of `items` and
 * created one `employee_ppe` row per item in a single transaction since
 * v1.3.1 -- but this dialog only ever operated on `data.items[0]`, with
 * no way to add a second row, so every issuance was still effectively
 * one-item-at-a-time despite the batch-capable backend underneath it.
 * No schema/backend change needed -- `items` was already the right
 * shape; this just lets the UI actually add/remove rows before one
 * submit, matching `ChecklistTemplatesSection`'s own established
 * dynamic-array-row pattern (Hse/Master.jsx) for consistency.
 */
function IssuePpeDialog({ open, onOpenChange, employee, ppeTypes }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        employee_id: employee.id,
        items: [blankPpeItem()],
    });

    function updateItem(index, field, value) {
        const items = [...data.items];
        items[index] = { ...items[index], [field]: value };
        setData('items', items);
    }

    function addItem() {
        setData('items', [...data.items, blankPpeItem()]);
    }

    function removeItem(index) {
        setData('items', data.items.filter((_, i) => i !== index));
    }

    function submit(e) {
        e.preventDefault();
        post(route('ppe.store'), {
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    }

    const canSubmit = data.items.length > 0 && data.items.every((i) => i.ppe_type_id && i.issued_date);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader><DialogTitle>Issue PPE to {employee.full_name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
                        {data.items.map((item, index) => (
                            <div key={index} className="space-y-3 rounded-lg border border-graphite-200 p-3 dark:border-slate-800">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-graphite-400">Item {index + 1}</p>
                                    {data.items.length > 1 && (
                                        <Button type="button" variant="ghost" size="icon" onClick={() => removeItem(index)}>
                                            <Trash2 className="h-4 w-4 text-red-500" />
                                        </Button>
                                    )}
                                </div>

                                <div className="space-y-1.5">
                                    <Label>PPE Item</Label>
                                    <Select value={item.ppe_type_id} onValueChange={(v) => updateItem(index, 'ppe_type_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select PPE type" /></SelectTrigger>
                                        <SelectContent>
                                            {ppeTypes.map((t) => (
                                                <SelectItem key={t.id} value={String(t.id)}>
                                                    {t.name} {t.replacement_interval_months ? `(${t.replacement_interval_months}mo)` : '(request-based)'}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors[`items.${index}.ppe_type_id`] && <p className="text-xs text-red-600">{errors[`items.${index}.ppe_type_id`]}</p>}
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label>Issue Date</Label>
                                        <Input type="date" value={item.issued_date} onChange={(e) => updateItem(index, 'issued_date', e.target.value)} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Expiry Date (optional)</Label>
                                        <Input type="date" value={item.expiry_date} onChange={(e) => updateItem(index, 'expiry_date', e.target.value)} placeholder="Auto-computed if blank" />
                                        {errors[`items.${index}.expiry_date`] && <p className="text-xs text-red-600">{errors[`items.${index}.expiry_date`]}</p>}
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    <Label>Remarks (optional)</Label>
                                    <Input value={item.remarks} onChange={(e) => updateItem(index, 'remarks', e.target.value)} />
                                </div>
                            </div>
                        ))}
                    </div>

                    <Button type="button" variant="outline" size="sm" onClick={addItem} className="w-full">
                        <Plus className="h-3.5 w-3.5" /> Add PPE Item
                    </Button>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing || !canSubmit}>Save All PPE ({data.items.length})</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * v1.11.6 (Production Readiness pass, Part 1): previously Status +
 * Remarks only, matching the (also just-fixed) backend validation --
 * that meant a wrong PPE type or a typo'd issue date could never be
 * corrected once issued, only worked around by Delete + re-Issue. Now
 * also edits PPE Type/Issue Date/Expiry Date. Employee is intentionally
 * not editable here -- reassigning an item to a different employee isn't
 * a correction, see UpdateEmployeePpeRequest's own doc comment.
 */
function EditAssignmentDialog({ assignment, ppeTypes, onOpenChange }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        status: '', remarks: '', ppe_type_id: '', issued_date: '', expiry_date: '',
    });

    useEffect(() => {
        if (assignment) {
            setData({
                status: assignment.status,
                remarks: assignment.remarks || '',
                ppe_type_id: String(assignment.ppe_type_id ?? assignment.ppe_type.id),
                issued_date: assignment.issued_date ? assignment.issued_date.slice(0, 10) : '',
                expiry_date: assignment.expiry_date ? assignment.expiry_date.slice(0, 10) : '',
            });
        }
    }, [assignment?.id]);

    function submit(e) {
        e.preventDefault();
        put(route('ppe.update', assignment.id), {
            onSuccess: () => {
                reset();
                onOpenChange();
            },
        });
    }

    if (!assignment) return null;

    return (
        <Dialog open={!!assignment} onOpenChange={(v) => { if (!v) { reset(); onOpenChange(); } }}>
            <DialogContent>
                <DialogHeader><DialogTitle>Edit {assignment.ppe_type.name}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>PPE Type</Label>
                        <Select value={data.ppe_type_id} onValueChange={(v) => setData('ppe_type_id', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                {ppeTypes.map((t) => (
                                    <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.ppe_type_id && <p className="text-xs text-red-600">{errors.ppe_type_id}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label>Issue Date</Label>
                            <Input type="date" value={data.issued_date} onChange={(e) => setData('issued_date', e.target.value)} />
                            {errors.issued_date && <p className="text-xs text-red-600">{errors.issued_date}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Expiry Date</Label>
                            <Input type="date" value={data.expiry_date} onChange={(e) => setData('expiry_date', e.target.value)} />
                            {errors.expiry_date && <p className="text-xs text-red-600">{errors.expiry_date}</p>}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Status</Label>
                        <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                {['issued', 'in_use', 'replacement_requested', 'replacement_approved', 'replacement_completed', 'archived'].map((s) => (
                                    <SelectItem key={s} value={s}>{humanize(s)}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5">
                        <Label>Remarks</Label>
                        <Input value={data.remarks} onChange={(e) => setData('remarks', e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange()}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Renew PPE (v1.6.7) -- reuses the existing ppe.complete-replacement
 * endpoint (archive current record, issue a fresh one with a new
 * issued_date and auto-computed expiry). Structurally identical to
 * "Replace"; offered here for still-current items as a proactive
 * "extend this before it expires" action rather than a reactive one.
 */
function RenewAssignmentDialog({ assignment, onOpenChange }) {
    const { data, setData, post, processing, reset } = useForm({ issued_date: new Date().toISOString().slice(0, 10) });

    function submit(e) {
        e.preventDefault();
        post(route('ppe.complete-replacement', assignment.id), {
            onSuccess: () => {
                reset();
                onOpenChange();
            },
        });
    }

    if (!assignment) return null;

    return (
        <Dialog open={!!assignment} onOpenChange={(v) => { if (!v) onOpenChange(); }}>
            <DialogContent>
                <DialogHeader><DialogTitle>Renew {assignment.ppe_type.name}</DialogTitle></DialogHeader>
                <p className="text-sm text-graphite-500 dark:text-slate-400">
                    This archives the current record and issues a brand-new one with today's date, giving it a fresh expiry.
                </p>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label>New Issue Date</Label>
                        <Input type="date" value={data.issued_date} onChange={(e) => setData('issued_date', e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange()}>Cancel</Button>
                        <Button type="submit" disabled={processing}>Renew</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
