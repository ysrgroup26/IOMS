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
    ArrowLeft, SearchCheck, CheckCircle2, AlertTriangle, Clock, MapPin,
    HeartPulse, Users, FileText, ShieldQuestion,
} from 'lucide-react';

/**
 * v2.73.0 -- THE INITIAL ACCIDENT REPORT, READ AS ONE.
 *
 * This page used to carry an editable Investigation form: method, root
 * cause, findings, recommendations, saved straight onto the incident by
 * whoever happened to open it. That form is gone. An investigation is now
 * its own record with its own page, and what belongs here is a LINK to it
 * and the one action that starts it.
 *
 * What is left is the report itself, laid out as the 5W1H document it is
 * rather than as a Details card with four rows in it. The order is the
 * order somebody reads an accident report in: what happened, who was
 * hurt, what was done about it, who saw it, what happens next.
 *
 * NOTHING ON THIS PAGE IS EDITABLE. The initial report is the factual
 * source record; an investigator revising it weeks later destroys the
 * thing they are working from. Corrections belong in the investigation,
 * where they are attributable.
 */
export default function IncidentShow({ incident: i, activities, canManage, users, injurySeverityLabels }) {
    const [findingOpen, setFindingOpen] = useState(false);
    const [openOpen, setOpenOpen] = useState(false);
    const displayTimeZone = usePage().props.display_timezone;

    const findingForm = useForm({ action: '', assigned_to: '', due_date: '', priority: 'medium' });
    const openForm = useForm({ investigator_id: '', scope: '', target_completion_date: '' });

    function transition(status, confirmMessage) {
        if (!confirm(confirmMessage)) return;
        router.post(route('incidents.transition', i.id), { status });
    }

    function submitFinding(e) {
        e.preventDefault();
        findingForm.post(route('incidents.raise-finding', i.id), {
            preserveScroll: true,
            onSuccess: () => { findingForm.reset(); setFindingOpen(false); },
        });
    }

    function submitOpenInvestigation(e) {
        e.preventDefault();
        openForm.post(route('investigations.store', i.id));
    }

    const inv = i.investigation;
    const actions = i.corrective_actions || [];
    const openActions = actions.filter((a) => !['verified', 'cancelled'].includes(a.status)).length;

    const injured = i.injured_employee?.full_name || i.injured_person_name;
    const eventAt = [
        formatDate(i.incident_date),
        i.incident_time ? i.incident_time.slice(0, 5) : null,
    ].filter(Boolean).join(' · ');

    return (
        <AuthenticatedLayout>
            <Head title={i.incident_number} />

            <Link href={route('incidents.index')} className="mb-3 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Incident Management
            </Link>

            <PageHeader
                title={<>{i.incident_number} <StatusBadge value={i.severity} /> <StatusBadge value={i.status} /></>}
                subtitle={<>Laporan awal kejadian. Investigasi dicatat terpisah pada workspace HSE Investigation.</>}
            >
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2">
                        {!inv && (
                            <Button variant="outline" onClick={() => setOpenOpen((v) => !v)}>
                                <SearchCheck className="h-4 w-4" /> {openOpen ? 'Cancel' : 'Open Investigation'}
                            </Button>
                        )}
                        {i.status !== 'closed' && (
                            <Button onClick={() => transition('closed', 'Close this incident report?')}>
                                <CheckCircle2 className="h-4 w-4" /> Close
                            </Button>
                        )}
                    </div>
                )}
            </PageHeader>

            {/* Where this report sits in the chain. Rendered from the
                incident end; the investigation renders the same chain
                looking back, from the same facts. */}
            <RecordChain
                className="mb-4"
                steps={[
                    {
                        key: 'inc',
                        label: 'Initial Report',
                        reference: i.incident_number,
                        status: i.status,
                        meta: formatDate(i.incident_date),
                        current: true,
                    },
                    {
                        key: 'inv',
                        label: 'HSE Investigation',
                        reference: inv?.investigation_number,
                        status: inv?.status,
                        meta: inv ? inv.investigator?.name : 'Dibuka oleh HSE bila diperlukan',
                        href: inv ? route('investigations.show', inv.id) : undefined,
                    },
                    {
                        key: 'capa',
                        label: 'Corrective Action',
                        reference: actions.length ? `${actions.length} action${actions.length === 1 ? '' : 's'}` : null,
                        meta: actions.length ? `${openActions} still open` : 'Diterbitkan dari hasil investigasi',
                        muted: true,
                    },
                ]}
            />

            {openOpen && canManage && !inv && (
                <Card className="mb-4 border-brand-200 dark:border-brand-900">
                    <CardHeader>
                        <CardTitle className="text-sm">Open HSE Investigation</CardTitle>
                        <p className="text-xs leading-relaxed text-graphite-500 dark:text-slate-400">
                            Investigasi dibuat sebagai catatan tersendiri dengan nomor, status, dan penutupannya
                            sendiri. Laporan awal ini tetap utuh sebagai sumber fakta dan tidak dapat diubah dari sana.
                        </p>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submitOpenInvestigation} className="space-y-3">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label>Lead Investigator <span className="text-red-500">*</span></Label>
                                    <Select value={openForm.data.investigator_id} onValueChange={(v) => openForm.setData('investigator_id', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select investigator" /></SelectTrigger>
                                        <SelectContent>{users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}</SelectContent>
                                    </Select>
                                    {openForm.errors.investigator_id && <p className="text-xs text-red-600">{openForm.errors.investigator_id}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Target Completion</Label>
                                    <Input type="date" value={openForm.data.target_completion_date} onChange={(e) => openForm.setData('target_completion_date', e.target.value)} />
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Scope</Label>
                                <Textarea rows={2} value={openForm.data.scope} onChange={(e) => openForm.setData('scope', e.target.value)} placeholder="Apa yang akan diperiksa, dan batasannya." />
                            </div>
                            <Button type="submit" size="sm" disabled={openForm.processing}>
                                <SearchCheck className="h-4 w-4" /> Open Investigation
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardContent className="space-y-5 pt-5">
                            <DetailSection index="01" title="What happened" icon={FileText}>
                                <FieldGrid columns={2}>
                                    <Field label="Event" value={i.title} span={2} emphasis />
                                    <Field label="Classification" value={humanize(i.category)} />
                                    <Field label="Severity" value={<StatusBadge value={i.severity} />} />
                                    <Field label="Date & Time" value={eventAt} />
                                    <Field label="Reported At" value={formatDateTime(i.reported_at, displayTimeZone)} hint="Dicatat oleh sistem, bukan diisi manual." />
                                    <Field label="Location" value={i.location} />
                                    <Field label="Work Area" value={i.work_area} />
                                    {i.project && <Field label="Project" value={i.project.name} />}
                                    {i.company && <Field label="Operating Unit" value={i.company.name} />}
                                    <Field label="Description" value={i.description} span={2} />
                                </FieldGrid>
                            </DetailSection>

                            <DetailSection
                                index="02"
                                title="Who was involved"
                                icon={HeartPulse}
                                description={
                                    i.injury_severity && i.injury_severity !== 'none'
                                        ? undefined
                                        : 'Tidak ada korban cedera yang dicatat pada laporan ini.'
                                }
                            >
                                <FieldGrid columns={3}>
                                    <Field
                                        label="Injury Outcome"
                                        value={injurySeverityLabels?.[i.injury_severity] || (i.injury_severity ? humanize(i.injury_severity) : null)}
                                        emphasis
                                    />
                                    <Field label="People Injured" value={i.people_injured} mono />
                                    <Field label="Person Type" value={i.injured_person_type ? humanize(i.injured_person_type) : null} />
                                    <Field label="Injured Person" value={injured} />
                                    <Field label="Job Title" value={i.injured_person_job_title} />
                                    <Field label="ID Number" value={i.injured_person_id_number} mono />
                                    <Field label="Injury Type" value={i.injury_type} />
                                    <Field label="Body Part" value={i.body_part} />
                                </FieldGrid>
                            </DetailSection>

                            <DetailSection index="03" title="Immediate response" icon={Clock}>
                                <FieldGrid columns={2}>
                                    <Field label="Treatment Given" value={i.immediate_treatment} span={2} />
                                    <Field label="Medical Facility" value={i.medical_facility} />
                                    <Field label="Referred To Facility" value={boolLabel(i.referred_to_facility)} />
                                    <Field
                                        label="Immediate Actions Taken"
                                        value={i.immediate_actions}
                                        span={2}
                                        hint="Tindakan pengamanan di lokasi. Bukan tindakan perbaikan (CAPA), yang diterbitkan dari investigasi."
                                    />
                                </FieldGrid>
                            </DetailSection>

                            <DetailSection index="04" title="How it happened" icon={MapPin}>
                                <FieldGrid columns={1}>
                                    <Field label="Chronology" value={i.chronology} />
                                    <Field
                                        label="Known Circumstances"
                                        value={i.initial_circumstances}
                                        hint="Keadaan yang diketahui saat pelaporan. Bukan analisis penyebab."
                                    />
                                </FieldGrid>
                            </DetailSection>

                            <DetailSection index="05" title="Witnesses" icon={Users}>
                                {(!i.witnesses || i.witnesses.length === 0) ? (
                                    <p className="text-sm text-graphite-400 dark:text-slate-500">Tidak ada saksi yang dicatat.</p>
                                ) : (
                                    <ul className="divide-y divide-graphite-100 rounded-lg border border-graphite-100 dark:divide-slate-800 dark:border-slate-800">
                                        {i.witnesses.map((w, idx) => (
                                            <li key={idx} className="px-3 py-2 text-sm">
                                                <span className="font-medium text-graphite-900 dark:text-slate-100">{w.name}</span>
                                                {w.contact && <span className="ml-2 text-xs text-graphite-500 dark:text-slate-400">{w.contact}</span>}
                                                {w.note && <p className="mt-0.5 text-xs text-graphite-500 dark:text-slate-400">{w.note}</p>}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </DetailSection>

                            <DetailSection
                                index="06"
                                title="Reporting"
                                icon={ShieldQuestion}
                                description="IOMS mencatat fakta pelaporan. Pengajuan klaim tetap dilakukan melalui kanal resmi masing-masing."
                            >
                                <FieldGrid columns={3}>
                                    <Field label="Reported By" value={i.reporter?.name} />
                                    <Field label="Work Related" value={boolLabel(i.work_related)} />
                                    <Field label="Reportable To Authority" value={boolLabel(i.reportable_to_authority)} />
                                    <Field label="Employment Injury Reference" value={i.employment_injury_reference} mono span={2} />
                                </FieldGrid>
                            </DetailSection>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <div className="min-w-0">
                                <CardTitle>Corrective Actions</CardTitle>
                                <p className="mt-0.5 text-xs text-graphite-500 dark:text-slate-400">
                                    Tindakan yang muncul langsung dari laporan ini. Tindakan hasil analisis penyebab
                                    diterbitkan dari investigasi.
                                </p>
                            </div>
                            {canManage && (
                                <Button variant="outline" size="sm" onClick={() => setFindingOpen((v) => !v)}>
                                    <AlertTriangle className="h-3.5 w-3.5" /> {findingOpen ? 'Cancel' : 'Raise CAPA'}
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {findingOpen && (
                                <form onSubmit={submitFinding} className="space-y-3 rounded-md border border-graphite-100 p-3 dark:border-slate-800">
                                    <div className="space-y-1.5"><Label>Action</Label><Input value={findingForm.data.action} onChange={(e) => findingForm.setData('action', e.target.value)} /></div>
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                        <div className="space-y-1.5">
                                            <Label>Assigned To</Label>
                                            <Select value={findingForm.data.assigned_to} onValueChange={(v) => findingForm.setData('assigned_to', v)}>
                                                <SelectTrigger><SelectValue placeholder="Select" /></SelectTrigger>
                                                <SelectContent>{users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}</SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1.5"><Label>Due Date</Label><Input type="date" value={findingForm.data.due_date} onChange={(e) => findingForm.setData('due_date', e.target.value)} /></div>
                                        <div className="space-y-1.5">
                                            <Label>Priority</Label>
                                            <Select value={findingForm.data.priority} onValueChange={(v) => findingForm.setData('priority', v)}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent><SelectItem value="low">Low</SelectItem><SelectItem value="medium">Medium</SelectItem><SelectItem value="high">High</SelectItem></SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                    <Button type="submit" size="sm" disabled={findingForm.processing}>Save</Button>
                                </form>
                            )}
                            {actions.length === 0 ? (
                                <p className="text-sm text-graphite-400 dark:text-slate-500">Belum ada tindakan perbaikan.</p>
                            ) : (
                                actions.map((a) => (
                                    <div key={a.id} className="rounded-md border border-graphite-100 p-3 text-sm dark:border-slate-800">
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
                        <CardHeader><CardTitle className="text-sm">HSE Investigation</CardTitle></CardHeader>
                        <CardContent>
                            {inv ? (
                                <div className="space-y-2.5 text-sm">
                                    <FieldGrid columns={1}>
                                        <Field label="Investigation" value={inv.investigation_number} mono emphasis />
                                        <Field label="Status" value={<StatusBadge value={inv.status} />} />
                                        <Field label="Lead Investigator" value={inv.investigator?.name} />
                                        <Field label="Started" value={formatDate(inv.started_at)} />
                                    </FieldGrid>
                                    <Button variant="outline" size="sm" asChild className="w-full">
                                        <Link href={route('investigations.show', inv.id)}>
                                            <SearchCheck className="h-4 w-4" /> Open Investigation
                                        </Link>
                                    </Button>
                                </div>
                            ) : (
                                <p className="text-sm leading-relaxed text-graphite-500 dark:text-slate-400">
                                    Belum ada investigasi untuk laporan ini. HSE membuka investigasi bila kejadian
                                    memerlukan analisis penyebab.
                                </p>
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

function humanize(value) {
    if (!value) return null;
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * `null` for a boolean that was never answered, which is genuinely
 * different from "No" on a report -- "was this work related?" left blank
 * is an open question, not a denial.
 */
function boolLabel(value) {
    if (value === null || value === undefined) return null;
    return value ? 'Yes' : 'No';
}

function formatDate(value) {
    if (!value) return null;
    return new Date(value).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** Resolved against the product's display timezone, never the device's -- see ApprovalStamp (v2.72.0). */
function formatDateTime(value, timeZone) {
    if (!value) return null;
    return new Date(value).toLocaleString('en-US', {
        day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
        ...(timeZone ? { timeZone } : {}),
    });
}
