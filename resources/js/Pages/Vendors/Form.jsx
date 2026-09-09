import { useRef } from 'react';
import { Head, useForm, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { FormSection, FormField, FormActions, ErrorSummary, SearchableSelect } from '@/Components/shared/form';
import { useUnsavedChanges } from '@/lib/useUnsavedChanges';
import { ArrowLeft, Building2, MapPin, Contact, Landmark, NotebookPen } from 'lucide-react';

/**
 * v2.66.0 -- master data rollout.
 *
 * OWNERSHIP MOVED TO THE TOP, which is the lesson this form taught the
 * rollout. Operating Unit was the second-to-last control on the page,
 * inside a card headed "Capability & Notes" -- so the single field that
 * decides WHO OWNS THE RECORD, and therefore who will ever see it again,
 * was filed under miscellaneous. Master data is created so that the rest
 * of IOMS can rely on it; the reader has to know whose register they are
 * writing into before they start filling it in, not after.
 *
 * Four cards became five plain sections. Twenty-three fields, only three
 * of which the server actually requires -- so the marking does a lot of
 * work here: it tells someone adding a vendor in a hurry that name, type
 * and Operating Unit are all that stands between them and a saved record.
 *
 * The `lg:grid-cols-3` city/province/country row is gone, per the
 * two-column rule established in v2.65.0.
 */

const ERROR_LABELS = {
    company_id: 'Operating Unit',
    pic_email: 'PIC email',
    npwp: 'NPWP',
    nib: 'NIB',
};

export default function VendorForm({ vendor, companies, types, vendorCode }) {
    const editing = !!vendor;
    const initial = useRef(null);

    const { data, setData, post, put, processing, errors } = useForm({
        company_id: editing ? String(vendor.company_id) : (companies[0]?.id ? String(companies[0].id) : ''),
        name: vendor?.name || '',
        type: vendor?.type || 'goods',
        legal_entity_name: vendor?.legal_entity_name || '',
        address: vendor?.address || '',
        city: vendor?.city || '',
        province: vendor?.province || '',
        country: vendor?.country || 'Indonesia',
        pic_name: vendor?.pic_name || '',
        pic_phone: vendor?.pic_phone || '',
        pic_email: vendor?.pic_email || '',
        website: vendor?.website || '',
        npwp: vendor?.npwp || '',
        nib: vendor?.nib || '',
        bank_name: vendor?.bank_name || '',
        bank_account_number: vendor?.bank_account_number || '',
        bank_account_holder: vendor?.bank_account_holder || '',
        payment_terms: vendor?.payment_terms || '',
        tax_info: vendor?.tax_info || '',
        category: vendor?.category || '',
        capability: vendor?.capability || '',
        is_active: vendor?.is_active ?? true,
        notes: vendor?.notes || '',
    });

    if (initial.current === null) initial.current = { ...data };

    const { release } = useUnsavedChanges(data, initial.current, !processing);

    function submit(e) {
        e.preventDefault();
        release();
        if (editing) { put(route('vendors.update', vendor.id)); } else { post(route('vendors.store')); }
    }

    function cancel() {
        release();
        router.visit(editing ? route('vendors.show', vendor.id) : route('vendors.index'));
    }

    return (
        <AuthenticatedLayout>
            <Head title={editing ? vendor.name : 'Add Vendor'} />

            <div className="mb-4">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={editing ? route('vendors.show', vendor.id) : route('vendors.index')}>
                        <ArrowLeft className="h-4 w-4" /> Back
                    </Link>
                </Button>
            </div>

            <h1 className="mb-1 text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-100">
                {editing ? 'Edit Vendor' : 'Add Vendor'}
            </h1>
            <p className="mb-6 text-sm text-graphite-500 dark:text-slate-400">
                <span className="font-mono font-medium text-navy-800 dark:text-slate-200">{editing ? vendor.vendor_code : vendorCode}</span>
                {' — '}
                purchase requisitions, RFQs and purchase orders are raised against this record.
            </p>

            <form onSubmit={submit} className="max-w-3xl space-y-6">
                <ErrorSummary errors={errors} labels={ERROR_LABELS} />

                <FormSection
                    title="Identity"
                    description="Who this vendor is, and which Operating Unit's register they belong to."
                    icon={Building2}
                >
                    <FormField label="Vendor name" name="name" required error={errors.name}>
                        {(control) => (
                            <Input {...control} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Type" name="type" required error={errors.type}>
                        {(control) => (
                            <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                <SelectTrigger {...control}><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        )}
                    </FormField>

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

                    <FormField label="Legal entity name" name="legal_entity_name" error={errors.legal_entity_name} hint="If it differs from the trading name. Used on purchase orders.">
                        {(control) => (
                            <Input {...control} value={data.legal_entity_name} onChange={(e) => setData('legal_entity_name', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Category" name="category" error={errors.category} className="sm:col-span-2">
                        {(control) => (
                            <Input {...control} value={data.category} onChange={(e) => setData('category', e.target.value)} placeholder="e.g. Safety Equipment, Spare Parts" />
                        )}
                    </FormField>

                    <div className="flex items-center sm:col-span-2">
                        <label className="flex cursor-pointer items-center gap-2 text-[13px] text-graphite-700 dark:text-slate-300">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} />
                            Active — an inactive vendor stays on record but cannot be selected on new documents
                        </label>
                    </div>
                </FormSection>

                <FormSection title="Address" description="Where the vendor is based." icon={MapPin}>
                    <FormField label="Street address" name="address" error={errors.address} className="sm:col-span-2">
                        {(control) => (
                            <Textarea {...control} value={data.address} onChange={(e) => setData('address', e.target.value)} rows={2} />
                        )}
                    </FormField>

                    <FormField label="City" name="city" error={errors.city}>
                        {(control) => <Input {...control} value={data.city} onChange={(e) => setData('city', e.target.value)} />}
                    </FormField>

                    <FormField label="Province" name="province" error={errors.province}>
                        {(control) => <Input {...control} value={data.province} onChange={(e) => setData('province', e.target.value)} />}
                    </FormField>

                    <FormField label="Country" name="country" error={errors.country}>
                        {(control) => <Input {...control} value={data.country} onChange={(e) => setData('country', e.target.value)} />}
                    </FormField>
                </FormSection>

                <FormSection title="Contact" description="Who to reach about an order." icon={Contact}>
                    <FormField label="PIC name" name="pic_name" error={errors.pic_name}>
                        {(control) => <Input {...control} value={data.pic_name} onChange={(e) => setData('pic_name', e.target.value)} />}
                    </FormField>

                    <FormField label="PIC phone" name="pic_phone" error={errors.pic_phone}>
                        {(control) => <Input {...control} type="tel" inputMode="tel" value={data.pic_phone} onChange={(e) => setData('pic_phone', e.target.value)} />}
                    </FormField>

                    <FormField label="PIC email" name="pic_email" error={errors.pic_email}>
                        {(control) => <Input {...control} type="email" value={data.pic_email} onChange={(e) => setData('pic_email', e.target.value)} />}
                    </FormField>

                    <FormField label="Website" name="website" error={errors.website}>
                        {(control) => <Input {...control} value={data.website} onChange={(e) => setData('website', e.target.value)} placeholder="https://" />}
                    </FormField>
                </FormSection>

                <FormSection
                    title="Commercial"
                    description="Registration and payment details, as they should appear on documents."
                    icon={Landmark}
                >
                    <FormField label="NPWP" name="npwp" error={errors.npwp}>
                        {(control) => <Input {...control} value={data.npwp} onChange={(e) => setData('npwp', e.target.value)} />}
                    </FormField>

                    <FormField label="NIB" name="nib" error={errors.nib}>
                        {(control) => <Input {...control} value={data.nib} onChange={(e) => setData('nib', e.target.value)} />}
                    </FormField>

                    <FormField label="Bank name" name="bank_name" error={errors.bank_name}>
                        {(control) => <Input {...control} value={data.bank_name} onChange={(e) => setData('bank_name', e.target.value)} />}
                    </FormField>

                    <FormField label="Bank account number" name="bank_account_number" error={errors.bank_account_number}>
                        {(control) => <Input {...control} inputMode="numeric" value={data.bank_account_number} onChange={(e) => setData('bank_account_number', e.target.value)} />}
                    </FormField>

                    <FormField label="Bank account holder" name="bank_account_holder" error={errors.bank_account_holder}>
                        {(control) => <Input {...control} value={data.bank_account_holder} onChange={(e) => setData('bank_account_holder', e.target.value)} />}
                    </FormField>

                    <FormField label="Payment terms" name="payment_terms" error={errors.payment_terms}>
                        {(control) => <Input {...control} value={data.payment_terms} onChange={(e) => setData('payment_terms', e.target.value)} placeholder="e.g. Net 30" />}
                    </FormField>

                    <FormField label="Tax info" name="tax_info" error={errors.tax_info} className="sm:col-span-2">
                        {(control) => <Input {...control} value={data.tax_info} onChange={(e) => setData('tax_info', e.target.value)} />}
                    </FormField>
                </FormSection>

                <FormSection title="Capability & notes" description="What this vendor can supply, and anything the buyer should know." icon={NotebookPen}>
                    <FormField label="Capability" name="capability" error={errors.capability} className="sm:col-span-2">
                        {(control) => <Textarea {...control} value={data.capability} onChange={(e) => setData('capability', e.target.value)} rows={2} />}
                    </FormField>

                    <FormField label="Notes" name="notes" error={errors.notes} className="sm:col-span-2">
                        {(control) => <Textarea {...control} value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} />}
                    </FormField>
                </FormSection>

                <FormActions
                    submitLabel={editing ? 'Save changes' : 'Add vendor'}
                    onCancel={cancel}
                    processing={processing}
                />
            </form>
        </AuthenticatedLayout>
    );
}
