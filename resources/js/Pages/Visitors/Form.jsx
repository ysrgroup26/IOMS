import { useRef } from 'react';
import { Head, useForm, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import EmployeeSelector from '@/Components/shared/EmployeeSelector';
import { FormSection, FormField, FormActions, ErrorSummary } from '@/Components/shared/form';
import { useUnsavedChanges } from '@/lib/useUnsavedChanges';
import { ArrowLeft, UserCheck, Contact } from 'lucide-react';

/**
 * v2.66.0 -- master data rollout.
 *
 * THE HOST PICKER WAS A RAW SELECT over the whole employee directory --
 * the third page found doing this, after Assets and the seven named in
 * EmployeeSelector's own v2.38.0 note. It now searches on demand, and the
 * controller no longer preloads the directory to feed it.
 *
 * That matters more here than elsewhere: visitor registration happens at
 * a gatehouse, often on a phone, with somebody waiting. Scrolling a
 * two-thousand-name dropdown to find the host is the worst possible
 * version of this task; typing three letters of their name is the point.
 *
 * The required set is small and specific -- name, host and visit date --
 * and stating it lets a guard register someone in three fields and fill
 * the rest in later.
 */

const ERROR_LABELS = {
    host_employee_id: 'Host employee',
    visitor_company: 'Visitor company',
    visit_date: 'Visit date',
    contact_phone: 'Phone',
    contact_email: 'Email',
};

export default function VisitorForm({ visitorNumber }) {
    const initial = useRef(null);

    const { data, setData, post, processing, errors } = useForm({
        name: '', visitor_company: '', purpose: '', host_employee_id: '',
        visit_date: new Date().toISOString().slice(0, 10), contact_phone: '', contact_email: '', notes: '',
    });

    if (initial.current === null) initial.current = { ...data };

    const { release } = useUnsavedChanges(data, initial.current, !processing);

    function submit(e) {
        e.preventDefault();
        release();
        post(route('visitors.store'));
    }

    function cancel() {
        release();
        router.visit(route('visitors.index'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Register Visitor" />

            <div className="mb-4">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={route('visitors.index')}><ArrowLeft className="h-4 w-4" /> Back</Link>
                </Button>
            </div>

            <h1 className="mb-1 text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-100">
                Register Visitor
            </h1>
            <p className="mb-6 text-sm text-graphite-500 dark:text-slate-400">
                Will be registered as <span className="font-mono font-medium text-navy-800 dark:text-slate-200">{visitorNumber}</span>.
            </p>

            <form onSubmit={submit} className="max-w-3xl space-y-6">
                <ErrorSummary errors={errors} labels={ERROR_LABELS} />

                <FormSection
                    title="Who is visiting"
                    description="The minimum a gatehouse needs to let someone on site."
                    icon={UserCheck}
                >
                    <FormField label="Visitor name" name="name" required error={errors.name}>
                        {(control) => (
                            <Input {...control} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Visitor's company" name="visitor_company" error={errors.visitor_company}>
                        {(control) => (
                            <Input {...control} value={data.visitor_company} onChange={(e) => setData('visitor_company', e.target.value)} placeholder="e.g. PT XYZ" />
                        )}
                    </FormField>

                    <FormField
                        label="Host employee"
                        name="host_employee_id"
                        required
                        error={errors.host_employee_id}
                        hint="Search by name or employee ID."
                    >
                        <EmployeeSelector
                            value={data.host_employee_id}
                            onChange={(v) => setData('host_employee_id', v)}
                            placeholder="Search by name or employee ID…"
                        />
                    </FormField>

                    <FormField label="Visit date" name="visit_date" required error={errors.visit_date}>
                        {(control) => (
                            <Input {...control} type="date" value={data.visit_date} onChange={(e) => setData('visit_date', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Purpose" name="purpose" error={errors.purpose} className="sm:col-span-2">
                        {(control) => (
                            <Input {...control} value={data.purpose} onChange={(e) => setData('purpose', e.target.value)} placeholder="e.g. Equipment inspection, vendor meeting" />
                        )}
                    </FormField>
                </FormSection>

                <FormSection title="Contact & notes" description="How to reach the visitor, and anything security should know." icon={Contact}>
                    <FormField label="Phone" name="contact_phone" error={errors.contact_phone}>
                        {(control) => (
                            <Input {...control} type="tel" inputMode="tel" value={data.contact_phone} onChange={(e) => setData('contact_phone', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Email" name="contact_email" error={errors.contact_email}>
                        {(control) => (
                            <Input {...control} type="email" value={data.contact_email} onChange={(e) => setData('contact_email', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Notes" name="notes" error={errors.notes} className="sm:col-span-2">
                        {(control) => (
                            <Textarea {...control} value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />
                        )}
                    </FormField>
                </FormSection>

                <FormActions
                    submitLabel="Register visitor"
                    onCancel={cancel}
                    processing={processing}
                />
            </form>
        </AuthenticatedLayout>
    );
}
