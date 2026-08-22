import { Head, useForm, router, Link } from '@inertiajs/react';
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
import { Plus, Pencil, Trash2, Recycle, Warehouse as WarehouseIcon, ArrowLeft } from 'lucide-react';

/**
 * v1.11.4 (HSE Waste Management, Part 12/15). Waste Types + Waste
 * Storage/TPS master CRUD, one shared setup page -- mirrors
 * Hse/Master.jsx's own multi-section master-data pattern exactly (same
 * per-section Card/Table/Dialog shape, same useForm/openCreate/openEdit/
 * submit/destroy structure). Reached from HSE Master Data's own "Safety
 * Equipment"-style tab structure is NOT reused here since Waste is its
 * own HSE sub-module (own routes, own dashboard) rather than a fifth tab
 * inside hse.master -- linked to from the HSE Overview's compact Waste
 * summary instead.
 */
export default function WasteMaster({ wasteTypes, storageLocations, companies, can }) {
    return (
        <AuthenticatedLayout>
            <Head title="Waste Management Master Data" />

            <Link href={route('waste.dashboard')} className="mb-3 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Waste Management
            </Link>

            <div className="mb-4">
                <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">Waste Management Master Data</h1>
                <p className="mt-0.5 text-xs text-graphite-500 dark:text-slate-400">
                    Configure waste types and storage/TPS locations. Storage limits are an operational monitoring setting, not legal advice.
                </p>
            </div>

            <div className="space-y-4">
                <WasteTypesSection wasteTypes={wasteTypes} companies={companies} can={can} />
                <StorageLocationsSection storageLocations={storageLocations} companies={companies} can={can} />
            </div>
        </AuthenticatedLayout>
    );
}

