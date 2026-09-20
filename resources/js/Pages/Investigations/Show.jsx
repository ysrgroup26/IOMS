import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import ActivityTimeline from '@/Components/shared/ActivityTimeline';
import StatusBadge from '@/Components/shared/StatusBadge';
import PageHeader from '@/Components/shared/PageHeader';
import RecordChain from '@/Components/shared/RecordChain';
import { FieldGrid, Field, DetailSection } from '@/Components/shared/DetailFields';
import {
    ArrowLeft, Save, Send, CheckCircle2, XCircle, RotateCcw, Users, MessageSquare,
    Trash2, AlertTriangle, GitBranch, ListTree, FileSearch, Plus,
} from 'lucide-react';

/**
 * v2.73.0 -- THE INVESTIGATION WORKING SURFACE.
 *
 * Not a form that gets submitted once. An investigator returns to this
 * page over days: adding an interview, extending the chronology,
 * revising a cause as evidence comes in, raising an action, and
 * eventually asking for review. So the page is organised as the WORK,
 * not as the table:
 *
 *   01 Scope and team        who is doing this and how far it reaches
 *   02 Chronology            what the investigator reconstructed
 *   03 Interviews            statements, as records, added one at a time
 *   04 Causal analysis       immediate -> basic -> root, three layers
 *   05 Findings              what was concluded, and what to do about it
 *   06 Corrective actions    raised FROM the cause that was identified
 *
 * WHY THE CAUSAL LAYERS ARE THREE FIELDS AND NOT ONE. A single "root
 * cause" box gets "operator error" written in it. Asking separately for
 * the immediate cause, the conditions that allowed it and the system
 * failure underneath makes the shortcut visible: if the immediate and the
 * root cause say the same thing, the analysis has not happened yet.
 *
 * WHY THE METHODOLOGY IS OPTIONAL. SCAT, 5 Why and Fishbone are
 * instruments an investigator chooses, not requirements. No Indonesian
 * regulation names any of them; what is required is that the
 * investigation happens, is recorded, and leads to action. "No formal
 * methodology" is a legitimate and often correct answer, and the product
 * must not imply that ticking one satisfies a rule.
 */
