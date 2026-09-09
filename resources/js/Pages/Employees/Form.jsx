import { useRef } from 'react';
import { Head, useForm, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import ImageUploadField from '@/Components/shared/ImageUploadField';
import { FormSection, FormField, FormActions, ErrorSummary, SearchableSelect } from '@/Components/shared/form';
import { useUnsavedChanges } from '@/lib/useUnsavedChanges';
import { ArrowLeft, ContactRound, Building2, BriefcaseBusiness, GraduationCap } from 'lucide-react';

/**
 * v2.65.0 -- THE REFERENCE IMPLEMENTATION for the Form Experience System.
 *
 * This was the audit's worst-case master data form: roughly twenty fields
 * under a single heading, "Employee Information", inside one card. The
 * grid gave it rhythm; nothing gave it meaning. It also had no required
 * markers, no error summary, no unsaved-change guard, a save button that
 * sat below the fold, and its whole action block DUPLICATED -- once for
 * the normal case and again inside the intern branch, so a change to one
 * had to be remembered in the other.
 *
 * WHAT CHANGED, AND WHAT DELIBERATELY DID NOT.
 *
 * Not a wizard. An HR administrator enters these weekly and knows the
 * fields; paginating them would make a familiar task slower. It stays one
 * page and roughly the same density -- but four named ideas instead of
 * twenty loose fields.
 *
 * The three cascading selects (Operating Unit -> Department -> Position)
 * became `SearchableSelect`. That chain grows with the customer: a
 * shipyard with four operating units and thirty departments is an
 * unusable native dropdown. Each one now also STATES its dependency in
 * the hint rather than only greying out, so a disabled control explains
 * itself instead of looking broken.
 *
 * Status and Workforce Type stay a plain `Select` -- three and five fixed
 * options respectively. Swapping every select for a searchable one would
 * be the same mistake in the other direction.
 *
 * NOTHING ABOUT THE SUBMITTED PAYLOAD CHANGED. Same field names, same
 * nested `internship` object, same POST + `_method=put` spoofing for the
 * multipart edit case, same routes. `StoreEmployeeRequest` and
 * `EmployeeController` are untouched, and server-side validation remains
 * the only authority on what is acceptable.
 */

const INTERN_TYPES = ['intern', 'pkl'];

// Field labels for ErrorSummary, so the summary says "Full name" rather
// than a humanised column name where the two differ.
const ERROR_LABELS = {
    employee_id: 'Employee ID',
    nik: 'NIK',
    full_name: 'Full name',
    company_id: 'Operating Unit',
    department_id: 'Department',
    position_id: 'Position',
    employment_type: 'Workforce type',
    join_date: 'Join date',
    contract_start_date: 'Contract start',
    contract_end_date: 'Contract end',
};

export default function EmployeeForm({ employee, companies, departments, positions, employmentTypes }) {
    const isEdit = !!employee;
    const internship = employee?.internship;

    const initial = useRef(null);

    const { data, setData, post, processing, errors, transform } = useForm({
        employee_id: employee?.employee_id || '',
        nik: employee?.nik || '',
        full_name: employee?.full_name || '',
        company_id: employee?.company_id ? String(employee.company_id) : undefined,
        department_id: employee?.department_id ? String(employee.department_id) : undefined,
        position_id: employee?.position_id ? String(employee.position_id) : undefined,
        status: employee?.status || 'active',
        employment_type: employee?.employment_type || 'pkwtt',
        join_date: employee?.join_date?.slice(0, 10) || '',
        contract_start_date: employee?.contract_start_date?.slice(0, 10) || '',
        contract_end_date: employee?.contract_end_date?.slice(0, 10) || '',
        phone: employee?.phone || '',
        photo: null,
        internship: {
            institution: internship?.institution || '',
            program: internship?.program || '',
            mentor_name: internship?.mentor_name || '',
            agreement_number: internship?.agreement_number || '',
            start_date: internship?.start_date?.slice(0, 10) || '',
            end_date: internship?.end_date?.slice(0, 10) || '',
            work_location: internship?.work_location || '',
            induction_completed: internship?.induction_completed || false,
            insurance_coverage: internship?.insurance_coverage || '',
            evaluation: internship?.evaluation || '',
            completion_status: internship?.completion_status || 'ongoing',
        },
    });

    // Captured once, on first render, so "dirty" means "differs from what
    // this form opened with" rather than "differs from the last keystroke".
    if (initial.current === null) initial.current = { ...data, internship: { ...data.internship } };

    const { release } = useUnsavedChanges(flatten(data), flatten(initial.current), !processing);

    const isInternOrPkl = INTERN_TYPES.includes(data.employment_type);
    const isContract = data.employment_type !== 'pkwtt';

    // Department depends on Operating Unit; Position depends on Department.
    const filteredDepartments = departments.filter((d) => String(d.company_id) === data.company_id);
    const filteredPositions = positions.filter((p) => String(p.department_id) === data.department_id);

    function setInternship(field, value) {
        setData('internship', { ...data.internship, [field]: value });
    }

    function submit(e) {
        e.preventDefault();
        release();

        if (isEdit) {
            // Laravel requires POST + _method=PUT spoofing for multipart/form-data
            // (a native PUT request body can't carry file uploads reliably).
            transform((payload) => ({ ...payload, _method: 'put' }));
            post(route('employees.update', employee.id), { forceFormData: true });
        } else {
            post(route('employees.store'), { forceFormData: true });
        }
    }

    function cancel() {
        release();
        router.visit(route('employees.index'));
    }

    return (
        <AuthenticatedLayout>
            <Head title={isEdit ? 'Edit Employee' : 'Add Employee'} />

            <Link href={route('employees.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Employees
            </Link>

            <h1 className="mb-1 text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-100">
                {isEdit ? 'Edit Employee' : 'Add Employee'}
            </h1>
            <p className="mb-6 text-sm text-graphite-500 dark:text-slate-400">
                This record is what permits, PPE issues, competency and man-hour entries are attributed to.
            </p>

            <form onSubmit={submit} className="max-w-3xl space-y-6">
                <ErrorSummary errors={errors} labels={ERROR_LABELS} />

                <FormSection
                    title="Identity"
                    description="How this person is identified on documents and in reports."
                    icon={ContactRound}
                >
                    <FormField label="Employee ID" name="employee_id" required error={errors.employee_id}>
                        {(control) => (
                            <Input
                                {...control}
                                value={data.employee_id}
                                onChange={(e) => setData('employee_id', e.target.value)}
                                placeholder="EMP-0001"
                            />
                        )}
                    </FormField>

                    <FormField
                        label="Full name"
                        name="full_name"
                        required
                        error={errors.full_name}
                    >
                        {(control) => (
                            <Input {...control} value={data.full_name} onChange={(e) => setData('full_name', e.target.value)} />
                        )}
                    </FormField>

                    <FormField
                        label="NIK"
                        name="nik"
                        error={errors.nik}
                        hint="National ID. Needed for BPJS and some client site inductions."
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={data.nik}
                                onChange={(e) => setData('nik', e.target.value)}
                                maxLength={20}
                            />
                        )}
                    </FormField>

                    <FormField label="Phone" name="phone" error={errors.phone}>
                        {(control) => (
                            <Input
                                {...control}
                                type="tel"
                                inputMode="tel"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                placeholder="08xxxxxxxxxx"
                            />
                        )}
                    </FormField>

                    <div className="sm:col-span-2">
                        <ImageUploadField
                            label="Photo"
                            existingUrl={employee?.photo_url}
                            file={data.photo}
                            onChange={(file) => setData('photo', file)}
                            shape="circle"
                            error={errors.photo}
                        />
                    </div>
                </FormSection>

                <FormSection
                    title="Placement"
                    description="Where in the organization does this person work? Each choice narrows the next."
                    icon={Building2}
                >
                    <FormField label="Operating Unit" name="company_id" required error={errors.company_id}>
                        {(control) => (
                            <SearchableSelect
                                {...control}
                                value={data.company_id ?? ''}
                                onChange={(v) => setData((prev) => ({ ...prev, company_id: v, department_id: undefined, position_id: undefined }))}
                                options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                placeholder="Select operating unit"
                            />
                        )}
                    </FormField>

                    <FormField
                        label="Department"
                        name="department_id"
                        required
                        error={errors.department_id}
                        hint={data.company_id ? undefined : 'Choose an Operating Unit first — departments belong to one.'}
                    >
                        {(control) => (
                            <SearchableSelect
                                {...control}
                                value={data.department_id ?? ''}
                                onChange={(v) => setData((prev) => ({ ...prev, department_id: v, position_id: undefined }))}
                                options={filteredDepartments.map((d) => ({ value: d.id, label: d.name }))}
                                placeholder="Select department"
                                disabled={!data.company_id}
                            />
                        )}
                    </FormField>

                    <FormField
                        label="Position"
                        name="position_id"
                        error={errors.position_id}
                        hint={data.department_id ? undefined : 'Choose a Department first — positions belong to one.'}
                        className="sm:col-span-2"
                    >
                        {(control) => (
                            <SearchableSelect
                                {...control}
                                value={data.position_id ?? ''}
                                onChange={(v) => setData('position_id', v)}
                                options={filteredPositions.map((p) => ({ value: p.id, label: p.name }))}
                                placeholder="Select position"
                                disabled={!data.department_id}
                            />
                        )}
                    </FormField>
                </FormSection>

                <FormSection
                    title="Employment"
                    description="Status and terms. Contract dates appear for any type other than permanent."
                    icon={BriefcaseBusiness}
                >
                    <FormField label="Status" name="status" required error={errors.status}>
                        {(control) => (
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger {...control}><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="inactive">Inactive</SelectItem>
                                    <SelectItem value="resigned">Resigned</SelectItem>
                                </SelectContent>
                            </Select>
                        )}
                    </FormField>

                    <FormField label="Workforce type" name="employment_type" required error={errors.employment_type}>
                        {(control) => (
                            <Select value={data.employment_type} onValueChange={(v) => setData('employment_type', v)}>
                                <SelectTrigger {...control}><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {(employmentTypes ?? []).map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        )}
                    </FormField>

                    <FormField label="Join date" name="join_date" error={errors.join_date}>
                        {(control) => (
                            <Input {...control} type="date" value={data.join_date} onChange={(e) => setData('join_date', e.target.value)} />
                        )}
                    </FormField>

                    {isContract && (
                        <>
                            <FormField label="Contract start" name="contract_start_date" error={errors.contract_start_date}>
                                {(control) => (
                                    <Input {...control} type="date" value={data.contract_start_date} onChange={(e) => setData('contract_start_date', e.target.value)} />
                                )}
                            </FormField>
                            <FormField
                                label="Contract end"
                                name="contract_end_date"
                                error={errors.contract_end_date}
                                hint="Drives the expiring-contract warning on the HR dashboard."
                            >
                                {(control) => (
                                    <Input {...control} type="date" value={data.contract_end_date} onChange={(e) => setData('contract_end_date', e.target.value)} />
                                )}
                            </FormField>
                        </>
                    )}
                </FormSection>

                {/* Intern/PKL detail section -- only shown (and only ever populated) when the
                    workforce type is intern or PKL. Not a duplicate employee record: this data
                    lives in App\Models\EmployeeInternship, a one-to-one detail extension of this
                    same Employee (see that model's own doc comment). */}
                {isInternOrPkl && (
                    <FormSection
                        title="Intern / PKL placement"
                        description="Placement information specific to internship and PKL workers."
                        icon={GraduationCap}
                    >
                        <FormField label="Institution" name="internship.institution" error={errors['internship.institution']}>
                            {(control) => (
                                <Input {...control} value={data.internship.institution} onChange={(e) => setInternship('institution', e.target.value)} placeholder="School / university" />
                            )}
                        </FormField>

                        <FormField label="Program / field of study" name="internship.program" error={errors['internship.program']}>
                            {(control) => (
                                <Input {...control} value={data.internship.program} onChange={(e) => setInternship('program', e.target.value)} />
                            )}
                        </FormField>

                        <FormField label="Mentor / supervisor" name="internship.mentor_name" error={errors['internship.mentor_name']}>
                            {(control) => (
                                <Input {...control} value={data.internship.mentor_name} onChange={(e) => setInternship('mentor_name', e.target.value)} />
                            )}
                        </FormField>

                        <FormField label="Agreement / reference no." name="internship.agreement_number" error={errors['internship.agreement_number']}>
                            {(control) => (
                                <Input {...control} value={data.internship.agreement_number} onChange={(e) => setInternship('agreement_number', e.target.value)} />
                            )}
                        </FormField>

                        <FormField label="Placement start" name="internship.start_date" error={errors['internship.start_date']}>
                            {(control) => (
                                <Input {...control} type="date" value={data.internship.start_date} onChange={(e) => setInternship('start_date', e.target.value)} />
                            )}
                        </FormField>

                        <FormField label="Placement end" name="internship.end_date" error={errors['internship.end_date']}>
                            {(control) => (
                                <Input {...control} type="date" value={data.internship.end_date} onChange={(e) => setInternship('end_date', e.target.value)} />
                            )}
                        </FormField>

                        <FormField label="Work location" name="internship.work_location" error={errors['internship.work_location']}>
                            {(control) => (
                                <Input {...control} value={data.internship.work_location} onChange={(e) => setInternship('work_location', e.target.value)} />
                            )}
                        </FormField>

                        <FormField label="Insurance / BPJS coverage" name="internship.insurance_coverage" error={errors['internship.insurance_coverage']}>
                            {(control) => (
                                <Input {...control} value={data.internship.insurance_coverage} onChange={(e) => setInternship('insurance_coverage', e.target.value)} placeholder="e.g. Institution insurance, BPJS" />
                            )}
                        </FormField>

                        <FormField label="Completion status" name="internship.completion_status" error={errors['internship.completion_status']}>
                            {(control) => (
                                <Select value={data.internship.completion_status} onValueChange={(v) => setInternship('completion_status', v)}>
                                    <SelectTrigger {...control}><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="ongoing">Ongoing</SelectItem>
                                        <SelectItem value="completed">Completed</SelectItem>
                                        <SelectItem value="terminated">Terminated</SelectItem>
                                    </SelectContent>
                                </Select>
                            )}
                        </FormField>

                        <div className="flex items-center sm:col-span-1">
                            <label className="flex cursor-pointer items-center gap-2 text-[13px] text-graphite-700 dark:text-slate-300">
                                <Checkbox
                                    checked={data.internship.induction_completed}
                                    onCheckedChange={(v) => setInternship('induction_completed', !!v)}
                                />
                                Safety induction completed
                            </label>
                        </div>

                        <FormField
                            label="Evaluation notes"
                            name="internship.evaluation"
                            error={errors['internship.evaluation']}
                            className="sm:col-span-2"
                        >
                            {(control) => (
                                <Textarea {...control} value={data.internship.evaluation} onChange={(e) => setInternship('evaluation', e.target.value)} rows={3} />
                            )}
                        </FormField>
                    </FormSection>
                )}

                {/* ONE action bar, outside every conditional. The previous
                    version rendered this block twice -- once for the normal
                    case and again inside the intern branch -- so the two
                    could drift apart. */}
                <FormActions
                    submitLabel={isEdit ? 'Save changes' : 'Create employee'}
                    onCancel={cancel}
                    processing={processing}
                />
            </form>
        </AuthenticatedLayout>
    );
}

/**
 * The dirty check compares one level deep, so the nested `internship`
 * object is flattened to `internship.institution` etc. Without this,
 * every keystroke inside that section would compare two different object
 * references and report the form dirty even after being reset.
 */
function flatten({ internship, ...rest }) {
    const out = { ...rest };

    for (const [key, value] of Object.entries(internship ?? {})) {
        out[`internship.${key}`] = value;
    }

    return out;
}
