import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import ImageUploadField from '@/Components/shared/ImageUploadField';
import { ArrowLeft, Loader2 } from 'lucide-react';

export default function EmployeeForm({ employee, companies, departments, positions }) {
    const isEdit = !!employee;

    const { data, setData, post, processing, errors, transform } = useForm({
        employee_id: employee?.employee_id || '',
        full_name: employee?.full_name || '',
        company_id: employee?.company_id ? String(employee.company_id) : undefined,
        department_id: employee?.department_id ? String(employee.department_id) : undefined,
        position_id: employee?.position_id ? String(employee.position_id) : undefined,
        status: employee?.status || 'active',
        join_date: employee?.join_date?.slice(0, 10) || '',
        phone: employee?.phone || '',
        photo: null,
    });

    // Department depends on Company; Position depends on Department --
    // same cascading-select pattern used elsewhere in this form.
    const filteredDepartments = departments.filter((d) => String(d.company_id) === data.company_id);
    const filteredPositions = positions.filter((p) => String(p.department_id) === data.department_id);

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

            <h1 className="mb-6 text-lg font-bold tracking-tight text-graphite-900">
                {isEdit ? 'Edit Employee' : 'Add Employee'}
            </h1>

            <Card className="max-w-2xl">
                <CardHeader><CardTitle>Employee Information</CardTitle></CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Employee ID" error={errors.employee_id}>
                                <Input value={data.employee_id} onChange={(e) => setData('employee_id', e.target.value)} placeholder="EMP-0001" />
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

                        <Field label="Full Name" error={errors.full_name}>
                            <Input value={data.full_name} onChange={(e) => setData('full_name', e.target.value)} />
                        </Field>

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
                            <Field label="Join Date" error={errors.join_date}>
                                <Input type="date" value={data.join_date} onChange={(e) => setData('join_date', e.target.value)} />
                            </Field>
                        </div>

                        <Field label="Phone" error={errors.phone}>
                            <Input value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="08xxxxxxxxxx" />
                        </Field>

                        <ImageUploadField
                            label="Photo (optional)"
                            existingUrl={employee?.photo_url}
                            file={data.photo}
                            onChange={(file) => setData('photo', file)}
                            shape="circle"
                            error={errors.photo}
                        />

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline" asChild>
                                <Link href={route('employees.index')}>Cancel</Link>
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                                {isEdit ? 'Save Changes' : 'Create Employee'}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
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
