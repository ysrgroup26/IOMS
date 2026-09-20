import { Head, useForm, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Checkbox } from '@/Components/ui/checkbox';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import CollapsibleSection from '@/Components/shared/CollapsibleSection';
import { FormDocumentHeader, FormSection, FormField, FormActions, ErrorSummary, SearchableSelect } from '@/Components/shared/form';
import { ArrowLeft, Siren, Plus, Trash2 } from 'lucide-react';

const BLANK_WITNESS = { name: '', contact: '', note: '' };

/**
 * v2.73.0 -- THE INITIAL ACCIDENT REPORT.
 *
 * Was eight fields: title, description, date, location, severity,
 * category, company, project. Enough to say an incident happened; not
 * enough to report one. Everything a report exists to capture -- who was
 * hurt, what the injury was, what first aid was given, who saw it, what
 * was done immediately -- had nowhere to go but the description box.
 *
 * ORGANISED AS 5W1H, because that is what somebody at the scene can
 * actually answer, and in the order they can answer it:
 *
 *   01 What happened      the event, when and where
 *   02 Who was involved   the injured party and the injury
 *   03 Immediate response treatment, facility, what was made safe
 *   04 How it happened    chronology and known circumstances
 *   05 Witnesses          names, while they are still on site
 *   06 Reporting          work-related, authority, claim reference
 *
 * SPEED IS A SAFETY REQUIREMENT HERE. Only four fields are mandatory --
 * title, date, severity, category -- the things known in the first
 * minute. Sections 03 to 06 are collapsed by default: a report filed
 * while an ambulance is still on site should take thirty seconds, and a
 * form that demands the medical facility before it will save is a form
 * that gets filled in tomorrow from memory.
 *
 * WHAT IS DELIBERATELY ABSENT: root cause, methodology, corrective
 * action, effectiveness verification. None of those are knowable at the
 * scene, and a field asking for them invites a guess that is later quoted
 * as a finding. They belong to the HSE Investigation workspace. See
 * docs/ADR/036.
 */