export default function InvestigationShow({
    investigation: v, activities, methodLabels, relationships, priorities,
    users, employees, openActionCount, can,
}) {
    const displayTimeZone = usePage().props.display_timezone;
    const [interviewOpen, setInterviewOpen] = useState(false);
    const [actionOpen, setActionOpen] = useState(false);

    const readOnly = !can.manage || ['closed', 'cancelled'].includes(v.status);

    const form = useForm({
        investigator_id: v.investigator_id ? String(v.investigator_id) : '',
        team: (v.team || []).map(String),
        scope: v.scope || '',
        target_completion_date: v.target_completion_date?.slice(0, 10) || '',
        investigated_at: v.investigated_at?.slice(0, 10) || '',
        method: v.method || 'none',
        detailed_chronology: v.detailed_chronology || '',
        immediate_causes: v.immediate_causes || '',
        basic_causes: v.basic_causes || '',
        root_cause: v.root_cause || '',
        contributing_factors: v.contributing_factors || '',
        findings: v.findings || '',
        recommendations: v.recommendations || '',
        conclusion: v.conclusion || '',
    });

    const interviewForm = useForm({
        employee_id: '', person_name: '', person_role: '',
        relationship: 'witness', interviewed_on: new Date().toISOString().slice(0, 10),
        statement: '', investigator_notes: '',
    });

    const actionForm = useForm({ action: '', assigned_to: '', due_date: '', priority: 'medium' });

    function save(e) {
        e.preventDefault();
        form.put(route('investigations.update', v.id), { preserveScroll: true });
    }

    function transition(status, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) return;
        router.post(route('investigations.transition', v.id), { status }, { preserveScroll: true });
    }

    function submitInterview(e) {
        e.preventDefault();
        interviewForm.post(route('investigations.interviews.store', v.id), {
            preserveScroll: true,
            onSuccess: () => { interviewForm.reset(); setInterviewOpen(false); },
        });
    }

    function removeInterview(id, name) {
        if (!confirm(`Remove the interview record for ${name}?`)) return;
        router.delete(route('investigations.interviews.destroy', [v.id, id]), { preserveScroll: true });
    }

    function submitAction(e) {
        e.preventDefault();
        actionForm.post(route('investigations.actions.store', v.id), {
            preserveScroll: true,
            onSuccess: () => { actionForm.reset(); setActionOpen(false); },
        });
    }

    const incident = v.incident;
    const interviews = v.interviews || [];
    const actions = v.corrective_actions || [];

    return (
        <AuthenticatedLayout>
            <Head title={v.investigation_number} />

            <Link href={route('investigations.index')} className="mb-3 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to HSE Investigation
            </Link>

            <PageHeader
                title={<>{v.investigation_number} <StatusBadge value={v.status} /></>}
                subtitle={<>Analisis penyebab atas {incident?.incident_number}. Laporan awal tetap menjadi sumber fakta dan tidak diubah dari sini.</>}
            >
                {can.manage && <WorkflowActions status={v.status} openActionCount={openActionCount} onTransition={transition} />}
            </PageHeader>

            {/* The same chain the incident renders, from the same facts --
                seen from this end. */}
            <RecordChain
                className="mb-4"
                steps={[
                    {
                        key: 'inc',
                        label: 'Initial Report',
                        reference: incident?.incident_number,
                        status: incident?.status,
                        meta: incident?.title,
                        href: incident ? route('incidents.show', incident.id) : undefined,
                    },
                    {
                        key: 'inv',
                        label: 'HSE Investigation',
                        reference: v.investigation_number,
                        status: v.status,
                        meta: v.investigator?.name,
                        current: true,
                    },
                    {
                        key: 'capa',
                        label: 'Corrective Action',
                        reference: actions.length ? `${actions.length} action${actions.length === 1 ? '' : 's'}` : null,
                        meta: actions.length ? `${openActionCount} still open` : 'Belum ada tindakan diterbitkan',
                        muted: true,
                    },
                ]}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <form onSubmit={save}>
                        <Card>
                            <CardContent className="space-y-5 pt-5">
                                <DetailSection index="01" title="Scope and team" icon={Users}>
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div className="space-y-1.5">
                                            <Label>Lead Investigator</Label>
                                            <Select disabled={readOnly} value={form.data.investigator_id} onValueChange={(x) => form.setData('investigator_id', x)}>
                                                <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                                <SelectContent>{users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Target Completion</Label>
                                            <Input type="date" disabled={readOnly} value={form.data.target_completion_date} onChange={(e) => form.setData('target_completion_date', e.target.value)} />
                                        </div>
                                        <div className="space-y-1.5 sm:col-span-2">
                                            <Label>Scope</Label>
                                            <Textarea rows={2} disabled={readOnly} value={form.data.scope} onChange={(e) => form.setData('scope', e.target.value)} placeholder="Apa yang diperiksa, dan apa yang berada di luar cakupan." />
                                        </div>
                                    </div>
                                </DetailSection>

                                <DetailSection
                                    index="02"
                                    title="Chronology"
                                    icon={ListTree}
                                    description="Urutan kejadian yang disusun ulang oleh investigator. Berbeda dari kronologi pada laporan awal, dan keduanya disimpan."
                                >
                                    <Textarea rows={6} disabled={readOnly} value={form.data.detailed_chronology} onChange={(e) => form.setData('detailed_chronology', e.target.value)} />
                                </DetailSection>

                                <DetailSection
                                    index="04"
                                    title="Causal analysis"
                                    icon={GitBranch}
                                    description="Tiga lapis sebab, dipisahkan dengan sengaja. Bila sebab langsung dan akar penyebab berbunyi sama, analisis belum selesai."
                                >
                                    <div className="space-y-3">
                                        <div className="space-y-1.5">
                                            <Label>Methodology</Label>
                                            <Select disabled={readOnly} value={form.data.method} onValueChange={(x) => form.setData('method', x)}>
                                                <SelectTrigger className="sm:max-w-sm"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    {Object.entries(methodLabels).map(([k, label]) => <SelectItem key={k} value={k}>{label}</SelectItem>)}
                                                </SelectContent>
                                            </Select>
                                            <p className="text-[11px] leading-relaxed text-graphite-500 dark:text-slate-400">
                                                Kerangka analisis, bukan persyaratan hukum. Tidak ada regulasi Indonesia yang
                                                mewajibkan metode tertentu &mdash; yang diwajibkan adalah investigasinya dilakukan,
                                                dicatat, dan ditindaklanjuti.
                                            </p>
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label>Immediate Causes</Label>
                                            <Textarea rows={2} disabled={readOnly} value={form.data.immediate_causes} onChange={(e) => form.setData('immediate_causes', e.target.value)} placeholder="Tindakan atau kondisi yang langsung menimbulkan kejadian." />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Basic / Underlying Causes</Label>
                                            <Textarea rows={2} disabled={readOnly} value={form.data.basic_causes} onChange={(e) => form.setData('basic_causes', e.target.value)} placeholder="Faktor personal dan faktor pekerjaan yang memungkinkan sebab langsung terjadi." />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Root Cause</Label>
                                            <Textarea rows={2} disabled={readOnly} value={form.data.root_cause} onChange={(e) => form.setData('root_cause', e.target.value)} placeholder="Kegagalan sistem yang membiarkan kondisi itu ada." />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Contributing Factors</Label>
                                            <Textarea rows={2} disabled={readOnly} value={form.data.contributing_factors} onChange={(e) => form.setData('contributing_factors', e.target.value)} />
                                        </div>
                                    </div>
                                </DetailSection>

                                <DetailSection index="05" title="Findings and conclusion" icon={FileSearch}>
                                    <div className="space-y-3">
                                        <div className="space-y-1.5">
                                            <Label>Findings</Label>
                                            <Textarea rows={4} disabled={readOnly} value={form.data.findings} onChange={(e) => form.setData('findings', e.target.value)} />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Recommendations</Label>
                                            <Textarea rows={3} disabled={readOnly} value={form.data.recommendations} onChange={(e) => form.setData('recommendations', e.target.value)} />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Conclusion</Label>
                                            <Textarea rows={3} disabled={readOnly} value={form.data.conclusion} onChange={(e) => form.setData('conclusion', e.target.value)} />
                                        </div>
                                        <div className="space-y-1.5 sm:max-w-xs">
                                            <Label>Investigated On</Label>
                                            <Input type="date" disabled={readOnly} value={form.data.investigated_at} onChange={(e) => form.setData('investigated_at', e.target.value)} />
                                        </div>
                                    </div>
                                </DetailSection>

                                {!readOnly && (
                                    <div className="flex items-center gap-2 border-t border-graphite-100 pt-4 dark:border-slate-800">
                                        <Button type="submit" disabled={form.processing}>
                                            <Save className="h-4 w-4" /> Save Investigation
                                        </Button>
                                        <p className="text-xs text-graphite-500 dark:text-slate-400">
                                            Semua bagian boleh disimpan setengah jadi.
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </form>

                    {/* 03 -- interviews are their own records, added one at a
                        time, so they live outside the single save form. */}
                    <Card>
                        <CardHeader className="flex flex-row items-start justify-between gap-3">
                            <div className="min-w-0">
                                <CardTitle className="text-sm">03 &middot; Interviews</CardTitle>
                                <p className="mt-0.5 text-xs leading-relaxed text-graphite-500 dark:text-slate-400">
                                    Keterangan yang dikumpulkan selama investigasi. Apa yang dikatakan dan bagaimana
                                    investigator menilainya dicatat terpisah.
                                </p>
                            </div>
                            {!readOnly && (
                                <Button variant="outline" size="sm" onClick={() => setInterviewOpen((x) => !x)}>
                                    <Plus className="h-3.5 w-3.5" /> {interviewOpen ? 'Cancel' : 'Record'}
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {interviewOpen && (
                                <form onSubmit={submitInterview} className="space-y-3 rounded-lg border border-graphite-100 p-3 dark:border-slate-800">
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <div className="space-y-1.5">
                                            <Label>From Workforce</Label>
                                            <Select
                                                value={interviewForm.data.employee_id}
                                                onValueChange={(x) => {
                                                    const emp = employees.find((e) => String(e.id) === x);
                                                    interviewForm.setData((d) => ({ ...d, employee_id: x, person_name: emp?.full_name || d.person_name }));
                                                }}
                                            >
                                                <SelectTrigger><SelectValue placeholder="Optional" /></SelectTrigger>
                                                <SelectContent>{employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.full_name}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Name <span className="text-red-500">*</span></Label>
                                            <Input value={interviewForm.data.person_name} onChange={(e) => interviewForm.setData('person_name', e.target.value)} />
                                            {interviewForm.errors.person_name && <p className="text-xs text-red-600">{interviewForm.errors.person_name}</p>}
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Relationship</Label>
                                            <Select value={interviewForm.data.relationship} onValueChange={(x) => interviewForm.setData('relationship', x)}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent>{relationships.map((r) => <SelectItem key={r} value={r} className="capitalize">{r.replace(/_/g, ' ')}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label>Interviewed On</Label>
                                            <Input type="date" value={interviewForm.data.interviewed_on} onChange={(e) => interviewForm.setData('interviewed_on', e.target.value)} />
                                        </div>
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Statement</Label>
                                        <Textarea rows={3} value={interviewForm.data.statement} onChange={(e) => interviewForm.setData('statement', e.target.value)} placeholder="Apa yang disampaikan orang tersebut." />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Investigator Notes</Label>
                                        <Textarea rows={2} value={interviewForm.data.investigator_notes} onChange={(e) => interviewForm.setData('investigator_notes', e.target.value)} placeholder="Penilaian investigator atas keterangan itu." />
                                    </div>
                                    <Button type="submit" size="sm" disabled={interviewForm.processing}>Save Interview</Button>
                                </form>
                            )}

                            {interviews.length === 0 ? (
                                <p className="text-sm text-graphite-400 dark:text-slate-500">Belum ada wawancara yang dicatat.</p>
                            ) : (
                                interviews.map((t) => (
                                    <div key={t.id} className="rounded-lg border border-graphite-100 p-3 dark:border-slate-800">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="text-[13px] font-semibold text-graphite-900 dark:text-slate-100">
                                                    {t.person_name}
                                                    {t.relationship && (
                                                        <span className="ml-2 rounded-full bg-graphite-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-graphite-600 dark:bg-slate-800 dark:text-slate-300">
                                                            {t.relationship.replace(/_/g, ' ')}
                                                        </span>
                                                    )}
                                                </p>
                                                <p className="mt-0.5 text-[11px] text-graphite-500 dark:text-slate-400">
                                                    {formatDate(t.interviewed_on) || 'No date'} · {t.interviewer?.name || 'Unknown interviewer'}
                                                </p>
                                            </div>
                                            {!readOnly && (
                                                <Button variant="ghost" size="sm" onClick={() => removeInterview(t.id, t.person_name)} aria-label={`Remove interview with ${t.person_name}`}>
                                                    <Trash2 className="h-3.5 w-3.5 text-red-500" />
                                                </Button>
                                            )}
                                        </div>
                                        {t.statement && (
                                            <p className="mt-2 whitespace-pre-wrap border-l-2 border-steel-200 pl-2.5 text-[13px] leading-relaxed text-graphite-700 dark:border-slate-700 dark:text-slate-300">
                                                {t.statement}
                                            </p>
                                        )}
                                        {t.investigator_notes && (
                                            <p className="mt-2 flex items-start gap-1.5 text-[12px] leading-relaxed text-graphite-500 dark:text-slate-400">
                                                <MessageSquare className="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />
                                                {t.investigator_notes}
                                            </p>
                                        )}
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-start justify-between gap-3">
                            <div className="min-w-0">
                                <CardTitle className="text-sm">06 &middot; Corrective Actions</CardTitle>
                                <p className="mt-0.5 text-xs leading-relaxed text-graphite-500 dark:text-slate-400">
                                    Diterbitkan dari penyebab yang ditemukan. Inilah yang membuat investigasi mengubah sesuatu.
                                </p>
                            </div>
                            {!readOnly && (
                                <Button variant="outline" size="sm" onClick={() => setActionOpen((x) => !x)}>
                                    <AlertTriangle className="h-3.5 w-3.5" /> {actionOpen ? 'Cancel' : 'Raise CAPA'}
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {actionOpen && (
                                <form onSubmit={submitAction} className="space-y-3 rounded-lg border border-graphite-100 p-3 dark:border-slate-800">
                                    <div className="space-y-1.5"><Label>Action</Label><Input value={actionForm.data.action} onChange={(e) => actionForm.setData('action', e.target.value)} /></div>
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                        <div className="space-y-1.5">
                                            <Label>Assigned To</Label>
                                            <Select value={actionForm.data.assigned_to} onValueChange={(x) => actionForm.setData('assigned_to', x)}>
                                                <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                                <SelectContent>{users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1.5"><Label>Due Date</Label><Input type="date" value={actionForm.data.due_date} onChange={(e) => actionForm.setData('due_date', e.target.value)} /></div>
                                        <div className="space-y-1.5">
                                            <Label>Priority</Label>
                                            <Select value={actionForm.data.priority} onValueChange={(x) => actionForm.setData('priority', x)}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent>{priorities.map((p) => <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                    <Button type="submit" size="sm" disabled={actionForm.processing}>Save</Button>
                                </form>
                            )}

                            {actions.length === 0 ? (
                                <p className="text-sm text-graphite-400 dark:text-slate-500">Belum ada tindakan perbaikan diterbitkan.</p>
                            ) : (
                                actions.map((a) => (
                                    <div key={a.id} className="rounded-lg border border-graphite-100 p-3 text-sm dark:border-slate-800">
                                        <p>{a.action}</p>
                                        <p className="mt-1 text-xs text-graphite-500 dark:text-slate-400">
                                            {a.assignee?.name || 'Unassigned'}
                                            {a.due_date && ` · due ${formatDate(a.due_date)}`} · <StatusBadge value={a.status} />
                                        </p>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader><CardTitle className="text-sm">Record</CardTitle></CardHeader>
                        <CardContent>
                            <FieldGrid columns={1}>
                                <Field label="Investigation" value={v.investigation_number} mono emphasis />
                                <Field label="Methodology" value={methodLabels[v.method]} />
                                <Field label="Lead Investigator" value={v.investigator?.name} />
                                <Field label="Started" value={formatDate(v.started_at)} />
                                <Field label="Target Completion" value={formatDate(v.target_completion_date)} />
                                <Field label="Reviewed By" value={v.reviewer?.name} hint={v.reviewed_at ? formatDateTime(v.reviewed_at, displayTimeZone) : undefined} />
                                <Field label="Closed By" value={v.closer?.name} hint={v.closed_at ? formatDateTime(v.closed_at, displayTimeZone) : undefined} />
                            </FieldGrid>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-sm">Source Report</CardTitle></CardHeader>
                        <CardContent>
                            <FieldGrid columns={1}>
                                <Field label="Incident" value={incident?.incident_number} mono emphasis />
                                <Field label="Event" value={incident?.title} />
                                <Field label="Occurred" value={formatDate(incident?.incident_date)} />
                                <Field label="Severity" value={incident?.severity ? <StatusBadge value={incident.severity} /> : null} />
                            </FieldGrid>
                            {incident && (
                                <Button variant="outline" size="sm" asChild className="mt-3 w-full">
                                    <Link href={route('incidents.show', incident.id)}>View Initial Report</Link>
                                </Button>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle className="text-sm">Activity</CardTitle></CardHeader>
                        <CardContent><ActivityTimeline activities={activities} /></CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

/**
 * Only the transitions the state machine actually allows are offered.
 * `IncidentInvestigation::$transitions` is the authority; this mirrors it
 * so the UI never shows a button the server will reject.
 *
 * The open-CAPA warning on Close is ADVISORY by design -- see
 * `IncidentInvestigation::canBeClosedCleanly()`. A system that refuses
 * gets worked around by cancelling the action instead, which destroys the
 * record; stating the position and letting a human decide keeps it.
 */
function WorkflowActions({ status, openActionCount, onTransition }) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            {status === 'draft' && (
                <Button variant="outline" onClick={() => onTransition('in_progress')}>
                    <Send className="h-4 w-4" /> Start
                </Button>
            )}
            {status === 'in_progress' && (
                <Button variant="outline" onClick={() => onTransition('under_review', 'Submit this investigation for review?')}>
                    <Send className="h-4 w-4" /> Submit for Review
                </Button>
            )}
            {status === 'under_review' && (
                <>
                    <Button onClick={() => onTransition('completed', 'Accept this investigation as complete?')}>
                        <CheckCircle2 className="h-4 w-4" /> Accept
                    </Button>
                    <Button variant="outline" onClick={() => onTransition('in_progress', 'Send this back to the investigator?')}>
                        <RotateCcw className="h-4 w-4" /> Send Back
                    </Button>
                </>
            )}
            {status === 'completed' && (
                <Button
                    onClick={() => onTransition(
                        'closed',
                        openActionCount > 0
                            ? `${openActionCount} corrective action(s) are still open. Close this investigation anyway?`
                            : 'Close this investigation?'
                    )}
                >
                    <CheckCircle2 className="h-4 w-4" /> Close
                </Button>
            )}
            {!['closed', 'cancelled'].includes(status) && (
                <Button variant="ghost" className="text-red-600" onClick={() => onTransition('cancelled', 'Cancel this investigation? This is not a conclusion.')}>
                    <XCircle className="h-4 w-4" /> Cancel
                </Button>
            )}
        </div>
    );
}

function formatDate(value) {
    if (!value) return null;
    return new Date(value).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** Display timezone, never the device's -- see ApprovalStamp (v2.72.0). */
function formatDateTime(value, timeZone) {
    if (!value) return null;
    return new Date(value).toLocaleString('en-US', {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
        ...(timeZone ? { timeZone } : {}),
    });
}
