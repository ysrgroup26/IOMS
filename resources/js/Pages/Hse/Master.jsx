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
import { Plus, Pencil, Trash2 } from 'lucide-react';

/**
 * Milestone 4, Workstream B0 (HSE Foundation/Master Data). Hazard Category
 * master on its own setup page -- mirrors Shifts/Master.jsx's section
 * shape exactly. Future HSE masters (Safety Equipment types, Safety
 * Material types, etc.) land on this same page as additional sections
 * rather than each growing its own standalone route.
 */
export default function HseMaster({ hazardCategories, companies, can }) {
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
        </AuthenticatedLayout>
    );
}
