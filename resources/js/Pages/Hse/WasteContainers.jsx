import { Head, useForm, router, Link } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatCard from '@/Components/shared/StatCard';
import EmptyState from '@/Components/shared/EmptyState';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Boxes, PackageCheck, PackageX, Wrench, Plus, Pencil, Trash2, ArrowLeft } from 'lucide-react';

const STATUS_BADGE = { active: 'success', under_maintenance: 'secondary', disposed: 'secondary' };
const STATUS_LABEL = { active: 'Active', under_maintenance: 'Under Maintenance', disposed: 'Disposed' };

/**
 * v2.3.0 (HSE Operations + IOMS OS Ecosystem pass, Part 7-11). Waste
 * Container Inventory -- physical container/equipment stock (drums, IBC
 * tanks, jumbo bags), tracked as total/available/in_use/damaged counts.
 * Deliberately a SEPARATE concept from Waste Records (actual waste
 * material by weight/volume, see waste-records.index) -- this page never
 * shows or edits waste material quantities, only container/equipment
 * counts. Mirrors Hse/WasteMaster.jsx's own Card/Table/Dialog CRUD shape
 * exactly (same useForm/openCreate/openEdit/submit/destroy structure),
 * not a new page pattern.
 */
export default function WasteContainers({ containers, summary, storageLocations, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        container_type: '', code: '', unit: 'unit',
        total_quantity: 0, in_use_quantity: 0, damaged_quantity: 0,
        capacity: '', capacity_unit: '', storage_location_id: '', status: 'active', notes: '',
    });

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(c) {
        setEditing(c);
        setData({
            company_id: String(c.company_id), container_type: c.container_type, code: c.code || '',
            unit: c.unit, total_quantity: c.total_quantity, in_use_quantity: c.in_use_quantity,
            damaged_quantity: c.damaged_quantity, capacity: c.capacity ? String(c.capacity) : '',
            capacity_unit: c.capacity_unit || '', storage_location_id: c.storage_location_id ? String(c.storage_location_id) : '',
            status: c.status, notes: c.notes || '',
        });
        setOpen(true);
    }
    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('waste-containers.update', editing.id), options); } else { post(route('waste-containers.store'), options); }
    }
    function destroy(c) {
        if (confirm(`Remove container inventory "${c.container_type}"?`)) {
            router.delete(route('waste-containers.destroy', c.id));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title="Waste Inventory" />

            <Link href={route('waste.dashboard')} className="mb-3 inline-flex items-center gap-1 text-sm text-graphite-500 hover:text-graphite-800">
                <ArrowLeft className="h-4 w-4" /> Back to Waste Management
            </Link>

            <div className="mb-4">
                <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900 dark:text-slate-50">Waste Inventory</h1>
                <p className="mt-0.5 text-xs text-graphite-500 dark:text-slate-400">
                    Kelola stok drum, IBC, jumbo bag, dan wadah lain yang dipakai untuk limbah -- ini stok WADAH, bukan stok limbah itu sendiri (lihat Waste Records untuk data limbah).
                </p>
            </div>

            <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard icon={Boxes} value={summary.total} label="Total Containers" />
                <StatCard icon={PackageCheck} value={summary.available} label="Available" accent={summary.available > 0 ? 'green' : null} />
                <StatCard icon={Wrench} value={summary.in_use} label="In Use" accent={summary.in_use > 0 ? 'amber' : null} />
                <StatCard icon={PackageX} value={summary.damaged} label="Damaged" accent={summary.damaged > 0 ? 'red' : null} />
            </div>

            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle className="flex items-center gap-2"><Boxes className="h-4 w-4 text-graphite-400" /> Container Inventory</CardTitle>
                        <CardDescription>{containers.length} tipe wadah tercatat.</CardDescription>
                    </div>
                    {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Container</Button>}
                </CardHeader>
                <CardContent>
                    {containers.length === 0 ? (
                        <EmptyState
                            icon={Boxes}
                            title="Belum ada stok container yang tercatat."
                            description="Tambahkan tipe wadah (drum, IBC, jumbo bag) beserta jumlahnya untuk mulai melacak stok."
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Container Type</TableHead>
                                    <TableHead>Total</TableHead>
                                    <TableHead>Available</TableHead>
                                    <TableHead>In Use</TableHead>
                                    <TableHead>Damaged</TableHead>
                                    <TableHead>Location</TableHead>
                                    <TableHead>Status</TableHead>
                                    {can.manage && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {containers.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell className="font-medium">
                                            {c.container_type}
                                            {c.code && <span className="ml-1.5 text-xs text-graphite-400"><code>{c.code}</code></span>}
                                            {c.capacity && <span className="block text-xs text-graphite-400">{c.capacity} {c.capacity_unit}</span>}
                                        </TableCell>
                                        <TableCell>{c.total_quantity} {c.unit}</TableCell>
                                        <TableCell>{c.available_quantity}</TableCell>
                                        <TableCell>{c.in_use_quantity}</TableCell>
                                        <TableCell>{c.damaged_quantity}</TableCell>
                                        <TableCell className="text-graphite-500">{c.storage_location || '-'}</TableCell>
                                        <TableCell><Badge variant={STATUS_BADGE[c.status] || 'secondary'}>{STATUS_LABEL[c.status] || c.status}</Badge></TableCell>
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
                    )}
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} Container Inventory</DialogTitle></DialogHeader>
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
                            <div className="space-y-1.5"><Label>Container Type</Label><Input value={data.container_type} onChange={(e) => setData('container_type', e.target.value)} placeholder="e.g. Drum Limbah B3" />{errors.container_type && <p className="text-xs text-red-600">{errors.container_type}</p>}</div>
                            <div className="space-y-1.5"><Label>Code (optional)</Label><Input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="e.g. DRM-001" /></div>
                        </div>
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-1.5"><Label>Total</Label><Input type="number" min="0" value={data.total_quantity} onChange={(e) => setData('total_quantity', e.target.value)} />{errors.total_quantity && <p className="text-xs text-red-600">{errors.total_quantity}</p>}</div>
                            <div className="space-y-1.5"><Label>In Use</Label><Input type="number" min="0" value={data.in_use_quantity} onChange={(e) => setData('in_use_quantity', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Damaged</Label><Input type="number" min="0" value={data.damaged_quantity} onChange={(e) => setData('damaged_quantity', e.target.value)} /></div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Unit</Label><Input value={data.unit} onChange={(e) => setData('unit', e.target.value)} placeholder="unit, pcs" /></div>
                            <div className="space-y-1.5">
                                <Label>Status</Label>
                                <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="under_maintenance">Under Maintenance</SelectItem>
                                        <SelectItem value="disposed">Disposed</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Capacity (optional)</Label><Input type="number" min="0" value={data.capacity} onChange={(e) => setData('capacity', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Capacity Unit</Label><Input value={data.capacity_unit} onChange={(e) => setData('capacity_unit', e.target.value)} placeholder="Liter, Kg" /></div>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Location / TPS (optional)</Label>
                            <Select value={data.storage_location_id || 'none'} onValueChange={(v) => setData('storage_location_id', v === 'none' ? '' : v)}>
                                <SelectTrigger><SelectValue placeholder="Not linked" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">Not linked</SelectItem>
                                    {storageLocations.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Notes (optional)</Label><Textarea value={data.notes} onChange={(e) => setData('notes', e.target.value)} rows={2} /></div>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
