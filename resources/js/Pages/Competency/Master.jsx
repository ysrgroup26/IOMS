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
import { Plus, Pencil, Trash2, AlertTriangle } from 'lucide-react';

/**
 * Milestone 4, Workstream A2. Training/Certification catalog -- same
 * table-driven master pattern as Ppe/Master.jsx (no hard-coded list of
 * competencies, Admin manages the catalog directly).
 */
export default function CompetencyMaster({ competencyTypes, companies, positions, can }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const { data, setData, post, put, processing, reset, errors } = useForm({
        company_id: companies[0]?.id ? String(companies[0].id) : '',
        name: '',
        type: 'training',
        issuing_body: '',
        validity_months: '',
        description: '',
        is_active: true,
        required_position_ids: [],
    });

    const filteredPositions = positions.filter((p) => String(p.company_id) === data.company_id);

    function openCreate() {
        setEditing(null);
        reset();
        setOpen(true);
    }

    function openEdit(type) {
        setEditing(type);
        setData({
            company_id: String(type.company_id),
            name: type.name,
            type: type.type,
            issuing_body: type.issuing_body ?? '',
            validity_months: type.validity_months ?? '',
            description: type.description ?? '',
            is_active: type.is_active,
            required_position_ids: (type.required_by_positions ?? []).map((p) => p.id),
        });
        setOpen(true);
    }

    function togglePosition(id) {
        setData('required_position_ids', data.required_position_ids.includes(id)
            ? data.required_position_ids.filter((i) => i !== id)
            : [...data.required_position_ids, id]);
    }

    function submit(e) {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); setOpen(false); } };
        if (editing) {
            put(route('competency-types.update', editing.id), options);
        } else {
            post(route('competency-types.store'), options);
        }
    }

    function destroy(type) {
        if (confirm(`Remove "${type.name}"? Only possible if no employee has this competency recorded.`)) {
            router.delete(route('competency-types.destroy', type.id));
        }
    }

    return (
        <AuthenticatedLayout>
            <Head title="Competency Master" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-[22px] font-semibold tracking-tight text-graphite-900">Training &amp; Competency Master</h1>
                    <p className="mt-1 text-sm text-graphite-500">
                        Configure the training and certification catalog. Nothing here is hard-coded -- everything is editable.
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" asChild>
                        <Link href={route('competency.expiring-soon')}><AlertTriangle className="h-4 w-4" /> Expiring Soon</Link>
                    </Button>
                    {can.manage && (
                        <Button onClick={openCreate}><Plus className="h-4 w-4" /> Add Competency</Button>
                    )}
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Competency Types</CardTitle>
                    <CardDescription>{competencyTypes.length} configured</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Issuing Body</TableHead>
                                    <TableHead>Validity</TableHead>
                                    <TableHead>Required By</TableHead>
                                    <TableHead>Records</TableHead>
                                    <TableHead>Status</TableHead>
                                    {can.manage && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {competencyTypes.map((t) => (
                                    <TableRow key={t.id}>
                                        <TableCell className="font-medium">{t.name}</TableCell>
                                        <TableCell><Badge variant="outline" className="capitalize">{t.type}</Badge></TableCell>
                                        <TableCell className="text-graphite-500">{t.issuing_body ?? '—'}</TableCell>
                                        <TableCell>{t.validity_months ? `${t.validity_months} months` : <span className="text-graphite-400">No expiry</span>}</TableCell>
                                        <TableCell className="text-graphite-500">
                                            {t.required_by_positions?.length
                                                ? t.required_by_positions.map((p) => p.name).join(', ')
                                                : '—'}
                                        </TableCell>
                                        <TableCell>{t.employee_competencies_count}</TableCell>
                                        <TableCell>
                                            <Badge variant={t.is_active ? 'success' : 'secondary'}>{t.is_active ? 'Active' : 'Inactive'}</Badge>
                                        </TableCell>
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
                    </div>
                </CardContent>
            </Card>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-xl">
                    <DialogHeader><DialogTitle>{editing ? 'Edit Competency Type' : 'Add Competency Type'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="max-h-[70vh] space-y-4 overflow-y-auto pr-1">
                        <div className="space-y-1.5">
                            <Label>Company</Label>
                            <Select value={data.company_id} onValueChange={(v) => setData((prev) => ({ ...prev, company_id: v, required_position_ids: [] }))}>
                                <SelectTrigger><SelectValue placeholder="Select company" /></SelectTrigger>
                                <SelectContent>
                                    {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {errors.company_id && <p className="text-xs text-red-600">{errors.company_id}</p>}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Name</Label>
                            <Input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Working at Height, SIO Crane" />
                            {errors.name && <p className="text-xs text-red-600">{errors.name}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="training">Training</SelectItem>
                                        <SelectItem value="certification">Certification</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Validity (months, optional)</Label>
                                <Input
                                    type="number" min="1" max="600"
                                    value={data.validity_months}
                                    onChange={(e) => setData('validity_months', e.target.value)}
                                    placeholder="Leave empty if it never expires"
                                />
                                {errors.validity_months && <p className="text-xs text-red-600">{errors.validity_months}</p>}
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Issuing Body (optional)</Label>
                            <Input value={data.issuing_body} onChange={(e) => setData('issuing_body', e.target.value)} placeholder="e.g. Kemnaker, BNSP" />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Description (optional)</Label>
                            <Textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows={2} />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Required for Positions (optional)</Label>
                            <div className="grid grid-cols-2 gap-2 rounded-lg border border-graphite-100 p-3 sm:grid-cols-3">
                                {filteredPositions.length === 0 && <p className="col-span-full text-xs text-graphite-400">No positions in this company yet.</p>}
                                {filteredPositions.map((p) => (
                                    <label key={p.id} className="flex items-center gap-2 text-sm">
                                        <Checkbox checked={data.required_position_ids.includes(p.id)} onCheckedChange={() => togglePosition(p.id)} />
                                        {p.name}
                                    </label>
                                ))}
                            </div>
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
        </AuthenticatedLayout>
    );
}
