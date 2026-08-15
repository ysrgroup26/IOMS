import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Checkbox } from '@/Components/ui/checkbox';
import { Plus, Pencil, Trash2, AlertCircle, HardHat, ClipboardList, ShieldAlert, Package } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Milestone 4, Workstream B0 (HSE Foundation/Master Data), IA reworked
 * v1.11.3 (Global Dashboard/Overview UX Rework, Part 1). Six CRUD
 * sections (Hazard Categories, Equipment Types, Safety Equipment,
 * HSE Materials, P3K Boxes, Checklist Templates) used to stack flat, top
 * to bottom, with no grouping -- confusing as "one long unrelated CRUD
 * page" per explicit user feedback. Grouped into 4 tabs based on actual
 * data relationships (audited, not assumed):
 *
 * - Safety Equipment: Equipment Types (config) -> Safety Equipment
 *   Register (physical items) -> Inspection History (child records) --
 *   a strict producer/consumer chain.
 * - Inspection Templates: Checklist Templates only -- reusable
 *   definitions, kept separate from actual inspection EXECUTION records
 *   (which live on a different route entirely, HseInspections/*,
 *   untouched by this page).
 * - Hazard Categories: its own tab -- feeds Safety Observations, an
 *   unrelated consumer to both groups above (NOT folded into "risk
 *   references" broadly; it has no relation to RiskAssessment/JSA).
 * - HSE Supplies & Facilities: HSE Materials + P3K Boxes -- both are
 *   stock/facility registers with inspection-due tracking, the closest
 *   in shape to each other of anything left.
 *
 * Client-side, single-route tabs (not `ModuleTabNav`'s route-per-tab
 * pattern -- all 6 sections still share one efficient, N+1-free
 * controller call, `HazardCategoryController::master()`; splitting into
 * separate routes would be new backend surface for no benefit). `?tab=`
 * mirrors the active tab via plain history API (no Inertia round-trip --
 * every section's data is already loaded) so links/bookmarks still work.
 * Every section component below is UNCHANGED internally -- this only
 * moves and groups them.
 */
const TABS = [
    { key: 'equipment', label: 'Safety Equipment', icon: HardHat },
    { key: 'templates', label: 'Inspection Templates', icon: ClipboardList },
    { key: 'hazards', label: 'Hazard Categories', icon: ShieldAlert },
    { key: 'supplies', label: 'HSE Supplies & Facilities', icon: Package },
];

function initialTab() {
    if (typeof window === 'undefined') return TABS[0].key;
    const requested = new URLSearchParams(window.location.search).get('tab');
    return TABS.some((t) => t.key === requested) ? requested : TABS[0].key;
}

export default function HseMaster({ hazardCategories, safetyEquipment, equipmentTypes, hseMaterials, p3kBoxes, checklistTemplates = [], inspectionTypes = [], assets = [], companies, can }) {
    const [activeTab, setActiveTab] = useState(initialTab);

    function selectTab(key) {
        setActiveTab(key);
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', key);
            window.history.replaceState({}, '', url);
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title="HSE Master Data" />

            <div className="mb-4">
                <h1 className="text-lg font-bold tracking-tight text-graphite-900 dark:text-slate-50">HSE Master Data</h1>
                <p className="mt-0.5 text-xs text-graphite-500 dark:text-slate-400">
                    Configure HSE catalogs shared across HSE modules. Nothing here is hard-coded.
                </p>
            </div>

            <div className="mb-4 flex flex-wrap gap-1 border-b border-graphite-200 dark:border-slate-800">
                {TABS.map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => selectTab(tab.key)}
                        className={cn(
                            'flex items-center gap-1.5 border-b-2 px-3 py-1.5 text-xs font-medium transition-colors',
                            activeTab === tab.key
                                ? 'border-brand-600 text-brand-700 dark:text-brand-400'
                                : 'border-transparent text-graphite-500 hover:border-graphite-300 hover:text-graphite-800 dark:text-slate-400 dark:hover:text-slate-200'
                        )}
                    >
                        <tab.icon className="h-3.5 w-3.5" /> {tab.label}
                    </button>
                ))}
            </div>

            <div className="space-y-4">
                {activeTab === 'equipment' && (
                    <>
                        <EquipmentTypesSection equipmentTypes={equipmentTypes} companies={companies} can={can} />
                        <SafetyEquipmentSection safetyEquipment={safetyEquipment} equipmentTypes={equipmentTypes} assets={assets} companies={companies} can={can} />
                    </>
                )}
                {activeTab === 'templates' && (
                    <ChecklistTemplatesSection checklistTemplates={checklistTemplates} inspectionTypes={inspectionTypes} companies={companies} can={can} />
                )}
                {activeTab === 'hazards' && (
                    <HazardCategoriesSection hazardCategories={hazardCategories} companies={companies} can={can} />
                )}
                {activeTab === 'supplies' && (
                    <>
                        <HseMaterialSection hseMaterials={hseMaterials} companies={companies} can={can} />
                        <P3kBoxSection p3kBoxes={p3kBoxes} companies={companies} can={can} />
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

/** Extracted from HseMaster's own body (was inline, unlike every other section) so it follows the same named-component pattern as EquipmentTypesSection/SafetyEquipmentSection/etc -- CRUD logic unchanged. */
function HazardCategoriesSection({ hazardCategories, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '',
        code: '',
        description: '',
        is_active: true,
    });

    function openCreate() {
        setEditing(null);
        reset();
        setOpen(true);
    }

    function openEdit(category) {
        setEditing(category);
        setData({
            company_id: String(category.company_id),
            name: category.name,
            code: category.code ?? '',
            description: category.description ?? '',
            is_active: category.is_active,
        });
        setOpen(true);
    }

    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) {
            put(route('hazard-categories.update', editing.id), options);
        } else {
            post(route('hazard-categories.store'), options);
        }
    }

    function destroy(category) {
        if (confirm(`Remove hazard category "${category.name}"? Only possible if no safety observation uses it.`)) {
            router.delete(route('hazard-categories.destroy', category.id));
        }
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div>
                    <CardTitle>Hazard Categories</CardTitle>
                    <CardDescription>{hazardCategories.length} configured -- used by Safety Observation</CardDescription>
                </div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Category</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Code</TableHead>
                            <TableHead>Observations</TableHead>
                            <TableHead>Status</TableHead>
                            {can.manage && <TableHead />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {hazardCategories.map((c) => (
                            <TableRow key={c.id}>
                                <TableCell className="font-medium">{c.name}</TableCell>
                                <TableCell className="text-graphite-500">{c.code || '-'}</TableCell>
                                <TableCell>{c.safety_observations_count}</TableCell>
                                <TableCell><Badge variant={c.is_active ? 'success' : 'secondary'}>{c.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                {can.manage && (
                                    <TableCell className="flex gap-1">
                                        <Button variant="ghost" size="icon" onClick={() => openEdit(c)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(c)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit Hazard Category' : 'Add Hazard Category'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Name</Label>
                                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Working at Height" />
                                {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Code (optional)</Label>
                                <Input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. WAH" />
                                {errors.code && <p className="text-xs text-red-600">{errors.code}</p>}
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Description (optional)</Label>
                            <Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} />
                            Active
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={processing}>Save</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

function SafetyEquipmentSection({ safetyEquipment, equipmentTypes, assets = [], companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [inspecting, setInspecting] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '', type: equipmentTypes[0]?.code || '', location: '', serial_number: '',
        last_inspection_date: '', next_inspection_due: '', status: 'active', notes: '', asset_id: '',
    }).transform((formData) => ({ ...formData, asset_id: formData.asset_id || null }));

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(e) {
        setEditing(e);
        setData({
            company_id: String(e.company_id), name: e.name, type: e.type, location: e.location || '',
            serial_number: e.serial_number || '', last_inspection_date: e.last_inspection_date?.slice(0, 10) || '',
            next_inspection_due: e.next_inspection_due?.slice(0, 10) || '', status: e.status, notes: e.notes || '',
            asset_id: e.asset_id ? String(e.asset_id) : '',
        });
        setOpen(true);
    }
    function submit(ev) {
        ev.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('safety-equipment.update', editing.id), options); } else { post(route('safety-equipment.store'), options); }
    }
    function destroy(e) {
        if (confirm(`Remove "${e.name}"?`)) router.delete(route('safety-equipment.destroy', e.id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div><CardTitle>Safety Equipment</CardTitle><CardDescription>{safetyEquipment.length} configured -- fire extinguishers, safety showers, emergency facilities</CardDescription></div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Equipment</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Type</TableHead><TableHead>Location</TableHead><TableHead>Next Inspection</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                    <TableBody>
                        {safetyEquipment.map((e) => (
                            <TableRow key={e.id} className={e.is_overdue ? 'bg-red-50/50' : ''}>
                                <TableCell className="font-medium">{e.name}</TableCell>
                                <TableCell className="capitalize">{e.type.replace('_', ' ')}</TableCell>
                                <TableCell>{e.location || '-'}</TableCell>
                                <TableCell>{e.next_inspection_due ? new Date(e.next_inspection_due).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'}{e.is_overdue && <AlertCircle className="ml-1 inline h-3.5 w-3.5 text-red-500" />}</TableCell>
                                <TableCell><Badge variant={e.status === 'active' ? 'success' : 'secondary'}>{e.status.replace('_', ' ')}</Badge></TableCell>
                                {can.manage && (
                                    <TableCell className="flex gap-1">
                                        <Button variant="outline" size="sm" onClick={() => setInspecting(e)}>Inspect</Button>
                                        <Button variant="ghost" size="icon" onClick={() => openEdit(e)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(e)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
            {inspecting && <EquipmentInspectionDialog equipment={inspecting} onClose={() => setInspecting(null)} />}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} Safety Equipment</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                            <div className="space-y-1.5">
                                <Label>Type</Label>
                                <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {/* v1.11.1: sourced from the configurable HseEquipmentType master
                                            (Settings/HSE Master -- Equipment Types section below) instead
                                            of a hardcoded list -- Super Admin can add new types (e.g. a
                                            future category) without a code change. */}
                                        {equipmentTypes.map((t) => <SelectItem key={t.code} value={t.code}>{t.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Location</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Serial Number</Label><Input value={data.serial_number} onChange={(e) => setData('serial_number', e.target.value)} /></div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Last Inspection</Label><Input type="date" value={data.last_inspection_date} onChange={(e) => setData('last_inspection_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Next Inspection Due</Label><Input type="date" value={data.next_inspection_due} onChange={(e) => setData('next_inspection_due', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Link to Company Asset (optional)</Label>
                            <Select value={data.asset_id || '__none'} onValueChange={(v) => setData('asset_id', v === '__none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="Not linked" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none">Not linked</SelectItem>
                                    {assets.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.asset_code ? `${a.asset_code} -- ${a.name}` : a.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-graphite-400">Only if this equipment is also tracked in the general Asset register (e.g. a capitalized gas detector). Purely optional -- HSE tracking works fully without it.</p>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Status</Label>
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="active">Active</SelectItem><SelectItem value="out_of_service">Out of Service</SelectItem></SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Notes</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

/**
 * v1.11.1 (HSE Domain Hardening II, Part 8). Real inspection history --
 * shows past SafetyEquipmentInspection rows (up to what the backend
 * eager-loaded) and a form to record a new one. Same "child table,
 * never overwritten" pattern GasTestRecord's own multi-stage history
 * already established.
 */
function EquipmentInspectionDialog({ equipment, onClose }) {
    const { data, setData, post, processing, reset } = useForm({
        inspection_date: new Date().toISOString().slice(0, 10),
        condition: 'good', result: 'pass', findings: '', next_inspection_due: '', notes: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('safety-equipment.inspections.store', equipment.id), { preserveScroll: true, onSuccess: () => reset() });
    }

    const RESULT_BADGE = { pass: 'success', fail: 'destructive', needs_action: 'secondary' };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <DialogHeader><DialogTitle>Inspection History -- {equipment.name}</DialogTitle></DialogHeader>

                <form onSubmit={submit} className="mb-4 grid grid-cols-2 gap-3 rounded-md border border-graphite-100 p-3 dark:border-slate-800">
                    <div className="space-y-1"><Label className="text-xs">Date</Label><Input type="date" value={data.inspection_date} onChange={(e) => setData('inspection_date', e.target.value)} /></div>
                    <div className="space-y-1">
                        <Label className="text-xs">Condition</Label>
                        <Select value={data.condition} onValueChange={(v) => setData('condition', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>{['good', 'fair', 'poor', 'damaged'].map((c) => <SelectItem key={c} value={c} className="capitalize">{c}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs">Result</Label>
                        <Select value={data.result} onValueChange={(v) => setData('result', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent><SelectItem value="pass">Pass</SelectItem><SelectItem value="fail">Fail</SelectItem><SelectItem value="needs_action">Needs Action</SelectItem></SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1"><Label className="text-xs">Next Due</Label><Input type="date" value={data.next_inspection_due} onChange={(e) => setData('next_inspection_due', e.target.value)} /></div>
                    <div className="col-span-2 space-y-1"><Label className="text-xs">Findings (if any)</Label><Textarea rows={2} value={data.findings} onChange={(e) => setData('findings', e.target.value)} /></div>
                    <div className="col-span-2"><Button type="submit" size="sm" disabled={processing}>Save Inspection</Button></div>
                </form>

                {(equipment.inspections || []).length === 0 ? (
                    <p className="text-center text-sm text-graphite-400">No inspections recorded yet.</p>
                ) : (
                    <ul className="divide-y divide-graphite-100 dark:divide-slate-800">
                        {equipment.inspections.map((i) => (
                            <li key={i.id} className="py-2 text-sm">
                                <div className="flex items-center justify-between">
                                    <span className="font-medium">{new Date(i.inspection_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                    <Badge variant={RESULT_BADGE[i.result] ?? 'secondary'} className="capitalize">{i.result.replace('_', ' ')}</Badge>
                                </div>
                                <p className="text-xs text-graphite-400">Condition: {i.condition} -- {i.inspector?.name || 'Unknown'}</p>
                                {i.findings && <p className="text-xs text-graphite-600 dark:text-slate-300">{i.findings}</p>}
                            </li>
                        ))}
                    </ul>
                )}
                <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button></DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function HseMaterialSection({ hseMaterials, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '', category: 'consumable', unit: 'pcs', current_stock: '0', reorder_level: '0', notes: '', is_active: true,
    });

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(m) {
        setEditing(m);
        setData({
            company_id: String(m.company_id), name: m.name, category: m.category, unit: m.unit,
            current_stock: String(m.current_stock), reorder_level: String(m.reorder_level), notes: m.notes || '', is_active: m.is_active,
        });
        setOpen(true);
    }
    function submit(ev) {
        ev.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('hse-materials.update', editing.id), options); } else { post(route('hse-materials.store'), options); }
    }
    function destroy(m) {
        if (confirm(`Remove "${m.name}"?`)) router.delete(route('hse-materials.destroy', m.id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div><CardTitle>HSE Materials &amp; Consumables</CardTitle><CardDescription>{hseMaterials.length} configured -- reordering goes through Material Request</CardDescription></div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Material</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Category</TableHead><TableHead>Stock</TableHead><TableHead>Reorder Level</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                    <TableBody>
                        {hseMaterials.map((m) => (
                            <TableRow key={m.id} className={m.is_low_stock ? 'bg-amber-50/50' : ''}>
                                <TableCell className="font-medium">{m.name}</TableCell>
                                <TableCell className="capitalize">{m.category.replace('_', ' ')}</TableCell>
                                <TableCell>{m.current_stock} {m.unit}{m.is_low_stock && <AlertCircle className="ml-1 inline h-3.5 w-3.5 text-amber-500" />}</TableCell>
                                <TableCell>{m.reorder_level} {m.unit}</TableCell>
                                <TableCell><Badge variant={m.is_active ? 'success' : 'secondary'}>{m.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                {can.manage && (
                                    <TableCell className="flex gap-1">
                                        <Button variant="ghost" size="icon" onClick={() => openEdit(m)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(m)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} HSE Material</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select value={data.category} onValueChange={(v) => setData('category', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{['consumable', 'reusable_material', 'chemical', 'other'].map((c) => <SelectItem key={c} value={c} className="capitalize">{c.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5"><Label>Unit</Label><Input value={data.unit} onChange={(e) => setData('unit', e.target.value)} placeholder="e.g. pcs, box, liter" /></div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Current Stock</Label><Input type="number" min="0" value={data.current_stock} onChange={(e) => setData('current_stock', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Reorder Level</Label><Input type="number" min="0" value={data.reorder_level} onChange={(e) => setData('reorder_level', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Notes</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <label className="flex items-center gap-2 text-sm"><Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} /> Active</label>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

function P3kBoxSection({ p3kBoxes, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        location: '', last_inspection_date: '', next_inspection_due: '', status: 'complete', notes: '',
    });

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(b) {
        setEditing(b);
        setData({
            company_id: String(b.company_id), location: b.location,
            last_inspection_date: b.last_inspection_date?.slice(0, 10) || '',
            next_inspection_due: b.next_inspection_due?.slice(0, 10) || '', status: b.status, notes: b.notes || '',
        });
        setOpen(true);
    }
    function submit(ev) {
        ev.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('p3k-boxes.update', editing.id), options); } else { post(route('p3k-boxes.store'), options); }
    }
    function destroy(b) {
        if (confirm(`Remove P3K box at "${b.location}"?`)) router.delete(route('p3k-boxes.destroy', b.id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div><CardTitle>P3K / First Aid Stations</CardTitle><CardDescription>{p3kBoxes.length} configured -- operational inspection only, not a medical records system</CardDescription></div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add P3K Box</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Location</TableHead><TableHead>Last Inspection</TableHead><TableHead>Next Due</TableHead><TableHead>Inspected By</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                    <TableBody>
                        {p3kBoxes.map((b) => (
                            <TableRow key={b.id} className={b.is_overdue ? 'bg-red-50/50' : ''}>
                                <TableCell className="font-medium">{b.location}</TableCell>
                                <TableCell>{b.last_inspection_date ? new Date(b.last_inspection_date).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'}</TableCell>
                                <TableCell>{b.next_inspection_due ? new Date(b.next_inspection_due).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : '-'}{b.is_overdue && <AlertCircle className="ml-1 inline h-3.5 w-3.5 text-red-500" />}</TableCell>
                                <TableCell>{b.inspector?.name || '-'}</TableCell>
                                <TableCell><Badge variant={b.status === 'complete' ? 'success' : 'destructive'}>{b.status}</Badge></TableCell>
                                {can.manage && (
                                    <TableCell className="flex gap-1">
                                        <Button variant="ghost" size="icon" onClick={() => openEdit(b)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(b)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} P3K Box</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Location</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} />{errors.location && <p className="text-xs text-red-600">{errors.location}</p>}</div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Last Inspection</Label><Input type="date" value={data.last_inspection_date} onChange={(e) => setData('last_inspection_date', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Next Inspection Due</Label><Input type="date" value={data.next_inspection_due} onChange={(e) => setData('next_inspection_due', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Status</Label>
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="complete">Complete</SelectItem><SelectItem value="incomplete">Incomplete</SelectItem></SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Notes</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

/**
 * v1.11.2 (Final Completion Pass, Part 7). The management UI the previous
 * pass left out -- backend (`hse-equipment-types.*` routes/controller,
 * `HseEquipmentType` model) already existed and works; this is purely the
 * missing frontend. Same CRUD shape as HazardCategorySection above (this
 * file's own established pattern) -- create/edit/deactivate/remove. New
 * types created here become selectable in SafetyEquipmentSection's own
 * Type <Select> immediately (both sections consume the same `equipmentTypes`
 * prop threaded from HazardCategoryController::master()).
 */
function EquipmentTypesSection({ equipmentTypes, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '', code: '', description: '', is_active: true, sort_order: 0,
    });

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(t) {
        setEditing(t);
        setData({
            company_id: String(t.company_id), name: t.name, code: t.code,
            description: t.description || '', is_active: t.is_active, sort_order: t.sort_order,
        });
        setOpen(true);
    }
    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('hse-equipment-types.update', editing.id), options); } else { post(route('hse-equipment-types.store'), options); }
    }
    function destroy(t) {
        if (confirm(`Remove equipment type "${t.name}"? Only possible if no equipment currently uses it -- deactivate instead if unsure.`)) {
            router.delete(route('hse-equipment-types.destroy', t.id));
        }
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div>
                    <CardTitle>Equipment Types</CardTitle>
                    <CardDescription>{equipmentTypes.length} configured -- APAR, HT, Gas Detector, Blower, TOA, and any future category. Selectable when registering Safety Equipment below.</CardDescription>
                </div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Type</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Code</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                    <TableBody>
                        {equipmentTypes.map((t) => (
                            <TableRow key={t.id}>
                                <TableCell className="font-medium">{t.name}</TableCell>
                                <TableCell className="text-graphite-500"><code className="text-xs">{t.code}</code></TableCell>
                                <TableCell><Badge variant={t.is_active ? 'success' : 'secondary'}>{t.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                {can.manage && (
                                    <TableCell className="flex gap-1">
                                        <Button variant="ghost" size="icon" onClick={() => openEdit(t)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(t)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} Equipment Type</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        {!editing && (
                            <div className="space-y-1.5">
                                <Label>Company</Label>
                                <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Emergency Light" />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                            <div className="space-y-1.5">
                                <Label>Code</Label>
                                <Input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. emergency_light" disabled={!!editing} />
                                {errors.code && <p className="text-xs text-red-600">{errors.code}</p>}
                                {editing && <p className="text-xs text-graphite-400">Code can't change once equipment may reference it.</p>}
                            </div>
                        </div>
                        <div className="space-y-1.5"><Label>Description (optional)</Label><Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} /></div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} />
                            Active
                        </label>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

/**
 * v1.11.2 (Final Completion Pass, Part 9). LSA/FFA/PPE checklist TEMPLATES
 * -- the reusable item lists the previous pass's category labels alone
 * didn't cover. A template is just a named seed for
 * HseInspection.checklist_items (already JSON, already fully configurable)
 * -- reuses the existing inspection engine, no second FFA/LSA/PPE system.
 * `category` reuses HseInspection::TYPES (`inspectionTypes` prop) as its
 * own source of truth, so a future inspection type is automatically a
 * valid template category with no code change here.
 */
function ChecklistTemplatesSection({ checklistTemplates, inspectionTypes, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors, transform } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        category: inspectionTypes[0] ?? 'ppe',
        name: '',
        items: [{ label: '' }],
        is_active: true,
        sort_order: 0,
    });

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(t) {
        setEditing(t);
        setData({
            company_id: String(t.company_id), category: t.category, name: t.name,
            items: t.items?.length ? t.items : [{ label: '' }],
            is_active: t.is_active, sort_order: t.sort_order,
        });
        setOpen(true);
    }
    function submit(e) {
        e.preventDefault();
        transform((d) => ({ ...d, items: d.items.filter((i) => i.label.trim() !== '') }));
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('hse-checklist-templates.update', editing.id), options); } else { post(route('hse-checklist-templates.store'), options); }
    }
    function destroy(t) {
        if (confirm(`Remove checklist template "${t.name}"?`)) {
            router.delete(route('hse-checklist-templates.destroy', t.id));
        }
    }
    function updateItemLabel(i, value) {
        const items = [...data.items];
        items[i] = { ...items[i], label: value };
        setData('items', items);
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div>
                    <CardTitle>Checklist Templates</CardTitle>
                    <CardDescription>{checklistTemplates.length} configured -- reusable item lists for LSA/FFA/PPE and any other inspection category. Selectable via "Load Template" when recording an HSE Inspection.</CardDescription>
                </div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Template</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Category</TableHead><TableHead>Items</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                    <TableBody>
                        {checklistTemplates.map((t) => (
                            <TableRow key={t.id}>
                                <TableCell className="font-medium">{t.name}</TableCell>
                                <TableCell className="capitalize">{t.category.replace('_', ' ')}</TableCell>
                                <TableCell>{t.items?.length ?? 0}</TableCell>
                                <TableCell><Badge variant={t.is_active ? 'success' : 'secondary'}>{t.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                {can.manage && (
                                    <TableCell className="flex gap-1">
                                        <Button variant="ghost" size="icon" onClick={() => openEdit(t)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(t)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} Checklist Template</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        {!editing && (
                            <div className="space-y-1.5">
                                <Label>Company</Label>
                                <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Standard FFA Inspection" />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select value={data.category} onValueChange={(v) => setData('category', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{inspectionTypes.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <div className="flex items-center justify-between">
                                <Label>Checklist Items</Label>
                                <Button type="button" variant="outline" size="sm" onClick={() => setData('items', [...data.items, { label: '' }])}><Plus className="h-3.5 w-3.5" /> Add Item</Button>
                            </div>
                            <div className="max-h-64 space-y-1.5 overflow-y-auto">
                                {data.items.map((item, i) => (
                                    <div key={i} className="flex items-center gap-1.5">
                                        <Input value={item.label} onChange={(e) => updateItemLabel(i, e.target.value)} placeholder={`Item ${i + 1}`} />
                                        <Button type="button" variant="ghost" size="icon" onClick={() => setData('items', data.items.filter((_, idx) => idx !== i))}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </div>
                                ))}
                            </div>
                            {errors.items && <p className="text-xs text-red-600">{errors.items}</p>}
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} />
                            Active
                        </label>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
