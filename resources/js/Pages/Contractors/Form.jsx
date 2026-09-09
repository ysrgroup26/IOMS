import { useRef } from 'react';
import { Head, useForm, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { FormSection, FormField, FormActions, ErrorSummary, SearchableSelect } from '@/Components/shared/form';
import { useUnsavedChanges } from '@/lib/useUnsavedChanges';
import { ArrowLeft, HardHat, Contact } from 'lucide-react';

/**
 * v2.66.0 -- master data rollout, and a terminology fix.
 *
 * THE OPERATING UNIT FIELD WAS LABELLED "Company". IOMS renamed Company
 * to Operating Unit for users in v2.54.0 precisely because "company" made
 * people read it as a separate legal entity or a separate subscription.
 * On a CONTRACTOR form the word is worse than ambiguous: the page already
 * has a field called "Company Name" meaning the contractor's own firm, so
 * two adjacent controls both said Company and meant opposite things --
 * whose register this belongs to, and who the contractor is. Renamed to
 * Operating Unit and moved up beside the identity fields, where ownership
 * belongs.
 *
 * Otherwise a small form that needed the contract, not a redesign.
 */

const ERROR_LABELS = {
    company_id: 'Operating Unit',
    company_name: 'Contractor company name',
    pic_name: 'PIC name',
    pic_contact: 'PIC contact',
};

export default function ContractorForm({ companies, contractorCode }) {
    const initial = useRef(null);

    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        company_name: '', address: '', pic_name: '', pic_contact: '', notes: '',
    });

    if (initial.current === null) initial.current = { ...data };

    const { release } = useUnsavedChanges(data, initial.current, !processing);

    function submit(e) {
        e.preventDefault();
        release();
        post(route('contractors.store'));
    }

    function cancel() {
        release();
        router.visit(route('contractors.index'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Register Contractor" />

            <div className="mb-4">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={route('contractors.index')}><ArrowLeft className="h-4 w-4" /> Back</Link>
                </Button>
            </div>

            <h1 className="mb-1 text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-100">
                Register Contractor
            </h1>
            <p className="mb-6 text-sm text-graphite-500 dark:text-slate-400">
                Will be registered as <span className="font-mono font-medium text-navy-800 dark:text-slate-200">{contractorCode}</span>.
                Contractor workers and their documents are held against this record.
            </p>

            <form onSubmit={submit} className="max-w-3xl space-y-6">
                <ErrorSummary errors={errors} labels={ERROR_LABELS} />

                <FormSection
                    title="The contractor"
                    description="Which firm this is, and which Operating Unit's register they belong to."
                    icon={HardHat}
                >
                    <FormField label="Company name" name="company_name" required error={errors.company_name}>
                        {(control) => (
                            <Input {...control} value={data.company_name} onChange={(e) => setData('company_name', e.target.value)} placeholder="e.g. PT ABC" />
                        )}
                    </FormField>

                    <FormField
                        label="Operating Unit"
                        name="company_id"
                        required
                        error={errors.company_id}
                        hint="Yours — not the contractor's."
                    >
                        {(control) => (
                            <SearchableSelect
                                {...control}
                                value={data.company_id}
                                onChange={(v) => setData('company_id', v)}
                                options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                placeholder="Select operating unit"
                            />
                        )}
                    </FormField>

                    <FormField label="Address" name="address" error={errors.address} className="sm:col-span-2">
                        {(control) => (
                            <Textarea {...control} value={data.address} onChange={(e) => setData('address', e.target.value)} rows={2} />
                        )}
                    </FormField>
                </FormSection>

                <FormSection title="Contact & notes" description="Who to reach, and anything site security should know." icon={Contact}>
                    <FormField label="PIC name" name="pic_name" error={errors.pic_name}>
                        {(control) => <Input {...control} value={data.pic_name} onChange={(e) => setData('pic_name', e.target.value)} />}
                    </FormField>

                    <FormField label="PIC contact" name="pic_contact" error={errors.pic_contact}>
                        {(control) => <Input {...control} value={data.pic_contact} onChange={(e) => setData('pic_contact', e.target.value)} placeholder="Phone or email" />}
                    </FormField>

                    <FormField label="Notes" name="notes" error={errors.notes} className="sm:col-span-2">
                        {(control) => <Textarea {...control} value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />}
                    </FormField>
                </FormSection>

                <FormActions
                    submitLabel="Register contractor"
                    onCancel={cancel}
                    processing={processing}
                    note="Registered contractors start as unapproved; approval is recorded separately."
                />
            </form>
        </AuthenticatedLayout>
    );
}
