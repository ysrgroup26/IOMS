import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Pencil, Trash2, Warehouse as WarehouseIcon } from 'lucide-react';

export default function WarehousesMaster({ warehouses, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '', code: '', name: '', location: '', status: 'active',
    });

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(w) {
        setEditing(w);
        setData({ company_id: String(w.company_id), code: w.code, name: w.name, location: w.location || '', status: w.status });
        setOpen(true);
    }
    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('warehouses.update', editing.id), options); } else { post(route('warehouses.store'), options); }
    }
    function destroy(w) {
        if (confirm(`Remove warehouse "${w.name}"?`)) router.delete(route('warehouses.destroy', w.id));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Warehouse Master" />
            <PageHeader title="Warehouse Master" subtitle="Daftar gudang dan lokasi penyimpanannya." />

            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <div><CardTitle>Warehouses</CardTitle><CardDescription>{warehouses.length} configured</CardDescription></div>
                    {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Warehouse</Button>}
                </CardHeader>
                <CardContent className="p-0">
                    {warehouses.length === 0 ? (
                        <EmptyState icon={WarehouseIcon} title="No warehouses configured" />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Name</TableHead><TableHead>Location</TableHead><TableHead>PIC</TableHead><TableHead>Locations</TableHead><TableHead>Items</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                            <TableBody>
                                {warehouses.map((w) => (
                                    <TableRow key={w.id}>
                                        <TableCell className="font-medium">{w.code}</TableCell>
                                        <TableCell>{w.name}</TableCell>
                                        <TableCell>{w.location || '-'}</TableCell>
                                        <TableCell>{w.pic?.name || '-'}</TableCell>
                                        <TableCell>{w.storage_locations_count}</TableCell>
                                        <TableCell>{w.stocks_count}</TableCell>
                                        <TableCell><Badge variant={w.status === 'active' ? 'success' : 'secondary'}>{w.status}</Badge></TableCell>
                                        {can.manage && (
                                            <TableCell className="flex gap-1">
                                                <Button variant="ghost" size="icon" onClick={() => openEdit(w)}><Pencil className="h-4 w-4" /></Button>
                                                <Button variant="ghost" size="icon" onClick={() => destroy(w)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
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
                    <DialogHeader><DialogTitle>{editing ? 'Edit' : 'Add'} Warehouse</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Code</Label><Input value={data.code} onChange={(e) => setData('code', e.target.value)} />{errors.code && <p className="text-xs text-red-600">{errors.code}</p>}</div>
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                        </div>
                        <div className="space-y-1.5"><Label>Location</Label><Input value={data.location} onChange={(e) => setData('location', e.target.value)} /></div>
                        <div className="space-y-1.5">
                            <Label>Status</Label>
                            <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="active">Active</SelectItem><SelectItem value="inactive">Inactive</SelectItem></SelectContent>
                            </Select>
                        </div>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
