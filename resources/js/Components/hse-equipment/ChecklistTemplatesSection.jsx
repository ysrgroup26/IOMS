import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/Components/ui/dialog';
import { Checkbox } from '@/Components/ui/checkbox';
import { CHECKS } from './compliance';
import { cn } from '@/lib/utils';
import { BookOpen, Camera, ChevronDown, ChevronUp, Plus, Pencil, ShieldAlert, Trash2 } from 'lucide-react';

const blankItem = () => ({ label: '', check: 'pass_fail', critical: false, photo_on_fail: false });

/**
 * Checklist Templates (v1.11.2; v2.135.0 for equipment).
 *
 * A template is either for an AREA inspection (one of HseInspection::TYPES,
 * loaded into an HSE Inspection) or for an EQUIPMENT TYPE (APAR, P3K, gas
 * detector -- used whenever a unit of that type is inspected). Each item
 * says what kind of check it is (Pass/Fail, a reading with its limits, or a
 * date read off the unit), whether a Fail is critical, and whether a Fail
 * needs a photo -- because an APAR, a first-aid box and a gas detector are
 * not inspected against the same list.
 *
 * The reference lists only START a template: HSE reviews the items against
 * the company's own procedure before saving.
 */
export default function ChecklistTemplatesSection({ checklistTemplates, inspectionTypes, equipmentTypes = [], checklistLibrary = [], companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [basis, setBasis] = useState(null);
    const typeName = Object.fromEntries(equipmentTypes.map((t) => [t.id, t.name]));
    const { data, setData, post, put, processing, reset, errors, transform } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        applies_to: 'equipment',
        equipment_type_id: '',
        category: inspectionTypes[0] ?? 'ppe',
        name: '',
        items: [blankItem()],
        is_active: true,
        sort_order: 0,
    });

    function openCreate() { setEditing(null); setBasis(null); reset(); setOpen(true); }
    function openEdit(t) {
        setEditing(t);
        setBasis(null);
        setData({
            company_id: String(t.company_id),
            applies_to: t.equipment_type_id ? 'equipment' : 'area',
            equipment_type_id: t.equipment_type_id ? String(t.equipment_type_id) : '',
            category: t.equipment_type_id ? (inspectionTypes[0] ?? 'ppe') : t.category,
            name: t.name,
            items: t.items?.length ? t.items.map((i) => ({ ...blankItem(), ...i })) : [blankItem()],
            is_active: t.is_active, sort_order: t.sort_order,
        });
        setOpen(true);
    }
    function startFrom(key) {
        const ref = checklistLibrary.find((l) => l.key === key);
        if (!ref) return;
        setBasis(ref.basis);
        setData((d) => ({ ...d, name: d.name || ref.name, items: ref.items.map((i) => ({ ...blankItem(), ...i })) }));
    }
    function submit(e) {
        e.preventDefault();
        transform((d) => ({
            ...d,
            equipment_type_id: d.applies_to === 'equipment' ? d.equipment_type_id || null : null,
            category: d.applies_to === 'area' ? d.category : null,
            items: d.items.filter((i) => i.label.trim() !== ''),
        }));
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('hse-checklist-templates.update', editing.id), options); } else { post(route('hse-checklist-templates.store'), options); }
    }
    function destroy(t) {
        if (confirm(`Remove checklist template "${t.name}"? Inspections already filed keep the items they were answered against.`)) {
            router.delete(route('hse-checklist-templates.destroy', t.id), { preserveScroll: true });
        }
    }
    function updateItem(i, patch) {
        setData('items', data.items.map((item, idx) => (idx === i ? { ...item, ...patch } : item)));
    }
    function moveItem(i, dir) {
        const j = i + dir;
        if (j < 0 || j >= data.items.length) return;
        const items = [...data.items];
        [items[i], items[j]] = [items[j], items[i]];
        setData('items', items);
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-3">
                <div>
                    <CardTitle>Checklist Templates</CardTitle>
                    <CardDescription>
                        {checklistTemplates.length} template. Template untuk jenis peralatan dipakai otomatis saat unit jenis itu diinspeksi; template area dipanggil lewat Load Template di HSE Inspection.
                    </CardDescription>
                </div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Template</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Applies To</TableHead><TableHead>Items</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead><span className="sr-only">Actions</span></TableHead>}</TableRow></TableHeader>
                    <TableBody>
                        {checklistTemplates.map((t) => (
                            <TableRow key={t.id}>
                                <TableCell className="font-medium">{t.name}</TableCell>
                                <TableCell>
                                    {t.equipment_type_id
                                        ? <span>Equipment · {typeName[t.equipment_type_id] ?? 'Type removed'}</span>
                                        : <span className="capitalize">Area · {t.category.replace('_', ' ')}</span>}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {t.items?.length ?? 0}
                                    {t.items?.some((i) => i.critical) && <span className="ml-1.5 text-xs text-graphite-500">({t.items.filter((i) => i.critical).length} critical)</span>}
                                </TableCell>
                                <TableCell><Badge variant={t.is_active ? 'success' : 'secondary'}>{t.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                {can.manage && (
                                    <TableCell className="flex gap-1">
                                        <Button variant="ghost" size="icon" aria-label={`Edit ${t.name}`} onClick={() => openEdit(t)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" aria-label={`Remove ${t.name}`} onClick={() => destroy(t)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="flex max-h-[92vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
                    <DialogHeader className="border-b border-graphite-100 px-5 pb-3 pt-5 dark:border-slate-800">
                        <DialogTitle>{editing ? 'Edit' : 'Add'} Checklist Template</DialogTitle>
                        <DialogDescription>Tentukan apa yang diperiksa, jenis pemeriksaannya, dan poin mana yang kritis.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col" noValidate>
                        <div className="relative min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                            {!editing && companies.length > 1 && (
                                <div className="space-y-1.5">
                                    <Label>Operating Unit</Label>
                                    <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                    </Select>
                                </div>
                            )}

                            <div className="space-y-1.5">
                                <Label id="applies-to">Applies To</Label>
                                <div role="radiogroup" aria-labelledby="applies-to" className="flex w-fit rounded-lg bg-graphite-100 p-0.5 dark:bg-slate-800">
                                    {[['equipment', 'Equipment Type'], ['area', 'Area Inspection']].map(([v, label]) => (
                                        <button key={v} type="button" role="radio" aria-checked={data.applies_to === v} onClick={() => setData('applies_to', v)}
                                            className={cn('rounded-md px-3 py-1.5 text-[13px] font-medium outline-none transition-[background-color,color,box-shadow] duration-fast ease-out-soft focus-visible:ring-2 focus-visible:ring-ring/60',
                                                data.applies_to === v ? 'bg-white text-navy-900 shadow-sm dark:bg-slate-700 dark:text-slate-100' : 'text-graphite-600 hover:text-graphite-900 dark:text-slate-400')}>
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {data.applies_to === 'equipment' ? (
                                    <div className="space-y-1.5">
                                        <Label>Equipment Type</Label>
                                        <Select value={data.equipment_type_id} onValueChange={(v) => setData('equipment_type_id', v)}>
                                            <SelectTrigger aria-invalid={!!errors.equipment_type_id}><SelectValue placeholder="Choose a type" /></SelectTrigger>
                                            <SelectContent>{equipmentTypes.filter((t) => t.is_active || String(t.id) === data.equipment_type_id).map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                        {(errors.equipment_type_id || errors.category) && <p className="text-xs text-red-600">{errors.equipment_type_id || errors.category}</p>}
                                    </div>
                                ) : (
                                    <div className="space-y-1.5">
                                        <Label>Inspection Category</Label>
                                        <Select value={data.category} onValueChange={(v) => setData('category', v)}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>{inspectionTypes.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                        </Select>
                                    </div>
                                )}
                                <div className="space-y-1.5">
                                    <Label htmlFor="template-name">Name</Label>
                                    <Input id="template-name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Inspeksi Bulanan APAR" aria-invalid={!!errors.name} />
                                    {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                                </div>
                            </div>

                            {data.applies_to === 'equipment' && checklistLibrary.length > 0 && (
                                <div className="rounded-xl border border-steel-200 bg-steel-50/60 p-3 dark:border-slate-700 dark:bg-slate-900/50">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <BookOpen className="h-4 w-4 text-steel-600" aria-hidden="true" />
                                        <span className="text-[13px] font-medium text-navy-800 dark:text-slate-200">Start from a reference list</span>
                                        <div className="flex flex-wrap gap-1.5">
                                            {checklistLibrary.map((l) => (
                                                <Button key={l.key} type="button" size="sm" variant="outline" onClick={() => startFrom(l.key)}>{l.name}</Button>
                                            ))}
                                        </div>
                                    </div>
                                    <p className="mt-1.5 text-xs text-graphite-600 dark:text-slate-400">
                                        {basis ?? 'Daftar acuan hanya titik awal. Tinjau setiap poin terhadap prosedur perusahaan dan petunjuk pabrikan sebelum menyimpan.'}
                                    </p>
                                </div>
                            )}

                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <Label>Checklist Items</Label>
                                    <Button type="button" variant="outline" size="sm" onClick={() => setData('items', [...data.items, blankItem()])} disabled={data.items.length >= 60}><Plus className="h-3.5 w-3.5" /> Add Item</Button>
                                </div>
                                <ol className="space-y-2">
                                    {data.items.map((item, i) => (
                                        <li key={i} className="rounded-xl border border-graphite-200/80 bg-white p-2.5 motion-safe:animate-fade-in dark:border-slate-700 dark:bg-slate-900">
                                            <div className="flex items-start gap-1.5">
                                                <span className="mt-2 w-5 shrink-0 text-right text-xs tabular-nums text-graphite-400">{i + 1}.</span>
                                                <Input value={item.label} onChange={(e) => updateItem(i, { label: e.target.value })} placeholder="Apa yang diperiksa" aria-label={`Item ${i + 1}`} />
                                                <div className="flex shrink-0">
                                                    <Button type="button" variant="ghost" size="icon" aria-label={`Move item ${i + 1} up`} disabled={i === 0} onClick={() => moveItem(i, -1)}><ChevronUp className="h-4 w-4" /></Button>
                                                    <Button type="button" variant="ghost" size="icon" aria-label={`Move item ${i + 1} down`} disabled={i === data.items.length - 1} onClick={() => moveItem(i, 1)}><ChevronDown className="h-4 w-4" /></Button>
                                                    <Button type="button" variant="ghost" size="icon" aria-label={`Remove item ${i + 1}`} onClick={() => setData('items', data.items.filter((_, idx) => idx !== i))} disabled={data.items.length === 1}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                                </div>
                                            </div>
                                            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 pl-6">
                                                <Select value={item.check || 'pass_fail'} onValueChange={(v) => updateItem(i, { check: v })}>
                                                    <SelectTrigger className="h-8 w-36" aria-label={`Check type for item ${i + 1}`}><SelectValue /></SelectTrigger>
                                                    <SelectContent>{Object.entries(CHECKS).map(([k, c]) => <SelectItem key={k} value={k}>{c.label}</SelectItem>)}</SelectContent>
                                                </Select>
                                                {item.check === 'reading' && (
                                                    <div className="flex items-center gap-1.5">
                                                        <Input className="h-8 w-16" placeholder="Unit" aria-label={`Unit for item ${i + 1}`} value={item.unit ?? ''} onChange={(e) => updateItem(i, { unit: e.target.value })} />
                                                        <Input className="h-8 w-20" type="number" step="any" placeholder="Min" aria-label={`Minimum for item ${i + 1}`} value={item.min ?? ''} onChange={(e) => updateItem(i, { min: e.target.value })} />
                                                        <Input className="h-8 w-20" type="number" step="any" placeholder="Max" aria-label={`Maximum for item ${i + 1}`} value={item.max ?? ''} onChange={(e) => updateItem(i, { max: e.target.value })} />
                                                    </div>
                                                )}
                                                <label className="flex items-center gap-1.5 text-[13px] text-graphite-700 dark:text-slate-300">
                                                    <Checkbox checked={!!item.critical} onCheckedChange={(v) => updateItem(i, { critical: !!v })} />
                                                    <ShieldAlert className="h-3.5 w-3.5 text-red-500" aria-hidden="true" /> Critical
                                                </label>
                                                <label className="flex items-center gap-1.5 text-[13px] text-graphite-700 dark:text-slate-300">
                                                    <Checkbox checked={!!item.photo_on_fail} onCheckedChange={(v) => updateItem(i, { photo_on_fail: !!v })} />
                                                    <Camera className="h-3.5 w-3.5 text-graphite-500" aria-hidden="true" /> Photo on Fail
                                                </label>
                                            </div>
                                            {Object.entries(errors).filter(([k]) => k.startsWith(`items.${i}.`)).map(([k, m]) => <p key={k} className="mt-1 pl-6 text-xs text-red-600">{m}</p>)}
                                        </li>
                                    ))}
                                </ol>
                                <p className="text-xs text-graphite-500">
                                    Critical: Fail pada poin ini membuat seluruh inspeksi Fail. Reading di luar batas dan Date yang sudah lewat otomatis dicatat Fail.
                                </p>
                                {errors.items && <p className="text-xs text-red-600">{errors.items}</p>}
                            </div>

                            <label className="flex items-center gap-2 text-[13px]">
                                <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} />
                                Active
                            </label>
                        </div>
                        <DialogFooter className="border-t border-graphite-100 px-5 py-3 dark:border-slate-800">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" loading={processing}>Save Template</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
