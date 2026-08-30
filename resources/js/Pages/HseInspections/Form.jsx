import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';

const RESULT_OPTIONS = [
    { value: 'ok', label: 'OK', activeClass: 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-400' },
    { value: 'not_ok', label: 'Not OK', activeClass: 'border-red-500 bg-red-50 text-red-700 dark:border-red-500 dark:bg-red-950/40 dark:text-red-400' },
    { value: 'na', label: 'N/A', activeClass: 'border-graphite-400 bg-graphite-100 text-graphite-700 dark:border-slate-500 dark:bg-slate-800 dark:text-slate-200' },
];

const BLANK_ITEM = { item: '', result: 'ok', remarks: '' };

/**
 * v1.11.2 (Final Completion Pass, Part 9). "Load Template" replaces
 * manually retyping the FFA/LSA/PPE item list every time -- reads from
 * `checklistTemplates` (HseChecklistTemplate, configurable per company via
 * HSE > Master Data > Checklist Templates), filtered to whichever
 * `inspection_type` is currently selected. Loading a template REPLACES the
 * current checklist rows (with confirmation if any are already filled in)
 * rather than merging, since re-loading is meant to reset to the standard
 * list, not append to a partially-filled one.
 */
export default function HseInspectionForm({ companies, projects, inspectionNumber, types, checklistTemplates = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        project_id: '',
        inspection_type: 'general',
        location: '',
        inspection_date: new Date().toISOString().slice(0, 10),
        notes: '',
        checklist_items: [{ ...BLANK_ITEM }],
    });

    function updateItem(i, field, value) {
        const items = [...data.checklist_items];
        items[i] = { ...items[i], [field]: value };
        setData('checklist_items', items);
    }

    const templatesForType = checklistTemplates.filter((t) => t.category === data.inspection_type);

    function loadTemplate(templateId) {
        const template = checklistTemplates.find((t) => String(t.id) === String(templateId));
        if (!template) return;
        const hasContent = data.checklist_items.some((i) => i.item || i.remarks);
        if (hasContent && !confirm('Replace the current checklist with this template? Unsaved rows will be lost.')) return;
        setData('checklist_items', template.items.map((it) => ({ item: it.label, result: 'ok', remarks: '' })));
    }

    function submit(e) {
        e.preventDefault();
        post(route('hse-inspections.store'));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Record HSE Inspection" />

            <div className="mb-4 flex items-center gap-2">
                <Button variant="ghost" size="sm" asChild><Link href={route('hse-inspections.index')}><ArrowLeft className="h-4 w-4" /> Back</Link></Button>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <Card>
                    <CardHeader><CardTitle>Record HSE Inspection -- {inspectionNumber}</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Inspection Type</Label>
                                <Select value={data.inspection_type} onValueChange={(v) => setData('inspection_type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5"><Label>Date</Label><Input type="date" value={data.inspection_date} onChange={(e) => setData('inspection_date', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Location</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} /></div>
                        <div className="space-y-1.5"><Label>Notes (optional)</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Project (optional)</Label>
                                <Select value={data.project_id || 'none'} onValueChange={(v) => setData('project_id', v === 'none' ? '' : v)}>
                                    <SelectTrigger><SelectValue placeholder="No project" /></SelectTrigger>
                                    <SelectContent><SelectItem value="none">No project</SelectItem>{projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Company</Label>
                                <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle>Checklist</CardTitle>
                            {/* v2.5.0 (Field HSE Experience pass, Part 7):
                                a real, honest progress indicator -- NOT a
                                fabricated "X/Y completed" count, since
                                every item defaults to `result: 'ok'`
                                (BLANK_ITEM above) rather than an
                                unanswered state, so "completed" has no
                                real meaning in this data model without a
                                bigger, riskier change to that default.
                                What IS real and useful: how many items
                                exist, and how many are currently flagged
                                Not OK (the ones that will need a CAPA). */}
                            <p className="text-xs text-graphite-400">
                                {data.checklist_items.length} item{data.checklist_items.length !== 1 ? 's' : ''}
                                {data.checklist_items.some((i) => i.result === 'not_ok') && (
                                    <span className="ml-1 text-red-600">
                                        · {data.checklist_items.filter((i) => i.result === 'not_ok').length} Not OK
                                    </span>
                                )}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            {templatesForType.length > 0 && (
                                <Select value="" onValueChange={loadTemplate}>
                                    <SelectTrigger className="w-56"><SelectValue placeholder="Load Template..." /></SelectTrigger>
                                    <SelectContent>{templatesForType.map((t) => <SelectItem key={t.id} value={String(t.id)}>{t.name} ({t.items.length} items)</SelectItem>)}</SelectContent>
                                </Select>
                            )}
                            <Button type="button" variant="outline" size="sm" onClick={() => setData('checklist_items', [...data.checklist_items, { ...BLANK_ITEM }])}><Plus className="h-4 w-4" /> Add Item</Button>
                        </div>
                    </CardHeader>
                    {/* v2.11.0 (Field/Foreman Experience pass, Phase 3E --
                        Digital Checklist). PREVIOUSLY a wide `<Table>`
                        (Item/Result/Remarks/delete, `min-w-[220px]` cells,
                        `overflow-x-auto`) -- forced horizontal scroll on
                        any phone, and marking a result meant opening a
                        3-option dropdown per item. Confirmed via this
                        pass's own fresh audit as the clearest remaining
                        field-usability gap in this module (the checklist
                        engine itself, templates, and the item-count/
                        Not-OK indicator above were all already correct
                        and are untouched).
                        Replaced with a stacked card per item -- a
                        checklist is inherently a linear list to work
                        through, not tabular data needing column
                        comparison, so this reads naturally on desktop
                        too, not just mobile. Result is now a one-tap
                        3-button toggle (OK / Not OK / N/A) instead of a
                        dropdown -- matches the explicit "clear OK / Not
                        OK state" + "large touch targets" requirement. No
                        change to what's collected/validated/stored --
                        same `checklist_items` shape
                        (`{item, result, remarks}`), same
                        `HseInspectionController::store()`. */}
                    <CardContent className="space-y-3">
                        {data.checklist_items.map((item, i) => (
                            <div key={i} className="rounded-lg border border-graphite-200 p-3 dark:border-slate-700">
                                <div className="flex items-start gap-2">
                                    <Input
                                        value={item.item}
                                        onChange={(e) => updateItem(i, 'item', e.target.value)}
                                        placeholder="Item yang diperiksa"
                                        className="flex-1"
                                    />
                                    <Button type="button" variant="ghost" size="icon" onClick={() => setData('checklist_items', data.checklist_items.filter((_, idx) => idx !== i))}>
                                        <Trash2 className="h-4 w-4 text-red-500" />
                                    </Button>
                                </div>
                                <div className="mt-2 grid grid-cols-3 gap-2">
                                    {RESULT_OPTIONS.map((opt) => (
                                        <button
                                            key={opt.value}
                                            type="button"
                                            onClick={() => updateItem(i, 'result', opt.value)}
                                            className={cn(
                                                'rounded-md border py-2 text-sm font-medium transition-colors',
                                                item.result === opt.value
                                                    ? opt.activeClass
                                                    : 'border-graphite-200 text-graphite-500 hover:bg-graphite-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800'
                                            )}
                                        >
                                            {opt.label}
                                        </button>
                                    ))}
                                </div>
                                <Input
                                    value={item.remarks}
                                    onChange={(e) => updateItem(i, 'remarks', e.target.value)}
                                    placeholder="Catatan (opsional)"
                                    className="mt-2"
                                />
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Button type="submit" disabled={processing}>Save Inspection</Button>
            </form>
        </AuthenticatedLayout>
    );
}
