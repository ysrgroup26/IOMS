import { useRef } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { FormSection, FormField, FormActions, ErrorSummary, SearchableSelect } from '@/Components/shared/form';
import { useUnsavedChanges } from '@/lib/useUnsavedChanges';
import { ArrowLeft, Plus, Trash2, ImagePlus, ClipboardList, Boxes } from 'lucide-react';

function emptyItem() {
    return { item_name: '', specification: '', quantity: '1', unit: '', remarks: '', reference_image: null, _preview: null };
}

/**
 * Material Request create/edit form (v1.6.8). Dynamic item table --
 * add/remove rows freely, each with its own optional reference image.
 * Deliberately no approval fields, no workflow step selector: Save Draft
 * or Submit are the only two states this MVP supports.
 *
 * v2.65.0 -- THE WORKFLOW CASE for the Form Experience System, and the
 * one that needed the most rethinking on a phone.
 *
 * WHAT A WORKFLOW FORM OWES THE USER THAT MASTER DATA DOES NOT: it must
 * say what happens next. This form ends in one of two genuinely different
 * states and never said so -- "Save Draft" and "Submit" sat side by side
 * as equals with no indication that one starts an approval and the other
 * does not. The action bar now states the consequence in a line beneath
 * the buttons.
 *
 * THE ITEM ROWS WERE UNUSABLE ON A PHONE. A twelve-column grid of seven
 * inputs collapses at `sm` into a single stack with no row identity, so
 * five requested items became thirty-five anonymous fields and nothing
 * said where one item ended and the next began. Each item is now a
 * numbered card carrying its own remove action -- one object per card,
 * which is precisely when a card is the right container. On desktop the
 * compact grid returns.
 *
 * Two of the four header fields could not display a validation error; all
 * four can now, because `FormField` cannot be declared without its error
 * slot.
 *
 * NOTHING ABOUT THE PAYLOAD OR THE WORKFLOW CHANGED. Same field names,
 * same `items[]` shape, same `_method` spoofing, same status override on
 * submit, same routes. The approval chain, `MaterialRequestController`
 * and its FormRequest are untouched.
 */

const ERROR_LABELS = {
    company_id: 'Operating Unit',
    request_date: 'Request date',
    department_id: 'Department',
    project_id: 'Project',
};

