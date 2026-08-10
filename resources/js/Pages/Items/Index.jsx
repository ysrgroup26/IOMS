import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/shared/PageHeader';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Badge } from '@/Components/ui/badge';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Checkbox } from '@/Components/ui/checkbox';
import EmptyState from '@/Components/shared/EmptyState';
import { Plus, Pencil, Trash2, Search, Package } from 'lucide-react';

export default function ItemsIndex({ items, filters, types, companies, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '', name: '', category: '', type: 'consumable', specification: '',
        unit: 'pcs', brand: '', min_stock: '0', max_stock: '', is_active: true, attachment: null,
    });

    function applyFilters(overrides = {}) {
        router.get(route('items.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });
    }

    function openCreate() { setEditing(null); reset(); setOpen(true); }
    function openEdit(item) {
        setEditing(item);
        setData({
            company_id: String(item.company_id), name: item.name, category: item.category || '',
            type: item.type, specification: item.specification || '', unit: item.unit, brand: item.brand || '',
            min_stock: String(item.min_stock), max_stock: item.max_stock ? String(item.max_stock) : '',
            is_active: item.is_active, attachment: null,
        });
        setOpen(true);
    }
    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, forceFormData: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) { put(route('items.update', editing.id), options); } else { post(route('items.store'), options); }
    }
    function destroy(item) {
        if (confirm(`Remove item "${item.name}"?`)) router.delete(route('items.destroy', item.id));
    }

    return (
        <AuthenticatedLayout>
            <Head title="Item Master" />
            <PageHeader title="Item Master" subtitle="Centralized item catalog shared by Warehouse, Maintenance, Project, and Procurement." />

            <Card className="mb-4">
                <CardContent className="flex flex-wrap items-center justify-between gap-2 p-3">
                    <div className="flex flex-wrap gap-2">
                        <div className="relative min-w-[220px]">
                            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                            <Input className="pl-8" placeholder="Search item name or code..." defaultValue={filters.search || ''} onChange={(e) => applyFilters({ search: e.target.value || null })} />
                        </div>
                        <Select value={filters.type || 'all'} onValueChange={(v) => applyFilters({ type: v === 'all' ? null : v })}>
                            <SelectTrigger className="w-40"><SelectValue placeholder="Type" /></SelectTrigger>
                            <SelectContent><SelectItem value="all">All Types</SelectItem>{types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    {can.manage && <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Item</Button>}
                </CardContent>
            </Card>

            <Card>
                <CardContent className="p-0">
                    {items.data.length === 0 ? (
                        <EmptyState icon={Package} title="No items in the catalog" description="Add an item to start tracking it." />
                    ) : (
                        <Table>
                            <TableHeader><TableRow><TableHead>Code</TableHead><TableHead>Name</TableHead><TableHead>Type</TableHead><TableHead>Category</TableHead><TableHead>Unit</TableHead><TableHead>Min/Max Stock</TableHead><TableHead>Status</TableHead>{can.manage && <TableHead />}</TableRow></TableHeader>
                            <TableBody>
                                {items.data.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium">{item.item_code}</TableCell>
                                        <TableCell>{item.name}</TableCell>
                                        <TableCell><Badge variant="outline" className="capitalize">{item.type.replace('_', ' ')}</Badge></TableCell>
                                        <TableCell>{item.category || '-'}</TableCell>
                                        <TableCell>{item.unit}</TableCell>
                                        <TableCell>{item.min_stock} / {item.max_stock ?? '-'}</TableCell>
                                        <TableCell><Badge variant={item.is_active ? 'success' : 'secondary'}>{item.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                        {can.manage && (
                                            <TableCell className="flex gap-1">
                                                <Button variant="ghost" size="icon" onClick={() => openEdit(item)}><Pencil className="h-4 w-4" /></Button>
                                                <Button variant="ghost" size="icon" onClick={() => destroy(item)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
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
                    <DialogHeader><DialogTitle>{editing ? 'Edit Item' : 'Add Item'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData('company_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} />{errors.name && <p className="text-xs text-red-600">{errors.name}</p>}</div>
                            <div className="space-y-1.5">
                                <Label>Type</Label>
                                <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{types.map((t) => <SelectItem key={t} value={t} className="capitalize">{t.replace('_', ' ')}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5"><Label>Category</Label><Input value={data.category} onChange={(e) => setData('category', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Brand</Label><Input value={data.brand} onChange={(e) => setData('brand', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Specification</Label><Textarea value={data.specification} onChange={(e) => setData('specification', e.target.value)} rows={2} /></div>
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-1.5"><Label>Unit</Label><Input value={data.unit} onChange={(e) => setData('unit', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Min Stock</Label><Input type="number" min="0" value={data.min_stock} onChange={(e) => setData('min_stock', e.target.value)} /></div>
                            <div className="space-y-1.5"><Label>Max Stock</Label><Input type="number" min="0" value={data.max_stock} onChange={(e) => setData('max_stock', e.target.value)} /></div>
                        </div>
                        <div className="space-y-1.5"><Label>Attachment</Label><Input type="file" onChange={(e) => setData('attachment', e.target.files[0])} /></div>
                        <label className="flex items-center gap-2 text-sm"><Checkbox checked={data.is_active} onCheckedChange={(v) => setData('is_active', !!v)} /> Active</label>
                        <DialogFooter><Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
