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
import { Plus, Pencil, Trash2, AlertCircle } from 'lucide-react';

/**
 * Milestone 4, Workstream B0 (HSE Foundation/Master Data). Hazard Category
 * master on its own setup page -- mirrors Shifts/Master.jsx's section
 * shape exactly. Future HSE masters (Safety Equipment types, Safety
 * Material types, etc.) land on this same page as additional sections
 * rather than each growing its own standalone route.
 */
export default function HseMaster({ hazardCategories, safetyEquipment, hseMaterials, p3kBoxes, companies, can }) {
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
        <AuthenticatedLayout>
            <Head title="HSE Master Data" />

            <div className="mb-6">
                <h1 className="text-lg font-bold tracking-tight text-graphite-900">HSE Master Data</h1>
                <p className="mt-1 text-sm text-graphite-500">
                    Configure HSE catalogs shared across HSE modules. Nothing here is hard-coded.
                </p>
            </div>

            <div className="space-y-6">
            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle>Hazard Categories</CardTitle>
                        <CardDescription>{hazardCategories.length} configured -- used by Safety Observation and future HIRADC/JSA</CardDescription>
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

            <SafetyEquipmentSection safetyEquipment={safetyEquipment} companies={companies} can={can} />
            <HseMaterialSection hseMaterials={hseMaterials} companies={companies} can={can} />
            <P3kBoxSection p3kBoxes={p3kBoxes} companies={companies} can={can} />
            </div>
        </AuthenticatedLayout>
    );
}

function SafetyEquipmentSection({ safetyEquipment, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '', type: 'fire_extinguisher', location: '', serial_number: '',
        last_inspection_date: '', next_inspection_due: '', status: 'active', notes: '',
    });

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(e) {
        setEditing(e);
        setData({
            company_id: String(e.company_id), name: e.name, type: e.type, location: e.location || '',
            serial_number: e.serial_number || '', last_inspection_date: e.last_inspection_date?.slice(0, 10) || '',
            next_inspection_due: e.next_inspection_due?.slice(0, 10) || '', status: e.status, notes: e.notes || '',
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
                                        <Button variant="ghost" size="icon" onClick={() => openEdit(e)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(e)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
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
                                        {['fire_extinguisher', 'safety_shower', 'eyewash_station', 'emergency_alarm', 'spill_kit', 'other'].map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}
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
