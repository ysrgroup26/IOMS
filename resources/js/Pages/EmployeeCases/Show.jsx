import { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import StatusBadge from '@/Components/shared/StatusBadge';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import EmptyState from '@/Components/shared/EmptyState';
import { FormField } from '@/Components/shared/form';
import { disciplinaryActionLabel } from '@/lib/utils';
import { ArrowLeft, ShieldAlert, Gavel, CheckCircle2, XCircle, RotateCcw, Pencil, FileSignature } from 'lucide-react';

function humanize(value) {
    return String(value).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * v2.69.0 -- the Employee Case record.
 *
 * The page is ordered by the question a reader arrives with: WHO is this
 * about and where do they stand, then WHAT was raised, then WHAT WAS
 * ISSUED, then the audit trail. Standing comes first because it is the
 * thing that changes what you do next -- an employee already on SP2 is a
 * different decision from one with a clean record, and burying that below
 * the narrative would make it easy to miss.
 */
export default function EmployeeCaseShow({ employeeCase, activities, standing, priorCaseCount, options, canOverride }) {
    const [actionOpen, setActionOpen] = useState(false);
    const [closeOpen, setCloseOpen] = useState(false);
    const [dismissOpen, setDismissOpen] = useState(false);

    const c = employeeCase;
    const isActive = c.is_active;

    const actionForm = useForm({
        type: 'verbal_warning',
        issued_at: new Date().toISOString().slice(0, 10),
        effective_until: '',
        reference_number: '',
        notes: '',
    });

    const closeForm = useForm({ outcome: 'substantiated', closure_note: '' });
    const dismissForm = useForm({ closure_note: '' });

    function submitAction(e) {
        e.preventDefault();
        actionForm.post(route('employee-cases.actions.store', c.id), {
            preserveScroll: true,
            onSuccess: () => { setActionOpen(false); actionForm.reset(); },
        });
    }

    function submitClose(e) {
        e.preventDefault();
        closeForm.post(route('employee-cases.close', c.id), {
            preserveScroll: true,
            onSuccess: () => setCloseOpen(false),
        });
    }

    function submitDismiss(e) {
        e.preventDefault();
        dismissForm.post(route('employee-cases.dismiss', c.id), {
            preserveScroll: true,
            onSuccess: () => setDismissOpen(false),
        });
    }

    return (
        <AuthenticatedLayout>
            <Head title={c.case_number} />
            <PageHeader title={c.case_number} subtitle={c.title}>
                <StatusBadge value={c.status} />
                <Button variant="outline" asChild>
                    <Link href={route('employee-cases.index')}><ArrowLeft className="h-4 w-4" /> Back</Link>
                </Button>
                {isActive && (
                    <Button variant="outline" asChild>
                        <Link href={route('employee-cases.edit', c.id)}><Pencil className="h-4 w-4" /> Edit</Link>
                    </Button>
                )}
            </PageHeader>

            {/* ---------- WHO, AND WHERE THEY STAND ---------- */}
            <Card className="mb-4">
                <CardHeader className="pb-2">
                    <CardTitle>Employee</CardTitle>
                    <CardDescription>Posisi disiplin saat ini dihitung dari tindakan yang masih berlaku, bukan dari status tersimpan.</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-3">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-graphite-400">Name</p>
                        <p className="font-semibold text-graphite-900 dark:text-slate-50">{c.employee?.full_name}</p>
                        <p className="text-xs text-graphite-500">
                            {c.employee?.employee_id}
                            {c.employee?.department?.name ? ` · ${c.employee.department.name}` : ''}
                        </p>
                    </div>

                    <div>
                        <p className="text-xs uppercase tracking-wide text-graphite-400">Current standing</p>
                        {standing ? (
                            <>
                                <p className="font-semibold text-danger">{disciplinaryActionLabel(standing.type)}</p>
                                <p className="text-xs text-graphite-500">
                                    Berlaku sampai {standing.effective_until ? formatDate(standing.effective_until) : 'tidak berakhir'}
                                </p>
                            </>
                        ) : (
                            <>
                                <p className="font-semibold text-success">Clear</p>
                                <p className="text-xs text-graphite-500">Tidak ada tindakan yang masih berlaku.</p>
                            </>
                        )}
                    </div>

                    <div>
                        <p className="text-xs uppercase tracking-wide text-graphite-400">Prior cases</p>
                        <p className="font-semibold text-graphite-900 dark:text-slate-50 tabular-nums">{priorCaseCount}</p>
                        <p className="text-xs text-graphite-500">Kasus lain atas nama karyawan ini.</p>
                    </div>
                </CardContent>
            </Card>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {/* ---------- THE CONCERN ---------- */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle>Case detail</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <p className="text-xs uppercase tracking-wide text-graphite-400">Category</p>
                                    <p className="capitalize">{c.category}</p>
                                </div>
                                <div>
                                    <p className="text-xs uppercase tracking-wide text-graphite-400">Severity</p>
                                    <StatusBadge value={c.severity} />
                                </div>
                                <div>
                                    <p className="text-xs uppercase tracking-wide text-graphite-400">Raised</p>
                                    <p>{formatDate(c.reported_at)}</p>
                                </div>
                            </div>

                            {c.details && (
                                <div>
                                    <p className="text-xs uppercase tracking-wide text-graphite-400">What happened</p>
                                    <p className="whitespace-pre-wrap text-graphite-700 dark:text-slate-200">{c.details}</p>
                                </div>
                            )}

                            <div className="grid gap-3 sm:grid-cols-2 border-t border-graphite-100 pt-3 dark:border-slate-800">
                                <div>
                                    <p className="text-xs uppercase tracking-wide text-graphite-400">Raised by</p>
                                    <p>{c.reporter?.name || '—'}</p>
                                </div>
                                <div>
                                    <p className="text-xs uppercase tracking-wide text-graphite-400">Handler</p>
                                    <p>{c.assignee?.name || <span className="text-graphite-400">Unassigned</span>}</p>
                                </div>
                            </div>

                            {(c.status === 'closed' || c.status === 'dismissed') && (
                                <div className="rounded-lg border border-graphite-200 bg-graphite-50 p-3 dark:border-slate-800 dark:bg-slate-800/50">
                                    <p className="text-xs uppercase tracking-wide text-graphite-400">
                                        {c.status === 'dismissed' ? 'Dismissed' : 'Closed'} · {formatDate(c.closed_at)} · {c.closer?.name || '—'}
                                    </p>
                                    {c.outcome && <p className="mt-1 font-medium capitalize">{humanize(c.outcome)}</p>}
                                    {c.closure_note && <p className="mt-1 whitespace-pre-wrap text-graphite-600 dark:text-slate-300">{c.closure_note}</p>}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* ---------- WHAT WAS ISSUED ---------- */}
                    <Card>
                        <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-2">
                            <div>
                                <CardTitle>Disciplinary actions</CardTitle>
                                <CardDescription>Setiap tindakan mencatat masa berlakunya sendiri.</CardDescription>
                            </div>
                            {(c.status === 'under_review' || c.status === 'action_issued') && (
                                <Button size="sm" onClick={() => setActionOpen(true)}>
                                    <Gavel className="h-4 w-4" /> Issue action
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="p-0">
                            {(!c.actions || c.actions.length === 0) ? (
                                <EmptyState
                                    icon={FileSignature}
                                    title="Belum ada tindakan diterbitkan"
                                    description="Kasus dapat ditutup tanpa tindakan bila memang tidak diperlukan."
                                />
                            ) : (
                                <ul className="divide-y divide-graphite-100 dark:divide-slate-800">
                                    {c.actions.map((a) => (
                                        <li key={a.id} className="flex flex-wrap items-start justify-between gap-3 p-4">
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-semibold text-graphite-900 dark:text-slate-50">{disciplinaryActionLabel(a.type)}</span>
                                                    {a.is_in_force
                                                        ? <StatusBadge value="active" label="In force" />
                                                        : <StatusBadge value="expired" label="Lapsed" />}
                                                    {a.reference_number && <span className="text-xs text-graphite-400">{a.reference_number}</span>}
                                                </div>
                                                <p className="mt-1 text-xs text-graphite-500">
                                                    Issued {formatDate(a.issued_at)}
                                                    {' · '}
                                                    {a.effective_until ? `valid until ${formatDate(a.effective_until)}` : 'does not lapse'}
                                                    {a.issuer?.name ? ` · ${a.issuer.name}` : ''}
                                                </p>
                                                {a.notes && <p className="mt-1 whitespace-pre-wrap text-sm text-graphite-600 dark:text-slate-300">{a.notes}</p>}
                                            </div>

                                            <div className="shrink-0 text-right">
                                                {a.acknowledged_at ? (
                                                    <p className="text-xs text-success">Acknowledged {formatDate(a.acknowledged_at)}</p>
                                                ) : (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => router.post(route('employee-cases.actions.acknowledge', [c.id, a.id]), {}, { preserveScroll: true })}
                                                    >
                                                        Record acknowledgement
                                                    </Button>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ---------- WHAT HAPPENS NEXT + AUDIT ---------- */}
                <div className="space-y-4">
                    <Card>
                        <CardHeader className="pb-2"><CardTitle>Actions</CardTitle></CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            {c.status === 'open' && (
                                <Button onClick={() => router.post(route('employee-cases.start-review', c.id), {}, { preserveScroll: true })}>
                                    <ShieldAlert className="h-4 w-4" /> Start review
                                </Button>
                            )}

                            {(c.status === 'under_review' || c.status === 'action_issued') && (
                                <Button variant="outline" onClick={() => setCloseOpen(true)}>
                                    <CheckCircle2 className="h-4 w-4" /> Close case
                                </Button>
                            )}

                            {(c.status === 'open' || c.status === 'under_review') && (
                                <Button variant="outline" onClick={() => setDismissOpen(true)}>
                                    <XCircle className="h-4 w-4" /> Dismiss
                                </Button>
                            )}

                            {!isActive && canOverride && (
                                <Button
                                    variant="outline"
                                    onClick={() => router.post(route('employee-cases.reopen', c.id), {}, { preserveScroll: true })}
                                >
                                    <RotateCcw className="h-4 w-4" /> Reopen
                                </Button>
                            )}

                            {!isActive && !canOverride && (
                                <p className="text-xs text-graphite-500">
                                    Kasus sudah selesai. Membuka kembali memerlukan wewenang override.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2"><CardTitle>Activity</CardTitle></CardHeader>
                        <CardContent><ActivityTimeline activities={activities} /></CardContent>
                    </Card>
                </div>
            </div>

            {/* ---------- ISSUE ACTION ---------- */}
            <Dialog open={actionOpen} onOpenChange={setActionOpen}>
                <DialogContent>
                    <form onSubmit={submitAction}>
                        <DialogHeader>
                            <DialogTitle>Issue disciplinary action</DialogTitle>
                            <DialogDescription>
                                Tindakan yang diterbitkan menjadi bagian permanen dari riwayat karyawan.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-3 py-3">
                            <FormField label="Action" name="type" required error={actionForm.errors.type}>
                                <Select value={actionForm.data.type} onValueChange={(v) => actionForm.setData('type', v)}>
                                    <SelectTrigger id="field-type"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {options.actionTypes.map((t) => (
                                            <SelectItem key={t} value={t}>{disciplinaryActionLabel(t)}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <FormField label="Issue date" name="issued_at" required error={actionForm.errors.issued_at}>
                                    <Input id="field-issued_at" type="date" value={actionForm.data.issued_at} onChange={(e) => actionForm.setData('issued_at', e.target.value)} />
                                </FormField>

                                <FormField
                                    label="Valid until"
                                    name="effective_until"
                                    error={actionForm.errors.effective_until}
                                    hint="Kosongkan bila tindakan tidak berakhir."
                                >
                                    <Input id="field-effective_until" type="date" value={actionForm.data.effective_until} onChange={(e) => actionForm.setData('effective_until', e.target.value)} />
                                </FormField>
                            </div>

                            <FormField label="Letter reference" name="reference_number" error={actionForm.errors.reference_number}>
                                <Input id="field-reference_number" value={actionForm.data.reference_number} onChange={(e) => actionForm.setData('reference_number', e.target.value)} />
                            </FormField>

                            <FormField label="Notes" name="notes" error={actionForm.errors.notes}>
                                <Textarea id="field-notes" rows={3} value={actionForm.data.notes} onChange={(e) => actionForm.setData('notes', e.target.value)} />
                            </FormField>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setActionOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={actionForm.processing}>Issue action</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ---------- CLOSE ---------- */}
            <Dialog open={closeOpen} onOpenChange={setCloseOpen}>
                <DialogContent>
                    <form onSubmit={submitClose}>
                        <DialogHeader>
                            <DialogTitle>Close case</DialogTitle>
                            <DialogDescription>Catat hasil akhir peninjauan.</DialogDescription>
                        </DialogHeader>

                        <div className="space-y-3 py-3">
                            <FormField label="Outcome" name="outcome" required error={closeForm.errors.outcome}>
                                <Select value={closeForm.data.outcome} onValueChange={(v) => closeForm.setData('outcome', v)}>
                                    <SelectTrigger id="field-outcome"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {options.outcomes.map((o) => <SelectItem key={o} value={o}>{humanize(o)}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </FormField>

                            <FormField label="Closing note" name="closure_note" error={closeForm.errors.closure_note}>
                                <Textarea id="field-closure_note" rows={3} value={closeForm.data.closure_note} onChange={(e) => closeForm.setData('closure_note', e.target.value)} />
                            </FormField>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setCloseOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={closeForm.processing}>Close case</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ---------- DISMISS ---------- */}
            <Dialog open={dismissOpen} onOpenChange={setDismissOpen}>
                <DialogContent>
                    <form onSubmit={submitDismiss}>
                        <DialogHeader>
                            <DialogTitle>Dismiss case</DialogTitle>
                            <DialogDescription>
                                Gunakan bila setelah ditinjau tidak ada persoalan yang perlu ditindaklanjuti.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="py-3">
                            <FormField label="Reason" name="closure_note" required error={dismissForm.errors.closure_note}>
                                <Textarea id="field-dismiss_note" rows={3} value={dismissForm.data.closure_note} onChange={(e) => dismissForm.setData('closure_note', e.target.value)} />
                            </FormField>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setDismissOpen(false)}>Cancel</Button>
                            <Button type="submit" variant="outline" disabled={dismissForm.processing}>Dismiss case</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