export default function MaterialRequestForm({ materialRequest, companies, departments, projects, requestNumber }) {
    const isEdit = !!materialRequest;
    const initial = useRef(null);

    const { data, setData, post, transform, processing, errors } = useForm({
        request_date: materialRequest?.request_date?.slice(0, 10) || new Date().toISOString().slice(0, 10),
        company_id: materialRequest?.company_id ? String(materialRequest.company_id) : undefined,
        project_id: materialRequest?.project_id ? String(materialRequest.project_id) : undefined,
        department_id: materialRequest?.department_id ? String(materialRequest.department_id) : undefined,
        status: materialRequest?.status || 'draft',
        notes: materialRequest?.notes || '',
        items: materialRequest?.items?.length
            ? materialRequest.items.map((i) => ({
                  id: i.id,
                  item_name: i.item_name,
                  specification: i.specification || '',
                  quantity: String(i.quantity),
                  unit: i.unit,
                  remarks: i.remarks || '',
                  reference_image: null,
                  _preview: i.reference_image_url,
              }))
            : [emptyItem()],
        _method: isEdit ? 'put' : 'post',
    });

    if (initial.current === null) initial.current = summarise(data);

    const { release } = useUnsavedChanges(summarise(data), initial.current, !processing);

    const availableDepartments = data.company_id ? departments.filter((d) => d.company_id === Number(data.company_id)) : departments;
    const availableProjects = data.company_id ? projects.filter((p) => p.company_id === Number(data.company_id)) : projects;

    function updateItem(index, field, value) {
        const items = [...data.items];
        items[index] = { ...items[index], [field]: value };
        setData('items', items);
    }

    function handleImage(index, file) {
        const items = [...data.items];
        items[index] = { ...items[index], reference_image: file, _preview: file ? URL.createObjectURL(file) : items[index]._preview };
        setData('items', items);
    }

    function addItem() {
        setData('items', [...data.items, emptyItem()]);
    }

    function removeItem(index) {
        if (data.items.length <= 1) return;
        setData('items', data.items.filter((_, i) => i !== index));
    }

    function submit(e, statusOverride) {
        e.preventDefault();
        release();
        const url = isEdit ? route('material-requests.update', materialRequest.id) : route('material-requests.store');
        transform((formData) => ({ ...formData, status: statusOverride || formData.status }));
        post(url, { forceFormData: true });
    }

    function cancel() {
        release();
        router.visit(route('material-requests.index'));
    }

    return (
        <AuthenticatedLayout>
            <Head title={isEdit ? `Edit ${materialRequest.request_number}` : 'New Material Request'} />

            <Link href={route('material-requests.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Material Requests
            </Link>

            <div className="mb-6">
                <h1 className="text-[22px] font-semibold tracking-tight text-navy-900 dark:text-slate-100">
                    {isEdit ? 'Edit Material Request' : 'New Material Request'}
                </h1>
                <p className="mt-0.5 font-mono text-xs text-graphite-500">{requestNumber}</p>
            </div>

            <form onSubmit={(e) => submit(e, 'submitted')} className="max-w-4xl space-y-6">
                <ErrorSummary errors={errors} labels={ERROR_LABELS} />

                <FormSection
                    title="What is this request for"
                    description="Who is asking, and which work it should be charged to."
                    icon={ClipboardList}
                >
                    <FormField label="Request date" name="request_date" required error={errors.request_date}>
                        {(control) => (
                            <Input {...control} type="date" value={data.request_date} onChange={(e) => setData('request_date', e.target.value)} />
                        )}
                    </FormField>

                    <FormField label="Operating Unit" name="company_id" required error={errors.company_id}>
                        {(control) => (
                            <SearchableSelect
                                {...control}
                                value={data.company_id ?? ''}
                                onChange={(v) => setData((d) => ({ ...d, company_id: v, department_id: undefined, project_id: undefined }))}
                                options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                placeholder="Select operating unit"
                            />
                        )}
                    </FormField>

                    <FormField
                        label="Department"
                        name="department_id"
                        error={errors.department_id}
                        hint={data.company_id ? undefined : 'Narrows once an Operating Unit is chosen.'}
                    >
                        {(control) => (
                            <SearchableSelect
                                {...control}
                                value={data.department_id ?? ''}
                                onChange={(v) => setData('department_id', v)}
                                options={availableDepartments.map((d) => ({ value: d.id, label: d.name }))}
                                placeholder="Select department"
                                clearable
                            />
                        )}
                    </FormField>

                    <FormField
                        label="Project"
                        name="project_id"
                        error={errors.project_id}
                        hint="Charges the materials to a project so they appear in its cost reporting."
                    >
                        {(control) => (
                            <SearchableSelect
                                {...control}
                                value={data.project_id ?? ''}
                                onChange={(v) => setData('project_id', v)}
                                options={availableProjects.map((p) => ({ value: p.id, label: p.name }))}
                                placeholder="Select project"
                                clearable
                            />
                        )}
                    </FormField>

                    <FormField label="Notes" name="notes" error={errors.notes} className="sm:col-span-2">
                        {(control) => (
                            <Textarea
                                {...control}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                rows={2}
                                placeholder="Any additional context for whoever approves this"
                            />
                        )}
                    </FormField>
                </FormSection>

                <FormSection
                    title="Items"
                    description="What is being requested. Each item can carry a reference photo."
                    icon={Boxes}
                    footer={
                        <Button type="button" variant="outline" size="sm" onClick={addItem}>
                            <Plus className="h-3.5 w-3.5" /> Add item
                        </Button>
                    }
                >
                    {/* One card per item. A card is right HERE, where it
                        contains a distinct object -- unlike the page-level
                        cards this redesign removed. */}
                    <div className="space-y-3 sm:col-span-2">
                        {data.items.map((item, index) => (
                            <div key={index} className="rounded-lg border border-graphite-200 bg-graphite-50/40 p-3 dark:border-slate-800 dark:bg-slate-900/40">
                                <div className="mb-2 flex items-center justify-between">
                                    <span className="text-[11px] font-semibold uppercase tracking-wide text-graphite-500">
                                        Item {index + 1}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => removeItem(index)}
                                        disabled={data.items.length <= 1}
                                        aria-label={`Remove item ${index + 1}`}
                                    >
                                        <Trash2 className="h-4 w-4 text-danger" />
                                    </Button>
                                </div>

                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-12">
                                    <FormField
                                        label="Item name"
                                        name={`items.${index}.item_name`}
                                        required
                                        error={errors[`items.${index}.item_name`]}
                                        className="col-span-2 sm:col-span-4"
                                    >
                                        {(control) => (
                                            <Input {...control} value={item.item_name} onChange={(e) => updateItem(index, 'item_name', e.target.value)} placeholder="e.g. Traffic Cone" />
                                        )}
                                    </FormField>

                                    <FormField
                                        label="Specification"
                                        name={`items.${index}.specification`}
                                        error={errors[`items.${index}.specification`]}
                                        className="col-span-2 sm:col-span-3"
                                    >
                                        {(control) => (
                                            <Input {...control} value={item.specification} onChange={(e) => updateItem(index, 'specification', e.target.value)} placeholder="70cm, red/white" />
                                        )}
                                    </FormField>

                                    <FormField
                                        label="Qty"
                                        name={`items.${index}.quantity`}
                                        required
                                        error={errors[`items.${index}.quantity`]}
                                        className="sm:col-span-1"
                                    >
                                        {(control) => (
                                            <Input {...control} type="number" inputMode="decimal" step="0.01" min="0.01" value={item.quantity} onChange={(e) => updateItem(index, 'quantity', e.target.value)} />
                                        )}
                                    </FormField>

                                    <FormField
                                        label="Unit"
                                        name={`items.${index}.unit`}
                                        required
                                        error={errors[`items.${index}.unit`]}
                                        className="sm:col-span-1"
                                    >
                                        {(control) => (
                                            <Input {...control} value={item.unit} onChange={(e) => updateItem(index, 'unit', e.target.value)} placeholder="pcs" />
                                        )}
                                    </FormField>

                                    <FormField
                                        label="Remarks"
                                        name={`items.${index}.remarks`}
                                        error={errors[`items.${index}.remarks`]}
                                        className="col-span-2 sm:col-span-2"
                                    >
                                        {(control) => (
                                            <Input {...control} value={item.remarks} onChange={(e) => updateItem(index, 'remarks', e.target.value)} />
                                        )}
                                    </FormField>

                                    <div className="col-span-2 sm:col-span-1">
                                        <Label className="mb-1.5 block">Photo</Label>
                                        <label className="flex h-9 w-full cursor-pointer items-center justify-center rounded-lg border border-dashed border-graphite-300 text-graphite-400 transition-colors hover:border-brand-400 hover:text-brand-600 focus-within:ring-2 focus-within:ring-ring">
                                            {item._preview ? (
                                                <img src={item._preview} className="h-7 w-7 rounded object-cover" alt={`Reference for item ${index + 1}`} />
                                            ) : (
                                                <ImagePlus className="h-4 w-4" aria-hidden="true" />
                                            )}
                                            <input
                                                type="file"
                                                accept="image/*"
                                                className="sr-only"
                                                aria-label={`Reference photo for item ${index + 1}`}
                                                onChange={(e) => handleImage(index, e.target.files[0])}
                                            />
                                        </label>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </FormSection>

                <FormActions
                    submitLabel={isEdit ? 'Save & submit' : 'Submit request'}
                    onCancel={cancel}
                    processing={processing}
                    secondary={
                        <Button type="button" variant="outline" onClick={(e) => submit(e, 'draft')} disabled={processing}>
                            Save as draft
                        </Button>
                    }
                    note="Submitting sends this for approval. A draft stays visible only to you and can be edited."
                />
            </form>
        </AuthenticatedLayout>
    );
}

/**
 * The dirty comparison needs a flat, stable shape. Item rows carry a
 * `_preview` blob URL that changes identity on every render, so items are
 * reduced to the values that actually represent user intent.
 */
function summarise(data) {
    return {
        request_date: data.request_date,
        company_id: data.company_id,
        project_id: data.project_id,
        department_id: data.department_id,
        notes: data.notes,
        items: data.items.map((i) =>
            [i.item_name, i.specification, i.quantity, i.unit, i.remarks, i.reference_image ? 'file' : ''].join('|')
        ),
    };
}
