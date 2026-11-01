/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Checkbox } from '@/Components/ui/checkbox';
import { Trash2, Pencil, Loader2 } from 'lucide-react';
import { moduleLabel } from '@/lib/moduleLabels';

/**
 * Milestone 3 (Dynamic Document Engine, Task #66). Form-based template
 * management, deliberately NOT a drag-drop canvas -- one form per
 * module_key covering header/footer text and which of logo/QR/
 * signature/watermark to show. Rendered by App\Services\DocumentEngine;
 * see that class's doc comment for how it plugs into each module's
 * EXISTING PDF Blade view rather than a second rendering pipeline.
 */
export default function DocumentTemplatesTab({ documentTemplates, moduleKeys }) {
    const [editingId, setEditingId] = useState(null);
    const createForm = useForm({
        module_key: moduleKeys?.[0] ?? '',
        name: '',
        header_text: '',
        footer_text: '',
        show_logo: true,
        show_qr: false,
        show_signature: true,
        show_watermark: false,
        watermark_text: '',
    });
    const editForm = useForm({
        name: '', header_text: '', footer_text: '',
        show_logo: true, show_qr: false, show_signature: true, show_watermark: false, watermark_text: '',
    });

    function submitCreate(e) {
        e.preventDefault();
        createForm.post(route('settings.documents.store'), {
            preserveScroll: true,
            onSuccess: () => createForm.reset('name', 'header_text', 'footer_text', 'watermark_text'),
        });
    }

    function startEdit(t) {
        setEditingId(t.id);
        editForm.setData({
            name: t.name, header_text: t.header_text || '', footer_text: t.footer_text || '',
            show_logo: t.show_logo, show_qr: t.show_qr, show_signature: t.show_signature,
            show_watermark: t.show_watermark, watermark_text: t.watermark_text || '',
        });
    }

    function submitEdit(e, id) {
        e.preventDefault();
        editForm.put(route('settings.documents.update', id), {
            preserveScroll: true,
            onSuccess: () => setEditingId(null),
        });
    }

    // v2.12.0 (Product Finalization pass, Part 19 -- destructive action
    // confirmation): every sibling delete on this page already confirms
    // (companies/departments/positions/KPI categories/users) -- this one
    // was the sole exception, firing instantly on click.
    function destroy(id) {
        if (confirm('Hapus template dokumen ini?')) {
            router.delete(route('settings.documents.destroy', id), { preserveScroll: true });
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Document Templates</CardTitle>
                <CardDescription>
                    One default template per module -- header/footer text and which of logo, QR verification text,
                    signature block, and watermark to show on that module's PDF. Applies automatically the moment
                    it's created; a module with no template here keeps its original built-in appearance.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
                {(documentTemplates ?? []).length === 0 ? (
                    <p className="text-sm text-graphite-400">No document templates yet -- create one below.</p>
                ) : (
                    <div className="space-y-2">
                        {documentTemplates.map((t) => (
                            <div key={t.id} className="rounded-xl border border-steel-100 bg-white/60 dark:border-slate-800">
                                {editingId === t.id ? (
                                    <form onSubmit={(e) => submitEdit(e, t.id)} className="space-y-3 p-4">
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div>
                                                <Label>Name</Label>
                                                <Input value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} />
                                            </div>
                                            <div>
                                                <Label>Watermark Text</Label>
                                                <Input value={editForm.data.watermark_text} onChange={(e) => editForm.setData('watermark_text', e.target.value)} disabled={!editForm.data.show_watermark} />
                                            </div>
                                            <div>
                                                <Label>Header Text</Label>
                                                <Input value={editForm.data.header_text} onChange={(e) => editForm.setData('header_text', e.target.value)} />
                                            </div>
                                            <div>
                                                <Label>Footer Text</Label>
                                                <Input value={editForm.data.footer_text} onChange={(e) => editForm.setData('footer_text', e.target.value)} />
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-4">
                                            {['show_logo', 'show_qr', 'show_signature', 'show_watermark'].map((key) => (
                                                <label key={key} className="flex items-center gap-1.5 text-sm">
                                                    <Checkbox checked={editForm.data[key]} onCheckedChange={(v) => editForm.setData(key, !!v)} />
                                                    {key.replace('show_', '').replace(/^./, (c) => c.toUpperCase())}
                                                </label>
                                            ))}
                                        </div>
                                        <div className="flex gap-2">
                                            <Button type="submit" size="sm" disabled={editForm.processing}>Save</Button>
                                            <Button type="button" size="sm" variant="outline" onClick={() => setEditingId(null)}>Cancel</Button>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="flex items-center justify-between gap-3 p-4">
                                        <div>
                                            <p className="text-sm font-medium text-graphite-800 dark:text-slate-100">
                                                {t.name} <Badge variant="secondary" className="ml-1">{moduleLabel(t.module_key)}</Badge>
                                            </p>
                                            <p className="mt-0.5 text-xs text-graphite-400">
                                                {[t.show_logo && 'Logo', t.show_qr && 'QR', t.show_signature && 'Signature', t.show_watermark && 'Watermark'].filter(Boolean).join(' · ') || 'No chrome enabled'}
                                            </p>
                                        </div>
                                        <div className="flex gap-1.5">
                                            <Button size="icon" variant="ghost" onClick={() => startEdit(t)}><Pencil className="h-3.5 w-3.5" /></Button>
                                            <Button size="icon" variant="ghost" onClick={() => destroy(t.id)}><Trash2 className="h-3.5 w-3.5 text-red-500" /></Button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                <form onSubmit={submitCreate} className="space-y-3 border-t border-graphite-100 pt-4 dark:border-slate-800">
                    <p className="text-sm font-semibold text-graphite-700 dark:text-slate-200">New Template</p>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label>Module</Label>
                            <Select value={createForm.data.module_key} onValueChange={(v) => createForm.setData('module_key', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {(moduleKeys ?? []).map((k) => <SelectItem key={k} value={k}>{moduleLabel(k)}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Name</Label>
                            <Input value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} placeholder="Default Letterhead" />
                        </div>
                        <div>
                            <Label>Header Text</Label>
                            <Input value={createForm.data.header_text} onChange={(e) => createForm.setData('header_text', e.target.value)} />
                        </div>
                        <div>
                            <Label>Footer Text</Label>
                            <Input value={createForm.data.footer_text} onChange={(e) => createForm.setData('footer_text', e.target.value)} />
                        </div>
                        <div>
                            <Label>Watermark Text</Label>
                            <Input value={createForm.data.watermark_text} onChange={(e) => createForm.setData('watermark_text', e.target.value)} disabled={!createForm.data.show_watermark} placeholder="CONFIDENTIAL" />
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-4">
                        {['show_logo', 'show_qr', 'show_signature', 'show_watermark'].map((key) => (
                            <label key={key} className="flex items-center gap-1.5 text-sm">
                                <Checkbox checked={createForm.data[key]} onCheckedChange={(v) => createForm.setData(key, !!v)} />
                                {key.replace('show_', '').replace(/^./, (c) => c.toUpperCase())}
                            </label>
                        ))}
                    </div>
                    <Button type="submit" disabled={createForm.processing || !createForm.data.name}>
                        {createForm.processing && <Loader2 className="h-4 w-4 animate-spin" />} Create Template
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}
