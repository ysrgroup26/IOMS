import { useState, useEffect } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PpeTabNav from '@/Components/shared/PpeTabNav';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import EmptyState from '@/Components/shared/EmptyState';
import { RefreshCw, FileText } from 'lucide-react';

/**
 * Replacement Due (v1.6.8 PPE Replacement Request MVP). Item-level list
 * -- one row per PPE item overdue for replacement -- distinct from
 * Employee PPE's employee-level list, since multi-selecting individual
 * PPE records to bundle into a request needs an item granularity that
 * page deliberately doesn't have. This is the ONLY place a Replacement
 * Request can be created from -- no separate manual "create" page.
 */
export default function PpeReplacementDue({ items, companies, filters }) {
    const [selected, setSelected] = useState([]);
    const [dialogOpen, setDialogOpen] = useState(false);

    function toggle(id) {
        setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    }

    function toggleAll() {
        setSelected(selected.length === items.length ? [] : items.map((i) => i.id));
    }

    const selectedItems = items.filter((i) => selected.includes(i.id));

    return (
        <AuthenticatedLayout>
            <Head title="Replacement Due" />

            <PpeTabNav />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">Replacement Due</h1>
                    <p className="text-xs text-graphite-500 dark:text-slate-400">Select one or more items to create a Replacement Request.</p>
                </div>
                <Button disabled={selected.length === 0} onClick={() => setDialogOpen(true)}>
                    <RefreshCw className="h-4 w-4" /> Create Replacement Request {selected.length > 0 && `(${selected.length})`}
                </Button>
            </div>

            <Card>
                <CardContent className="p-0">
                    {items.length === 0 ? (
                        <EmptyState icon={FileText} title="Nothing overdue" description="No PPE items are currently due for replacement." />
                    ) : (
                        <div className="divide-y divide-graphite-100 dark:divide-slate-800">
                            <div className="flex items-center gap-3 px-4 py-2">
                                <Checkbox checked={selected.length === items.length} onCheckedChange={toggleAll} />
                                <span className="text-xs font-medium uppercase tracking-wide text-graphite-400">Select All ({items.length})</span>
                            </div>
                            {items.map((item) => (
                                <label key={item.id} className="flex cursor-pointer items-center gap-3 px-4 py-2 text-[13px] hover:bg-graphite-50 dark:hover:bg-slate-800/60">
                                    <Checkbox checked={selected.includes(item.id)} onCheckedChange={() => toggle(item.id)} />
                                    <span className="w-40 shrink-0 font-medium text-graphite-900 dark:text-slate-100">{item.employee_name}</span>
                                    <span className="w-28 shrink-0 text-graphite-500 dark:text-slate-400">{item.department || '-'}</span>
                                    <span className="w-32 shrink-0 text-graphite-700 dark:text-slate-300">{item.ppe_type}</span>
                                    <span className="ml-auto shrink-0 text-red-600">{item.days_overdue}d overdue</span>
                                </label>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <CreateReplacementRequestDialog open={dialogOpen} onOpenChange={setDialogOpen} items={selectedItems} onSuccess={() => setSelected([])} />
        </AuthenticatedLayout>
    );
}

/**
 * Auto-populates everything the spec asks for (Employee, Employee ID/NIK,
 * Department, PPE Item) directly from the selected items -- read-only,
 * since those are facts about existing records, not something to
 * re-type. Project and quantity/remarks/documentation photo are the only
 * per-item editable fields, matching "only allow editing where
 * necessary."
 */
function CreateReplacementRequestDialog({ open, onOpenChange, items, onSuccess }) {
    const { data, setData, post, processing, reset, transform } = useForm({
        notes: '',
        items: [],
    });

    const itemIds = items.map((i) => i.id).join(',');

    // Re-seed per-item editable fields whenever the selection changes.
    // Keyed on the actual set of selected IDs (not just `open` or array
    // length), and run in an effect rather than during render.
    useEffect(() => {
        if (open) {
            setData('items', items.map((i) => ({ employee_ppe_id: i.id, project_id: undefined, quantity: '1', remarks: '', documentation_photo: null, _preview: null })));
        }
    }, [open, itemIds]);

    function updateItem(index, field, value) {
        const next = [...data.items];
        next[index] = { ...next[index], [field]: value };
        setData('items', next);
    }

    function handlePhoto(index, file) {
        const next = [...data.items];
        next[index] = { ...next[index], documentation_photo: file, _preview: file ? URL.createObjectURL(file) : null };
        setData('items', next);
    }

    function submit(e, statusOverride) {
        e.preventDefault();
        transform((formData) => ({ ...formData, status: statusOverride }));
        post(route('ppe.replacement-requests.store'), {
            forceFormData: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
                onSuccess();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <DialogHeader><DialogTitle>Create Replacement Request ({items.length} item{items.length !== 1 ? 's' : ''})</DialogTitle></DialogHeader>
                <form onSubmit={(e) => submit(e, 'submitted')} className="space-y-3">
                    {items.map((item, index) => (
                        <div key={item.id} className="rounded-lg border border-graphite-100 p-3">
                            <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-graphite-500">
                                <span><strong className="text-graphite-800">{item.employee_name}</strong> ({item.employee_code}{item.nik ? ` / ${item.nik}` : ''})</span>
                                <span>{item.department || '-'}</span>
                                <span>{item.ppe_type}</span>
                                <span>Expired {item.expiry_date}</span>
                            </div>
                            <div className="mt-2 grid grid-cols-3 gap-2">
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Quantity</Label>
                                    <Input type="number" min="1" value={data.items[index]?.quantity || '1'} onChange={(e) => updateItem(index, 'quantity', e.target.value)} />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Remarks</Label>
                                    <Input value={data.items[index]?.remarks || ''} onChange={(e) => updateItem(index, 'remarks', e.target.value)} />
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-[11px]">Documentation Photo</Label>
                                    <Input type="file" accept="image/*" onChange={(e) => handlePhoto(index, e.target.files[0])} />
                                </div>
                            </div>
                        </div>
                    ))}

                    <div className="space-y-1.5">
                        <Label>Notes (optional)</Label>
                        <Input value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="button" variant="outline" onClick={(e) => submit(e, 'draft')} disabled={processing}>Save Draft</Button>
                        <Button type="submit" disabled={processing}>Submit</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
