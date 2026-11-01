/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Plus, Trash2, Pencil } from 'lucide-react';

export default function PositionsTab({ positions, departments, companies, filters }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const { data, setData, post, put, processing, reset } = useForm({ name: '', description: '', company_id: undefined, department_id: '', sort_order: 0 });

    function filterByCompany(companyId) {
        router.get(route('settings.index'), { ...filters, company_id: companyId === 'all' ? null : companyId, tab: 'positions' }, { preserveState: true, preserveScroll: true, replace: true });
    }

    const availableDepartments = data.company_id ? departments.filter((d) => d.company_id === Number(data.company_id)) : departments;

    function openCreate() {
        setEditing(null);
        reset();
        setOpen(true);
    }

    function openEdit(position) {
        setEditing(position);
        setData({ name: position.name, description: position.description ?? '', company_id: String(position.company_id), department_id: position.department_id ? String(position.department_id) : '', sort_order: position.sort_order ?? 0 });
        setOpen(true);
    }

    function submit(e) {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); setOpen(false); } };
        if (editing) {
            put(route('settings.positions.update', editing.id), options);
        } else {
            post(route('settings.positions.store'), options);
        }
    }

    function destroy(id) {
        if (confirm('Hapus posisi ini?')) router.delete(route('settings.positions.destroy', id));
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div>
                    <CardTitle>Positions</CardTitle>
                    <CardDescription>Manage job positions per department. Display Order controls seniority ordering throughout the app.</CardDescription>
                </div>
                <Button size="sm" onClick={openCreate}><Plus className="h-4 w-4" /> Add</Button>
            </CardHeader>
            <CardContent>
                <div className="mb-3 flex items-center gap-2">
                    <Label className="text-xs text-graphite-500">Operating Unit</Label>
                    <Select value={filters?.company_id ? String(filters.company_id) : 'all'} onValueChange={filterByCompany}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="All Operating Units" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Operating Units</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>
                <Table>
                    <TableHeader><TableRow><TableHead>Order</TableHead><TableHead>Name</TableHead><TableHead>Department</TableHead><TableHead /></TableRow></TableHeader>
                    <TableBody>
                        {positions.map((p) => (
                            <TableRow key={p.id}>
                                <TableCell className="text-graphite-400">{p.sort_order}</TableCell>
                                <TableCell className="font-medium">{p.name}</TableCell>
                                <TableCell>{p.department?.name ?? '-'}</TableCell>
                                <TableCell className="flex gap-1">
                                    <Button variant="ghost" size="icon" onClick={() => openEdit(p)}><Pencil className="h-4 w-4" /></Button>
                                    <Button variant="ghost" size="icon" onClick={() => destroy(p.id)}><Trash2 className="h-4 w-4 text-red-500" /></Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit Position' : 'Add Position'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div className="space-y-1.5"><Label>Name</Label><Input value={data.name} onChange={(e) => setData('name', e.target.value)} /></div>
                        <div className="space-y-1.5">
                            <Label>Operating Unit</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData((d) => ({ ...d, company_id: v, department_id: '' }))}>
                                <SelectTrigger><SelectValue placeholder="Select operating unit" /></SelectTrigger>
                                <SelectContent>{companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Department (optional)</Label>
                            <Select value={data.department_id} onValueChange={(v) => setData('department_id', v)}>
                                <SelectTrigger><SelectValue placeholder="Select department" /></SelectTrigger>
                                <SelectContent>{availableDepartments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5"><Label>Description (optional)</Label><Input value={data.description} onChange={(e) => setData('description', e.target.value)} /></div>
                        <div className="space-y-1.5">
                            <Label>Display Order</Label>
                            <Input type="number" min="0" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} />
                            <p className="text-xs text-graphite-400">Lower numbers appear first (e.g. General Manager before Staff).</p>
                        </div>
                        <DialogFooter><Button type="submit" disabled={processing}>Save</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
