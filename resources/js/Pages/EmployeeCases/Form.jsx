import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import EmployeeSelector from '@/Components/shared/EmployeeSelector';
import { FormSection, FormField, FormActions, ErrorSummary } from '@/Components/shared/form';
import { useUnsavedChanges } from '@/lib/useUnsavedChanges';
import { ArrowLeft, ShieldAlert, FileText } from 'lucide-react';

/**
 * v2.69.0 -- opening or editing an Employee Case.
 *
 * A page form, so it takes the full Form Experience System contract
 * (FormSection + FormField + FormActions + ErrorSummary) per the rule in
 * Components/shared/form/index.js.
 *
 * The employee is picked with EmployeeSelector rather than a `<select>`
 * over the directory -- the tenth call site for that component and the
 * same reasoning as Visitors: the controller ships no `employees` prop at
 * all, so opening this page does not send the whole workforce to the
 * browser.
 *
 * WHAT THIS FORM DELIBERATELY CANNOT DO: issue a disciplinary action.
 * That lives on the case's own page, after review, because an action is a
 * decision taken ON a case rather than a field of it -- and putting the
 * two on one screen would let somebody sanction a person in the same
 * keystroke that records the allegation.
 */
function humanize(value) {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const FIELD_LABELS = {
    company_id: 'Operating Unit',
    employee_id: 'Employee',
    category: 'Category',
    severity: 'Severity',
    title: 'Subject',
    details: 'What happened',
    reported_at: 'Date raised',
    assigned_to: 'Handler',
};

export default function EmployeeCaseForm({ employeeCase, caseNumber, companies, handlers = [], options }) {
    const editing = !!employeeCase;

    const { data, setData, post, put, processing, errors, isDirty } = useForm({
        company_id: employeeCase?.company_id ? String(employeeCase.company_id) : (companies[0]?.id ? String(companies[0].id) : ''),
        employee_id: employeeCase?.employee_id ? String(employeeCase.employee_id) : '',
        category: employeeCase?.category || 'conduct',
        severity: employeeCase?.severity || 'low',
        title: employeeCase?.title || '',
        details: employeeCase?.details || '',
        reported_at: employeeCase?.reported_at ? String(employeeCase.reported_at).slice(0, 10) : new Date().toISOString().slice(0, 10),
        assigned_to: employeeCase?.assigned_to ? String(employeeCase.assigned_to) : '',
    });

    useUnsavedChanges(isDirty && !processing);

    function submit(e) {
        e.preventDefault();
        if (editing) {
            put(route('employee-cases.update', employeeCase.id));
        } else {
            post(route('employee-cases.store'));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title={editing ? `Edit ${employeeCase.case_number}` : 'Open Employee Case'} />
            <PageHeader
                title={editing ? `Edit ${employeeCase.case_number}` : 'Open Employee Case'}
                subtitle={editing ? 'Perbarui rincian kasus yang masih berjalan.' : `Nomor kasus ${caseNumber} akan dibuat saat disimpan.`}
            >
                <Button variant="outline" asChild>
                    <Link href={editing ? route('employee-cases.show', employeeCase.id) : route('employee-cases.index')}>
                        <ArrowLeft className="h-4 w-4" /> Back
                    </Link>
                </Button>
            </PageHeader>

            <form onSubmit={submit} className="space-y-4">
                <ErrorSummary errors={errors} labels={FIELD_LABELS} />

                {/*
                    Ownership and identity first -- v2.66.0's principle:
                    the Operating Unit decides whose register this is
                    written into and therefore who will ever see it again,
                    so it is never buried further down.
                */}
                <FormSection
                    title="Who and where"
                    description="Kasus dicatat pada satu Operating Unit dan satu karyawan."
                    icon={ShieldAlert}
                    variant="card"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label="Operating Unit" name="company_id" required error={errors.company_id}>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger id="field-company_id"><SelectValue placeholder="Select operating unit" /></SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </FormField>

                        <FormField
                            label="Employee"
                            name="employee_id"
                            required
                            error={errors.employee_id}
                            hint="Cari berdasarkan nama atau NIK."
                        >
                            <EmployeeSelector
                                value={data.employee_id}
                                onChange={(v) => setData('employee_id', v)}
                                disabled={editing}
                            />
                        </FormField>
                    </div>

                    {editing && (
                        <p className="mt-2 text-xs text-graphite-500">
                            Karyawan tidak dapat diubah setelah kasus dibuka — buka kasus baru bila orangnya berbeda.
                        </p>
                    )}
                </FormSection>

                <FormSection
                    title="The concern"
                    description="Ringkas dan faktual. Rincian ini menjadi dasar peninjauan."
                    icon={FileText}
                    variant="card"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label="Category" name="category" required error={errors.category}>
                            <Select value={data.category} onValueChange={(v) => setData('category', v)}>
                                <SelectTrigger id="field-category"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {options.categories.map((c) => <SelectItem key={c} value={c}>{humanize(c)}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </FormField>

                        <FormField label="Severity" name="severity" required error={errors.severity}>
                            <Select value={data.severity} onValueChange={(v) => setData('severity', v)}>
                                <SelectTrigger id="field-severity"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {options.severities.map((s) => <SelectItem key={s} value={s}>{humanize(s)}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </FormField>
                    </div>

                    <FormField label="Subject" name="title" required error={errors.title} hint="Satu baris yang menjelaskan inti persoalan.">
                        <Input id="field-title" value={data.title} onChange={(e) => setData('title', e.target.value)} maxLength={255} />
                    </FormField>

                    <FormField label="What happened" name="details" error={errors.details}>
                        <Textarea id="field-details" rows={5} value={data.details} onChange={(e) => setData('details', e.target.value)} maxLength={5000} />
                    </FormField>
                </FormSection>

                <FormSection title="Handling" description="Siapa yang menindaklanjuti, dan sejak kapan." variant="card">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label="Date raised" name="reported_at" required error={errors.reported_at}>
                            <Input id="field-reported_at" type="date" value={data.reported_at} onChange={(e) => setData('reported_at', e.target.value)} />
                        </FormField>

                        <FormField
                            label="Handler"
                            name="assigned_to"
                            error={errors.assigned_to}
                            hint="Dapat diisi menyusul, tetapi kasus tanpa penanggung jawab mudah terlewat."
                        >
                            <Select value={data.assigned_to || 'none'} onValueChange={(v) => setData('assigned_to', v === 'none' ? '' : v)}>
                                <SelectTrigger id="field-assigned_to"><SelectValue placeholder="Select handler" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Unassigned</SelectItem>
                                    {handlers.map((u) => (
                                        <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                    </div>
                </FormSection>

                <FormActions
                    submitLabel={editing ? 'Save changes' : 'Open case'}
                    processing={processing}
                    cancelHref={editing ? route('employee-cases.show', employeeCase.id) : route('employee-cases.index')}
                    note={editing ? undefined : 'Kasus dibuka dengan status Open dan belum berisi tindakan apa pun.'}
                />
            </form>
        </AuthenticatedLayout>
    );
}
