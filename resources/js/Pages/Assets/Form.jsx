import { useRef } from 'react';
import { Head, useForm, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import EmployeeSelector from '@/Components/shared/EmployeeSelector';
import { FormSection, FormField, FormActions, ErrorSummary, SearchableSelect } from '@/Components/shared/form';
import { useUnsavedChanges } from '@/lib/useUnsavedChanges';
import { ArrowLeft, Tag, MapPin, Receipt } from 'lucide-react';

/**
 * v2.65.0 -- the SIMPLE master data case, and a real defect found while
 * converting it.
 *
 * THIRTEEN OF ITS FIFTEEN FIELDS COULD NOT SHOW A VALIDATION ERROR. Only
 * `name` and `company_id` rendered one; every other field omitted the
 * `{errors.x && ...}` line entirely. So a server rejection on serial
 * number, purchase date, vendor or attachment produced a form that
 * silently refused to save with nothing on screen to explain why. Using
 * `FormField` everywhere fixes that by construction -- a field cannot be
 * declared without its error slot.
 *
 * It also had a `lg:grid-cols-3` row (serial / brand / model). Three
 * columns on a laptop produces fields narrower than the values they hold;
 * the system's two-column rhythm replaces it.
 *
 * Responsible Employee was a raw `<select>` over the entire employee
 * directory -- exactly what `EmployeeSelector` was built for in v2.38.0,
 * and one of the seven controllers that pass mentions. It now searches
 * server-side instead of rendering thousands of options.
 *
 * Grouping is by QUESTION, not by column type: what is it, where is it
 * and who has it, and what did we buy it under. `assetCode` moves to the
 * page header where it belongs -- it identifies the record, it is not a
 * section title.
 */

const ERROR_LABELS = {
    company_id: 'Operating Unit',
    responsible_employee_id: 'Responsible employee',
    purchase_order_id: 'Source PO',
    vendor_id: 'Vendor',
};

export default function AssetForm({ companies, vendors, purchaseOrders, categories, assetCode, prefill }) {
    const initial = useRef(null);

    const { data, setData, post, processing, errors } = useForm({
        company_id: prefill?.company_id ? String(prefill.company_id) : (companies[0]?.id ? String(companies[0].id) : ''),
        name: '', category: categories[0] || '', serial_number: '', brand: '', model: '',
        purchase_date: prefill?.purchase_date || '', vendor_id: prefill?.vendor_id ? String(prefill.vendor_id) : '',
        purchase_order_id: prefill?.purchase_order_id ? String(prefill.purchase_order_id) : '',
        location: '', responsible_employee_id: '', notes: '', attachment: null,
    });

    if (initial.current === null) initial.current = { ...data };

    const { release } = useUnsavedChanges(data, initial.current, !processing);

    function submit(e) {
        e.preventDefault();
        release();
        post(route('assets.store'), { forceFormData: true });
    }

    function cancel() {
        release();
        router.visit(route('assets.index'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Register Asset" />

            <div className="mb-4">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={route('assets.index')}><ArrowLeft className="h-4 w-4" /> Back</Link>
                </Button>
            </div>

            <h1 className="mb-1 text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-100">
                Register Asset
            </h1>
            <p className="mb-6 text-sm text-graphite-500 dark:text-slate-400">
                Will be registered as <span className="font-mono font-medium text-navy-800 dark:text-slate-200">{assetCode}</span>.
                Maintenance requests and work orders are raised against this record.
            </p>

            <form onSubmit={submit} className="max-w-3xl space-y-6">
                <ErrorSummary errors={errors} labels={ERROR_LABELS} />

                <FormSection
                    title="What is it"
                    description="How this asset is identified on the register and on work orders."
                    icon={Tag}
                >
                    <FormField label="Name" name="name" required error={errors.name}>
                        {(control) => (
                            <Input {...control} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Hydraulic Press 100T" />
                        )}
                    </FormField>

                    <FormField label="Category" name="category" error={errors.category}>
                        {(control) => (
                            <Select value={data.category} onValueChange={(v) => setData('category', v)}>
                                <SelectTrigger {...control}><SelectValue /></SelectTrigger>
                                <SelectContent>{categories.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}</SelectContent>
                            </Select>
                        )}
                    </FormField>

                    <FormField label="Serial number" name="serial_number" error={errors.serial_number}>
                        {(control) => (
                            <Input {...control} value={data.serial_number} onChange={(e) => setData('serial_number', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Brand" name="brand" error={errors.brand}>
                        {(control) => (
                            <Input {...control} value={data.brand} onChange={(e) => setData('brand', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Model" name="model" error={errors.model}>
                        {(control) => (
                            <Input {...control} value={data.model} onChange={(e) => setData('model', e.target.value)} />
                        )}
                    </FormField>
                </FormSection>

                <FormSection
                    title="Where it is, and who has it"
                    description="Which Operating Unit owns the asset, and who is accountable for it."
                    icon={MapPin}
                >
                    <FormField label="Operating Unit" name="company_id" required error={errors.company_id}>
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

                    <FormField label="Location" name="location" error={errors.location} hint="Where it physically sits — dock, workshop, store.">
                        {(control) => (
                            <Input {...control} value={data.location} onChange={(e) => setData('location', e.target.value)} />
                        )}
                    </FormField>

                    <FormField
                        label="Responsible employee"
                        name="responsible_employee_id"
                        error={errors.responsible_employee_id}
                        className="sm:col-span-2"
                    >
                        <EmployeeSelector
                            value={data.responsible_employee_id}
                            onChange={(v) => setData('responsible_employee_id', v)}
                            placeholder="Search by name or employee ID…"
                        />
                    </FormField>
                </FormSection>

                <FormSection
                    title="Purchase record"
                    description="Optional. Links the asset back to what it was bought under."
                    icon={Receipt}
                >
                    <FormField label="Purchase date" name="purchase_date" error={errors.purchase_date}>
                        {(control) => (
                            <Input {...control} type="date" value={data.purchase_date} onChange={(e) => setData('purchase_date', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Vendor" name="vendor_id" error={errors.vendor_id}>
                        {(control) => (
                            <SearchableSelect
                                {...control}
                                value={data.vendor_id}
                                onChange={(v) => setData('vendor_id', v)}
                                options={vendors.map((v) => ({ value: v.id, label: v.name }))}
                                placeholder="None"
                                clearable
                            />
                        )}
                    </FormField>

                    <FormField label="Source PO" name="purchase_order_id" error={errors.purchase_order_id}>
                        {(control) => (
                            <SearchableSelect
                                {...control}
                                value={data.purchase_order_id}
                                onChange={(v) => setData('purchase_order_id', v)}
                                options={purchaseOrders.map((p) => ({ value: p.id, label: p.po_number }))}
                                placeholder="None"
                                clearable
                            />
                        )}
                    </FormField>

                    <FormField
                        label="Attachment"
                        name="attachment"
                        error={errors.attachment}
                        hint={data.attachment ? data.attachment.name : 'Manual, certificate or purchase document.'}
                    >
                        {(control) => (
                            <Input
                                {...control}
                                type="file"
                                onChange={(e) => setData('attachment', e.target.files[0] ?? null)}
                                className="file:mr-3 file:rounded file:bg-graphite-100 file:px-2 file:py-1 file:text-xs"
                            />
                        )}
                    </FormField>

                    <FormField label="Notes" name="notes" error={errors.notes} className="sm:col-span-2">
                        {(control) => (
                            <Textarea {...control} value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />
                        )}
                    </FormField>
                </FormSection>

                <FormActions
                    submitLabel="Register asset"
                    onCancel={cancel}
                    processing={processing}
                    note="The asset becomes available to Maintenance Requests and Work Orders immediately."
                />
            </form>
        </AuthenticatedLayout>
    );
}
