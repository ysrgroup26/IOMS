import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import ImageUploadField from '@/Components/shared/ImageUploadField';
import { ArrowLeft, Loader2 } from 'lucide-react';

const INTERN_TYPES = ['intern', 'pkl'];

export default function EmployeeForm({ employee, companies, departments, positions, employmentTypes }) {
    const isEdit = !!employee;
    const internship = employee?.internship;

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

    const isInternOrPkl = INTERN_TYPES.includes(data.employment_type);

    // Department depends on Company; Position depends on Department --
    // same cascading-select pattern used elsewhere in this form.
    const filteredDepartments = departments.filter((d) => String(d.company_id) === data.company_id);
    const filteredPositions = positions.filter((p) => String(p.department_id) === data.department_id);

    function setInternship(field, value) {
        setData('internship', { ...data.internship, [field]: value });
    }

    function submit(e) {
        e.preventDefault();
        if (isEdit) {
            // Laravel requires POST + _method=PUT spoofing for multipart/form-data
            // (a native PUT request body can't carry file uploads reliably).
            transform((data) => ({ ...data, _method: 'put' }));
            post(route('employees.update', employee.id), { forceFormData: true });
        } else {
            post(route('employees.store'), { forceFormData: true });
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={isEdit ? 'Edit Employee' : 'Add Employee'} />

            <Link href={route('employees.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Employees
            </Link>

            <h1 className="mb-6 text-[22px] font-semibold tracking-tight text-graphite-900">
                {isEdit ? 'Edit Employee' : 'Add Employee'}
            </h1>

            <div className="max-w-2xl space-y-6">
                <Card>
                    <CardHeader><CardTitle>Employee Information</CardTitle></CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <Field label="Employee ID" error={errors.employee_id}>
                                    <Input value={data.employee_id} onChange={(e) => setData('employee_id', e.target.value)} placeholder="EMP-0001" />
                                </Field>
                                <Field label="NIK (optional)" error={errors.nik}>
                                    <Input value={data.nik} onChange={(e) => setData('nik', e.target.value)} placeholder="National ID number" maxLength={20} />
                                </Field>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <Field label="Full Name" error={errors.full_name}>
                                    <Input value={data.full_name} onChange={(e) => setData('full_name', e.target.value)} />
                                </Field>
                                <Field label="Company" error={errors.company_id}>
                                    <Select
                                        value={data.company_id}
                                        onValueChange={(v) => setData((prevData) => ({ ...prevData, company_id: v, department_id: undefined, position_id: undefined }))}
                                    >
                                        <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                        <SelectContent>
                                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </Field>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <Field label="Department" error={errors.department_id}>
                                    <Select
                                        value={data.department_id}
                                        onValueChange={(v) => setData((prevData) => ({ ...prevData, department_id: v, position_id: undefined }))}
                                        disabled={!data.company_id}
                                    >
                                        <SelectTrigger><SelectValue placeholder={data.company_id ? 'Select department' : 'Select company first'} /></SelectTrigger>
                                        <SelectContent>
                                            {filteredDepartments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </Field>
                                <Field label="Position" error={errors.position_id}>
                                    <Select value={data.position_id} onValueChange={(v) => setData('position_id', v)} disabled={!data.department_id}>
                                        <SelectTrigger><SelectValue placeholder="Select position" /></SelectTrigger>
                                        <SelectContent>
                                            {filteredPositions.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </Field>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <Field label="Status" error={errors.status}>
                                    <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="active">Active</SelectItem>
                                            <SelectItem value="inactive">Inactive</SelectItem>
                                            <SelectItem value="resigned">Resigned</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </Field>
                                <Field label="Workforce Type" error={errors.employment_type}>
                                    <Select value={data.employment_type} onValueChange={(v) => setData('employment_type', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {(employmentTypes ?? []).map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </Field>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <Field label="Join Date" error={errors.join_date}>
                                    <Input type="date" value={data.join_date} onChange={(e) => setData('join_date', e.target.value)} />
                                </Field>
                                <Field label="Phone" error={errors.phone}>
                                    <Input value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="08xxxxxxxxxx" />
                                </Field>
                            </div>

                            {data.employment_type !== 'pkwtt' && (
                                <div className="grid grid-cols-2 gap-4">
                                    <Field label="Contract Start" error={errors.contract_start_date}>
                                        <Input type="date" value={data.contract_start_date} onChange={(e) => setData('contract_start_date', e.target.value)} />
                                    </Field>
                                    <Field label="Contract End" error={errors.contract_end_date}>
                                        <Input type="date" value={data.contract_end_date} onChange={(e) => setData('contract_end_date', e.target.value)} />
                                    </Field>
                                </div>
                            )}

                            <ImageUploadField
                                label="Photo (optional)"
                                existingUrl={employee?.photo_url}
                                file={data.photo}
                                onChange={(file) => setData('photo', file)}
                                shape="circle"
                                error={errors.photo}
                            />

                            {!isInternOrPkl && (
                                <div className="flex justify-end gap-2 pt-2">
                                    <Button type="button" variant="outline" asChild>
                                        <Link href={route('employees.index')}>Cancel</Link>
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                                        {isEdit ? 'Save Changes' : 'Create Employee'}
                                    </Button>
                                </div>
                            )}

                            {/* Intern/PKL detail section -- only shown (and only ever populated) when the
                                workforce type is intern or PKL. Not a duplicate employee record: this data
                                lives in App\Models\EmployeeInternship, a one-to-one detail extension of this
                                same Employee (see that model's own doc comment). */}
                            {isInternOrPkl && (
                                <div className="space-y-4 border-t border-graphite-200 pt-4 dark:border-slate-700">
                                    <div>
                                        <h3 className="text-sm font-semibold text-graphite-800 dark:text-slate-100">Intern / PKL Details</h3>
                                        <p className="text-xs text-graphite-500">Placement information specific to internship/PKL workers.</p>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <Field label="Institution" error={errors['internship.institution']}>
                                            <Input value={data.internship.institution} onChange={(e) => setInternship('institution', e.target.value)} placeholder="School / university" />
                                        </Field>
                                        <Field label="Program / Field of Study" error={errors['internship.program']}>
                                            <Input value={data.internship.program} onChange={(e) => setInternship('program', e.target.value)} />
                                        </Field>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <Field label="Mentor / Supervisor" error={errors['internship.mentor_name']}>
                                            <Input value={data.internship.mentor_name} onChange={(e) => setInternship('mentor_name', e.target.value)} />
                                        </Field>
                                        <Field label="Agreement / Reference No." error={errors['internship.agreement_number']}>
                                            <Input value={data.internship.agreement_number} onChange={(e) => setInternship('agreement_number', e.target.value)} />
                                        </Field>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <Field label="Placement Start" error={errors['internship.start_date']}>
                                            <Input type="date" value={data.internship.start_date} onChange={(e) => setInternship('start_date', e.target.value)} />
                                        </Field>
                                        <Field label="Placement End" error={errors['internship.end_date']}>
                                            <Input type="date" value={data.internship.end_date} onChange={(e) => setInternship('end_date', e.target.value)} />
                                        </Field>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <Field label="Work Location" error={errors['internship.work_location']}>
                                            <Input value={data.internship.work_location} onChange={(e) => setInternship('work_location', e.target.value)} />
                                        </Field>
                                        <Field label="Insurance / BPJS Coverage" error={errors['internship.insurance_coverage']}>
                                            <Input value={data.internship.insurance_coverage} onChange={(e) => setInternship('insurance_coverage', e.target.value)} placeholder="e.g. Institution insurance, BPJS" />
                                        </Field>
                                    </div>

                                    <Field label="Completion Status" error={errors['internship.completion_status']}>
                                        <Select value={data.internship.completion_status} onValueChange={(v) => setInternship('completion_status', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="ongoing">Ongoing</SelectItem>
                                                <SelectItem value="completed">Completed</SelectItem>
                                                <SelectItem value="terminated">Terminated</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </Field>

                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={data.internship.induction_completed}
                                            onCheckedChange={(v) => setInternship('induction_completed', !!v)}
                                        />
                                        Safety induction completed
                                    </label>

                                    <Field label="Evaluation Notes (optional)" error={errors['internship.evaluation']}>
                                        <Textarea value={data.internship.evaluation} onChange={(e) => setInternship('evaluation', e.target.value)} rows={3} />
                                    </Field>

                                    <div className="flex justify-end gap-2 pt-2">
                                        <Button type="button" variant="outline" asChild>
                                            <Link href={route('employees.index')}>Cancel</Link>
                                        </Button>
                                        <Button type="submit" disabled={processing}>
                                            {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                                            {isEdit ? 'Save Changes' : 'Create Employee'}
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function Field({ label, error, children }) {
    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            {children}
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}
