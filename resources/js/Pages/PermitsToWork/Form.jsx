import { Head, useForm, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import CollapsibleSection from '@/Components/shared/CollapsibleSection';
import EmployeeSelector from '@/Components/shared/EmployeeSelector';
import { ArrowLeft, ShieldCheck, Users } from 'lucide-react';

/**
 * v2.4.0 (PTW UX + Field Operations pass, Phase 1). Was previously one
 * flat Card with every field shown at once (13 fields, no grouping) and
 * a `grid-cols-2` block with no mobile fallback -- confirmed via this
 * pass's own audit before touching anything. Reworked around progressive
 * disclosure: required work info always visible, Safety Controls as its
 * own clearly-labeled section (per the explicit product structure), and
 * Required Qualification / Linked HIRADC / Linked JSA moved into a
 * collapsed "Optional / Advanced" section (CollapsibleSection, new this
 * pass) -- nothing here changes what data is collected or how it's
 * validated/stored; StorePermitToWorkRequest and PermitToWorkController
 * are untouched. Every grid now has an explicit `sm:` fallback so it
 * stacks on a phone instead of staying 2 columns squeezed.
 */
/**
 * v2.38.0 -- PTW time defaults. CONFIRMED BUG being fixed here: the
 * previous defaults were `new Date().toISOString().slice(0, 16)`.
 * `toISOString()` always returns UTC, but a `datetime-local` input
 * expects LOCAL wall-clock time. For a user in WIB (UTC+7) opening the
 * form at 16:37, the field pre-filled 09:37 -- seven hours earlier -- and
 * that wrong value was what got submitted unless the user noticed and
 * corrected it. This is the most likely source of the reported
 * "UI says 4:37 PM, PDF says 08:37" discrepancy: not a PDF formatting
 * fault at all, but a wrong value written at creation time.
 *
 * `toLocalInput()` below formats local wall-clock time instead, which is
 * what the control actually asks for.
 *
 * Business rule for the end default (per product direction): end at
 * 17:00 the same day, but if the permit is opened at/after 17:00 the
 * same-day default would already be in the past -- so it rolls to 17:00
 * the NEXT day. Both fields remain fully editable; server-side
 * `after:start_datetime` validation is unchanged and still authoritative.
 */
function toLocalInput(date) {
    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function defaultStart() {
    return toLocalInput(new Date());
}

function defaultEnd() {
    const now = new Date();
    const end = new Date(now);

    end.setHours(17, 0, 0, 0);

    if (end <= now) {
        end.setDate(end.getDate() + 1);
    }

    return toLocalInput(end);
}

export default function PermitToWorkForm({ companies, projects, riskAssessments, jsas, ptwNumber, types }) {
    const { auth } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        project_id: '',
        risk_assessment_id: '',
        jsa_id: '',
        permit_type: 'hot_work',
        work_description: '',
        location: '',
        start_datetime: defaultStart(),
        end_datetime: defaultEnd(),
        required_qualification: '',
        precautions: '',
        // v2.17.0 (PTW Field Workflow Foundation, Part 8/9): both
        // optional. `pic_employee_id` is a single Employee; `personnel_ids`
        // an arbitrary-length list of Employee ids, never free text.
        // v2.38.0: sourced via EmployeeSelector -> /employee-lookup
        // (tenant-scoped, active-only) instead of a preloaded prop; the
        // backend still validates every id with `InCurrentTenant`.
        pic_employee_id: '',
        personnel_ids: [],
    });

    function submit(e) {
        e.preventDefault();
        post(route('permits-to-work.store'));
    }

    // v2.38.0: `addPersonnel`/`removePersonnel`/`selectedPersonnel` and
    // the `employees` prop they depended on are gone -- EmployeeSelector
    // owns selection state and resolves names itself, so this page no
    // longer needs the whole directory in its payload.

    return (
        <AuthenticatedLayout>
            <Head title="New Permit To Work" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('permits-to-work.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="mx-auto max-w-xl space-y-4">
                <Card>
                    <CardHeader>
                        {/* v2.20.0 (PTW Experience & Visual Polish pass,
                            Part 8): small numbered step badge -- same
                            "01/02/03" visual language as the Document
                            view's own numbered sections, reinforcing that
                            this is a guided sequence (Work -> Workforce ->
                            Safety -> Optional -> Submit), not one long
                            undifferentiated form. */}
                        <CardTitle className="flex items-center gap-2">
                            <StepBadge>01</StepBadge> New Permit To Work -- {ptwNumber}
                        </CardTitle>
                        <CardDescription>Isi data pekerjaan yang akan dilakukan.</CardDescription>
                        {/* v2.17.0 (PTW Field Workflow Foundation, Part
                            2/12): Requester is informational only, never
                            an input -- the backend derives it from the
                            authenticated session regardless of what (if
                            anything) is rendered here. */}
                        <div className="mt-1 flex items-center gap-1.5 text-xs text-graphite-500 dark:text-slate-400">
                            <span>Requester:</span>
                            <span className="font-medium text-graphite-800 dark:text-slate-200">{auth?.user?.name}</span>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {companies.length > 1 && (
                            <div className="space-y-1.5">
                                <Label>Company</Label>
                                <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label>Permit Type</Label>
                            <Select value={data.permit_type} onValueChange={(v) => setData('permit_type', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Work Description</Label>
                            <Textarea value={data.work_description} onChange={(e) => setData('work_description', e.target.value)} rows={3} placeholder="Apa pekerjaan yang akan dilakukan?" />
                            {errors.work_description && <p className="text-xs text-red-600">{errors.work_description}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Location</Label>
                            <Input value={data.location} onChange={(e) => setData('location', e.target.value)} placeholder="Contoh: Dock 1 atau Area Tanki" />
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Start</Label>
                                <Input type="datetime-local" value={data.start_datetime} onChange={(e) => setData('start_datetime', e.target.value)} />
                                {errors.start_datetime && <p className="text-xs text-red-600">{errors.start_datetime}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>End</Label>
                                <Input type="datetime-local" value={data.end_datetime} onChange={(e) => setData('end_datetime', e.target.value)} />
                                {errors.end_datetime && <p className="text-xs text-red-600">{errors.end_datetime}</p>}
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Project (optional)</Label>
                            <Select value={data.project_id || 'none'} onValueChange={(v) => setData('project_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="No project" /></SelectTrigger>
                                <SelectContent><SelectItem value="none">No project</SelectItem>{projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                {/* v2.17.0 (PTW Field Workflow Foundation, Part 8/9/12).
                    Both fields optional, per the product direction --
                    this section can be skipped entirely without blocking
                    submission. PIC is a single person distinct from
                    Requester (see this card's own field labels); Workforce
                    is the permit's OVERALL planned crew, never duplicated
                    into JSA (JSA has no manpower concept in this
                    codebase, confirmed by audit -- untouched by this
                    pass). */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-sm"><StepBadge>02</StepBadge><Users className="h-4 w-4 text-graphite-400" /> Workforce -- Optional</CardTitle>
                        <CardDescription>Siapa yang terlibat dalam pekerjaan ini?</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {/* v2.38.0: both selectors were a plain `<Select>` fed
                            by the entire preloaded employee directory --
                            unusable past a few dozen workers, and the reason
                            free-text was tempting for this field. Replaced
                            with the shared `EmployeeSelector`, which searches
                            server-side and groups by department. The stored
                            value is still a real Employee FK, so "which
                            permits was this person responsible for?" stays
                            answerable. */}
                        <div className="space-y-1.5">
                            <Label>Penanggung Jawab Pekerjaan (opsional)</Label>
                            <EmployeeSelector
                                mode="single"
                                value={data.pic_employee_id}
                                onChange={(v) => setData('pic_employee_id', v)}
                                placeholder="Cari penanggung jawab..."
                            />
                            <p className="text-xs text-graphite-400">
                                Orang yang bertanggung jawab atas pelaksanaan pekerjaan di lapangan &mdash; berbeda dari pemohon izin.
                            </p>
                            {errors.pic_employee_id && <p className="text-xs text-red-600">{errors.pic_employee_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Workforce / Personel (opsional)</Label>
                            <EmployeeSelector
                                mode="multiple"
                                value={data.personnel_ids}
                                onChange={(v) => setData('personnel_ids', v)}
                                placeholder="Cari dan tambahkan personel..."
                            />
                            {errors.personnel_ids && <p className="text-xs text-red-600">{errors.personnel_ids}</p>}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-sm"><StepBadge>03</StepBadge><ShieldCheck className="h-4 w-4 text-graphite-400" /> Safety Controls</CardTitle>
                        <CardDescription>Apa langkah pengamanan yang diperlukan untuk pekerjaan ini?</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Textarea value={data.precautions} onChange={(e) => setData('precautions', e.target.value)} rows={3} placeholder="Contoh: pasang fire watch, siapkan APAR, isolasi area kerja" />
                    </CardContent>
                </Card>

                <CollapsibleSection title="04 · Optional / Advanced" description="Isi jika diperlukan -- kualifikasi, HIRADC, atau JSA terkait.">
                    <div className="space-y-1.5">
                        <Label>Required Qualification (optional)</Label>
                        <Input value={data.required_qualification} onChange={(e) => setData('required_qualification', e.target.value)} placeholder="Contoh: Sertifikat Confined Space Entry -- informasi saja, tidak diverifikasi otomatis" />
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Linked HIRADC (optional)</Label>
                            <Select value={data.risk_assessment_id || 'none'} onValueChange={(v) => setData('risk_assessment_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                <SelectContent><SelectItem value="none">None</SelectItem>{riskAssessments.map((r) => <SelectItem key={r.id} value={String(r.id)}>{r.ra_number}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Linked JSA (optional)</Label>
                            <Select value={data.jsa_id || 'none'} onValueChange={(v) => setData('jsa_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                <SelectContent><SelectItem value="none">None</SelectItem>{jsas.map((j) => <SelectItem key={j.id} value={String(j.id)}>{j.jsa_number}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                    </div>
                </CollapsibleSection>

                <Button type="submit" disabled={processing} className="w-full" size="lg">Submit PTW</Button>
            </form>
        </AuthenticatedLayout>
    );
}

/** v2.20.0 (PTW Experience & Visual Polish pass). Small numbered step badge -- same visual language as `PermitsToWork/Document.jsx`'s own `DocSection` index, so the Create form reads as a continuation of one consistent PTW numbering convention, not a coincidence. */
function StepBadge({ children }) {
    return (
        <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-graphite-100 text-[10px] font-bold tabular-nums text-graphite-500 dark:bg-slate-800 dark:text-slate-400">
            {children}
        </span>
    );
}
