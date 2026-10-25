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
import { Plus, Pencil, Trash2, AlertCircle, ShieldCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import PageHeader from '@/Components/shared/PageHeader';
import AreaNav, { AREA_GROUPS } from '@/Components/hse-equipment/AreaNav';

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
 * - Supplies & First Aid (named "HSE Supplies & Facilities" until
 *   v2.71.0): HSE Materials + P3K Boxes -- both are stock/facility
 *   registers with inspection-due tracking, the closest in shape to each
 *   other of anything left.
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
/**
 * v2.71.0 -- THE TABS NOW SAY WHICH KIND OF THING THEY HOLD.
 *
 * Five sibling tabs of equal weight, and no way to tell that two of them
 * hold the equipment the company actually owns while three define what
 * the system offers to choose from. Reported as the area "feeling rigid"
 * and as Equipment Register being indistinguishable from Supplies --
 * which it was, because nothing said they were different KINDS of list.
 *
 * They are now grouped under two headings in the tab strip itself, and
 * the page header's chip follows the ACTIVE tab rather than labelling the
 * whole page. That is the honest thing to do here: this page genuinely
 * contains both, and claiming it is entirely one or the other would
 * mislabel half of it. See PageHeader's own `kind` note for the product-
 * wide convention.
 *
 * Two labels changed, nothing moved:
 *   "Equipment Master"          -> "Equipment Types"  (it defines types;
 *                                  "Master" is now carried by the chip)
 *   "HSE Supplies & Facilities" -> "Supplies & First Aid"
 * Routes, tab keys, data and every `?tab=` bookmark are unchanged.
 */
// v2.133.0 (ADR 074): the tab strip is the area's shared AreaNav. The
// register left this page for its own route (`?tab=register` redirects
// there server side); the four tabs below still switch in place.
const TABS = AREA_GROUPS.flatMap((group) => group.tabs.map((tab) => ({ ...tab, kind: group.kind })))
    .filter((tab) => !tab.route);

function initialTab() {
    if (typeof window === 'undefined') return TABS[0].key;
    const requested = new URLSearchParams(window.location.search).get('tab');
    return TABS.some((t) => t.key === requested) ? requested : TABS[0].key;
}

export default function HseMaster({ hazardCategories, equipmentTypes, equipmentCounts = {}, hseMaterials, p3kBoxes, checklistTemplates = [], inspectionTypes = [], companies, can }) {
    const [activeTab, setActiveTab] = useState(initialTab);
    const active = TABS.find((t) => t.key === activeTab);

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
            <Head title="Safety Equipment & Compliance" />

            {/* v2.71.0: was a bare <h1>/<p> on the page background while
                every other module page had moved to the shared PageHeader
                surface -- which is most of why this area read as a generic
                admin screen rather than part of IOMS. Same title, same
                route; it now sits on the product's own module-page
                surface, carries its icon chip, and states which KIND of
                data the open tab holds. */}
            <PageHeader
                icon={ShieldCheck}
                title="Safety Equipment & Compliance"
                kind={active?.kind}
                subtitle={
                    active?.kind === 'master'
                        ? 'Definisi yang dipakai seluruh modul HSE. Mengubahnya mengubah pilihan yang tersedia bagi semua orang berikutnya, dan tidak mengubah data yang sudah tercatat.'
                        : 'Bahan habis pakai dan perlengkapan P3K. Stoknya dihitung dan diisi ulang, berbeda dengan peralatan yang diinspeksi per unit di Equipment Register.'
                }
            />

            <AreaNav active={activeTab} onSelect={selectTab} />

            <div className="space-y-4">
                {activeTab === 'equipment-master' && (
                    <EquipmentTypesSection equipmentTypes={equipmentTypes} equipmentCounts={equipmentCounts} companies={companies} can={can} />
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
                    <CardDescription>{hazardCategories.length} kategori tersedia. Dipakai saat mencatat Safety Observation.</CardDescription>
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
                            <Label>Operating Unit</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select operating unit" /></SelectTrigger>
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
                                <Input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. WAH" uppercase />
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
                <div><CardTitle>HSE Materials &amp; Consumables</CardTitle><CardDescription>{hseMaterials.length} item terdaftar. Permintaan penambahan stok diajukan melalui Material Request.</CardDescription></div>
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
                            <Label>Operating Unit</Label>
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
                <div><CardTitle>P3K / First Aid Stations</CardTitle><CardDescription>{p3kBoxes.length} titik terdaftar. Hanya untuk inspeksi kelengkapan kotak P3K, bukan rekam medis.</CardDescription></div>
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
                            <Label>Operating Unit</Label>
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
const LIFECYCLE_FLAGS = [
    ['tracks_inspection', 'Inspection'],
    ['tracks_calibration', 'Calibration'],
    ['tracks_service', 'Service'],
    ['tracks_expiry', 'Expiry'],
];

/**
 * v2.133.0 (ADR 074): a type now declares its unit ID prefix and which
 * lifecycles it tracks. v2.53.0 added those columns and no form could set
 * them, so every type tracked inspection only and every unit was an "EQ".
 */
function EquipmentTypesSection({ equipmentTypes, equipmentCounts = {}, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const blank = {
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '', code: '', code_prefix: '', description: '', is_active: true, sort_order: 0,
        tracks_inspection: true, tracks_calibration: false, tracks_service: false, tracks_expiry: false,
        inspection_interval_months: '',
    };
    const { data, setData, post, put, processing, reset, errors, transform } = useForm(blank);
    transform((form) => ({ ...form, inspection_interval_months: form.inspection_interval_months === '' ? null : Number(form.inspection_interval_months) }));

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(t) {
        setEditing(t);
        setData({
            company_id: String(t.company_id), name: t.name, code: t.code, code_prefix: t.code_prefix || '',
            description: t.description || '', is_active: t.is_active, sort_order: t.sort_order,
            tracks_inspection: !!t.tracks_inspection, tracks_calibration: !!t.tracks_calibration,
            tracks_service: !!t.tracks_service, tracks_expiry: !!t.tracks_expiry,
            inspection_interval_months: t.inspection_interval_months ?? '',
        });
        setOpen(true);
    }
    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('hse-equipment-types.update', editing.id), options); } else { post(route('hse-equipment-types.store'), options); }
    }
    function destroy(t) {
        if (confirm(`Remove equipment type "${t.name}"? This is only possible when no unit uses it. Deactivate it instead if unsure.`)) {
            router.delete(route('hse-equipment-types.destroy', t.id));
        }
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div>
                    <CardTitle>Equipment Types</CardTitle>
                    <CardDescription>{equipmentTypes.length} jenis. Jenis menentukan awalan ID unit dan tanggal apa yang dilacak (inspeksi, kalibrasi, servis, kedaluwarsa) di Equipment Register.</CardDescription>
                </div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Type</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>ID Prefix</TableHead><TableHead>Tracks</TableHead><TableHead>Units</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                    <TableBody>
                        {equipmentTypes.map((t) => (
                            <TableRow key={t.id}>
                                <TableCell className="font-medium">{t.name}<p className="font-mono text-[11px] text-graphite-400">{t.code}</p></TableCell>
                                <TableCell className="font-mono text-xs">{t.code_prefix || <span className="font-sans text-graphite-400">EQ (default)</span>}</TableCell>
                                <TableCell className="text-xs">
                                    {LIFECYCLE_FLAGS.filter(([k]) => t[k]).map(([, label]) => label).join(', ') || <span className="text-graphite-400">Nothing</span>}
                                    {t.inspection_interval_months && <span className="block text-[11px] text-graphite-400">every {t.inspection_interval_months} months</span>}
                                </TableCell>
                                <TableCell className="tabular-nums">{equipmentCounts[t.id] ?? 0}</TableCell>
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
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} Equipment Type</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        {!editing && companies.length > 1 && (
                            <div className="space-y-1.5">
                                <Label>Operating Unit</Label>
                                <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Fire Extinguisher (APAR)" />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                            <div className="space-y-1.5">
                                <Label>Code</Label>
                                <Input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="fire_extinguisher" disabled={!!editing} />
                                {errors.code && <p className="text-xs text-red-600">{errors.code}</p>}
                                {editing && <p className="text-xs text-graphite-400">Kode tidak dapat diubah setelah dibuat.</p>}
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Unit ID Prefix</Label>
                                <Input value={data.code_prefix} onChange={(e) => setData('code_prefix', e.target.value.toUpperCase())} placeholder="APAR" className="font-mono" maxLength={12} />
                                {errors.code_prefix ? <p className="text-xs text-red-600">{errors.code_prefix}</p> : <p className="text-xs text-graphite-400">Unit baru mendapat ID seperti {(data.code_prefix || 'EQ')}-00001. ID yang sudah ada tidak berubah.</p>}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Inspection Interval (months)</Label>
                                <Input type="number" min={1} max={120} value={data.inspection_interval_months} onChange={(e) => setData('inspection_interval_months', e.target.value)} placeholder="6" />
                                {errors.inspection_interval_months ? <p className="text-xs text-red-600">{errors.inspection_interval_months}</p> : <p className="text-xs text-graphite-400">Dipakai untuk mengusulkan jatuh tempo berikutnya.</p>}
                            </div>
                        </div>
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">Tracked Lifecycles</legend>
                            <p className="text-xs text-graphite-400">Hanya tanggal yang dicentang yang diminta saat mendaftarkan unit jenis ini.</p>
                            <div className="grid grid-cols-2 gap-2">
                                {LIFECYCLE_FLAGS.map(([key, label]) => (
                                    <label key={key} className="flex items-center gap-2 text-sm">
                                        <Checkbox checked={data[key]} onCheckedChange={(v) => setData(key, !!v)} /> {label}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <div className="space-y-1.5"><Label>Description</Label><Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} /></div>
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
                    <CardDescription>{checklistTemplates.length} template tersedia: daftar poin yang dapat dipakai ulang untuk LSA, FFA, PPE, dan kategori inspeksi lainnya. Dipanggil lewat tombol Load Template saat mengisi HSE Inspection.</CardDescription>
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
                                <Label>Operating Unit</Label>
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