function WasteTypesSection({ wasteTypes, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '', code: '', category: 'non_b3', waste_code: '', characteristics: '',
        unit: 'kg', storage_limit_days: '', is_active: true, sort_order: 0,
    });

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(t) {
        setEditing(t);
        setData({
            company_id: String(t.company_id), name: t.name, code: t.code, category: t.category,
            waste_code: t.waste_code || '', characteristics: t.characteristics || '', unit: t.unit,
            storage_limit_days: t.storage_limit_days ? String(t.storage_limit_days) : '', is_active: t.is_active, sort_order: t.sort_order,
        });
        setOpen(true);
    }
    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('waste-types.update', editing.id), options); } else { post(route('waste-types.store'), options); }
    }
    function destroy(t) {
        if (confirm(`Remove waste type "${t.name}"? Only possible if no waste record uses it -- deactivate instead if unsure.`)) {
            router.delete(route('waste-types.destroy', t.id));
        }
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div>
                    <CardTitle className="flex items-center gap-2"><Recycle className="h-4 w-4 text-graphite-400" /> Waste Types</CardTitle>
                    <CardDescription>{wasteTypes.length} configured -- B3 &amp; Non-B3 categories, fully tenant-configurable.</CardDescription>
                </div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Type</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Code</TableHead><TableHead>Category</TableHead><TableHead>Storage Limit</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                    <TableBody>
                        {wasteTypes.map((t) => (
                            <TableRow key={t.id}>
                                <TableCell className="font-medium">{t.name}</TableCell>
                                <TableCell className="text-graphite-500"><code className="text-xs">{t.code}</code></TableCell>
                                <TableCell><Badge variant={t.category === 'b3' ? 'destructive' : 'secondary'}>{t.category === 'b3' ? 'B3' : 'Non-B3'}</Badge></TableCell>
                                <TableCell>{t.storage_limit_days ? `${t.storage_limit_days} days` : 'Not configured'}</TableCell>
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
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} Waste Type</DialogTitle></DialogHeader>
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
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Used Oil" />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                            <div className="space-y-1.5">
                                <Label>Code</Label>
                                <Input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. used_oil" disabled={!!editing} />
                                {errors.code && <p className="text-xs text-red-600">{errors.code}</p>}
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select value={data.category} onValueChange={(v) => setData('category', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value="b3">B3 (Hazardous)</SelectItem><SelectItem value="non_b3">Non-B3</SelectItem></SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5"><Label>Regulatory Waste Code (optional)</Label><Input value={data.waste_code} onChange={(e) => setData('waste_code', e.target.value)} placeholder="e.g. B105d" /></div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Unit</Label><Input value={data.unit} onChange={(e) => setData('unit', e.target.value)} placeholder="kg, liter, drum" /></div>
                            <div className="space-y-1.5">
                                <Label>Storage Limit (days, optional)</Label>
                                <Input type="number" min="1" value={data.storage_limit_days} onChange={(e) => setData('storage_limit_days', e.target.value)} placeholder="Operational threshold, not legal advice" />
                            </div>
                        </div>
                        <div className="space-y-1.5"><Label>Characteristics (optional)</Label><Textarea value={data.characteristics} onChange={(e) => setData('characteristics', e.target.value)} rows={2} /></div>
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

function StorageLocationsSection({ storageLocations, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '', code: '', location: '', container_type: '', capacity: '', capacity_unit: '', status: 'active', notes: '',
    });

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(s) {
        setEditing(s);
        setData({
            company_id: String(s.company_id), name: s.name, code: s.code, location: s.location || '',
            container_type: s.container_type || '', capacity: s.capacity ? String(s.capacity) : '',
            capacity_unit: s.capacity_unit || '', status: s.status, notes: s.notes || '',
        });
        setOpen(true);
    }
    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('waste-storage-locations.update', editing.id), options); } else { post(route('waste-storage-locations.store'), options); }
    }
    function destroy(s) {
        if (confirm(`Remove storage location "${s.name}"? Only possible if no waste record uses it.`)) {
            router.delete(route('waste-storage-locations.destroy', s.id));
        }
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <div>
                    <CardTitle className="flex items-center gap-2"><WarehouseIcon className="h-4 w-4 text-graphite-400" /> Storage / TPS Locations</CardTitle>
                    <CardDescription>{storageLocations.length} configured -- separate from the general Warehouse module's own storage locations.</CardDescription>
                </div>
                {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Location</Button>}
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Code</TableHead><TableHead>Container</TableHead><TableHead>Currently Stored</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                    <TableBody>
                        {storageLocations.map((s) => (
                            <TableRow key={s.id}>
                                <TableCell className="font-medium">{s.name}</TableCell>
                                <TableCell className="text-graphite-500"><code className="text-xs">{s.code}</code></TableCell>
                                <TableCell>{s.container_type || '-'}{s.capacity ? ` (${s.capacity} ${s.capacity_unit || ''})` : ''}</TableCell>
                                <TableCell>{s.waste_records_count ?? 0}</TableCell>
                                <TableCell><Badge variant={s.status === 'active' ? 'success' : 'secondary'}>{s.status}</Badge></TableCell>
                                {can.manage && (
                                    <TableCell className="flex gap-1">
                                        <Button variant="ghost" size="icon" onClick={() => openEdit(s)}><Pencil className="h-4 w-4" /></Button>
                                        <Button variant="ghost" size="icon" onClick={() => destroy(s)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} Storage / TPS Location</DialogTitle></DialogHeader>
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
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. TPS Area A" />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                            <div className="space-y-1.5"><Label>Code</Label><Input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. TPS-A" />{errors.code && <p className="text-xs text-red-600">{errors.code}</p>}</div>
                        </div>
                        <div className="space-y-1.5"><Label>Physical Location (optional)</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} /></div>
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-1.5"><Label>Container Type</Label><Input value={data.container_type} onChange={(e) => setData('container_type', e.target.value)} placeholder="drum, IBC, other" /></div>
                            <div className="space-y-1.5"><Label>Capacity</Label><Input type="number" min="0" value={data.capacity} onChange={(e) => setData('capacity', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Unit</Label><Input value={data.capacity_unit} onChange={(e) => setData('capacity_unit', e.target.value)} placeholder="liter, kg" /></div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Status</Label>
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="active">Active</SelectItem><SelectItem value="inactive">Inactive</SelectItem></SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Notes (optional)</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