export default function IncidentForm({
    companies, projects, incidentNumber, severities, categories,
    personTypes, injurySeverities, injurySeverityLabels, employees,
}) {
    const { auth } = usePage().props;

    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        incident_date: new Date().toISOString().slice(0, 10),
        incident_time: new Date().toTimeString().slice(0, 5),
        location: '',
        work_area: '',
        severity: 'minor',
        category: 'near_miss',
        company_id: '',
        project_id: '',

        injured_employee_id: '',
        injured_person_name: '',
        injured_person_type: 'none',
        injured_person_job_title: '',
        injured_person_id_number: '',
        injury_type: '',
        body_part: '',
        injury_severity: 'none',
        people_injured: '',

        immediate_treatment: '',
        medical_facility: '',
        referred_to_facility: false,

        chronology: '',
        initial_circumstances: '',
        immediate_actions: '',

        witnesses: [{ ...BLANK_WITNESS }],

        work_related: false,
        reportable_to_authority: false,
        employment_injury_reference: '',
    });

    // Someone is only being described as injured once the outcome says so.
    // Driving the section off `injury_severity` rather than off `category`
    // is deliberate: a property-damage event that also cut somebody's hand
    // is both, and reading the category would hide the injury.
    const hasInjury = data.injury_severity && data.injury_severity !== 'none';

    const availableProjects = data.company_id
        ? projects.filter((p) => String(p.company_id) === String(data.company_id) || p.company_id === undefined)
        : projects;

    function updateWitness(index, field, value) {
        const next = [...data.witnesses];
        next[index] = { ...next[index], [field]: value };
        setData('witnesses', next);
    }

    function submit(e) {
        e.preventDefault();
        post(route('incidents.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Report Incident" />

            <div className="mb-3 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={route('incidents.index')}><ArrowLeft className="h-4 w-4" /> Back</Link>
                </Button>
            </div>

            <FormDocumentHeader
                icon={Siren}
                documentType="Initial Accident Report"
                reference={incidentNumber}
                state="draft"
                meta={[{ label: 'Reported By', value: auth?.user?.name }]}
                workflow="Laporan ini menjadi catatan fakta atas kejadian. HSE dapat membuka investigasi terpisah untuk menganalisis penyebabnya. Analisis penyebab dan tindakan perbaikan tidak diisi di sini."
                className="mb-4"
            />

            <form onSubmit={submit} className="max-w-4xl space-y-4">
                <ErrorSummary errors={errors} labels={ERROR_LABELS} />

                <FormSection
                    title="What happened"
                    description="Kejadiannya apa, kapan, dan di mana. Isi secepat mungkin selagi faktanya masih segar."
                >
                    <FormField label="What happened" name="title" required error={errors.title} hint="Satu kalimat ringkas.">
                        {(control) => (
                            <Input {...control} value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="e.g. Pekerja terjatuh dari perancah di Blok B" />
                        )}
                    </FormField>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label="Date" name="incident_date" required error={errors.incident_date}>
                            {(control) => <Input {...control} type="date" value={data.incident_date} onChange={(e) => setData('incident_date', e.target.value)} />}
                        </FormField>
                        <FormField label="Time" name="incident_time" error={errors.incident_time} hint="Perkiraan waktu sudah cukup.">
                            {(control) => <Input {...control} type="time" value={data.incident_time} onChange={(e) => setData('incident_time', e.target.value)} />}
                        </FormField>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label="Event Type" name="category" required error={errors.category}>
                            {(control) => (
                                <Select value={data.category} onValueChange={(v) => setData('category', v)}>
                                    <SelectTrigger {...control}><SelectValue /></SelectTrigger>
                                    <SelectContent>{categories.map((c) => <SelectItem key={c} value={c} className="capitalize">{c.replace(/_/g, ' ')}</SelectItem>)}</SelectContent>
                                </Select>
                            )}
                        </FormField>
                        <FormField label="Severity" name="severity" required error={errors.severity}>
                            {(control) => (
                                <Select value={data.severity} onValueChange={(v) => setData('severity', v)}>
                                    <SelectTrigger {...control}><SelectValue /></SelectTrigger>
                                    <SelectContent>{severities.map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}</SelectContent>
                                </Select>
                            )}
                        </FormField>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label="Location" name="location" error={errors.location}>
                            {(control) => <Input {...control} value={data.location} onChange={(e) => setData('location', e.target.value)} placeholder="e.g. Dock 2" />}
                        </FormField>
                        <FormField label="Work Area" name="work_area" error={errors.work_area} hint="Area spesifik, seperti yang tertulis pada permit.">
                            {(control) => <Input {...control} value={data.work_area} onChange={(e) => setData('work_area', e.target.value)} placeholder="e.g. Blok B, deck 3" />}
                        </FormField>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {companies.length > 1 && (
                            <FormField label="Operating Unit" name="company_id" error={errors.company_id}>
                                {(control) => (
                                    <SearchableSelect
                                        {...control}
                                        value={data.company_id}
                                        onChange={(v) => setData((d) => ({ ...d, company_id: v, project_id: '' }))}
                                        options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                        placeholder="Select operating unit"
                                        clearable
                                    />
                                )}
                            </FormField>
                        )}
                        <FormField label="Project" name="project_id" error={errors.project_id}>
                            {(control) => (
                                <SearchableSelect
                                    {...control}
                                    value={data.project_id}
                                    onChange={(v) => setData('project_id', v)}
                                    options={availableProjects.map((p) => ({ value: p.id, label: p.name }))}
                                    placeholder="Not linked to a project"
                                    clearable
                                />
                            )}
                        </FormField>
                    </div>

                    <FormField label="Description" name="description" error={errors.description}>
                        {(control) => <Textarea {...control} rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} />}
                    </FormField>
                </FormSection>

                <FormSection
                    title="Who was involved"
                    description="Isi bila ada korban. Untuk nearmiss atau kerusakan properti tanpa korban, biarkan Injury Outcome pada No Injury."
                >
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <FormField label="Injury Outcome" name="injury_severity" error={errors.injury_severity}>
                            {(control) => (
                                <Select value={data.injury_severity} onValueChange={(v) => setData('injury_severity', v)}>
                                    <SelectTrigger {...control}><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {injurySeverities.map((s) => <SelectItem key={s} value={s}>{injurySeverityLabels?.[s] || s}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            )}
                        </FormField>
                        <FormField label="Person Type" name="injured_person_type" error={errors.injured_person_type}>
                            {(control) => (
                                <Select value={data.injured_person_type} onValueChange={(v) => setData('injured_person_type', v)}>
                                    <SelectTrigger {...control}><SelectValue /></SelectTrigger>
                                    <SelectContent>{personTypes.map((t) => <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>)}</SelectContent>
                                </Select>
                            )}
                        </FormField>
                    </div>

                    {/* Only shown once an outcome says somebody was hurt.
                        Asking for a body part on a near miss is how a form
                        teaches people to stop reading it. */}
                    {hasInjury && (
                        <>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField label="From Workforce" name="injured_employee_id" error={errors.injured_employee_id} hint="Menautkan laporan ke berkas karyawan.">
                                    {(control) => (
                                        <SearchableSelect
                                            {...control}
                                            value={data.injured_employee_id}
                                            onChange={(v) => {
                                                const emp = employees.find((e) => String(e.id) === String(v));
                                                setData((d) => ({ ...d, injured_employee_id: v, injured_person_name: emp?.full_name || d.injured_person_name }));
                                            }}
                                            options={employees.map((e) => ({ value: e.id, label: e.full_name }))}
                                            placeholder="Select employee"
                                            clearable
                                        />
                                    )}
                                </FormField>
                                <FormField label="Injured Person" name="injured_person_name" error={errors.injured_person_name} hint="Isi manual untuk kontraktor, tamu, atau pihak luar.">
                                    {(control) => <Input {...control} value={data.injured_person_name} onChange={(e) => setData('injured_person_name', e.target.value)} />}
                                </FormField>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <FormField label="Job Title" name="injured_person_job_title" error={errors.injured_person_job_title}>
                                    {(control) => <Input {...control} value={data.injured_person_job_title} onChange={(e) => setData('injured_person_job_title', e.target.value)} />}
                                </FormField>
                                <FormField label="ID Number" name="injured_person_id_number" error={errors.injured_person_id_number}>
                                    {(control) => <Input {...control} value={data.injured_person_id_number} onChange={(e) => setData('injured_person_id_number', e.target.value)} />}
                                </FormField>
                                <FormField label="People Injured" name="people_injured" error={errors.people_injured}>
                                    {(control) => <Input {...control} type="number" min="0" value={data.people_injured} onChange={(e) => setData('people_injured', e.target.value)} />}
                                </FormField>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <FormField label="Injury Type" name="injury_type" error={errors.injury_type}>
                                    {(control) => <Input {...control} value={data.injury_type} onChange={(e) => setData('injury_type', e.target.value)} placeholder="e.g. Luka robek, patah tulang, luka bakar" />}
                                </FormField>
                                <FormField label="Body Part" name="body_part" error={errors.body_part}>
                                    {(control) => <Input {...control} value={data.body_part} onChange={(e) => setData('body_part', e.target.value)} placeholder="e.g. Tangan kiri" />}
                                </FormField>
                            </div>
                        </>
                    )}
                </FormSection>

                <CollapsibleSection title="Immediate response" description="Pertolongan pertama, rujukan, dan pengamanan lokasi.">
                    <div className="space-y-4">
                        <FormField label="Treatment Given" name="immediate_treatment" error={errors.immediate_treatment}>
                            {(control) => <Textarea {...control} rows={2} value={data.immediate_treatment} onChange={(e) => setData('immediate_treatment', e.target.value)} />}
                        </FormField>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <FormField label="Medical Facility" name="medical_facility" error={errors.medical_facility}>
                                {(control) => <Input {...control} value={data.medical_facility} onChange={(e) => setData('medical_facility', e.target.value)} placeholder="e.g. Klinik perusahaan / RS terdekat" />}
                            </FormField>
                            <div className="flex items-end pb-2">
                                <label className="flex items-center gap-2">
                                    <Checkbox checked={data.referred_to_facility} onCheckedChange={(v) => setData('referred_to_facility', Boolean(v))} aria-label="Referred to a medical facility" />
                                    <span className="text-sm text-graphite-700 dark:text-slate-300">Referred to a medical facility</span>
                                </label>
                            </div>
                        </div>
                        <FormField
                            label="Immediate Actions Taken"
                            name="immediate_actions"
                            error={errors.immediate_actions}
                            hint="Pengamanan lokasi saat itu juga. Bukan tindakan perbaikan (CAPA)."
                        >
                            {(control) => <Textarea {...control} rows={2} value={data.immediate_actions} onChange={(e) => setData('immediate_actions', e.target.value)} />}
                        </FormField>
                    </div>
                </CollapsibleSection>

                <CollapsibleSection title="How it happened" description="Kronologi dan keadaan yang diketahui saat pelaporan.">
                    <div className="space-y-4">
                        <FormField label="Chronology" name="chronology" error={errors.chronology} hint="Urutan kejadian menurut pelapor.">
                            {(control) => <Textarea {...control} rows={4} value={data.chronology} onChange={(e) => setData('chronology', e.target.value)} />}
                        </FormField>
                        <FormField
                            label="Known Circumstances"
                            name="initial_circumstances"
                            error={errors.initial_circumstances}
                            hint="Apa yang diketahui saat ini. Analisis penyebab dilakukan pada tahap investigasi, bukan di sini."
                        >
                            {(control) => <Textarea {...control} rows={2} value={data.initial_circumstances} onChange={(e) => setData('initial_circumstances', e.target.value)} />}
                        </FormField>
                    </div>
                </CollapsibleSection>

                <CollapsibleSection title="Witnesses" description="Catat namanya selagi orangnya masih di lokasi.">
                    <div className="space-y-3">
                        {data.witnesses.map((w, i) => (
                            <div key={i} className="grid grid-cols-1 gap-3 rounded-lg border border-graphite-100 p-3 sm:grid-cols-[1fr_1fr_auto] dark:border-slate-800">
                                <div className="space-y-1.5">
                                    <Label className="text-xs">Name</Label>
                                    <Input value={w.name} onChange={(e) => updateWitness(i, 'name', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs">Contact</Label>
                                    <Input value={w.contact} onChange={(e) => updateWitness(i, 'contact', e.target.value)} placeholder="Telepon / departemen" />
                                </div>
                                <div className="flex items-end pb-1">
                                    {data.witnesses.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setData('witnesses', data.witnesses.filter((_, idx) => idx !== i))}
                                            aria-label={`Remove witness ${i + 1}`}
                                        >
                                            <Trash2 className="h-4 w-4 text-red-500" />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                        <Button type="button" variant="outline" size="sm" onClick={() => setData('witnesses', [...data.witnesses, { ...BLANK_WITNESS }])}>
                            <Plus className="h-4 w-4" /> Add Witness
                        </Button>
                    </div>
                </CollapsibleSection>

                <CollapsibleSection
                    title="Reporting"
                    description="IOMS mencatat fakta pelaporan. Pengajuan klaim tetap dilakukan melalui kanal resmi masing-masing."
                >
                    <div className="space-y-4">
                        <div className="flex flex-col gap-2.5">
                            <label className="flex items-center gap-2">
                                <Checkbox checked={data.work_related} onCheckedChange={(v) => setData('work_related', Boolean(v))} aria-label="Work related" />
                                <span className="text-sm text-graphite-700 dark:text-slate-300">Work related</span>
                            </label>
                            <label className="flex items-center gap-2">
                                <Checkbox checked={data.reportable_to_authority} onCheckedChange={(v) => setData('reportable_to_authority', Boolean(v))} aria-label="Reportable to authority" />
                                <span className="text-sm text-graphite-700 dark:text-slate-300">Reportable to authority</span>
                            </label>
                        </div>
                        <FormField
                            label="Employment Injury Reference"
                            name="employment_injury_reference"
                            error={errors.employment_injury_reference}
                            hint="Nomor rujukan klaim, bila sudah ada. Diisi agar laporan ini dapat ditemukan dari nomor tersebut."
                        >
                            {(control) => <Input {...control} value={data.employment_injury_reference} onChange={(e) => setData('employment_injury_reference', e.target.value)} />}
                        </FormField>
                    </div>
                </CollapsibleSection>

                <FormActions
                    submitLabel="Submit Report"
                    processing={processing}
                    cancelHref={route('incidents.index')}
                    note="Laporan tersimpan dengan status Reported. HSE dapat membuka investigasi terpisah bila kejadian memerlukan analisis penyebab."
                />
            </form>
        </AuthenticatedLayout>
    );
}

const ERROR_LABELS = {
    title: 'What happened',
    incident_date: 'Date',
    incident_time: 'Time',
    severity: 'Severity',
    category: 'Event type',
    company_id: 'Operating Unit',
    project_id: 'Project',
    injury_severity: 'Injury outcome',
    injured_employee_id: 'Injured person',
};
