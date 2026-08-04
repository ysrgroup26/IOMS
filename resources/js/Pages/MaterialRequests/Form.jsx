import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft, Plus, Trash2, ImagePlus } from 'lucide-react';

function emptyItem() {
    return { item_name: '', specification: '', quantity: '1', unit: '', remarks: '', reference_image: null, _preview: null };
}

/**
 * Material Request create/edit form (v1.6.8). Dynamic item table --
 * add/remove rows freely, each with its own optional reference image.
 * Deliberately no approval fields, no workflow step selector: Save Draft
 * or Submit are the only two states this MVP supports.
 */
export default function MaterialRequestForm({ materialRequest, companies, departments, projects, requestNumber }) {
    const isEdit = !!materialRequest;

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
        const url = isEdit ? route('material-requests.update', materialRequest.id) : route('material-requests.store');
        transform((formData) => ({ ...formData, status: statusOverride || formData.status }));
        post(url, { forceFormData: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title={isEdit ? `Edit ${materialRequest.request_number}` : 'New Material Request'} />

            <Link href={route('material-requests.index')} className="mb-4 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Material Requests
            </Link>

            <div className="mb-4">
                <h1 className="text-lg font-bold tracking-tight text-graphite-900">{isEdit ? 'Edit Material Request' : 'New Material Request'}</h1>
                <p className="text-xs text-graphite-500">{requestNumber}</p>
            </div>

            <form onSubmit={(e) => submit(e)} className="space-y-4">
                <Card>
                    <CardHeader><CardTitle>Request Details</CardTitle></CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="space-y-1.5">
                            <Label>Request Date</Label>
                            <Input type="date" value={data.request_date} onChange={(e) => setData('request_date', e.target.value)} />
                            {errors.request_date && <p className="text-xs text-red-600">{errors.request_date}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData((d) => ({ ...d, company_id: v, department_id: undefined, project_id: undefined }))}>
                                <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label>Department (optional)</Label>
                            <Select value={data.department_id} onValueChange={(v) => setData('department_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select department" /></SelectTrigger>
                                <SelectContent>
                                    {availableDepartments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Project (optional)</Label>
                            <Select value={data.project_id} onValueChange={(v) => setData('project_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select project" /></SelectTrigger>
                                <SelectContent>
                                    {availableProjects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <CardTitle>Items</CardTitle>
                        <Button type="button" variant="outline" size="sm" onClick={addItem}><Plus className="h-3.5 w-3.5" /> Add Item</Button>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {data.items.map((item, index) => (
                            <div key={index} className="grid grid-cols-1 gap-2 rounded-lg border border-graphite-100 p-3 sm:grid-cols-12">
                                <div className="sm:col-span-3 space-y-1">
                                    <Label className="text-[11px]">Item Name</Label>
                                    <Input value={item.item_name} onChange={(e) => updateItem(index, 'item_name', e.target.value)} placeholder="e.g. Traffic Cone" />
                                    {errors[`items.${index}.item_name`] && <p className="text-xs text-red-600">{errors[`items.${index}.item_name`]}</p>}
                                </div>
                                <div className="sm:col-span-3 space-y-1">
                                    <Label className="text-[11px]">Specification</Label>
                                    <Input value={item.specification} onChange={(e) => updateItem(index, 'specification', e.target.value)} placeholder="e.g. 70cm, red/white" />
                                </div>
                                <div className="sm:col-span-1 space-y-1">
                                    <Label className="text-[11px]">Qty</Label>
                                    <Input type="number" step="0.01" min="0.01" value={item.quantity} onChange={(e) => updateItem(index, 'quantity', e.target.value)} />
                                </div>
                                <div className="sm:col-span-1 space-y-1">
                                    <Label className="text-[11px]">Unit</Label>
                                    <Input value={item.unit} onChange={(e) => updateItem(index, 'unit', e.target.value)} placeholder="pcs" />
                                </div>
                                <div className="sm:col-span-2 space-y-1">
                                    <Label className="text-[11px]">Remarks</Label>
                                    <Input value={item.remarks} onChange={(e) => updateItem(index, 'remarks', e.target.value)} />
                                </div>
                                <div className="sm:col-span-1 space-y-1">
                                    <Label className="text-[11px]">Reference</Label>
                                    <label className="flex h-8 w-full cursor-pointer items-center justify-center rounded-lg border border-dashed border-graphite-300 text-graphite-400 hover:border-brand-400 hover:text-brand-600">
                                        {item._preview ? (
                                            <img src={item._preview} className="h-8 w-8 rounded object-cover" alt="" />
                                        ) : (
                                            <ImagePlus className="h-4 w-4" />
                                        )}
                                        <input type="file" accept="image/*" className="hidden" onChange={(e) => handleImage(index, e.target.files[0])} />
                                    </label>
                                </div>
                                <div className="flex items-end sm:col-span-1">
                                    <Button type="button" variant="ghost" size="icon" onClick={() => removeItem(index)} disabled={data.items.length <= 1}>
                                        <Trash2 className="h-4 w-4 text-red-500" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>Notes (optional)</CardTitle></CardHeader>
                    <CardContent>
                        <Input value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Any additional context for this request" />
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={(e) => submit(e, 'draft')} disabled={processing}>Save Draft</Button>
                    <Button type="button" onClick={(e) => submit(e, 'submitted')} disabled={processing}>Submit</Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
